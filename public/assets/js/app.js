/* Dwell Central — app shell: sign-in, navigation, routing and shared building blocks. */
(function ($, DC) {
  'use strict';

  var boot = DC.boot = JSON.parse(document.getElementById('boot').textContent);
  var S = DC.S = { me: null, perms: [], lists: { brands: [], entities: [], properties: [], departments: [] } };
  var esc = DC.esc;
  var $root = $('#app');
  DC.views = {};

  DC.can = function (perm) { return S.perms.indexOf(perm) !== -1; };

  // ------------------------------------------------------------------ lookups
  DC.byId = function (list, id) { id = +id; for (var i = 0; i < list.length; i++) if (list[i].id === id) return list[i]; return null; };
  DC.brandColor = function (person) { return (person && person.brand && person.brand.color) || '#2B4F86'; };
  /** "Dwell Central" → "Central" under the Dwell Communities logo (the full name if it doesn't start with Dwell). */
  DC.portalLabel = function () { var n = S.portal || boot.portal_name || ''; return /^dwell\s+/i.test(n) ? n.replace(/^dwell\s+/i, '') : n; };
  DC.logoHtml = function (cls) {
    return '<img class="' + (cls || 'logo') + '" src="' + esc(boot.logo) + '" alt="' + esc(S.org || boot.org_name) + '" width="410" height="116"><span class="portal-label">' + esc(DC.portalLabel()) + '</span>';
  };
  DC.options = function (list, selected, empty, labelFn) {
    var html = empty !== false ? '<option value="">' + esc(empty || '—') + '</option>' : '';
    list.forEach(function (o) {
      html += '<option value="' + o.id + '"' + (String(o.id) === String(selected) ? ' selected' : '') + '>' + esc(labelFn ? labelFn(o) : o.name) + (o.is_active === false ? ' (inactive)' : '') + '</option>';
    });
    return html;
  };

  /** Round avatar: approved photo, else initials on the person's company color. */
  DC.avatar = function (p, size) {
    var c = DC.brandColor(p);
    var cls = 'avatar' + (size ? ' ' + size : '');
    var inner = p.photo ? '<img src="' + esc(p.photo) + '" alt="" loading="lazy">' : esc(p.initials || '?');
    return '<span class="' + cls + '" style="--c:' + esc(c) + '">' + inner + '</span>';
  };

  DC.typeBadge = function (type, years) {
    return type === 'birthday'
      ? '<span class="badge bday">' + DC.icon('cake') + 'Birthday</span>'
      : '<span class="badge anniv">' + DC.icon('award') + (years ? DC.plural(years, 'year') : 'Anniversary') + '</span>';
  };

  DC.modePill = function (mode) {
    if (!mode) return '';
    var label = { test: 'Test mode', live: 'Live', off: 'Emails off' }[mode] || mode;
    return '<span class="mode-pill ' + esc(mode) + '"><span class="dot"></span>' + esc(label) + '</span>';
  };

  DC.pageHead = function (title, sub, $actions) {
    var $h = $('<div class="page-head"><div><h1></h1><div class="sub"></div></div><div class="actions"></div></div>');
    $h.find('h1').text(title);
    if (sub) $h.find('.sub').html(sub); else $h.find('.sub').remove();
    if ($actions) $h.find('.actions').append($actions);
    return $h;
  };

  /** Notification status chips for a celebration (advance / day-of / monthly). */
  DC.notifChips = function (ev) {
    var out = [];
    var kinds = { advance: 'Reminder', day_of: 'Day-of', monthly: 'Monthly' };
    var seen = {};
    (ev.notifications || []).forEach(function (n) {
      if (!kinds[n.kind] || seen[n.kind + n.mode]) return;
      seen[n.kind + n.mode] = true;
      var cls = n.status === 'sent' ? 'ok' : (n.status === 'failed' ? 'danger' : (n.status === 'held' ? 'outline' : 'info'));
      var label = kinds[n.kind] + (n.mode === 'test' ? ' (test)' : '') + ': ' + ({ sent: 'sent', pending: 'queued', failed: 'failed', held: 'recorded', cancelled: 'cancelled' }[n.status] || n.status);
      out.push('<span class="badge ' + cls + '" title="' + esc(n.to ? 'To ' + n.to : '') + '">' + esc(label) + '</span>');
    });
    if (ev.follow_up) {
      out.push('<span class="badge ' + (ev.follow_up.status === 'completed' ? 'ok' : 'outline') + '">' + esc({ planned: 'Planned', completed: 'Celebrated', skipped: 'Skipped' }[ev.follow_up.status]) + '</span>');
    }
    if (!ev.owner || !ev.owner.id) out.unshift('<span class="badge danger">' + DC.icon('alert') + 'No owner</span>');
    return out.join('');
  };

  // ------------------------------------------------------------------ boot

  DC.onAuthFail = function () { if (S.me) { S.me = null; location.hash = '#/login'; } };

  function init() {
    DC.api('me', 'GET', null, { quiet401: true }).then(function (res) {
      applyMe(res);
      if (S.me) { renderShell(); route(); } else { route(); }
    }, function (err) {
      $root.html('<div class="login"><div class="login-card"><h1>Can’t load</h1><p class="lead"></p><button class="btn js-reload">Try again</button></div></div>');
      $root.find('.lead').text(err.message);
      $root.find('.js-reload').on('click', function () { location.reload(); });
    });
  }

  function applyMe(res) {
    S.portal = res.portal_name; S.org = res.org_name; S.google = res.google;
    S.me = res.user;
    if (!S.me) return;
    S.perms = res.permissions; S.lists = res.lists; S.today = res.today; S.tz = res.timezone; S.mode = res.mode;
    S.classifications = res.classifications; S.statuses = res.statuses; S.issueLabels = res.issue_labels; S.roles = res.roles;
    S.storeBirthYear = res.store_birth_year;
    DC.csrf = res.csrf;
  }
  DC.refreshMe = function () { return DC.get('me').then(function (res) { applyMe(res); updateShell(); }); };
  DC.refreshLists = function (lists) { if (lists) S.lists = lists; };

  // ------------------------------------------------------------------ sign-in screens

  function loginCard(inner) {
    $root.html('<div class="login"><div class="login-card">' +
      '<div class="login-brand">' + DC.logoHtml() + '</div>' +
      inner +
      '<div class="private-note">' + DC.icon('lock') + '<span>A private personnel application for authorized ' + esc(S.org || boot.org_name) + ' staff. Activity is logged.</span></div>' +
      '</div></div>');
    return $root.find('.login-card');
  }

  function showLogin(error) {
    var $c = loginCard('<h1>Welcome back</h1><p class="lead">Sign in to see your team.</p>' +
      (S.google ? '<a class="btn btn-google btn-block" href="google.php?start=1">' + DC.googleLogo + ' Sign in with Google</a><div class="or">or with email</div>' : '') +
      '<form novalidate>' +
        '<div class="field"><label for="li-email">Email</label><input class="input" type="email" id="li-email" name="email" autocomplete="username" required></div>' +
        '<div class="field"><label for="li-pass">Password</label><input class="input" type="password" id="li-pass" name="password" autocomplete="current-password" required></div>' +
        '<button class="btn btn-block" type="submit">Sign in</button>' +
      '</form><div class="login-foot"><a href="#/forgot">Forgot your password?</a></div>');
    var $f = $c.find('form');
    if (error) DC.formErrors($f, { message: error });
    if (!DC.isMobile()) $f.find('[name=email]').trigger('focus');
    $f.on('submit', function (e) {
      e.preventDefault();
      var $b = $f.find('button[type=submit]');
      DC.formErrors($f, null);
      DC.busy($b, DC.api('login', 'POST', { email: $f.find('[name=email]').val(), password: $f.find('[name=password]').val() }, { quiet401: true }), 'Signing in…')
        .then(function (res) { DC.csrf = res.csrf; return DC.get('me'); })
        .then(function (res) { applyMe(res); renderShell(); location.hash = '#/'; route(); })
        .catch(function (err) { DC.formErrors($f, err); });
    });
  }

  function showForgot() {
    var $c = loginCard('<h1>Reset password</h1><p class="lead">Enter your work email. If you have an account, we’ll email you a link.</p>' +
      '<form novalidate><div class="field"><label for="fp-email">Email</label><input class="input" type="email" id="fp-email" name="email" autocomplete="username" required></div>' +
      '<button class="btn btn-block" type="submit">Email me a link</button></form><div class="login-foot"><a href="#/login">Back to sign in</a></div>');
    var $f = $c.find('form');
    $f.on('submit', function (e) {
      e.preventDefault();
      DC.busy($f.find('button'), DC.api('forgot', 'POST', { email: $f.find('[name=email]').val() }, { quiet401: true }), 'Sending…').then(function () {
        $f.replaceWith('<div class="alert alert-ok">' + DC.icon('check') + '<span>If that email has an account, a reset link is on its way. It works for 24 hours.</span></div>');
      }).catch(function (err) { DC.formErrors($f, err); });
    });
  }

  function showTokenPage(purpose, token) {
    var $c = loginCard('<div class="empty"><span class="spinner"></span></div>');
    DC.api('token.check', 'GET', { purpose: purpose, token: token }, { quiet401: true }).then(function (res) {
      if (!res.ok) {
        $c.find('.empty').replaceWith('<h1>Link expired</h1><p class="lead">This link has expired or was already used. Ask an administrator for a new one.</p><a class="btn btn-ghost" href="#/login">Go to sign in</a>');
        return;
      }
      $c.find('.empty').replaceWith(
        '<h1>' + (purpose === 'invite' ? 'Welcome, ' + esc(res.name.split(' ')[0]) : 'Choose a new password') + '</h1>' +
        '<p class="lead">' + (purpose === 'invite' ? 'Choose a password for <strong>' + esc(res.email) + '</strong>.' : 'For ' + esc(res.email) + '.') + '</p>' +
        '<form novalidate><input type="email" name="username" value="' + esc(res.email) + '" autocomplete="username" hidden>' +
        '<div class="field"><label for="tp-pass">New password</label><input class="input" type="password" id="tp-pass" name="password" autocomplete="new-password" minlength="10" required>' +
        '<div class="field-hint">At least 10 characters. A short sentence works well.</div></div>' +
        '<button class="btn btn-block" type="submit">' + (purpose === 'invite' ? 'Set password and continue' : 'Save password') + '</button></form>');
      var $f = $c.find('form');
      $f.on('submit', function (e) {
        e.preventDefault();
        DC.busy($f.find('button'), DC.api('token.redeem', 'POST', { purpose: purpose, token: token, password: $f.find('[name=password]').val() }, { quiet401: true }), 'Saving…')
          .then(function (r) { DC.csrf = r.csrf; return DC.get('me'); })
          .then(function (res) { applyMe(res); renderShell(); location.hash = '#/'; })
          .catch(function (err) { DC.formErrors($f, err); });
      });
    });
  }

  // ------------------------------------------------------------------ shell

  function navItems() {
    var items = [];
    if (DC.can('celebrations.view')) items.push(['dashboard', 'Dashboard', 'home']);
    items.push(['org', 'Org chart', 'tree'], ['people', 'Directory', 'users']);
    if (DC.can('celebrations.view')) items.push(['celebrations', 'Celebrations', 'cal']);
    var admin = [];
    if (DC.can('people.edit')) admin.push(['admin/people', 'People records', 'user']);
    if (DC.can('import.run')) admin.push(['admin/import', 'Import', 'upload']);
    if (DC.can('notifications.view')) admin.push(['notifications', 'Notifications', 'mail']);
    if (DC.can('org.manage')) admin.push(['admin/organization', 'Organization', 'building']);
    if (DC.can('users.manage')) admin.push(['admin/users', 'Users & access', 'key']);
    if (DC.can('settings.manage')) admin.push(['admin/settings', 'Settings', 'gear']);
    if (DC.can('audit.view')) admin.push(['admin/audit', 'Audit log', 'shield']);
    return { main: items, admin: admin };
  }

  function navHtml(list) {
    return list.map(function (n) { return '<a class="nav" href="#/' + n[0] + '" data-nav="' + n[0] + '">' + DC.icon(n[2]) + '<span>' + esc(n[1]) + '</span></a>'; }).join('');
  }

  function renderShell() {
    var nav = navItems();
    var links = navHtml(nav.main) + (nav.admin.length ? '<div class="side-label">Administration</div>' + navHtml(nav.admin) : '');
    var brand = DC.logoHtml();
    $root.html(
      '<div class="shell">' +
        '<aside class="side"><a class="side-brand" href="#/">' + brand + '</a><div class="side-label">Team</div>' + links +
          '<div class="side-foot"><div class="js-mode"></div><div><strong>' + esc(S.me.name) + '</strong>' + esc(S.me.role_label) + '</div>' +
          '<div class="links">' + (S.me.has_password ? '<button type="button" class="js-password">Password</button>' : '') + '<button type="button" class="js-logout">Sign out</button></div></div></aside>' +
        '<div style="min-width:0"><header class="topbar"><a href="#/" style="display:flex;flex-direction:column;text-decoration:none">' + DC.logoHtml() + '</a>' +
          '<button type="button" class="icon-btn js-menu" aria-label="Menu">' + DC.icon('menu') + '</button></header>' +
          '<main class="content" id="main"></main></div>' +
      '</div>' +
      '<nav class="mobile-nav" hidden><div class="mn-head"><a class="side-brand" href="#/">' + brand + '</a><button type="button" class="icon-btn js-menu-close" aria-label="Close menu">' + DC.icon('x') + '</button></div>' +
        links +
        '<div class="side-foot" style="margin-top:18px"><div><strong>' + esc(S.me.name) + '</strong>' + esc(S.me.email) + '</div><div class="links">' +
        (S.me.has_password ? '<button type="button" class="js-password">Password</button>' : '') + '<button type="button" class="js-logout">Sign out</button></div></div></nav>');
    updateShell();
  }

  function updateShell() {
    $root.find('.js-mode').html(S.mode ? DC.modePill(S.mode) : '');
  }
  DC.setMode = function (mode) { S.mode = mode; updateShell(); };

  $(document).on('click', '.js-menu', function () { $('.mobile-nav').prop('hidden', false); });
  $(document).on('click', '.js-menu-close, .mobile-nav a', function () { $('.mobile-nav').prop('hidden', true); });
  $(document).on('click', '.js-logout', function () {
    DC.api('logout', 'POST', {}, { quiet401: true }).finally(function () { S.me = null; location.hash = '#/login'; location.reload(); });
  });
  $(document).on('click', '.js-password', function () { passwordDialog(); });

  function passwordDialog() {
    var $f = $('<form novalidate>' +
      '<input type="email" name="username" value="' + esc(S.me.email) + '" autocomplete="username" hidden>' +
      '<div class="field"><label>Current password</label><input class="input" type="password" name="current" autocomplete="current-password"></div>' +
      '<div class="field"><label>New password</label><input class="input" type="password" name="password" autocomplete="new-password"><div class="field-hint">At least 10 characters. Changing it signs out your other devices.</div></div></form>');
    var $save = $('<button class="btn" type="button">Change password</button>');
    var d = DC.dialog({ title: 'Change password', body: $f, foot: [$('<button class="btn btn-ghost" type="button">Cancel</button>').on('click', function () { d.close(); }), $save] });
    function submit() {
      DC.formErrors($f, null);
      DC.busy($save, DC.post('password.change', { current: $f.find('[name=current]').val(), password: $f.find('[name=password]').val() }), 'Saving…')
        .then(function (r) { DC.csrf = r.csrf; d.close(); DC.toast('Password changed.'); })
        .catch(function (err) { DC.formErrors($f, err); });
    }
    $save.on('click', submit);
    $f.on('submit', function (e) { e.preventDefault(); submit(); });
  }

  // ------------------------------------------------------------------ routing

  DC.$main = function () { return $('#main'); };
  DC.route = route;
  DC.currentRoute = '';

  function route() {
    var hash = location.hash.replace(/^#\/?/, '');
    var q = '';
    if (hash.indexOf('?') !== -1) { q = hash.slice(hash.indexOf('?') + 1); hash = hash.slice(0, hash.indexOf('?')); }
    var parts = hash.split('/').filter(function (p) { return p !== ''; }).map(decodeURIComponent);
    var params = {};
    q.split('&').forEach(function (kv) { if (!kv) return; var p = kv.split('='); params[decodeURIComponent(p[0])] = decodeURIComponent((p[1] || '').replace(/\+/g, ' ')); });

    if (!S.me) {
      if (parts[0] === 'forgot') return showForgot();
      if (parts[0] === 'welcome' && parts[1]) return showTokenPage('invite', parts[1]);
      if (parts[0] === 'reset' && parts[1]) return showTokenPage('reset', parts[1]);
      return showLogin(params.error);
    }
    if (!$('#main').length) renderShell();
    if (['login', 'forgot', 'welcome', 'reset'].indexOf(parts[0]) !== -1) { location.hash = '#/'; return; }

    var view = parts[0] || (DC.can('celebrations.view') ? 'dashboard' : 'org');
    var key = view === 'admin' ? 'admin/' + (parts[1] || '') : view;
    var sub = view === 'admin' ? parts.slice(2) : parts.slice(1);

    DC.closeDialogs();
    DC.closeDrawer(true);
    $('.mobile-nav').prop('hidden', true);
    $root.find('[data-nav]').removeClass('active').filter(function () {
      var n = $(this).data('nav');
      return n === key || (key === 'dashboard' && n === 'dashboard');
    }).addClass('active');
    var fn = DC.views[key];
    var $main = $('#main').empty().removeClass('flush');
    if (!fn) {
      $main.append(DC.pageHead('Not found'), '<div class="panel"><div class="empty"><strong>That page doesn’t exist.</strong><a href="#/">Go to the start</a></div></div>');
      return;
    }
    DC.currentRoute = key;
    window.scrollTo(0, 0);
    fn($main, sub, params);
  }
  $(window).on('hashchange', route);

  DC.loading = function ($el) { $el.html('<div class="empty"><span class="spinner"></span></div>'); };
  DC.forbiddenOr = function ($el, err) {
    if (err && (err.status === 403 || err.status === 404)) {
      $el.html('<div class="panel"><div class="empty"><strong>Not available</strong>' + esc(err.message) + '</div></div>');
    } else { DC.fail(err); }
  };

  $(init);
})(jQuery, window.DC);
