/* Dwell Central — administration: notifications, organization, users, settings, audit log. */
(function ($, DC) {
  'use strict';

  var S = DC.S;
  var esc = DC.esc;

  function emailFrame(html) {
    var $f = $('<iframe class="email-frame" sandbox title="Email preview"></iframe>');
    $f.attr('srcdoc', html);
    return $f;
  }

  // ================================================================== notifications

  var KIND_LABELS = { monthly: 'Monthly summary', org_report: 'Organization report', advance: 'Advance reminder', day_of: 'Day-of', owner_missing: 'No-owner alert', failure_alert: 'Failure alert', account: 'Account', test: 'Test' };
  var STATUS_BADGE = { sent: 'ok', pending: 'info', failed: 'danger', held: 'outline', cancelled: 'outline' };
  var STATUS_LABEL = { sent: 'Sent', pending: 'Queued', failed: 'Failed', held: 'Recorded only', cancelled: 'Cancelled' };

  DC.views.notifications = function ($main) {
    var manage = DC.can('notifications.manage');
    $main.append(DC.pageHead('Notifications', 'Celebration emails, reminders and alerts: what was prepared, where it went, and whether it was delivered.'));
    var $mode = $('<div></div>').appendTo($main);
    var $hist = $('<div class="panel tight" style="margin-top:18px"></div>').appendTo($main);
    var f = { kind: '', status: '', mode: '', q: '', page: 0 };

    function loadHealth() {
      DC.get('health').then(function (r) { renderMode(r.health); }).catch(DC.fail);
    }

    function renderMode(h) {
      DC.setMode(h.mode);
      var cards = [
        ['off', 'Off', 'Nothing is prepared or sent.'],
        ['test', 'Test', 'Emails are built exactly as live, but only go to ' + (h.test_recipient ? esc(h.test_recipient.name) : 'the log (choose a test inbox in Settings)') + '.'],
        ['live', 'Live', 'Supervisors and owners receive their emails.']
      ];
      var changed = h.mode_changed ? ' Last changed by ' + esc(h.mode_changed.by) + ' on ' + esc(DC.date.stamp(h.mode_changed.at, S.tz)) + '.' : '';
      var html = DC.modeBanner(h.mode) + '<div class="panel"><h2>Celebration emails ' + DC.modePill(h.mode) + '</h2>' +
        '<p class="panel-sub">Checked daily at ' + esc(h.send_time) + ' (' + esc(h.timezone) + '): a summary on the 1st of each month, a reminder a few days before, and a note on the day.' + changed + '</p>' +
        '<div class="mode-cards">' + cards.map(function (c) {
          return '<button type="button" class="mode-card ' + c[0] + (h.mode === c[0] ? ' is-on' : '') + '" data-mode="' + c[0] + '"' + (manage ? '' : ' disabled') + '><strong>' + c[1] + (h.mode === c[0] ? ' ' + DC.icon('check') : '') + '</strong><span>' + c[2] + '</span></button>';
        }).join('') + '</div>';
      if (manage) {
        html += '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:16px;align-items:center">' +
          '<button type="button" class="btn btn-sm js-run">' + DC.icon('play') + 'Run today’s check now</button>' +
          '<span style="display:inline-flex;gap:6px;align-items:center"><input class="input" type="date" name="pdate" value="' + esc(S.today) + '" style="min-height:34px;padding:4px 8px;width:auto;font-size:14px" aria-label="Preview date">' +
          '<button type="button" class="btn btn-sm btn-ghost js-preview">' + DC.icon('eye') + 'Preview a day</button></span>' +
          '<button type="button" class="btn btn-sm btn-ghost js-test">' + DC.icon('mail') + 'Send me a test email</button>' +
          '<a class="btn btn-sm btn-quiet" href="#/admin/settings">' + DC.icon('gear') + 'Email settings</a></div>';
      }
      html += '<div class="health" style="margin-top:16px">' +
        '<div class="health-row ' + (h.cron_ok ? 'ok' : 'bad') + '"><span class="dot"></span><span>' + (h.cron_ok ? 'Background tasks are running' : 'Background tasks haven’t run in the last 20 minutes. Check the cron job (see the deployment guide).') + '</span></div>' +
        '<div class="health-row ' + (h.mail_transport === 'smtp' ? 'ok' : 'warn') + '"><span class="dot"></span><span>' + (h.mail_transport === 'smtp' ? 'Mail server: SMTP' : 'Mail is written to the server log, not sent (config/config.php → mail.transport is “' + esc(h.mail_transport) + '”)') + '</span></div>' +
        (h.last_daily ? '<div class="health-row ok"><span class="dot"></span><span>Last daily check: ' + esc(DC.date.stamp(h.last_daily.started_at, S.tz)) + ' (' + esc(h.last_daily.mode) + ' mode)</span></div>' : '') +
        (h.failed ? '<div class="health-row bad"><span class="dot"></span><span>' + h.failed + ' failed. Fix the mail settings, then use Retry below.</span></div>' : '') +
        '</div></div>';
      $mode.html(html);
    }

    $mode.on('click', '[data-mode]', function () {
      var m = $(this).data('mode');
      if ($(this).hasClass('is-on')) return;
      if (m === 'live') return goLive();
      DC.confirm({ title: m === 'off' ? 'Turn celebration emails off?' : 'Switch to test mode?', message: m === 'off' ? 'Nothing will be prepared or sent until you turn them back on.' : 'Emails will be built but only go to the test inbox.', ok: 'Switch' })
        .then(function (ok) { if (ok) DC.post('notifications.mode', { mode: m }).then(function (r) { renderMode(r.health); DC.toast('Switched to ' + m + '.'); loadHist(); }).catch(DC.fail); });
    });

    function goLive() {
      DC.get('dashboard').then(function (d) {
        var k = d.kpis, h = d.health;
        var checks = [
          [h.mail_transport === 'smtp', 'Mail server is configured (SMTP)', 'config/config.php still says “' + h.mail_transport + '”: nothing would be delivered.'],
          [h.cron_ok, 'Background tasks are running', 'The cron job isn’t running: emails wouldn’t go out.'],
          [k.no_owner === 0, 'Everyone has a celebration owner', k.no_owner + ' active people have nobody to remind. Administrators will get alerts for them.'],
          [k.birthday_review + k.birthday_missing === 0, 'All birthdays are confirmed', (k.birthday_review + k.birthday_missing) + ' birthdays are missing or need review; those people won’t be celebrated.'],
          [k.hire_missing === 0, 'All hire dates are present', k.hire_missing + ' people have no hire date.']
        ];
        var $b = $('<div><p style="margin:0 0 14px;color:var(--ink-2)">In live mode, supervisors and celebration owners start receiving emails at the next daily check. Please review:</p>' +
          '<div class="health" style="margin-bottom:16px">' + checks.map(function (c) { return '<div class="health-row ' + (c[0] ? 'ok' : 'warn') + '"><span class="dot"></span><span>' + esc(c[0] ? c[1] : c[2]) + '</span></div>'; }).join('') + '</div>' +
          '<p class="small muted">Tip: use “Preview a day” to read exactly what would be sent, e.g. on the 1st of next month.</p>' +
          '<form novalidate><div class="field"><label>Type GO LIVE to confirm</label><input class="input" name="confirm" autocomplete="off"></div></form></div>');
        var $ok = $('<button type="button" class="btn btn-accent">Switch to live</button>');
        var dlg = DC.dialog({ title: 'Send emails for real?', body: $b, foot: [$('<button type="button" class="btn btn-ghost">Cancel</button>').on('click', function () { dlg.close(); }), $ok] });
        $ok.on('click', function () {
          var $f = $b.find('form');
          DC.formErrors($f, null);
          DC.busy($ok, DC.post('notifications.mode', { mode: 'live', confirm: $f.find('[name=confirm]').val() }), 'Switching…').then(function (r) {
            dlg.close(); renderMode(r.health); DC.toast('Celebration emails are live.'); loadHist();
          }).catch(function (err) { DC.formErrors($f, err); });
        });
      }).catch(DC.fail);
    }

    $mode.on('click', '.js-run', function () {
      DC.busy($(this), DC.post('notifications.run', {}), 'Running…').then(function (r) {
        var res = r.result;
        if (res.skipped) { DC.toast(res.skipped); return; }
        var made = Object.keys(res.created || {}).map(function (k) { return res.created[k] + ' ' + (KIND_LABELS[k] || k).toLowerCase(); });
        DC.toast(made.length ? 'Prepared ' + made.join(', ') + '.' : 'Nothing new to send today.');
        loadHealth(); loadHist();
      }).catch(DC.fail);
    });
    $mode.on('click', '.js-test', function () {
      DC.busy($(this), DC.post('notifications.test', {}), 'Sending…').then(function (r) {
        if (r.ok) DC.toast(r.transport === 'smtp' ? 'Sent to ' + S.me.email + '. Check your inbox.' : 'Written to storage/logs/mail.log (mail transport is “' + r.transport + '”).');
        else DC.toast('Couldn’t send: ' + (r.error || r.status), 'error');
        loadHist();
      }).catch(DC.fail);
    });
    $mode.on('click', '.js-preview', function () {
      var date = $mode.find('[name=pdate]').val();
      var d = DC.dialog({ title: 'What would be sent', eyebrow: date ? DC.date.long(date, true) : '', xwide: true, body: '<div class="empty"><span class="spinner"></span></div>' });
      DC.get('notifications.preview', { date: date }).then(function (r) {
        if (!r.messages.length) { d.$body.html('<div class="empty"><strong>Nothing would be sent on this day.</strong>Try the 1st of a month to see the monthly summaries.</div>'); return; }
        var $list = $('<div class="list"></div>');
        r.messages.forEach(function (m, i) {
          $list.append('<div class="list-item clickable" data-i="' + i + '"><div class="li-body"><div class="li-main">' + esc(m.subject) + '</div><div class="li-sub">' + esc(m.label) + ' · to ' + esc(m.to) + '</div></div>' +
            (m.existing ? '<span class="badge outline">Already prepared</span>' : '') + '</div>');
        });
        var $view = $('<div style="margin-top:12px"></div>');
        d.$body.html('').append('<p class="small muted" style="margin:0 0 8px">' + DC.plural(r.messages.length, 'message') + '. Nothing has been queued; this is a preview with real recipients (' + esc(r.mode) + ' mode would ' + (r.mode === 'live' ? 'send them' : 'only record them or send to the test inbox') + ').</p>', $list, $view);
        $list.on('click', '.list-item', function () {
          var m = r.messages[+$(this).data('i')];
          $list.find('.list-item').css('background', ''); $(this).css('background', 'var(--surface-2)');
          $view.empty().append(emailFrame(m.html));
        });
        $list.find('.list-item').first().trigger('click');
      }).catch(function (err) { d.$body.html('<div class="alert alert-error">' + esc(err.message) + '</div>'); });
    });

    // ---- history
    function loadHist() {
      DC.get('notifications.list', f).then(renderHist).catch(DC.fail);
    }
    function renderHist(r) {
      var opts = function (map, val, empty) { return '<option value="">' + empty + '</option>' + Object.keys(map).map(function (k) { return '<option value="' + k + '"' + (val === k ? ' selected' : '') + '>' + esc(map[k]) + '</option>'; }).join(''); };
      var h = '<div style="padding:18px 20px 4px"><h2 style="font-size:15.5px;font-weight:650;margin:0 0 12px">History</h2><div class="filters">' +
        '<input class="input search" type="search" name="q" value="' + esc(f.q) + '" placeholder="Search subject or recipient">' +
        '<select class="select" name="kind">' + opts(KIND_LABELS, f.kind, 'All types') + '</select>' +
        '<select class="select" name="status">' + opts(STATUS_LABEL, f.status, 'Any status') + '</select>' +
        '<select class="select" name="mode">' + opts({ test: 'Test', live: 'Live', system: 'System' }, f.mode, 'Test & live') + '</select></div></div>';
      if (!r.notifications.length) {
        h += '<div class="empty"><strong>No messages yet</strong>They appear here once the daily check prepares them.</div>';
      } else {
        h += '<div style="overflow-x:auto"><table class="table"><thead><tr><th>Prepared</th><th>Type</th><th>For</th><th>Subject</th><th>Status</th><th></th></tr></thead><tbody>' +
          r.notifications.map(function (n) {
            return '<tr class="clickable" data-id="' + n.id + '"><td class="nowrap">' + esc(DC.date.stamp(n.created_at, S.tz)) + '</td><td class="nowrap">' + esc(KIND_LABELS[n.kind] || n.kind) +
              (n.mode === 'test' ? ' <span class="badge warn">test</span>' : '') + '</td><td>' + esc(n.intended_name || '') + '<div class="sub">' + esc(n.intended_email || '') + '</div>' +
              (n.mode === 'test' ? '<div class="sub">' + (n.to_email ? 'Delivered to test inbox ' + esc(n.to_email) : 'Not delivered (recorded only)') + '</div>' : '') + '</td>' +
              '<td>' + esc(n.subject) + (n.last_error ? '<div class="sub" style="color:var(--danger)">' + esc(n.last_error) + '</div>' : '') + '</td>' +
              '<td class="nowrap"><span class="badge ' + (STATUS_BADGE[n.status] || '') + '">' + esc(STATUS_LABEL[n.status] || n.status) + '</span>' + (n.sent_at ? '<div class="sub">' + esc(DC.date.stamp(n.sent_at, S.tz)) + '</div>' : '') + '</td>' +
              '<td class="nowrap">' + (manage && n.status === 'failed' ? '<button type="button" class="btn btn-xs btn-ghost js-retry">Retry</button> ' : '') +
              (manage && ['pending', 'failed', 'held'].indexOf(n.status) !== -1 ? '<button type="button" class="btn btn-xs btn-quiet js-cancel">Cancel</button>' : '') + '</td></tr>';
          }).join('') + '</tbody></table></div>';
        h += '<div class="pager" style="padding:12px 20px"><span>Page ' + (f.page + 1) + '</span><span>' +
          (f.page > 0 ? '<button type="button" class="btn btn-xs btn-ghost js-page" data-d="-1">Newer</button> ' : '') +
          (r.more ? '<button type="button" class="btn btn-xs btn-ghost js-page" data-d="1">Older</button>' : '') + '</span></div>';
      }
      $hist.html(h);
    }
    $hist.on('change', 'select', function () { f[this.name] = this.value; f.page = 0; loadHist(); });
    $hist.on('input', '[name=q]', DC.debounce(function () { f.q = this.value; f.page = 0; loadHist(); }, 300));
    $hist.on('click', '.js-page', function () { f.page += +$(this).data('d'); loadHist(); });
    $hist.on('click', '.js-retry', function (e) {
      e.stopPropagation();
      DC.post('notifications.retry', { id: $(this).closest('tr').data('id') }).then(function (r) { DC.toast(r.status === 'sent' ? 'Sent.' : 'Still failing; see the error.'); loadHist(); loadHealth(); }).catch(DC.fail);
    });
    $hist.on('click', '.js-cancel', function (e) {
      e.stopPropagation();
      DC.post('notifications.cancel', { id: $(this).closest('tr').data('id') }).then(function () { DC.toast('Cancelled.'); loadHist(); }).catch(DC.fail);
    });
    $hist.on('click', 'tr.clickable', function () {
      DC.get('notifications.get', { id: $(this).data('id') }).then(function (r) {
        var n = r.notification;
        var $b = $('<div></div>').append('<dl class="dl"><dt>For</dt><dd>' + esc(n.intended_name || '') + ' &lt;' + esc(n.intended_email || '') + '&gt;</dd>' +
          '<dt>Delivered to</dt><dd>' + (n.to_email ? esc(n.to_email) : '<span class="muted">Not delivered (test mode, recorded only)</span>') + '</dd>' +
          '<dt>Status</dt><dd>' + esc(STATUS_LABEL[n.status] || n.status) + (n.attempts ? ' · ' + DC.plural(+n.attempts, 'attempt') : '') + (n.last_error ? '<span class="sub" style="color:var(--danger)">' + esc(n.last_error) + '</span>' : '') + '</dd></dl>', emailFrame(n.body_html));
        DC.dialog({ title: n.subject, eyebrow: (KIND_LABELS[n.kind] || n.kind) + ' · ' + n.mode, xwide: true, body: $b });
      }).catch(DC.fail);
    });

    loadHealth();
    loadHist();
  };

  // ================================================================== organization

  var ORG = {
    brands: { label: 'Companies', one: 'company', help: 'The brands people belong to (Dwell Communities, 58 Place). Their color is used across the org chart.' },
    entities: { label: 'Legal entities', one: 'legal entity', help: 'Employers of record. Import spellings are listed as “Also known as”, so future imports match automatically.' },
    properties: { label: 'Properties & offices', one: 'property or office', help: 'Where people work. The property manager is the fallback celebration owner when a supervisor isn’t available.' },
    departments: { label: 'Departments', one: 'department', help: 'Functional teams (Maintenance, Leasing, Accounting…).' }
  };

  DC.views['admin/organization'] = function ($main, sub) {
    var tab = ORG[sub[0]] ? sub[0] : 'brands';
    var lists = S.lists;
    var people = [];
    $main.append(DC.pageHead('Organization', 'Shared records every module links to: companies, legal entities, properties and departments.'));
    var $tabs = $('<div class="tabs">' + Object.keys(ORG).map(function (k) { return '<button type="button" data-tab="' + k + '">' + ORG[k].label + '</button>'; }).join('') + '</div>');
    var $body = $('<div></div>');
    $main.append($tabs, $body);

    function render() {
      $tabs.find('button').removeClass('is-on').filter('[data-tab=' + tab + ']').addClass('is-on');
      history.replaceState(null, '', '#/admin/organization/' + tab);
      var rows = lists[tab] || [];
      var h = '<div class="filters"><span class="muted small" style="flex:1">' + esc(ORG[tab].help) + '</span><button type="button" class="btn btn-sm js-add">' + DC.icon('plus') + 'Add ' + ORG[tab].one + '</button></div>';
      if (!rows.length) {
        h += '<div class="panel"><div class="empty"><strong>None yet</strong>' + (tab === 'departments' ? 'Add departments here, or include a Department column in your import.' : 'These are created when you import the employee spreadsheet, or add them here.') + '</div></div>';
      } else {
        h += '<div class="table-wrap"><table class="table"><thead><tr><th>Name</th><th class="hide-sm">Details</th><th class="num">People</th><th></th></tr></thead><tbody>' + rows.map(function (r) {
          var det = [];
          if (tab === 'entities') { det.push({ employer: 'Employer', contractor: 'Contractors (1099)', other: 'Other' }[r.kind]); var b = DC.byId(lists.brands, r.brand_id); if (b) det.push(b.name); }
          if (tab === 'properties') { det.push({ property: 'Property', office: 'Office', team: 'Team', other: 'Other' }[r.kind]); var b2 = DC.byId(lists.brands, r.brand_id); if (b2) det.push(b2.name); if (r.manager_id) { var m = people.filter(function (p) { return p.id === r.manager_id; })[0]; det.push('Manager: ' + (m ? m.name : '#' + r.manager_id)); } }
          if (r.aliases) det.push('Also known as: ' + r.aliases.split('\n').join(', '));
          return '<tr class="clickable' + (r.is_active ? '' : ' is-off') + '" data-id="' + r.id + '"><td>' + (tab === 'brands' ? '<span class="color-dot" style="--c:' + esc(r.color) + '"></span>' : '') + '<strong>' + esc(r.name) + '</strong>' +
            (r.short_name ? ' <span class="muted">(' + esc(r.short_name) + ')</span>' : '') + (r.is_active ? '' : ' <span class="badge outline">Inactive</span>') + (r.legal_name ? '<div class="sub">' + esc(r.legal_name) + '</div>' : '') + '</td>' +
            '<td class="hide-sm sub">' + esc(det.join(' · ')) + '</td><td class="num">' + r.people + '</td><td><button type="button" class="icon-btn" aria-label="Edit">' + DC.icon('edit') + '</button></td></tr>';
        }).join('') + '</tbody></table></div>';
      }
      $body.html(h);
    }

    function edit(rec) {
      rec = rec || { is_active: true, color: '#2B4F86', kind: tab === 'entities' ? 'employer' : 'property', sort: 0 };
      var personOpts = '<option value="">None</option>' + people.map(function (p) { return '<option value="' + p.id + '"' + (rec.manager_id === p.id ? ' selected' : '') + '>' + esc(p.name + (p.job_title ? ' — ' + p.job_title : '')) + '</option>'; }).join('');
      var h = '<form novalidate><div class="field"><label>Name</label><input class="input" name="name" value="' + esc(rec.name || '') + '" maxlength="160"></div>';
      if (tab === 'brands') h += '<div class="field"><label>Color</label><div style="display:flex;gap:8px;align-items:center"><input type="color" name="colorpick" value="' + esc(rec.color) + '" style="width:48px;height:40px;border:1px solid var(--line);border-radius:8px;padding:2px;background:#fff"><input class="input" name="color" value="' + esc(rec.color) + '" maxlength="7" style="max-width:130px"></div></div>';
      if (tab === 'entities') {
        h += '<div class="field"><label>Full legal name <span class="opt">(optional)</span></label><input class="input" name="legal_name" value="' + esc(rec.legal_name || '') + '"></div><div class="row">' +
          '<div class="field"><label>Type</label><select class="select" name="kind"><option value="employer">Employer</option><option value="contractor">Contractors (1099 / vendors)</option><option value="other">Other</option></select></div>' +
          '<div class="field"><label>Company</label><select class="select" name="brand_id">' + DC.options(lists.brands, rec.brand_id, 'None') + '</select></div></div>';
      }
      if (tab === 'properties') {
        h += '<div class="row"><div class="field"><label>Short name <span class="opt">(optional)</span></label><input class="input" name="short_name" value="' + esc(rec.short_name || '') + '" maxlength="60"></div>' +
          '<div class="field"><label>Type</label><select class="select" name="kind"><option value="property">Property</option><option value="office">Office</option><option value="team">Team (e.g. landscape crew)</option><option value="other">Other</option></select></div></div>' +
          '<div class="row"><div class="field"><label>Company</label><select class="select" name="brand_id">' + DC.options(lists.brands, rec.brand_id, 'None') + '</select></div>' +
          '<div class="field"><label>Property manager</label><select class="select" name="manager_id">' + personOpts + '</select></div></div>' +
          '<div class="field"><label>Address <span class="opt">(optional)</span></label><input class="input" name="address" value="' + esc(rec.address || '') + '" maxlength="255"></div>';
      }
      if (tab !== 'brands') h += '<div class="field"><label>Also known as <span class="opt">(one per line)</span></label><textarea class="textarea" name="aliases" rows="3">' + esc(rec.aliases || '') + '</textarea><div class="field-hint">Spellings used in HR spreadsheets, so imports match this record.</div></div>';
      h += '<div class="row"><div class="field"><label>Sort order</label><input class="input" name="sort" inputmode="numeric" value="' + esc(rec.sort || 0) + '"></div><div class="field"><label>&nbsp;</label><label class="check"><input type="checkbox" name="is_active"' + (rec.is_active ? ' checked' : '') + '> Active</label></div></div></form>';
      var $f = $(h);
      $f.find('[name=kind]').val(rec.kind);
      $f.on('input', '[name=colorpick]', function () { $f.find('[name=color]').val(this.value.toUpperCase()); });
      var $save = $('<button type="button" class="btn">Save</button>');
      var foot = [];
      if (rec.id && !rec.people) foot.push($('<button type="button" class="btn btn-danger-ghost">Delete</button>').on('click', function () {
        DC.confirm({ title: 'Delete ' + rec.name + '?', message: 'This can’t be undone.', ok: 'Delete', danger: true }).then(function (ok) {
          if (ok) DC.post('org.delete', { table: tab, id: rec.id }).then(function (r) { lists = S.lists = r.lists; d.close(); render(); DC.toast('Deleted.'); }).catch(DC.fail);
        });
      }), $('<span class="spacer"></span>'));
      foot.push($('<button type="button" class="btn btn-ghost">Cancel</button>').on('click', function () { d.close(); }), $save);
      var d = DC.dialog({ title: rec.id ? 'Edit ' + ORG[tab].one : 'Add ' + ORG[tab].one, body: $f, foot: foot });
      $save.on('click', function () {
        var data = { id: rec.id };
        $f.find('input[name],select[name],textarea[name]').each(function () {
          if (this.name === 'colorpick') return;
          data[this.name] = this.type === 'checkbox' ? this.checked : $(this).val();
        });
        DC.formErrors($f, null);
        DC.busy($save, DC.post('org.save', { table: tab, record: data }), 'Saving…').then(function (r) {
          lists = S.lists = r.lists; d.close(); render(); DC.toast('Saved.');
        }).catch(function (err) { DC.formErrors($f, err); });
      });
    }

    $tabs.on('click', 'button', function () { tab = $(this).data('tab'); render(); });
    $body.on('click', '.js-add', function () { edit(null); });
    $body.on('click', 'tr.clickable', function () { edit(DC.byId(lists[tab], $(this).data('id'))); });
    Promise.all([DC.get('org.lists'), DC.get('people.options')]).then(function (r) { lists = S.lists = r[0]; people = r[1].people; render(); }).catch(DC.fail);
    DC.loading($body);
  };

  // ================================================================== users & access

  var ROLE_HELP = {
    system_admin: 'Everything: people records, imports, settings, email mode, users and the audit log.',
    executive: 'Sees the whole organization (read-only) and can receive the organization-wide monthly report.',
    manager: 'Sees only the people they’re responsible for: their reporting line, properties they manage or are given below, and people they’re the celebration owner for.',
    employee: 'Future: the directory and org chart with basic details only (no dates).'
  };

  DC.views['admin/users'] = function ($main) {
    var users = [];
    var people = [];
    var $add = $('<button class="btn btn-sm">' + DC.icon('plus') + 'Add user</button>').on('click', function () { edit(null); });
    $main.append(DC.pageHead('Users & access', 'Who can sign in, and what they can see. Access is checked on the server for every request.', $add));
    var $body = $('<div class="table-wrap"></div>').appendTo($main);
    $main.append('<p class="small muted" style="margin-top:14px">Knowing someone’s email doesn’t give them access: an administrator must add them here. ' +
      'With Google sign-in enabled, people sign in with their Workspace account; otherwise they set a password from an invitation link.</p>');

    function load() {
      DC.loading($body);
      Promise.all([DC.get('users.list'), DC.get('people.options')]).then(function (r) { users = r[0].users; people = r[1].people; render(); }).catch(function (e) { DC.forbiddenOr($body, e); });
    }
    function render() {
      $body.html('<table class="table"><thead><tr><th>Name</th><th>Role</th><th class="hide-sm">Linked person</th><th class="hide-sm">Last sign-in</th><th></th></tr></thead><tbody>' + users.map(function (u) {
        var scopes = u.scopes.filter(function (s) { return s.scope === 'property'; }).map(function (s) { var p = DC.byId(S.lists.properties, s.id); return p ? p.name : '#' + s.id; });
        return '<tr class="clickable' + (u.is_active ? '' : ' is-off') + '" data-id="' + u.id + '"><td><strong>' + esc(u.name) + '</strong><div class="sub">' + esc(u.email) + '</div></td>' +
          '<td>' + esc(u.role_label) + (scopes.length ? '<div class="sub">+ ' + esc(scopes.join(', ')) + '</div>' : '') +
          (u.org_report ? '<div class="sub">Gets organization report</div>' : '') + (u.alerts ? '<div class="sub">Gets admin alerts</div>' : '') + '</td>' +
          '<td class="hide-sm">' + (u.employee ? esc(u.employee.name) : '<span class="muted">—</span>') + '</td>' +
          '<td class="hide-sm nowrap">' + (u.last_login_at ? esc(DC.date.stamp(u.last_login_at, S.tz)) : (u.has_password || u.google_linked ? 'Never' : '<span class="badge warn">Invited</span>')) + '</td>' +
          '<td>' + (u.is_active ? '' : '<span class="badge outline">Disabled</span>') + '</td></tr>';
      }).join('') + '</tbody></table>');
    }

    function edit(u) {
      u = u || { role: 'manager', is_active: true, scopes: [] };
      var scopeIds = u.scopes.filter(function (s) { return s.scope === 'property'; }).map(function (s) { return s.id; });
      var $f = $('<form novalidate>' +
        '<div class="row"><div class="field"><label>Name</label><input class="input" name="name" value="' + esc(u.name || '') + '"></div>' +
        '<div class="field"><label>Email</label><input class="input" type="email" name="email" value="' + esc(u.email || '') + '"></div></div>' +
        '<div class="field"><label>Role</label><select class="select" name="role">' + Object.keys(S.roles).map(function (k) { return '<option value="' + k + '"' + (u.role === k ? ' selected' : '') + '>' + esc(S.roles[k]) + '</option>'; }).join('') + '</select><div class="field-hint js-role-help"></div></div>' +
        '<div class="field"><label>Their own record in Dwell Central</label><select class="select" name="employee_id"><option value="">Not linked</option>' + people.map(function (p) { return '<option value="' + p.id + '"' + (u.employee && u.employee.id === p.id ? ' selected' : '') + '>' + esc(p.name + (p.job_title ? ' — ' + p.job_title : '')) + '</option>'; }).join('') + '</select>' +
        '<div class="field-hint">Managers see everyone below this record in the reporting chain.</div></div>' +
        '<div class="field js-scopes"><span class="field-label">Also sees everyone at</span><div class="check-grid">' + S.lists.properties.map(function (p) {
          return '<label class="check"><input type="checkbox" name="scope" value="' + p.id + '"' + (scopeIds.indexOf(p.id) !== -1 ? ' checked' : '') + '> ' + esc(p.name) + '</label>';
        }).join('') + '</div></div>' +
        '<div class="checks"><label class="check js-orgreport"><input type="checkbox" name="org_report"' + (u.org_report ? ' checked' : '') + '> Send them the organization-wide monthly report</label>' +
        '<label class="check js-alerts"><input type="checkbox" name="alerts"' + (u.alerts ? ' checked' : '') + '> Send them “no celebration owner” and delivery-failure alerts</label>' +
        '<label class="check"><input type="checkbox" name="is_active"' + (u.is_active ? ' checked' : '') + '> Can sign in</label></div>' +
        (u.id ? '' : '<label class="check"><input type="checkbox" name="send_invite" checked> Email them an invitation to choose a password</label>') + '</form>');
      function roleUi() {
        var r = $f.find('[name=role]').val();
        $f.find('.js-role-help').text(ROLE_HELP[r]);
        $f.find('.js-scopes').toggle(r === 'manager');
        $f.find('.js-orgreport').toggle(r === 'system_admin' || r === 'executive');
        $f.find('.js-alerts').toggle(r === 'system_admin');
      }
      $f.on('change', '[name=role]', roleUi);
      roleUi();
      var $save = $('<button type="button" class="btn">' + (u.id ? 'Save' : 'Add user') + '</button>');
      var foot = [];
      if (u.id) {
        foot.push($('<button type="button" class="btn btn-ghost btn-sm">' + DC.icon('link') + (u.has_password ? 'Password reset link' : 'Invitation link') + '</button>').on('click', function () { linkDialog(u); }), $('<span class="spacer"></span>'));
      }
      foot.push($('<button type="button" class="btn btn-ghost">Cancel</button>').on('click', function () { d.close(); }), $save);
      var d = DC.dialog({ title: u.id ? u.name : 'Add a user', eyebrow: u.id ? u.email : 'New account', wide: true, body: $f, foot: foot });
      $save.on('click', function () {
        var role = $f.find('[name=role]').val();
        var data = {
          id: u.id, name: $f.find('[name=name]').val(), email: $f.find('[name=email]').val(), role: role, employee_id: $f.find('[name=employee_id]').val(),
          org_report: $f.find('[name=org_report]').is(':checked'), alerts: $f.find('[name=alerts]').is(':checked'), is_active: $f.find('[name=is_active]').is(':checked'),
          send_invite: $f.find('[name=send_invite]').is(':checked'),
          scopes: role === 'manager' ? $f.find('[name=scope]:checked').map(function () { return { scope: 'property', id: +this.value }; }).get() : []
        };
        DC.formErrors($f, null);
        DC.busy($save, DC.post('users.save', data), 'Saving…').then(function (r) {
          users = r.users; render(); d.close();
          if (r.link) showLink(r.link, data.email, true); else DC.toast('Saved.');
        }).catch(function (err) { DC.formErrors($f, err); });
      });
    }

    function linkDialog(u) {
      DC.post('users.link', { id: u.id, email: false }).then(function (r) { showLink(r, u.email, false, u); }).catch(DC.fail);
    }
    function showLink(link, email, emailed, u) {
      var $b = $('<div><p style="margin:0 0 12px;color:var(--ink-2)">' + (emailed ? 'An email with this link was sent to ' + esc(email) + ' (or written to the mail log in test setups). ' : '') +
        'You can also copy it and send it yourself. It works ' + (link.purpose === 'invite' ? 'for 7 days' : 'for 24 hours') + ', once.</p>' +
        '<div class="copy-row"><input class="input" readonly value="' + esc(link.url) + '"><button type="button" class="btn btn-sm js-copy">Copy</button></div></div>');
      $b.on('click', '.js-copy', function () { DC.copy(link.url).then(function () { DC.toast('Copied.'); }); });
      var foot = [];
      if (u && !emailed) foot.push($('<button type="button" class="btn btn-ghost">Email it to them</button>').on('click', function () {
        DC.post('users.link', { id: u.id, email: true }).then(function () { d.close(); DC.toast('Emailed a fresh link to ' + u.email + '.'); }).catch(DC.fail);
      }));
      foot.push($('<button type="button" class="btn">Done</button>').on('click', function () { d.close(); }));
      var d = DC.dialog({ title: link.purpose === 'invite' ? 'Invitation link' : 'Password reset link', body: $b, foot: foot });
    }

    $body.on('click', 'tr.clickable', function () { edit(DC.byId(users, $(this).data('id'))); });
    load();
  };

  // ================================================================== settings

  DC.views['admin/settings'] = function ($main) {
    $main.append(DC.pageHead('Settings', 'Celebration policies, email timing and wording, and data retention.'));
    var $body = $('<div class="settings-wrap"></div>').appendTo($main);
    DC.loading($body);
    DC.get('settings.get').then(function (r) { render(r.settings, r.admins); }).catch(function (e) { DC.forbiddenOr($body, e); });

    function render(s, admins) {
      var chk = function (name, label, hint) { return '<label class="check"><input type="checkbox" name="' + name + '"' + (s[name] ? ' checked' : '') + '> <span>' + label + (hint ? '<br><span class="field-hint">' + hint + '</span>' : '') + '</span></label>'; };
      var $f = $('<form novalidate>' +
        '<div class="panel"><h2>Celebration rules</h2><p class="panel-sub">How dates are counted. Documented in docs/POLICIES.md.</p>' +
          '<div class="row"><div class="field"><label>February 29 in non-leap years</label><select class="select" name="feb29_rule"><option value="feb28">Celebrate on February 28</option><option value="mar1">Celebrate on March 1</option></select></div>' +
          '<div class="field"><label>Count work anniversaries from</label><select class="select" name="anniversary_from"><option value="hire_date">The most recent hire date (rehires start again)</option><option value="original_hire_date">The original hire date, when there is one</option></select></div></div>' +
          '<div class="checks">' + chk('celebrate_contractors', 'Include contractors and vendors (1099) in celebrations', 'They always appear in the directory and org chart.') +
          chk('store_birth_year', 'Keep the year of birth', 'Off (recommended): only the month and day are stored; imports check the year for mistakes and then discard it. Turning this off erases any stored years. Years and ages are never shown in emails or charts.') + '</div></div>' +
        '<div class="panel"><h2>Emails</h2><p class="panel-sub">Whether emails go to real people is set on the Notifications page (Off / Test / Live).</p>' +
          '<div class="row"><div class="field"><label>Daily send time (' + esc(s.timezone) + ')</label><input class="input" type="time" name="send_time" value="' + esc(s.send_time) + '"></div>' +
          '<div class="field"><label>Test-mode inbox</label><select class="select" name="test_user_id"><option value="0">Nobody (record only, deliver nothing)</option>' +
          admins.map(function (a) { return '<option value="' + a.id + '"' + (+s.test_user_id === +a.id ? ' selected' : '') + '>' + esc(a.name + ' <' + a.email + '>') + '</option>'; }).join('') + '</select>' +
          '<div class="field-hint">In test mode every email goes here instead of to supervisors. Only system administrators can be chosen.</div></div></div>' +
          '<div class="section-title">Monthly summary (on the 1st)</div><div class="checks">' + chk('monthly_enabled', 'Send each celebration owner a summary of their team’s month') + chk('monthly_send_empty', 'Also send a short “no celebrations this month” note') + '</div>' +
          '<div class="field"><label>Opening message</label><textarea class="textarea" name="monthly_intro" rows="3">' + esc(s.monthly_intro) + '</textarea></div>' +
          '<div class="row"><div class="field"><label>Catch-up window</label><select class="select" name="monthly_catchup_days">' + [0, 1, 2, 3, 5, 7].map(function (n) { return '<option value="' + n + '"' + (+s.monthly_catchup_days === n ? ' selected' : '') + '>' + (n ? 'Until the ' + (n + 1) + (n + 1 === 2 ? 'nd' : n + 1 === 3 ? 'rd' : 'th') + ' if the server was down' : 'Only on the 1st') + '</option>'; }).join('') + '</select></div></div>' +
          '<div class="section-title">Reminders</div><div class="row"><div class="field">' + chk('advance_enabled', 'Advance reminder') + '</div>' +
          '<div class="field"><label>Days before</label><input class="input" type="number" min="1" max="30" name="advance_days" value="' + esc(s.advance_days) + '"></div></div>' +
          '<div class="checks">' + chk('day_of_enabled', 'Day-of email (“Today Is Maria’s Birthday!”)') + '</div>' +
          '<div class="field"><label>Day-of message</label><textarea class="textarea" name="day_of_note" rows="2">' + esc(s.day_of_note) + '</textarea></div>' +
          '<div class="section-title">Routing</div><div class="checks">' +
          chk('owner_escalate', 'If a supervisor position is vacant, remind the next manager up', 'Otherwise the property manager is used, and if there’s nobody, administrators are alerted.') +
          chk('notify_dotted_line', 'Copy dotted-line managers on reminders') + '</div></div>' +
        '<div class="panel"><h2>General &amp; data</h2><div class="row">' +
          '<div class="field"><label>Portal name</label><input class="input" name="portal_name" value="' + esc(s.portal_name) + '"></div>' +
          '<div class="field"><label>Organization name</label><input class="input" name="org_name" value="' + esc(s.org_name) + '"></div>' +
          '<div class="field"><label>Erase unapplied import previews after (days)</label><input class="input" type="number" min="1" max="365" name="import_retention_days" value="' + esc(s.import_retention_days) + '"></div>' +
          '<div class="field"><label>Keep email history for (days)</label><input class="input" type="number" min="30" max="3650" name="notification_retention_days" value="' + esc(s.notification_retention_days) + '"></div>' +
          '<input type="hidden" name="timezone" value="' + esc(s.timezone) + '"></div></div>' +
        '<div class="sticky-save"><button type="submit" class="btn">Save settings</button><span class="small muted js-status"></span></div></form>');
      $f.find('[name=feb29_rule]').val(s.feb29_rule);
      $f.find('[name=anniversary_from]').val(s.anniversary_from);
      $body.html('').append($f);
      $f.on('submit', function (e) {
        e.preventDefault();
        var data = {};
        $f.find('input[name],select[name],textarea[name]').each(function () { data[this.name] = this.type === 'checkbox' ? this.checked : $(this).val(); });
        var go = function () {
          DC.formErrors($f, null);
          DC.busy($f.find('[type=submit]'), DC.post('settings.save', { settings: data }), 'Saving…').then(function (r) {
            s = r.settings; DC.toast('Settings saved.'); DC.refreshMe();
          }).catch(function (err) { DC.formErrors($f, err); });
        };
        if (!s.store_birth_year && data.store_birth_year) {
          DC.confirm({ title: 'Keep years of birth?', message: 'Only turn this on if Dwell has decided it needs birth years. They are visible to system administrators only and never appear in emails, charts or the calendar. Already-imported years were not kept; they would need to be re-imported.', ok: 'Keep birth years' }).then(function (ok) { if (ok) go(); });
        } else if (s.store_birth_year && !data.store_birth_year) {
          DC.confirm({ title: 'Erase stored birth years?', message: 'Turning this off permanently erases every stored year of birth. Months and days are kept.', ok: 'Erase and save', danger: true }).then(function (ok) { if (ok) go(); });
        } else { go(); }
      });
    }
  };

  // ================================================================== audit log

  DC.views['admin/audit'] = function ($main) {
    $main.append(DC.pageHead('Audit log', 'Every sign-in, change, import and email-mode switch. Birthdays are recorded as “changed” without their values.'));
    var $filters = $('<div class="filters"><input class="input search" type="search" placeholder="Search who or what"></div>').appendTo($main);
    var $list = $('<div class="panel"></div>').appendTo($main);
    var q = '';
    var offset = 0;
    function load(append) {
      if (!append) { offset = 0; DC.loading($list); }
      DC.get('audit.list', { q: q, offset: offset }).then(function (r) {
        var html = r.entries.map(function (h) {
          var det = '';
          if (h.details && typeof h.details === 'object' && !Array.isArray(h.details) && h.action.indexOf('update') !== -1) {
            det = Object.keys(h.details).slice(0, 12).map(function (k) {
              var c = h.details[k];
              if (!c || typeof c !== 'object') return '';
              return '<div><code>' + esc(k) + '</code> ' + (c.changed ? 'changed' : esc(c.from == null ? '—' : c.from) + ' → ' + esc(c.to == null ? '—' : c.to)) + '</div>';
            }).join('');
          }
          return '<div class="list-item" style="align-items:flex-start"><div class="li-body"><div style="font-weight:600;font-size:14px;white-space:normal">' + esc(h.summary) + '</div>' +
            '<div class="li-sub">' + esc(DC.date.stamp(h.created_at, S.tz)) + ' · ' + esc(h.actor) + ' · ' + esc(h.action) + '</div>' + (det ? '<div class="audit-details">' + det + '</div>' : '') + '</div></div>';
        }).join('');
        if (!append) $list.html('<div class="list"></div>');
        $list.find('.js-more').remove();
        $list.find('.list').append(html || (append ? '' : '<div class="empty">Nothing recorded yet.</div>'));
        if (r.entries.length === 100) $list.append('<div class="pager js-more"><span></span><button type="button" class="btn btn-sm btn-ghost">Load more</button></div>');
      }).catch(function (e) { DC.forbiddenOr($list, e); });
    }
    $filters.on('input', 'input', DC.debounce(function () { q = this.value; load(false); }, 300));
    $list.on('click', '.js-more button', function () { offset += 100; load(true); });
    load(false);
  };
})(jQuery, window.DC);
