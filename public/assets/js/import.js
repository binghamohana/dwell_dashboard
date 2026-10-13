/* Dwell Central — spreadsheet import: upload → review → explicit approval. */
(function ($, DC) {
  'use strict';

  var S = DC.S;
  var esc = DC.esc;
  var ACT = {
    create: 'New', update: 'Update', unchanged: 'No change', protected: 'Protected', skip: 'Skipped',
    error: 'Error', duplicate: 'Duplicate', ambiguous: 'Choose'
  };

  DC.views['admin/import'] = function ($main, sub) {
    if (sub[0] && /^\d+$/.test(sub[0])) return review($main, +sub[0]);
    $main.append(DC.pageHead('Import', 'Bring in the HR spreadsheet. You’ll review every change before anything is saved.'));
    var $drop = $('<label class="dropzone">' + DC.icon('upload') + '<strong>Choose the employee spreadsheet</strong><span>Excel .xlsx or .csv · the file itself isn’t kept</span>' +
      '<input type="file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" hidden></label>');
    var $past = $('<div class="panel" style="margin-top:18px"><h2>Earlier imports</h2><div class="js-list"><div class="empty"><span class="spinner"></span></div></div></div>');
    $main.append('<div class="panel" style="margin-bottom:18px"><h2>Setting up for the first time?</h2>' +
      '<p class="small" style="margin:0 0 12px;color:var(--ink-2)">Download the fill-in workbook: one sheet each for companies, legal entities, properties, departments and people, with drop-down lists so everything links up. ' +
      'Fill it in, upload it here, review, apply. You can upload it again later to make changes.</p>' +
      '<a class="btn btn-sm btn-ghost" href="assets/templates/Dwell-Central-template.xlsx" download>' + DC.icon('file') + 'Download the template (.xlsx)</a></div>');
    $main.append($drop, '<p class="small muted" style="margin:12px 2px 0">Recognized columns include Employee Name (or First/Last Name), Employee ID, Work Email, Job Title, Department, Status, Company, Division / Property, Hire Date, ' +
      'Original Hire Date, Term Date, Classification, Birthday and Supervisor. Other columns are ignored.</p>', $past);

    function upload(file) {
      if (!file) return;
      var fd = new FormData();
      fd.append('file', file);
      $drop.find('strong').html('<span class="spinner"></span> Reading ' + esc(file.name) + '…');
      DC.api('import.upload', 'POST', fd).then(function (r) { location.hash = '#/admin/import/' + r.id; })
        .catch(function (err) { $drop.find('strong').text('Choose the employee spreadsheet'); DC.fail(err); });
    }
    $drop.find('input').on('change', function () { upload(this.files[0]); this.value = ''; });
    $drop.on('dragover', function (e) { e.preventDefault(); $drop.addClass('is-over'); })
      .on('dragleave drop', function () { $drop.removeClass('is-over'); })
      .on('drop', function (e) { e.preventDefault(); upload(e.originalEvent.dataTransfer.files[0]); });

    DC.get('import.list').then(function (r) {
      var $l = $past.find('.js-list');
      if (!r.imports.length) { $l.html('<div class="empty">No imports yet.</div>'); return; }
      $l.html('<div class="list">' + r.imports.map(function (i) {
        var s = i.summary || {};
        var applied = s.applied || null;
        return '<div class="list-item' + (i.status === 'preview' ? ' clickable' : '') + '" data-id="' + i.id + '"><div class="li-body"><div class="li-main">' + esc(i.file_name) + (i.sheet ? ' <span class="muted" style="font-weight:400">· ' + esc(i.sheet) + '</span>' : '') + '</div>' +
          '<div class="li-sub">' + esc(DC.date.stamp(i.created_at, S.tz)) + (i.user_name ? ' · ' + esc(i.user_name) : '') +
          (applied ? ' · ' + applied.created + ' added, ' + applied.updated + ' updated' + (applied.terminated ? ', ' + applied.terminated + ' marked former' : '') : '') + '</div></div>' +
          '<span class="badge ' + (i.status === 'applied' ? 'ok' : i.status === 'preview' ? 'info' : 'outline') + '">' + esc({ applied: 'Applied', preview: 'Waiting for review', discarded: 'Discarded' }[i.status]) + '</span></div>';
      }).join('') + '</div>');
      $l.on('click', '.clickable', function () { location.hash = '#/admin/import/' + $(this).data('id'); });
    }).catch(DC.fail);
  };

  function review($main, id) {
    var plan = null;
    var decisions = {};
    var tab = 'changes';
    $main.append(DC.pageHead('Review import', null, $('<a class="btn btn-sm btn-ghost" href="#/admin/import">' + DC.icon('left') + 'All imports</a>')));
    var $body = $('<div class="steps"></div>').appendTo($main);
    DC.loading($body);

    function load(promise) {
      return promise.then(function (r) { plan = r.plan; decisions = $.extend(true, {}, plan.decisions || {}); render(); })
        .catch(function (err) { $body.html('<div class="panel"><div class="alert alert-error">' + esc(err.message) + '</div><a class="btn btn-ghost" href="#/admin/import">Back to imports</a></div>'); });
    }
    var refresh = DC.debounce(function () {
      $body.find('.sticky-bar .msg').html('<span class="spinner"></span> Updating preview…');
      load(DC.post('import.preview', { id: id, decisions: decisions }));
    }, 250);

    function render() {
      var s = plan.summary;
      var h = '';
      // 1. File & sheet
      h += '<div class="panel"><div class="step-title done">File</div><p class="panel-sub" style="margin:4px 0 12px">' + esc(plan.file_name) + ' · header on row ' + plan.header_row + '</p>';
      if (plan.previously_applied) h += '<div class="alert alert-warn">' + DC.icon('info') + '<span>This exact file was already imported on ' + esc(DC.date.stamp(plan.previously_applied.created_at, S.tz)) + '.</span></div>';
      if (plan.sheets.length > 1) {
        h += '<div class="field" style="max-width:420px"><label>Sheet</label><select class="select js-sheet">' + plan.sheets.map(function (sh) {
          return '<option value="' + esc(sh.name) + '"' + (sh.name === plan.sheet ? ' selected' : '') + (sh.header_row ? '' : ' disabled') + '>' + esc(sh.name) + ' (' + (sh.header_row ? DC.plural(sh.rows, 'row') : 'no header found') + ')</option>';
        }).join('') + '</select>' + (plan.org && plan.org.present ? '' : '<div class="field-hint">Use the sheet with everyone on it. Sheets that repeat the same people would be treated as the same records.</div>') + '</div>';
      }
      h += '<div class="small" style="color:var(--ink-2)"><strong>Columns used:</strong> ' + plan.columns.map(function (c) { return esc(c.header) + ' → ' + esc(c.label); }).join(' · ') + '</div>';
      if (plan.ignored.length) h += '<div class="small muted" style="margin-top:4px"><strong>Not imported:</strong> ' + esc(plan.ignored.join(', ')) + '</div>';
      h += '</div>';

      // Organization sheets (template)
      if (plan.org && plan.org.present) {
        var oc = plan.org.counts;
        var sheetLabels = { brands: 'Companies', entities: 'Legal entities', properties: 'Properties', departments: 'Departments' };
        h += '<div class="panel"><div class="step-title">Organization records</div><p class="panel-sub" style="margin:4px 0 12px">From the Companies, Legal entities, Properties and Departments sheets: ' +
          oc.create + ' new, ' + oc.update + ' to update, ' + oc.unchanged + ' unchanged' + (oc.error ? ', ' + oc.error + ' skipped' : '') + '. Blank cells never erase saved values.</p>';
        Object.keys(sheetLabels).forEach(function (t) {
          var recs = (plan.org.records[t] || []);
          if (!recs.length) return;
          h += '<div class="section-title">' + sheetLabels[t] + '</div><div style="overflow-x:auto"><table class="table"><tbody>' + recs.map(function (r) {
            var det = r.changes && r.changes.length ? '<ul class="changes">' + r.changes.map(function (c) {
              return '<li>' + esc(c.label) + ': ' + (r.action === 'create' ? '' : '<del>' + esc(c.from) + '</del> → ') + '<ins>' + esc(c.to) + '</ins></li>';
            }).join('') + '</ul>' : '';
            if (r.issues && r.issues.length) det += '<ul class="issues">' + r.issues.map(function (i) { return '<li>' + esc(i) + '</li>'; }).join('') + '</ul>';
            return '<tr><td class="sub" style="width:48px">' + r.row + '</td><td style="width:30%"><strong>' + esc(r.name || '—') + '</strong></td><td style="width:110px"><span class="act ' + (r.action === 'error' ? 'error' : r.action) + '">' +
              esc({ create: 'New', update: 'Update', unchanged: 'No change', error: 'Skipped' }[r.action]) + '</span></td><td>' + det + '</td></tr>';
          }).join('') + '</tbody></table></div>';
        });
        h += '</div>';
      }

      // 2. Values to match
      var needs = plan.values.filter(function (v) { return v.status !== 'matched' && v.status !== 'template'; });
      h += '<div class="panel"><div class="step-title' + (s.values_unresolved ? '' : ' done') + '">Companies, properties and departments</div>';
      if (!plan.values.length) h += '<p class="panel-sub" style="margin:4px 0 0">None in this file.</p>';
      else if (!needs.length) h += '<p class="panel-sub" style="margin:4px 0 0">Every value matches an existing record.</p>';
      else {
        h += '<p class="panel-sub" style="margin:4px 0 8px">These spellings from the file aren’t linked to a record yet. Match each to an existing record, create a new one (edit the name first if needed), or leave it blank. Values given the same new name become one record.</p>';
        needs.forEach(function (v) {
          var table = { entity: 'entities', property: 'properties', department: 'departments' }[v.kind];
          var c = v.choice || {};
          var action = c.action || '';
          var sug = v.suggestion || {};
          h += '<div class="value-row" data-kind="' + v.kind + '" data-key="' + esc(v.key) + '"><div><div class="v-name">“' + esc(v.value) + '”</div><div class="v-meta">' + esc({ entity: 'Company / legal entity', property: 'Property / division', department: 'Department' }[v.kind]) + ' · ' + DC.plural(v.rows, 'row') + '</div></div>' +
            '<div class="v-ctrl"><select class="select js-action"><option value="">Choose…</option><option value="new"' + (action === 'new' ? ' selected' : '') + '>Create new</option>' +
            (S.lists[table].length ? '<option value="map"' + (action === 'map' ? ' selected' : '') + '>Same as existing…</option>' : '') + '<option value="none"' + (action === 'none' ? ' selected' : '') + '>Leave blank</option></select>';
          if (action === 'map') h += '<select class="select js-map">' + DC.options(S.lists[table], c.id, 'Pick one') + '</select>';
          if (action === 'new') {
            h += '<input class="input js-name" value="' + esc(c.name || sug.name || v.value) + '" aria-label="Name">';
            if (v.kind === 'entity') h += '<select class="select js-kind"><option value="employer">Employer</option><option value="contractor"' + ((c.kind || sug.kind) === 'contractor' ? ' selected' : '') + '>Contractors (1099)</option><option value="other">Other</option></select>';
            if (v.kind === 'property') h += '<select class="select js-kind">' + ['property', 'office', 'team', 'other'].map(function (k) { return '<option value="' + k + '"' + ((c.kind || sug.kind) === k ? ' selected' : '') + '>' + { property: 'Property', office: 'Office', team: 'Team', other: 'Other' }[k] + '</option>'; }).join('') + '</select>';
            if (v.kind !== 'department') h += '<select class="select js-brand">' + DC.options(S.lists.brands, c.brand_id !== undefined ? c.brand_id : sug.brand_id, 'No company') + '</select>';
          }
          h += '</div></div>';
        });
        h += '<div style="margin-top:12px"><button type="button" class="btn btn-sm btn-ghost js-accept">Use all suggestions</button></div>';
      }
      h += '</div>';

      // 3. Rows
      var rows = plan.rows;
      var groups = {
        changes: rows.filter(function (r) { return ['create', 'update', 'protected', 'ambiguous', 'error'].indexOf(r.action) !== -1; }),
        review: rows.filter(function (r) { return (r.issues || []).length && r.action !== 'skip'; }),
        unchanged: rows.filter(function (r) { return r.action === 'unchanged'; }),
        skipped: rows.filter(function (r) { return ['skip', 'duplicate'].indexOf(r.action) !== -1; })
      };
      h += '<div class="panel"><div class="step-title">People</div><div class="summary-chips" style="margin:10px 0 14px">' +
        ['create', 'update', 'protected', 'ambiguous', 'unchanged', 'skip', 'error'].filter(function (k) { return s[k] || k === 'create' || k === 'update'; }).map(function (k) {
          return '<div class="summary-chip ' + k + '"><b>' + (s[k] || 0) + '</b><span>' + esc({ create: 'New people', update: 'Updates', protected: 'Protected (verified)', ambiguous: 'Need a choice', unchanged: 'No change', skip: 'Skipped', error: 'Errors' }[k]) + '</span></div>';
        }).join('') + (s.needs_review ? '<div class="summary-chip protected"><b>' + s.needs_review + '</b><span>Dates to review later</span></div>' : '') + '</div>' +
        '<div class="tabs">' + [['changes', 'Changes'], ['review', 'With notes'], ['unchanged', 'No change'], ['skipped', 'Skipped']].map(function (t) {
          return '<button type="button" data-tab="' + t[0] + '" class="' + (tab === t[0] ? 'is-on' : '') + '">' + t[1] + ' (' + groups[t[0]].length + ')</button>';
        }).join('') + '</div>';
      var list = groups[tab];
      if (!list.length) h += '<div class="empty">Nothing here.</div>';
      else {
        h += '<div style="overflow-x:auto"><table class="table"><thead><tr><th>Row</th><th>Person</th><th></th><th>Details</th><th></th></tr></thead><tbody>' + list.map(function (r) {
          var dec = (decisions.rows || {})[r.row] || {};
          var det = '';
          if (r.action === 'create') {
            det = '<ul class="changes">' + Object.keys(r.fields || {}).filter(function (k) { return ['first_name', 'last_name', 'preferred_name'].indexOf(k) === -1 && r.fields[k] !== '—'; }).map(function (k) {
              return '<li>' + esc(fieldLabel(k)) + ': <ins>' + esc(r.fields[k]) + '</ins></li>';
            }).join('') + '</ul>';
          } else if (r.changes && r.changes.length) {
            det = '<ul class="changes">' + r.changes.map(function (c) { return '<li>' + esc(c.label) + ': <del>' + esc(c.from) + '</del> → <ins>' + esc(c.to) + '</ins></li>'; }).join('') + '</ul>';
          } else if (r.skip_reason) det = '<span class="muted small">' + esc(r.skip_reason) + '</span>';
          if (r.issues && r.issues.length) det += '<ul class="issues">' + r.issues.map(function (i) { return '<li>' + esc(i) + '</li>'; }).join('') + '</ul>';
          var ctrl = '';
          if (r.action === 'protected' || (r.action === 'update' && dec.overwrite)) ctrl = '<label class="check small"><input type="checkbox" class="js-overwrite"' + (dec.overwrite ? ' checked' : '') + '> Approve changes</label>';
          if (r.action === 'ambiguous') ctrl = '<select class="select js-match" style="min-height:34px;font-size:13.5px"><option value="">Who is this?</option>' + r.candidates.map(function (c) { return '<option value="' + c.id + '">' + esc(c.name + ' (' + c.code + ')') + '</option>'; }).join('') + '<option value="new">A different person (create new)</option></select>';
          if ((r.action === 'create' || r.action === 'update') && !dec.overwrite) ctrl = '<label class="check small"><input type="checkbox" class="js-skip"> Skip</label>';
          if (r.action === 'skip' && dec.skip) ctrl = '<label class="check small"><input type="checkbox" class="js-skip" checked> Skip</label>';
          if (r.action === 'duplicate') ctrl = '<button type="button" class="btn btn-xs btn-ghost js-separate">Different person</button>';
          return '<tr data-row="' + r.row + '"><td class="sub">' + r.row + '</td><td><strong>' + esc(r.name) + '</strong>' + (r.code ? '<div class="sub">' + esc(r.code) + (r.match_by ? ' · matched by ' + esc(r.match_by) : '') + '</div>' : '') + '</td>' +
            '<td><span class="act ' + r.action + '">' + esc(ACT[r.action]) + '</span></td><td>' + det + '</td><td class="nowrap">' + ctrl + '</td></tr>';
        }).join('') + '</tbody></table></div>';
      }
      h += '</div>';

      // 4. Not in file
      if (plan.missing.length) {
        h += '<div class="panel"><div class="step-title">Current team members not in this file</div><p class="panel-sub" style="margin:4px 0 12px">' +
          'Only tick people who have actually left: if this file covers only part of the organization, everyone else appears here too. Ticked people become former employees (history is kept).</p><div class="check-grid">' +
          plan.missing.map(function (m) { return '<label class="check"><input type="checkbox" class="js-term" value="' + m.id + '"' + (m.terminate ? ' checked' : '') + '> ' + esc(m.name) + '</label>'; }).join('') + '</div></div>';
      }
      h += '<div class="panel"><label class="check"><input type="checkbox" class="js-incterm"' + (decisions.include_terminated ? ' checked' : '') + '> <span><strong>Also import former employees who aren’t in Dwell Central yet</strong><br><span class="field-hint">Usually unnecessary: keeping less personal data is safer. Former employees already in Dwell Central are always updated.</span></span></label></div>';

      // Apply bar
      var ready = plan.ready;
      var willChange = s.create + s.update + s.terminate_missing + (s.org_changes || 0);
      h += '<div class="sticky-bar"><div class="msg">' + (ready
        ? (willChange ? '<strong>Ready.</strong> ' + (s.org_changes ? DC.plural(s.org_changes, 'organization record') + ', ' : '') + DC.plural(s.create, 'new person', 'new people') + ', ' + DC.plural(s.update, 'update') + (s.terminate_missing ? ', ' + s.terminate_missing + ' marked former' : '') + '. Nothing is saved until you apply.' : 'Nothing to change.')
        : '<strong>Choices needed:</strong> ' + [s.values_unresolved ? DC.plural(s.values_unresolved, 'value') + ' to match' : '', s.ambiguous ? DC.plural(s.ambiguous, 'person', 'people') + ' to identify' : ''].filter(Boolean).join(' and ') + '.') +
        '</div><button type="button" class="btn btn-ghost js-discard">Discard</button><button type="button" class="btn js-apply"' + (ready && willChange ? '' : ' disabled') + '>Apply import</button></div>';
      $body.html(h);
    }

    function fieldLabel(k) {
      return { dotted_line_id: 'Dotted line', work_email: 'Email', job_title: 'Title', department_id: 'Department', entity_id: 'Entity', property_id: 'Property', status: 'Status', term_date: 'Term date', hire_date: 'Hired',
        original_hire_date: 'Originally hired', classification: 'Type', birthday: 'Birthday', hr_id: 'Employee ID', supervisor_id: 'Supervisor' }[k] || k;
    }

    function setValue($row) {
      var kind = $row.data('kind'), key = String($row.data('key'));
      decisions.values = decisions.values || {};
      decisions.values[kind] = decisions.values[kind] || {};
      var action = $row.find('.js-action').val();
      var c = { action: action };
      if (action === 'map') c.id = +$row.find('.js-map').val() || null;
      if (action === 'new') {
        var v = plan.values.filter(function (x) { return x.kind === kind && x.key === key; })[0];
        c.name = $row.find('.js-name').length ? $row.find('.js-name').val() : (v.suggestion.name || v.value);
        c.kind = $row.find('.js-kind').length ? $row.find('.js-kind').val() : v.suggestion.kind;
        c.brand_id = $row.find('.js-brand').length ? (+$row.find('.js-brand').val() || null) : (v.suggestion.brand_id || null);
      }
      if (!action) delete decisions.values[kind][key]; else decisions.values[kind][key] = c;
      refresh();
    }
    function rowDecision(row, patch) {
      decisions.rows = decisions.rows || {};
      decisions.rows[row] = $.extend({}, decisions.rows[row] || {}, patch);
      Object.keys(decisions.rows[row]).forEach(function (k) { if (decisions.rows[row][k] === null) delete decisions.rows[row][k]; });
      refresh();
    }

    $body.on('change', '.js-sheet', function () { decisions = { sheet: this.value }; refresh(); });
    $body.on('change', '.js-action, .js-map, .js-kind, .js-brand', function () { setValue($(this).closest('.value-row')); });
    $body.on('change', '.js-name', function () { setValue($(this).closest('.value-row')); });
    $body.on('click', '.js-accept', function () {
      decisions.values = decisions.values || {};
      plan.values.forEach(function (v) {
        if (v.status !== 'needs_choice') return;
        decisions.values[v.kind] = decisions.values[v.kind] || {};
        decisions.values[v.kind][v.key] = $.extend({ action: 'new' }, v.suggestion);
      });
      refresh();
    });
    $body.on('click', '[data-tab]', function () { tab = $(this).data('tab'); render(); });
    $body.on('change', '.js-overwrite', function () { rowDecision($(this).closest('tr').data('row'), { overwrite: this.checked || null }); });
    $body.on('change', '.js-skip', function () { rowDecision($(this).closest('tr').data('row'), { skip: this.checked || null }); });
    $body.on('change', '.js-match', function () { rowDecision($(this).closest('tr').data('row'), { match: this.value === 'new' ? 'new' : (+this.value || null) }); });
    $body.on('click', '.js-separate', function () { rowDecision($(this).closest('tr').data('row'), { match: 'new' }); });
    $body.on('change', '.js-term', function () {
      decisions.terminate_missing = $body.find('.js-term:checked').map(function () { return +this.value; }).get();
      refresh();
    });
    $body.on('change', '.js-incterm', function () { decisions.include_terminated = this.checked; refresh(); });
    $body.on('click', '.js-discard', function () {
      DC.confirm({ title: 'Discard this import?', message: 'Nothing from it has been saved. The uploaded rows are erased.', ok: 'Discard', danger: true }).then(function (ok) {
        if (ok) DC.post('import.discard', { id: id }).then(function () { location.hash = '#/admin/import'; }).catch(DC.fail);
      });
    });
    $body.on('click', '.js-apply', function () {
      var s = plan.summary;
      DC.confirm({
        title: 'Apply this import?',
        html: '<p style="margin:0 0 10px;color:var(--ink-2)">This will ' + (s.org_changes ? 'add or update ' + DC.plural(s.org_changes, 'organization record') + ', ' : '') + 'add ' + DC.plural(s.create, 'person', 'people') + ' and update ' + DC.plural(s.update, 'record') +
          (s.terminate_missing ? ', and mark ' + s.terminate_missing + ' as former employees' : '') + '. Every change is recorded in the audit log.</p>' +
          (s.protected ? '<p class="small muted" style="margin:0">' + DC.plural(s.protected, 'verified record') + ' will be left as they are.</p>' : ''),
        ok: 'Apply import'
      }).then(function (ok) {
        if (!ok) return;
        var $b = $body.find('.js-apply');
        DC.busy($b, DC.post('import.apply', { id: id, decisions: decisions, hash: plan.hash }), 'Applying…').then(function (r) {
          var a = r.summary.applied;
          DC.toast('Import applied: ' + a.created + ' added, ' + a.updated + ' updated' + (a.terminated ? ', ' + a.terminated + ' marked former' : '') + (a.org_created || a.org_updated ? '; ' + (a.org_created + a.org_updated) + ' organization records' : '') + '.');
          DC.refreshMe();
          location.hash = '#/admin/people';
        }).catch(function (err) {
          if (err.status === 409 && err.data && err.data.plan) { plan = err.data.plan; render(); }
          DC.fail(err);
        });
      });
    });

    load(DC.get('import.get', { id: id }));
  }
})(jQuery, window.DC);
