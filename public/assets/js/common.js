/* Dwell Central — shared helpers: dates, escaping, icons, API calls, dialogs, drawers, toasts. */
(function ($, window) {
  'use strict';

  var DC = window.DC = window.DC || {};

  // ---------------------------------------------------------------- dates ("YYYY-MM-DD", math in UTC)
  var DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  function parse(d) { var p = String(d).split('-'); return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2])); }
  function iso(dt) { return dt.toISOString().slice(0, 10); }

  DC.date = {
    MONTHS: MONTHS, DAYS: DAYS,
    parse: parse, iso: iso,
    addDays: function (d, n) { var dt = parse(d); dt.setUTCDate(dt.getUTCDate() + n); return iso(dt); },
    addMonths: function (d, n) { var dt = parse(d.slice(0, 8) + '01'); dt.setUTCMonth(dt.getUTCMonth() + n); return iso(dt); },
    monthStart: function (d) { return d.slice(0, 8) + '01'; },
    monthEnd: function (d) { var dt = parse(d.slice(0, 8) + '01'); dt.setUTCMonth(dt.getUTCMonth() + 1); dt.setUTCDate(0); return iso(dt); },
    weekday: function (d) { return parse(d).getUTCDay(); },
    diffDays: function (a, b) { return Math.round((parse(b) - parse(a)) / 86400000); },
    long: function (d, withYear) { var dt = parse(d); return DAYS[dt.getUTCDay()] + ', ' + MONTHS[dt.getUTCMonth()] + ' ' + dt.getUTCDate() + (withYear ? ', ' + dt.getUTCFullYear() : ''); },
    short: function (d) { var dt = parse(d); return DAYS[dt.getUTCDay()].slice(0, 3) + ', ' + MONTHS[dt.getUTCMonth()].slice(0, 3) + ' ' + dt.getUTCDate(); },
    md: function (d) { var dt = parse(d); return MONTHS[dt.getUTCMonth()].slice(0, 3) + ' ' + dt.getUTCDate(); },
    full: function (d) { var dt = parse(d); return MONTHS[dt.getUTCMonth()] + ' ' + dt.getUTCDate() + ', ' + dt.getUTCFullYear(); },
    monthYear: function (d) { var dt = parse(d); return MONTHS[dt.getUTCMonth()] + ' ' + dt.getUTCFullYear(); },
    relative: function (d, today) {
      var diff = DC.date.diffDays(today, d);
      if (diff === 0) return 'Today';
      if (diff === 1) return 'Tomorrow';
      if (diff > 1 && diff < 7) return 'In ' + diff + ' days';
      return DC.date.short(d);
    },
    /** UTC "YYYY-MM-DD HH:MM:SS" → local readable string in the organization's time zone. */
    stamp: function (utc, tz) {
      if (!utc) return '';
      var dt = new Date(String(utc).replace(' ', 'T') + 'Z');
      try { return dt.toLocaleString('en-US', { timeZone: tz || 'America/New_York', month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }); }
      catch (e) { return utc + ' UTC'; }
    }
  };

  // ---------------------------------------------------------------- misc
  DC.esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  };
  DC.plural = function (n, one, many) { return n + ' ' + (n === 1 ? one : (many || one + 's')); };
  DC.store = {
    get: function (k, fallback) { try { var v = localStorage.getItem('dc.' + k); return v == null ? fallback : JSON.parse(v); } catch (e) { return fallback; } },
    set: function (k, v) { try { localStorage.setItem('dc.' + k, JSON.stringify(v)); } catch (e) { /* private mode */ } }
  };
  DC.isMobile = function () { return window.matchMedia('(max-width: 640px)').matches; };
  DC.debounce = function (fn, ms) { var t; return function () { var a = arguments, self = this; clearTimeout(t); t = setTimeout(function () { fn.apply(self, a); }, ms); }; };

  var ICONS = {
    home: '<path d="M4 11l8-6.5 8 6.5"/><path d="M6 9.5V20h12V9.5"/><path d="M10 20v-5h4v5"/>',
    tree: '<rect x="9" y="3" width="6" height="5" rx="1.2"/><rect x="2.5" y="16" width="6" height="5" rx="1.2"/><rect x="15.5" y="16" width="6" height="5" rx="1.2"/><path d="M12 8v4M5.5 16v-2h13v2"/>',
    users: '<circle cx="9" cy="9" r="3.2"/><path d="M3.5 19c.6-3 2.8-4.6 5.5-4.6s4.9 1.6 5.5 4.6"/><path d="M15.5 6.3a3 3 0 0 1 0 5.6M17.5 14.8c1.6.6 2.6 2 3 4.2"/>',
    user: '<circle cx="12" cy="8.5" r="3.5"/><path d="M5 20c.8-3.6 3.6-5.5 7-5.5s6.2 1.9 7 5.5"/>',
    cake: '<path d="M4 20h16M5 20v-6.5a1.5 1.5 0 0 1 1.5-1.5h11a1.5 1.5 0 0 1 1.5 1.5V20"/><path d="M5 15.5c1.2 1 2.3 1 3.5 0s2.3-1 3.5 0 2.3 1 3.5 0 2.3-1 3.5 0"/><path d="M12 12V9"/><path d="M12 6.8c-.9-.7-.9-1.8 0-3 .9 1.2.9 2.3 0 3z"/>',
    award: '<circle cx="12" cy="9" r="5.5"/><path d="M8.5 13.5L7 21l5-2.5 5 2.5-1.5-7.5"/><path d="M12 6.5l.8 1.6 1.7.2-1.2 1.2.3 1.7-1.6-.8-1.6.8.3-1.7-1.2-1.2 1.7-.2z"/>',
    cal: '<rect x="3.5" y="5" width="17" height="15" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
    upload: '<path d="M12 15V4M7.5 8.5L12 4l4.5 4.5"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
    mail: '<rect x="3.5" y="5.5" width="17" height="13" rx="2.5"/><path d="M4 7l8 6 8-6"/>',
    gear: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.6 1.6 0 0 0-1-1.5 1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.6 1.6 0 0 0 1.5-1 1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/>',
    shield: '<path d="M12 3l7 3v5.5c0 4.5-3 8-7 9.5-4-1.5-7-5-7-9.5V6z"/><path d="M9 12l2 2 4-4"/>',
    building: '<path d="M4 21V5.5A1.5 1.5 0 0 1 5.5 4h7A1.5 1.5 0 0 1 14 5.5V21"/><path d="M14 10h4.5A1.5 1.5 0 0 1 20 11.5V21M3 21h18M7.5 8h3M7.5 12h3M7.5 16h3M17 14v.01M17 17v.01"/>',
    key: '<circle cx="8" cy="15" r="3.5"/><path d="M10.5 12.5L19 4M16 7l2.5 2.5"/>',
    search: '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    minus: '<path d="M5 12h14"/>',
    x: '<path d="M6 6l12 12M18 6L6 18"/>',
    left: '<path d="M15 5l-7 7 7 7"/>',
    right: '<path d="M9 5l7 7-7 7"/>',
    down: '<path d="M6 9l6 6 6-6"/>',
    edit: '<path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16z"/><path d="M13.5 6.5l4 4"/>',
    check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    alert: '<path d="M12 3.5L2.5 20h19z"/><path d="M12 10v4.5M12 17.5v.01"/>',
    info: '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5M12 8v.01"/>',
    lock: '<rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/>',
    pin: '<path d="M12 21s-6.5-6.2-6.5-11a6.5 6.5 0 0 1 13 0c0 4.8-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.3"/>',
    briefcase: '<rect x="3.5" y="7" width="17" height="12.5" rx="2"/><path d="M9 7V5.5A1.5 1.5 0 0 1 10.5 4h3A1.5 1.5 0 0 1 15 5.5V7M3.5 12.5h17"/>',
    expand: '<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/>',
    collapse: '<path d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5"/>',
    fit: '<rect x="4" y="4" width="16" height="16" rx="2.5"/><path d="M9 12h6M12 9v6"/>',
    zoomin: '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5M8.5 11h5M11 8.5v5"/>',
    zoomout: '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5M8.5 11h5"/>',
    camera: '<path d="M4 8.5A1.5 1.5 0 0 1 5.5 7h2l1.5-2h6l1.5 2h2A1.5 1.5 0 0 1 20 8.5v9a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 17.5z"/><circle cx="12" cy="12.5" r="3.5"/>',
    menu: '<path d="M4 7h16M4 12h16M4 17h16"/>',
    out: '<path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4M10 16l-4-4 4-4M6 12h10"/>',
    play: '<path d="M7 5l12 7-12 7z"/>',
    eye: '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
    refresh: '<path d="M20 11a8 8 0 0 0-14.6-4.5L4 8M4 4v4h4M4 13a8 8 0 0 0 14.6 4.5L20 16M20 20v-4h-4"/>',
    link: '<path d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1 1"/><path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1-1"/>',
    file: '<path d="M14 3.5H7A1.5 1.5 0 0 0 5.5 5v14A1.5 1.5 0 0 0 7 20.5h10a1.5 1.5 0 0 0 1.5-1.5V8z"/><path d="M14 3.5V8h4.5M8.5 13h7M8.5 16.5h5"/>',
    dot: '<circle cx="12" cy="12" r="3" fill="currentColor"/>'
  };
  DC.icon = function (name) {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (ICONS[name] || '') + '</svg>';
  };
  DC.googleLogo = '<svg viewBox="0 0 48 48" width="18" height="18" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>';

  // ---------------------------------------------------------------- API
  DC.csrf = '';
  DC.onAuthFail = null;
  /** JSON request → Promise. Errors reject with {message, field, code, status, data}. */
  DC.api = function (action, method, data, opts) {
    opts = opts || {};
    method = method || 'GET';
    return new Promise(function (resolve, reject) {
      var isForm = typeof FormData !== 'undefined' && data instanceof FormData;
      var req = {
        url: 'api.php?action=' + encodeURIComponent(action) + (method === 'GET' && data ? '&' + $.param(data) : ''),
        method: method,
        dataType: 'json',
        headers: method === 'GET' ? {} : { 'X-CSRF-Token': DC.csrf },
        timeout: opts.timeout || 60000
      };
      if (method !== 'GET') {
        if (isForm) { req.data = data; req.processData = false; req.contentType = false; }
        else { req.data = JSON.stringify(data || {}); req.contentType = 'application/json'; }
      }
      $.ajax(req).done(resolve).fail(function (xhr, status) {
        var body = xhr.responseJSON || {};
        var err = {
          message: body.error || (status === 'timeout' ? 'The connection timed out. Please try again.'
            : (xhr.status === 0 ? 'Can’t reach the server. Please check your connection.' : 'Something went wrong. Please try again.')),
          field: body.field || null, code: body.code || null, status: xhr.status, data: body
        };
        if (err.status === 401 && DC.onAuthFail && !opts.quiet401) DC.onAuthFail(err);
        if (err.code === 'csrf') { DC.toast('Your session expired. Reloading…', 'error'); setTimeout(function () { location.reload(); }, 1200); }
        reject(err);
      });
    });
  };
  DC.get = function (action, params) { return DC.api(action, 'GET', params); };
  DC.post = function (action, data) { return DC.api(action, 'POST', data); };
  DC.fail = function (err) { if (err && err.status !== 401 && err.code !== 'csrf') DC.toast(err.message || String(err), 'error'); };

  // ---------------------------------------------------------------- toasts
  DC.toast = function (msg, type) {
    var $wrap = $('.toasts');
    if (!$wrap.length) $wrap = $('<div class="toasts" role="status" aria-live="polite"></div>').appendTo('body');
    var $t = $('<div class="toast"></div>').toggleClass('error', type === 'error').text(msg).appendTo($wrap);
    setTimeout(function () { $t.fadeOut(200, function () { $t.remove(); }); }, type === 'error' ? 6500 : 3800);
  };

  // ---------------------------------------------------------------- dialogs
  var openDialogs = [];
  /** opts: {title, eyebrow, body, foot, wide, xwide, onClose}. Returns {$el, $body, $foot, close, setTitle}. */
  DC.dialog = function (opts) {
    var lastFocus = document.activeElement;
    var $ov = $('<div class="overlay" role="presentation"></div>');
    var $dlg = $('<div class="dialog" role="dialog" aria-modal="true"></div>').toggleClass('wide', !!opts.wide).toggleClass('xwide', !!opts.xwide);
    var $head = $('<div class="dialog-head"><div style="flex:1;min-width:0"><div class="eyebrow"></div><h2></h2></div></div>');
    var $close = $('<button type="button" class="icon-btn dialog-close" aria-label="Close">' + DC.icon('x') + '</button>');
    var $body = $('<div class="dialog-body"></div>').append(opts.body || '');
    var $foot = $('<div class="dialog-foot"></div>').append(opts.foot || '');
    $head.find('.eyebrow').text(opts.eyebrow || '').toggle(!!opts.eyebrow);
    $head.find('h2').text(opts.title || '');
    $head.append($close);
    $dlg.append($head, $body);
    if (opts.foot) $dlg.append($foot);
    $ov.append($dlg).appendTo('body');
    var id = 'dlg-' + Math.random().toString(36).slice(2);
    $head.find('h2').attr('id', id);
    $dlg.attr('aria-labelledby', id);
    $('body').css('overflow', 'hidden');
    requestAnimationFrame(function () { $ov.addClass('is-open'); });
    var api = {
      $el: $dlg, $body: $body, $foot: $foot,
      setTitle: function (t, eyebrow) { $head.find('h2').text(t); if (eyebrow !== undefined) $head.find('.eyebrow').text(eyebrow).toggle(!!eyebrow); },
      close: function () {
        if (api.closed) return;
        api.closed = true;
        openDialogs = openDialogs.filter(function (d) { return d !== api; });
        $ov.removeClass('is-open');
        setTimeout(function () { $ov.remove(); }, 180);
        if (!openDialogs.length && !DC.drawerOpen) $('body').css('overflow', '');
        if (lastFocus && lastFocus.focus) { try { lastFocus.focus({ preventScroll: true }); } catch (e) { /* ignore */ } }
        if (opts.onClose) opts.onClose();
      }
    };
    $close.on('click', api.close);
    $ov.on('mousedown', function (e) { if (e.target === $ov[0]) api.close(); });
    openDialogs.push(api);
    setTimeout(function () {
      if ($dlg[0].contains(document.activeElement)) return;
      var $f = $dlg.find('[autofocus]').first();
      if (!$f.length && !DC.isMobile()) $f = $dlg.find('input:not([type=hidden]):not([type=checkbox]),select,textarea').filter(':visible').first();
      ($f.length ? $f : $close).trigger('focus');
    }, 60);
    return api;
  };
  DC.closeDialogs = function () { openDialogs.slice().forEach(function (d) { d.close(); }); };

  DC.confirm = function (opts) {
    return new Promise(function (resolve) {
      var done = false;
      var $ok = $('<button type="button" class="btn"></button>').text(opts.ok || 'Confirm').toggleClass('btn-danger', !!opts.danger);
      var $cancel = $('<button type="button" class="btn btn-ghost"></button>').text(opts.cancel || 'Go back');
      var $body = $('<div></div>');
      if (opts.html) $body.html(opts.html); else $body.append($('<p style="margin:0 0 8px;color:var(--ink-2)"></p>').text(opts.message || ''));
      var d = DC.dialog({ title: opts.title, body: $body, foot: [$cancel, $ok], onClose: function () { if (!done) resolve(false); } });
      $ok.on('click', function () { done = true; d.close(); resolve(true); });
      $cancel.on('click', function () { d.close(); });
    });
  };

  // ---------------------------------------------------------------- side drawer (profiles)
  var drawer = null;
  DC.drawerOpen = false;
  DC.drawer = function (content, onClose) {
    if (drawer) { drawer.$body.empty().append(content); drawer.onClose = onClose; drawer.$body.scrollTop(0); return drawer; }
    var lastFocus = document.activeElement;
    var $ov = $('<div class="drawer-ov"></div>');
    var $dr = $('<aside class="drawer" role="dialog" aria-modal="true" aria-label="Details"></aside>');
    var $close = $('<button type="button" class="icon-btn" aria-label="Close">' + DC.icon('x') + '</button>');
    var $head = $('<div class="drawer-head"></div>').append($close);
    var $body = $('<div class="drawer-body"></div>').append(content);
    $dr.append($head, $body);
    $('body').append($ov, $dr).css('overflow', 'hidden');
    requestAnimationFrame(function () { $ov.addClass('is-open'); $dr.addClass('is-open'); });
    DC.drawerOpen = true;
    drawer = {
      $el: $dr, $body: $body, onClose: onClose,
      close: function (silent) {
        if (!drawer) return;
        var cb = drawer.onClose;
        drawer = null;
        DC.drawerOpen = false;
        $ov.removeClass('is-open'); $dr.removeClass('is-open');
        setTimeout(function () { $ov.remove(); $dr.remove(); }, 230);
        if (!openDialogs.length) $('body').css('overflow', '');
        if (lastFocus && lastFocus.focus) { try { lastFocus.focus({ preventScroll: true }); } catch (e) { /* ignore */ } }
        if (cb && !silent) cb();
      }
    };
    $close.on('click', function () { drawer && drawer.close(); });
    $ov.on('click', function () { drawer && drawer.close(); });
    setTimeout(function () { $close.trigger('focus'); }, 60);
    return drawer;
  };
  DC.closeDrawer = function (silent) { if (drawer) drawer.close(silent); };

  $(document).on('keydown', function (e) {
    if (e.key !== 'Escape') return;
    if (openDialogs.length) openDialogs[openDialogs.length - 1].close();
    else if (drawer) drawer.close();
  });

  /** Show server validation errors next to the matching field. */
  DC.formErrors = function ($form, err) {
    $form.find('.field').removeClass('has-error').find('.field-error').remove();
    $form.find('.form-alert').remove();
    if (!err) return;
    var $field = err.field ? $form.find('[name="' + err.field + '"]').first().closest('.field') : $();
    if ($field.length) {
      $field.addClass('has-error').append($('<div class="field-error"></div>').text(err.message));
      var el = $field.find('input,select,textarea')[0];
      if (el) { el.focus({ preventScroll: true }); el.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
    } else {
      var $a = $('<div class="alert alert-error form-alert" role="alert"></div>').text(err.message);
      $form.prepend($a);
      if ($a[0].scrollIntoView) $a[0].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
  };

  /** Busy state for a button while a promise runs. */
  DC.busy = function ($btn, promise, label) {
    var html = $btn.html();
    $btn.addClass('is-busy').html('<span class="spinner"></span> ' + DC.esc(label || 'Working…'));
    return promise.finally(function () { $btn.removeClass('is-busy').html(html); });
  };

  DC.copy = function (text) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
    var $t = $('<textarea style="position:fixed;opacity:0"></textarea>').val(text).appendTo('body');
    $t[0].select();
    try { document.execCommand('copy'); } catch (e) { /* ignore */ }
    $t.remove();
    return Promise.resolve();
  };
})(jQuery, window);
