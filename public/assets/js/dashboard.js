/* Dwell Central — executive dashboard and the celebration detail dialog. */
(function ($, DC) {
  'use strict';

  var S = DC.S;
  var esc = DC.esc;

  function greeting() {
    var h = new Date().getHours();
    return h < 12 ? 'Good morning' : (h < 17 ? 'Good afternoon' : 'Good evening');
  }

  DC.modeBanner = function (mode) {
    if (!mode || mode === 'live' || !DC.can('notifications.view')) return '';
    if (mode === 'off') return '<div class="banner off">' + DC.icon('mail') + '<span><strong>Celebration emails are turned off.</strong> Nothing is being prepared or sent.</span>' +
      (DC.can('notifications.manage') ? '<a class="btn btn-sm btn-ghost" href="#/notifications">Email settings</a>' : '') + '</div>';
    return '<div class="banner test">' + DC.icon('alert') + '<span><strong>Test mode.</strong> Celebration emails are prepared and recorded, but nothing reaches supervisors until an administrator switches to live.</span>' +
      (DC.can('notifications.manage') ? '<a class="btn btn-sm btn-ghost" href="#/notifications">Review emails</a>' : '') + '</div>';
  };

  function eventRow(ev) {
    var p = ev.person;
    var today = ev.date === S.today;
    return '<div class="list-item clickable" data-key="' + esc(ev.key) + '">' +
      '<div class="li-date' + (today ? ' today' : '') + '"><div class="m">' + esc(DC.date.MONTHS[+ev.date.slice(5, 7) - 1].slice(0, 3)) + '</div><div class="d">' + (+ev.date.slice(8)) + '</div></div>' +
      DC.avatar(p, 'sm') +
      '<div class="li-body"><div class="li-main">' + esc(p.name) + '</div>' +
      '<div class="li-sub">' + esc(DC.date.relative(ev.date, S.today)) + (p.property ? ' · ' + esc(p.property) : '') + (ev.owner.id ? ' · ' + esc(ev.owner.name) : '') + '</div></div>' +
      '<div class="li-side">' + DC.typeBadge(ev.type, ev.years) + DC.notifChips(ev) + '</div></div>';
  }
  DC.eventRow = eventRow;

  DC.views.dashboard = function ($main) {
    var first = S.me.name.split(' ')[0];
    $main.append(DC.pageHead(greeting() + ', ' + first, esc(DC.date.long(S.today)) + ' · ' + esc(S.org),
      $('<a class="btn btn-sm btn-ghost" href="#/celebrations">' + DC.icon('cal') + 'Celebrations calendar</a>')));
    var $body = $('<div></div>').appendTo($main);
    DC.loading($body);

    function load() {
      DC.get('dashboard').then(function (d) { render(d); }).catch(function (e) { DC.forbiddenOr($body, e); });
    }

    function kpi(label, value, foot, cls, href, icon) {
      var tag = href ? 'a' : 'div';
      return '<' + tag + ' class="kpi ' + (cls || '') + '"' + (href ? ' href="' + href + '"' : '') + '><div class="k-label">' + (icon ? DC.icon(icon) : '') + esc(label) + '</div>' +
        '<div class="k-value">' + value + '</div>' + (foot ? '<div class="k-foot">' + foot + '</div>' : '') + '</' + tag + '>';
    }

    function render(d) {
      var k = d.kpis;
      if (d.health) DC.setMode(d.health.mode);
      var admin = DC.can('people.edit');
      var h = DC.modeBanner(d.health && d.health.mode);
      h += '<div class="kpis">' +
        kpi('Active team members', k.active, k.excluded ? k.excluded + ' not celebrated (contractors or on leave)' : 'Across every property', '', '#/people', 'users') +
        kpi('Birthdays in ' + k.month, k.birthdays_month, 'Confirmed dates only', 'bday', '#/celebrations', 'cake') +
        kpi('Work anniversaries in ' + k.month, k.anniversaries_month, 'Counted from the most recent hire date', 'anniv', '#/celebrations', 'award') +
        kpi('Next 7 days', k.next_7, k.next_30 + ' in the next 30 days', '', '#/celebrations', 'cal') +
        kpi('Birthdays to fix', k.birthday_missing + k.birthday_review, k.birthday_review + ' need review · ' + k.birthday_missing + ' missing', (k.birthday_missing + k.birthday_review) ? 'warn' : '', admin ? '#/admin/people/issue/birthday_review' : '', 'alert') +
        kpi('Missing hire dates', k.hire_missing, 'Needed for work anniversaries', k.hire_missing ? 'warn' : '', admin ? '#/admin/people/issue/hire_missing' : '', 'file') +
        kpi('No celebration owner', k.no_owner, k.no_owner_next_30 + ' with a celebration in the next 30 days', k.no_owner ? 'alert-kpi' : '', admin ? '#/admin/people/issue/no_owner' : '', 'user') +
        kpi('No supervisor recorded', k.no_supervisor, 'Includes the top of the organization', k.no_supervisor ? 'warn' : '', admin ? '#/admin/people/issue/no_supervisor' : '', 'tree') +
        '</div>';

      h += '<div class="grid-main"><div class="panel"><h2>Coming up in the next 30 days <a href="#/celebrations">Calendar</a></h2>';
      if (d.upcoming.length) {
        h += '<div class="list js-upcoming">' + d.upcoming.slice(0, 12).map(eventRow).join('') + '</div>';
        if (d.upcoming.length > 12) h += '<div style="padding-top:10px"><a class="btn btn-sm btn-ghost" href="#/celebrations">See all ' + d.upcoming.length + ' in the calendar</a></div>';
      } else {
        h += '<div class="empty"><strong>Nothing in the next 30 days</strong>Only confirmed birthdays and hire dates of active team members appear here.</div>';
      }
      h += '</div><div class="stack">';

      if (d.issues) {
        var order = ['no_owner', 'no_supervisor', 'birthday_review', 'birthday_missing', 'hire_missing', 'hire_review', 'no_email', 'no_property', 'unverified'];
        h += '<div class="panel"><h2>Data to tidy up <a href="#/admin/people">People records</a></h2><div class="issue-list">' + order.map(function (i) {
          var n = d.issues[i] || 0;
          return '<a class="issue-row' + (n ? '' : ' zero') + '" href="#/admin/people/issue/' + i + '"><span>' + esc(S.issueLabels[i]) + '</span><span class="n">' + n + '</span></a>';
        }).join('') + '</div></div>';
      }
      if (d.health) {
        var hl = d.health;
        var rows = [];
        rows.push(hl.cron_ok ? ['ok', 'Background tasks are running'] : ['bad', 'Background tasks haven’t run recently (check the cron job)']);
        rows.push(hl.mode === 'live' ? ['ok', 'Celebration emails are live'] : (hl.mode === 'test' ? ['warn', 'Test mode: emails go only to ' + (hl.test_recipient ? esc(hl.test_recipient.name) : 'the log (no test inbox chosen)')] : ['warn', 'Celebration emails are off']));
        rows.push(hl.mail_transport === 'smtp' ? ['ok', 'Mail server connected (SMTP)'] : ['warn', 'Mail goes to the server log (config: transport “' + esc(hl.mail_transport) + '”)']);
        if (hl.failed) rows.push(['bad', hl.failed + ' email' + (hl.failed === 1 ? '' : 's') + ' failed to send']);
        rows.push(['ok', 'Daily check at ' + esc(hl.send_time) + ' ' + esc(hl.timezone) + (hl.last_daily ? ' · last run ' + esc(DC.date.stamp(hl.last_daily.started_at, S.tz)) : '')]);
        h += '<div class="panel"><h2>Email status <a href="#/notifications">Notifications</a></h2><div class="health">' + rows.map(function (r) {
          return '<div class="health-row ' + r[0] + '"><span class="dot"></span><span>' + r[1] + '</span></div>';
        }).join('') + '</div>';
        if (d.recent && d.recent.length) {
          h += '<div class="list" style="margin-top:14px">' + d.recent.slice(0, 5).map(function (n) {
            return '<div class="list-item"><div class="li-body"><div class="li-main" style="font-weight:500;font-size:13.5px">' + esc(n.subject) + '</div><div class="li-sub">' +
              esc(n.intended_name || '') + ' · ' + esc(DC.date.stamp(n.created_at, S.tz)) + '</div></div><span class="badge ' + (n.status === 'sent' ? 'ok' : n.status === 'failed' ? 'danger' : 'outline') + '">' + esc(n.mode === 'test' ? 'test · ' : '') + esc(n.status) + '</span></div>';
          }).join('') + '</div>';
        }
        h += '</div>';
      }
      if (!d.issues && !d.health) {
        h += '<div class="panel"><h2>How reminders work</h2><p class="small" style="margin:0;color:var(--ink-2)">On the 1st of each month you get a summary of your team’s birthdays and work anniversaries, a reminder ' +
          'a few days before each one, and a note on the day. Only confirmed dates are included; birth years and ages are never shown.</p></div>';
      }
      h += '</div></div>';
      $body.html(h);
      $body.find('.js-upcoming').on('click', '.list-item', function () {
        var key = $(this).data('key');
        var ev = d.upcoming.filter(function (x) { return x.key === key; })[0];
        if (ev) DC.openEvent(ev, load);
      });
    }

    DC.onPeopleChanged(load);
    load();
  };

  // ------------------------------------------------------------------ celebration detail

  DC.openEvent = function (ev, onChange) {
    var p = ev.person;
    var kinds = { monthly: 'Monthly summary', org_report: 'Organization report', advance: 'Advance reminder', day_of: 'Day-of email', owner_missing: 'No-owner alert' };
    var h = '<div class="list-item" style="border:0;padding-top:0">' + DC.avatar(p) + '<div class="li-body"><div class="li-main">' + esc(p.name) + '</div><div class="li-sub">' +
      esc([p.job_title, p.property].filter(Boolean).join(' · ')) + '</div></div><button type="button" class="btn btn-sm btn-ghost js-profile">Profile</button></div>';
    h += '<dl class="dl"><dt>Date</dt><dd>' + esc(DC.date.long(ev.date, true)) + ' <span class="muted">(' + esc(DC.date.relative(ev.date, S.today)) + ')</span></dd>';
    h += '<dt>Celebration</dt><dd>' + DC.typeBadge(ev.type, ev.years) + (ev.type === 'anniversary' ? ' ' + DC.plural(ev.years, 'year') + ' with the team' : '') + '</dd>';
    h += '<dt>Celebrated by</dt><dd>' + (ev.owner.id ? esc(ev.owner.name) + '<span class="sub">' + esc({ assigned: 'Assigned owner', supervisor: 'Direct supervisor', property_manager: 'Property manager', escalated: 'Next manager up' }[ev.owner.source] || '') + (ev.owner.note ? ' · ' + esc(ev.owner.note) : '') + '</span>'
      : '<span class="badge danger">' + DC.icon('alert') + 'No owner</span><span class="sub">' + esc(ev.owner.note || '') + '. An administrator is alerted before the date.</span>') + '</dd>';
    h += '<dt>Emails</dt><dd>' + ((ev.notifications || []).length ? ev.notifications.map(function (n) {
      return '<div>' + esc(kinds[n.kind] || n.kind) + (n.mode === 'test' ? ' <span class="badge warn">test</span>' : '') + ' · ' + esc(n.status) + (n.to ? ' <span class="muted">to ' + esc(n.to) + '</span>' : '') + '</div>';
    }).join('') : '<span class="muted">None yet. Reminders go out ' + 'automatically before the date.</span>') + '</dd></dl>';

    var $body = $('<div></div>').html(h);
    var foot = null;
    if (DC.can('celebrations.status')) {
      var cur = ev.follow_up ? ev.follow_up.status : '';
      var $track = $('<div><div class="section-title" style="margin-top:4px">Follow-through</div>' +
        '<p class="small muted" style="margin:0 0 10px">An email being delivered doesn’t mean anyone celebrated. Mark it here once it’s planned or done.</p>' +
        '<div class="seg js-status" role="group" aria-label="Status"><button type="button" data-s="">Not tracked</button><button type="button" data-s="planned">Planned</button><button type="button" data-s="completed">Celebrated</button><button type="button" data-s="skipped">Skipped</button></div>' +
        '<div class="field" style="margin-top:12px"><label>Note <span class="opt">(optional)</span></label><input class="input" name="note" maxlength="255" value="' + esc(ev.follow_up && ev.follow_up.note || '') + '" placeholder="e.g. Cake at Friday’s huddle"></div></div>');
      $track.find('[data-s="' + cur + '"]').addClass('is-on');
      $track.on('click', '[data-s]', function () { $track.find('[data-s]').removeClass('is-on'); $(this).addClass('is-on'); });
      $body.append($track);
      var $save = $('<button type="button" class="btn">Save</button>');
      foot = [$save];
      $save.on('click', function () {
        var status = $track.find('.is-on').data('s') || '';
        DC.busy($save, DC.post('celebrations.status', { employee_id: p.id, type: ev.type, date: ev.date, status: status, note: $track.find('[name=note]').val() }), 'Saving…')
          .then(function () { d.close(); DC.toast('Saved.'); if (onChange) onChange(); }).catch(DC.fail);
      });
    }
    var d = DC.dialog({ title: ev.title, eyebrow: DC.date.long(ev.date), body: $body, foot: foot });
    $body.find('.js-profile').on('click', function () { d.close(); DC.openPerson(p.id); });
  };
})(jQuery, window.DC);
