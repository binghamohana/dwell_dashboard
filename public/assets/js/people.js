/* Dwell Central — directory, profiles, people records and the edit form. */
(function ($, DC) {
  'use strict';

  var S = DC.S;
  var esc = DC.esc;
  var MONTHS = DC.date.MONTHS;

  function personMeta(p) {
    var bits = [];
    if (p.property) bits.push('<span>' + DC.icon('pin') + esc(p.property.name) + '</span>');
    if (p.department) bits.push('<span>' + DC.icon('briefcase') + esc(p.department.name) + '</span>');
    return bits.join('');
  }

  function birthdayLabel(p) {
    if (!p.birthday) return '';
    if (p.birthday.status === 'missing') return '<span class="muted">—</span>';
    return esc(p.birthday.label) + (p.birthday.status === 'needs_review' ? ' <span class="badge warn">Needs review</span>' : '');
  }

  function hireLabel(p) {
    if (!p.hire_date) return '<span class="muted">—</span>';
    return esc(DC.date.full(p.hire_date)) + (p.service_years != null ? '<div class="sub">' + (p.service_years ? DC.plural(p.service_years, 'year') : (p.hire_date > S.today ? 'Starts soon' : 'Under a year')) + '</div>' : '') +
      (p.hire_status === 'needs_review' ? ' <span class="badge warn">Needs review</span>' : '');
  }

  // ------------------------------------------------------------------ profile drawer

  DC.openPerson = function (id) {
    var $box = $('<div><div class="empty"><span class="spinner"></span></div></div>');
    DC.drawer($box);
    DC.get('people.get', { id: id }).then(function (res) {
      renderProfile($box, res.person);
    }, function (err) {
      $box.html('<div class="empty"><strong>Not available</strong>' + esc(err.message) + '</div>');
    });
  };

  function personLink(ref) {
    if (!ref) return '<span class="muted">—</span>';
    if (!ref.id) return esc(ref.name);
    return '<button type="button" class="person-link" data-person="' + ref.id + '">' + esc(ref.name) + '</button>';
  }

  function renderProfile($box, p) {
    var h = '<div class="profile-top">' + DC.avatar(p, 'xl') +
      '<h2>' + esc(p.name) + '</h2>' +
      (p.job_title ? '<div class="title">' + esc(p.job_title) + '</div>' : '') +
      '<div class="legal">' + (p.preferred_name ? 'Legal name: ' + esc(p.first_name + ' ' + p.last_name) + ' · ' : '') + esc(p.code) + '</div>' +
      '<div class="badges">' +
        (p.brand ? '<span class="badge" style="background:color-mix(in srgb,' + esc(p.brand.color) + ' 14%,#fff);color:' + esc(p.brand.color) + '">' + esc(p.brand.name) + '</span>' : '') +
        (p.status !== 'active' ? '<span class="badge ' + (p.status === 'terminated' ? 'danger' : 'warn') + '">' + esc(S.statuses[p.status]) + '</span>' : '') +
        (p.is_contractor ? '<span class="badge outline">Contractor / vendor</span>' : '') +
        (p.verified_at ? '<span class="badge ok">' + DC.icon('check') + 'Verified</span>' : '') +
      '</div><div class="profile-actions">' +
        (DC.can('people.edit') ? '<button type="button" class="btn btn-sm js-edit">' + DC.icon('edit') + 'Edit</button>' : '') +
        '<a class="btn btn-sm btn-ghost" href="#/org/reporting?focus=' + p.id + '">' + DC.icon('tree') + 'Org chart</a>' +
        (p.work_email ? '<a class="btn btn-sm btn-ghost" href="mailto:' + esc(p.work_email) + '">' + DC.icon('mail') + 'Email</a>' : '') +
      '</div></div>';

    h += '<div class="section-title">Work</div><dl class="dl">';
    h += '<dt>Property / office</dt><dd>' + (p.property ? esc(p.property.name) : '<span class="muted">—</span>') +
      (p.additional_properties && p.additional_properties.length ? '<span class="sub">Also: ' + esc(p.additional_properties.map(function (x) { return x.name; }).join(', ')) + '</span>' : '') + '</dd>';
    h += '<dt>Department</dt><dd>' + (p.department ? esc(p.department.name) : '<span class="muted">—</span>') + '</dd>';
    h += '<dt>Legal entity</dt><dd>' + (p.entity ? esc(p.entity.name) : '<span class="muted">—</span>') + '</dd>';
    h += '<dt>Supervisor</dt><dd>' + personLink(p.supervisor) + '</dd>';
    if (p.dotted_line) h += '<dt>Dotted line</dt><dd>' + personLink(p.dotted_line) + '</dd>';
    h += '<dt>Work email</dt><dd>' + (p.work_email ? '<a href="mailto:' + esc(p.work_email) + '">' + esc(p.work_email) + '</a>' : '<span class="muted">—</span>') + '</dd>';
    if (p.classification) h += '<dt>Classification</dt><dd>' + esc(S.classifications[p.classification] || p.classification) + '</dd>';
    h += '</dl>';

    if (p.reports && p.reports.length) {
      h += '<div class="section-title">Direct reports (' + p.reports.length + ')</div><div class="mini-list">' + p.reports.map(miniPerson).join('') + '</div>';
    }
    if (p.dotted_reports && p.dotted_reports.length) {
      h += '<div class="section-title">Dotted-line reports</div><div class="mini-list">' + p.dotted_reports.map(miniPerson).join('') + '</div>';
    }

    if (p.birthday) {
      h += '<div class="section-title">Celebrations</div><dl class="dl">';
      h += '<dt>Birthday</dt><dd>' + birthdayLabel(p) + (p.birth_year ? '<span class="sub">Year on file (administrators only)</span>' : '') + '</dd>';
      h += '<dt>Hire date</dt><dd>' + hireLabel(p) + (p.original_hire_date ? '<span class="sub">Originally hired ' + esc(DC.date.full(p.original_hire_date)) + '</span>' : '') + '</dd>';
      h += '<dt>Celebrated by</dt><dd>' + (p.owner.id
        ? personLink({ id: p.owner.id, name: p.owner.name }) + '<span class="sub">' + esc(p.owner.label) + (p.owner.note ? ' · ' + esc(p.owner.note) : '') + '</span>'
        : '<span class="badge danger">' + DC.icon('alert') + 'No owner</span><span class="sub">' + esc(p.owner.note || '') + '</span>') + '</dd>';
      if (p.excluded) {
        h += '<dt>Automatic emails</dt><dd><span class="badge outline">Not celebrated</span><span class="sub">' + esc(p.excluded) + '</span></dd>';
      } else if (!p.celebrate_birthday || !p.celebrate_anniversary) {
        h += '<dt>Preferences</dt><dd>' + (!p.celebrate_birthday ? 'Prefers no birthday celebration. ' : '') + (!p.celebrate_anniversary ? 'Prefers no anniversary celebration.' : '') + '</dd>';
      }
      if (p.upcoming && p.upcoming.length) {
        h += '<dt>Coming up</dt><dd>' + p.upcoming.map(function (e) {
          return '<div>' + DC.typeBadge(e.type, e.years) + ' ' + esc(DC.date.relative(e.date, S.today)) + (DC.date.diffDays(S.today, e.date) >= 7 ? '' : '') + ' <span class="muted small">' + esc(DC.date.md(e.date)) + '</span></div>';
        }).join('') + '</dd>';
      }
      h += '</dl>';
    }

    if (p.issues && p.issues.length && DC.can('people.edit')) {
      h += '<div class="section-title">Needs attention</div><div class="issues-box">' + p.issues.map(function (i) {
        return '<span class="badge ' + (i === 'unverified' ? 'outline' : 'warn') + '">' + esc(S.issueLabels[i] || i) + '</span>';
      }).join('') + '</div>';
    }
    if (DC.can('people.private')) {
      h += '<div class="section-title">Administrators only</div><dl class="dl">' +
        '<dt>HR ID</dt><dd>' + (p.hr_id ? esc(p.hr_id) : '<span class="muted">—</span>') + '</dd>' +
        (p.term_date ? '<dt>Term date</dt><dd>' + esc(DC.date.full(p.term_date)) + '</dd>' : '') +
        '<dt>Source</dt><dd>' + esc(p.source || '—') + '</dd>' +
        '<dt>Verified</dt><dd>' + (p.verified_at ? esc(DC.date.stamp(p.verified_at, S.tz)) : 'Not yet') + ' <button type="button" class="link-btn js-verify" data-v="' + (p.verified_at ? 0 : 1) + '">' + (p.verified_at ? 'Remove' : 'Mark as verified') + '</button></dd>' +
        '</dl>' + (p.notes ? '<div class="note-box">' + esc(p.notes) + '</div>' : '') +
        (DC.can('audit.view') ? '<p><button type="button" class="link-btn js-history">View change history</button></p>' : '');
    }
    $box.html(h);
    $box.on('click', '[data-person]', function () { DC.openPerson(+$(this).data('person')); });
    $box.find('.js-edit').on('click', function () { DC.editPerson(p.id, function () { DC.openPerson(p.id); }); });
    $box.find('.js-verify').on('click', function () {
      DC.post('people.verify', { id: p.id, verified: $(this).data('v') === 1 }).then(function (r) { renderProfile($box.off(), r.person); DC.toast('Saved.'); DC.peopleChanged(); }).catch(DC.fail);
    });
    $box.find('.js-history').on('click', function () { historyDialog(p); });
  }

  function miniPerson(r) {
    return '<button type="button" class="mini-person" data-person="' + r.id + '"><span class="avatar sm" style="--c:#2B4F86">' + esc(initials(r.name)) + '</span><span><strong>' + esc(r.name) + '</strong><span>' + esc(r.job_title || '') + '</span></span></button>';
  }
  function initials(name) { var p = String(name).split(' '); return ((p[0] || '')[0] || '') + ((p[1] || '')[0] || ''); }

  function historyDialog(p) {
    var d = DC.dialog({ title: 'Change history', eyebrow: p.name, wide: true, body: '<div class="empty"><span class="spinner"></span></div>' });
    DC.get('people.history', { id: p.id }).then(function (res) {
      if (!res.history.length) { d.$body.html('<div class="empty">No changes recorded.</div>'); return; }
      d.$body.html('<div class="list">' + res.history.map(function (h) {
        var det = '';
        if (h.details && typeof h.details === 'object' && !Array.isArray(h.details)) {
          det = Object.keys(h.details).map(function (k) {
            var c = h.details[k];
            if (!c || typeof c !== 'object') return '';
            return '<div><code>' + esc(k) + '</code> ' + (c.changed ? 'changed' : esc(c.from == null ? '—' : c.from) + ' → ' + esc(c.to == null ? '—' : c.to)) + '</div>';
          }).join('');
        }
        return '<div class="list-item" style="align-items:flex-start"><div class="li-body"><div style="font-weight:600;font-size:14px">' + esc(h.summary) + '</div>' +
          '<div class="li-sub">' + esc(DC.date.stamp(h.created_at, S.tz)) + ' · ' + esc(h.actor) + '</div>' + (det ? '<div class="audit-details">' + det + '</div>' : '') + '</div></div>';
      }).join('') + '</div>');
    }).catch(function (err) { d.$body.html('<div class="alert alert-error">' + esc(err.message) + '</div>'); });
  }

  // Views can refresh themselves when a record changes.
  var changeHandlers = [];
  DC.onPeopleChanged = function (fn) { changeHandlers = [fn]; };
  DC.peopleChanged = function () { changeHandlers.forEach(function (fn) { fn(); }); };

  // ------------------------------------------------------------------ edit form (administrators)

  var optionsCache = null;
  function peopleOptions() {
    if (optionsCache) return Promise.resolve(optionsCache);
    return DC.get('people.options').then(function (r) { optionsCache = r.people; setTimeout(function () { optionsCache = null; }, 30000); return r.people; });
  }

  DC.editPerson = function (id, onSaved) {
    Promise.all([id ? DC.get('people.get', { id: id }) : Promise.resolve({ person: null }), peopleOptions()]).then(function (res) {
      editDialog(res[0].person, res[1].filter(function (o) { return !id || o.id !== id; }), onSaved);
    }).catch(DC.fail);
  };

  function editDialog(p, people, onSaved) {
    p = p || { status: 'active', celebrate_birthday: true, celebrate_anniversary: true, birthday: { status: 'missing' }, additional_properties: [] };
    var L = S.lists;
    var bday = p.birthday || {};
    var personOpts = function (sel, empty) {
      return '<option value="">' + esc(empty) + '</option>' + people.map(function (o) {
        return '<option value="' + o.id + '"' + (+sel === o.id ? ' selected' : '') + '>' + esc(o.name + (o.job_title ? ' — ' + o.job_title : '') + (o.has_email ? '' : ' (no email)')) + '</option>';
      }).join('');
    };
    var monthOpts = '<option value="">Month</option>' + MONTHS.map(function (m, i) { return '<option value="' + (i + 1) + '"' + (bday.month === i + 1 ? ' selected' : '') + '>' + m + '</option>'; }).join('');
    var dayOpts = '<option value="">Day</option>';
    for (var dd = 1; dd <= 31; dd++) dayOpts += '<option value="' + dd + '"' + (bday.day === dd ? ' selected' : '') + '>' + dd + '</option>';
    var extra = (p.additional_properties || []).map(function (x) { return x.id; });
    var activeProps = L.properties.filter(function (x) { return x.is_active !== false || x.id === (p.property && p.property.id) || extra.indexOf(x.id) !== -1; });

    var $f = $('<form novalidate autocomplete="off">' +
      '<fieldset class="group"><legend>Name</legend><div class="row-3">' +
        '<div class="field"><label>First name (legal)</label><input class="input" name="first_name" value="' + esc(p.first_name || '') + '" maxlength="80"></div>' +
        '<div class="field"><label>Last name (legal)</label><input class="input" name="last_name" value="' + esc(p.last_name || '') + '" maxlength="120"></div>' +
        '<div class="field"><label>Goes by <span class="opt">(optional)</span></label><input class="input" name="preferred_name" value="' + esc(p.preferred_name || '') + '" maxlength="80"></div>' +
      '</div></fieldset>' +
      '<fieldset class="group"><legend>Work</legend><div class="row">' +
        '<div class="field"><label>Job title</label><input class="input" name="job_title" value="' + esc(p.job_title || '') + '" maxlength="160"></div>' +
        '<div class="field"><label>Work email</label><input class="input" type="email" name="work_email" value="' + esc(p.work_email || '') + '" maxlength="190"></div>' +
        '<div class="field"><label>Department</label><select class="select" name="department_id">' + DC.options(L.departments, p.department && p.department.id) + '</select></div>' +
        '<div class="field"><label>Primary property / office</label><select class="select" name="property_id">' + DC.options(activeProps, p.property && p.property.id) + '</select></div>' +
        '<div class="field"><label>Legal entity (employer)</label><select class="select" name="entity_id">' + DC.options(L.entities, p.entity && p.entity.id) + '</select></div>' +
        '<div class="field"><label>Company / brand</label><select class="select" name="brand_id">' + DC.options(L.brands, p.brand_id, 'From the legal entity') + '</select></div>' +
        '<div class="field"><label>Classification</label><select class="select" name="classification">' + DC.options(Object.keys(S.classifications).map(function (k) { return { id: k, name: S.classifications[k] }; }), p.classification) + '</select></div>' +
        '<div class="field"><label>Employee ID (HR) <span class="opt">(optional)</span></label><input class="input" name="hr_id" value="' + esc(p.hr_id || '') + '" maxlength="64"></div>' +
      '</div>' +
      '<div class="field"><span class="field-label">Also works at</span><div class="check-grid">' + activeProps.map(function (x) {
        return '<label class="check"><input type="checkbox" name="additional_property_ids" value="' + x.id + '"' + (extra.indexOf(x.id) !== -1 ? ' checked' : '') + '> ' + esc(x.name) + '</label>';
      }).join('') + '</div></div></fieldset>' +
      '<fieldset class="group"><legend>Reporting</legend><div class="row">' +
        '<div class="field"><label>Supervisor</label><select class="select" name="supervisor_id">' + personOpts(p.supervisor && p.supervisor.id, 'No supervisor') + '</select></div>' +
        '<div class="field"><label>Dotted-line manager <span class="opt">(optional)</span></label><select class="select" name="dotted_line_id">' + personOpts(p.dotted_line && p.dotted_line.id, 'None') + '</select></div>' +
      '</div><div class="field"><label>Celebration owner</label><select class="select" name="celebration_owner_id">' + personOpts(p.celebration_owner_id, 'Automatic (supervisor, then property manager)') + '</select>' +
        '<div class="field-hint">' + (p.owner ? (p.owner.id ? 'Currently: ' + esc(p.owner.name) + ' (' + esc(p.owner.label) + ').' : 'Currently nobody: ' + esc(p.owner.note || '') + '.') : 'Who gets the reminder emails for this person.') + '</div></div></fieldset>' +
      '<fieldset class="group"><legend>Dates</legend><div class="row-3">' +
        '<div class="field"><label>Hire date (most recent)</label><input class="input" type="date" name="hire_date" value="' + esc(p.hire_date || '') + '"></div>' +
        '<div class="field"><label>Original hire date <span class="opt">(if rehired)</span></label><input class="input" type="date" name="original_hire_date" value="' + esc(p.original_hire_date || '') + '"></div>' +
        '<div class="field"><label>Status</label><select class="select" name="status">' + DC.options(Object.keys(S.statuses).map(function (k) { return { id: k, name: S.statuses[k] }; }), p.status, false) + '</select></div>' +
      '</div><div class="row-3">' +
        '<div class="field"><label>Birthday</label><select class="select" name="birth_month">' + monthOpts + '</select></div>' +
        '<div class="field"><label>&nbsp;</label><select class="select" name="birth_day" aria-label="Birthday day">' + dayOpts + '</select></div>' +
        (S.storeBirthYear ? '<div class="field"><label>Birth year <span class="opt">(admins only)</span></label><input class="input" name="birth_year" inputmode="numeric" maxlength="4" value="' + esc(p.birth_year || '') + '"></div>'
          : '<div class="field"><label>Term date</label><input class="input" type="date" name="term_date" value="' + esc(p.term_date || '') + '"></div>') +
      '</div>' + (S.storeBirthYear ? '<div class="row-3"><div class="field"><label>Term date</label><input class="input" type="date" name="term_date" value="' + esc(p.term_date || '') + '"></div></div>' : '') +
      '<div class="checks">' +
        '<label class="check"><input type="checkbox" name="birthday_review"' + (bday.status === 'needs_review' ? ' checked' : '') + '> <span><strong>Birthday needs review</strong> (not celebrated until confirmed)</span></label>' +
        '<label class="check"><input type="checkbox" name="celebrate_birthday"' + (p.celebrate_birthday !== false ? ' checked' : '') + '> Celebrate their birthday</label>' +
        '<label class="check"><input type="checkbox" name="celebrate_anniversary"' + (p.celebrate_anniversary !== false ? ' checked' : '') + '> Celebrate their work anniversary</label>' +
      '</div></fieldset>' +
      (p.id ? '<fieldset class="group"><legend>Photo</legend><div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">' + DC.avatar(p, 'lg') +
        '<label class="btn btn-sm btn-ghost">' + DC.icon('camera') + 'Upload approved photo<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" hidden></label>' +
        (p.photo ? '<button type="button" class="btn btn-sm btn-danger-ghost js-photo-remove">Remove</button>' : '') +
        '<div class="field-hint" style="flex-basis:100%">Only upload photos the person has approved. Photos are visible only to signed-in staff.</div></div></fieldset>' : '') +
      '<fieldset class="group"><legend>Internal notes</legend><div class="field"><textarea class="textarea" name="notes" maxlength="4000" placeholder="Visible to system administrators only">' + esc(p.notes || '') + '</textarea></div>' +
        '<label class="check"><input type="checkbox" name="verified"' + (p.verified_at ? ' checked' : '') + '> <span><strong>Verified against HR records.</strong> Imports won’t change a verified record without your approval.</span></label></fieldset>' +
      '</form>');

    var $save = $('<button type="button" class="btn">' + (p.id ? 'Save changes' : 'Add person') + '</button>');
    var $cancel = $('<button type="button" class="btn btn-ghost">Cancel</button>');
    var foot = [];
    if (p.id) foot.push($('<button type="button" class="btn btn-danger-ghost">Delete</button>').on('click', function () { deletePerson(p, d, onSaved); }), $('<span class="spacer"></span>'));
    foot.push($cancel, $save);
    var d = DC.dialog({ title: p.id ? 'Edit ' + p.name : 'Add a person', eyebrow: p.code || 'New record', xwide: true, body: $f, foot: foot });
    $cancel.on('click', function () { d.close(); });

    $f.find('[name=photo]').on('change', function () {
      var file = this.files[0];
      if (!file) return;
      var fd = new FormData();
      fd.append('id', p.id);
      fd.append('photo', file);
      DC.toast('Uploading photo…');
      DC.api('people.photo', 'POST', fd).then(function () { DC.toast('Photo saved.'); d.close(); DC.peopleChanged(); if (onSaved) onSaved(); }).catch(DC.fail);
    });
    $f.find('.js-photo-remove').on('click', function () {
      DC.post('people.photo_remove', { id: p.id }).then(function () { DC.toast('Photo removed.'); d.close(); DC.peopleChanged(); if (onSaved) onSaved(); }).catch(DC.fail);
    });

    $save.on('click', function () {
      var v = function (n) { return $f.find('[name=' + n + ']').val(); };
      var data = {
        first_name: v('first_name'), last_name: v('last_name'), preferred_name: v('preferred_name'), job_title: v('job_title'), work_email: v('work_email'),
        department_id: v('department_id'), property_id: v('property_id'), entity_id: v('entity_id'), brand_id: v('brand_id'), classification: v('classification'),
        hr_id: v('hr_id'), supervisor_id: v('supervisor_id'), dotted_line_id: v('dotted_line_id'), celebration_owner_id: v('celebration_owner_id'),
        hire_date: v('hire_date'), original_hire_date: v('original_hire_date'), term_date: v('term_date'), status: v('status'),
        birth_month: v('birth_month'), birth_day: v('birth_day'), birthday_status: $f.find('[name=birthday_review]').is(':checked') ? 'needs_review' : 'ok',
        celebrate_birthday: $f.find('[name=celebrate_birthday]').is(':checked'), celebrate_anniversary: $f.find('[name=celebrate_anniversary]').is(':checked'),
        notes: v('notes'), verified: $f.find('[name=verified]').is(':checked'),
        additional_property_ids: $f.find('[name=additional_property_ids]:checked').map(function () { return +this.value; }).get()
      };
      if (S.storeBirthYear) data.birth_year = v('birth_year');
      if (p.id) data.id = p.id;
      DC.formErrors($f, null);
      DC.busy($save, DC.post('people.save', data), 'Saving…').then(function (r) {
        d.close();
        DC.toast(p.id ? 'Saved.' : 'Added ' + r.person.name + '.');
        optionsCache = null;
        DC.peopleChanged();
        if (onSaved) onSaved(r.person);
      }).catch(function (err) { DC.formErrors($f, err); });
    });
  }

  function deletePerson(p, d, onSaved) {
    DC.confirm({
      title: 'Delete ' + p.name + '?',
      message: 'Only delete records created by mistake. For someone who has left, set their status to “Former employee” instead so history is kept.',
      ok: 'Delete record', danger: true
    }).then(function (ok) {
      if (!ok) return;
      DC.post('people.delete', { id: p.id }).then(function () {
        d.close(); DC.closeDrawer(true); DC.toast('Deleted.'); optionsCache = null; DC.peopleChanged();
      }).catch(DC.fail);
    });
  }

  // ------------------------------------------------------------------ directory

  DC.views.people = function ($main, sub) {
    var state = $.extend({ q: '', brand_id: '', property_id: '', department_id: '', status: 'current', group: 'property', layout: 'cards' }, DC.store.get('dir', {}));
    state.q = '';
    var people = [];
    var $actions = DC.can('people.edit') ? $('<button class="btn btn-sm">' + DC.icon('plus') + 'Add person</button>').on('click', function () { DC.editPerson(null, function (np) { if (np) DC.openPerson(np.id); }); }) : null;
    $main.append(DC.pageHead('Directory', 'Everyone on the team' + (DC.can('people.all') ? '' : ' you look after'), $actions));
    var L = S.lists;
    var $filters = $('<div class="filters">' +
      '<input class="input search" type="search" placeholder="Search name, title or email" aria-label="Search">' +
      '<select class="select" name="brand_id" aria-label="Company">' + DC.options(L.brands, state.brand_id, 'All companies') + '</select>' +
      '<select class="select" name="property_id" aria-label="Property">' + DC.options(L.properties, state.property_id, 'All properties') + '</select>' +
      '<select class="select" name="department_id" aria-label="Department">' + DC.options(L.departments, state.department_id, 'All departments') + '</select>' +
      (DC.can('people.all') ? '<select class="select" name="status" aria-label="Status"><option value="current">Current team</option><option value="active">Active</option><option value="leave">On leave</option><option value="terminated">Former employees</option></select>' : '') +
      '<div class="seg" role="group" aria-label="Group by"><button type="button" data-group="property">By property</button><button type="button" data-group="brand">By company</button><button type="button" data-group="none">A–Z</button></div>' +
      '<span class="count"></span></div>');
    var $list = $('<div></div>');
    $main.append($filters, $list);
    $filters.find('[name=status]').val(state.status);
    $filters.find('[data-group=' + state.group + ']').addClass('is-on');

    function load() {
      DC.loading($list);
      DC.get('people.list', { status: state.status }).then(function (r) { people = r.people; render(); }).catch(function (e) { DC.forbiddenOr($list, e); });
    }
    function matches(p) {
      if (state.brand_id && (!p.brand || p.brand.id !== +state.brand_id)) return false;
      if (state.property_id && (!p.property || p.property.id !== +state.property_id)) return false;
      if (state.department_id && (!p.department || p.department.id !== +state.department_id)) return false;
      if (state.q) {
        var hay = (p.name + ' ' + p.first_name + ' ' + p.last_name + ' ' + (p.job_title || '') + ' ' + (p.work_email || '')).toLowerCase();
        return state.q.toLowerCase().split(/\s+/).every(function (w) { return hay.indexOf(w) !== -1; });
      }
      return true;
    }
    function card(p) {
      return '<button type="button" class="person-card" data-id="' + p.id + '">' + DC.avatar(p) + '<span class="pc-body"><span class="pc-name">' + esc(p.name) + '</span>' +
        '<span class="pc-title" style="display:block">' + esc(p.job_title || ' ') + '</span><span class="pc-meta">' + personMeta(p) +
        (p.status !== 'active' ? '<span class="badge ' + (p.status === 'terminated' ? 'danger' : 'warn') + '">' + esc(S.statuses[p.status]) + '</span>' : '') + '</span></span></button>';
    }
    function render() {
      var list = people.filter(matches);
      $filters.find('.count').text(DC.plural(list.length, 'person', 'people'));
      if (!list.length) {
        $list.html('<div class="panel"><div class="empty"><strong>Nobody matches</strong>' + (people.length ? 'Try a different search or filter.' : (DC.can('import.run') ? '<a href="#/admin/import">Import the employee spreadsheet</a> to get started.' : 'No one is assigned to you yet.')) + '</div></div>');
        return;
      }
      var groups = {};
      var order = [];
      list.forEach(function (p) {
        var key = state.group === 'property' ? (p.property ? p.property.name : 'No property assigned') : (state.group === 'brand' ? (p.brand ? p.brand.name : 'No company assigned') : '');
        if (!groups[key]) { groups[key] = []; order.push(key); }
        groups[key].push(p);
      });
      order.sort(function (a, b) { return (a.indexOf('No ') === 0) - (b.indexOf('No ') === 0) || a.localeCompare(b); });
      var h = '';
      order.forEach(function (k) {
        if (k) h += '<div class="group-head"><h2>' + esc(k) + '</h2><span class="n">' + DC.plural(groups[k].length, 'person', 'people') + '</span></div>';
        h += '<div class="people-grid">' + groups[k].map(card).join('') + '</div>';
      });
      $list.html(h);
    }
    function save() { DC.store.set('dir', { brand_id: state.brand_id, property_id: state.property_id, department_id: state.department_id, group: state.group, status: state.status }); }

    $filters.on('input', '.search', DC.debounce(function () { state.q = this.value.trim(); render(); }, 120));
    $filters.on('change', 'select', function () {
      state[this.name] = this.value; save();
      if (this.name === 'status') load(); else render();
    });
    $filters.on('click', '[data-group]', function () {
      state.group = $(this).data('group'); save();
      $filters.find('[data-group]').removeClass('is-on'); $(this).addClass('is-on'); render();
    });
    $list.on('click', '.person-card', function () { DC.openPerson(+$(this).data('id')); });
    DC.onPeopleChanged(load);
    load();
    if (sub[0] && /^\d+$/.test(sub[0])) DC.openPerson(+sub[0]);
  };

  // ------------------------------------------------------------------ people records (administrators)

  DC.views['admin/people'] = function ($main, sub) {
    var state = { q: '', issue: sub[0] === 'issue' ? (sub[1] || '') : '', brand_id: '', property_id: '', status: 'current' };
    var people = [];
    var $actions = $('<a class="btn btn-sm btn-ghost" href="#/admin/import">' + DC.icon('upload') + 'Import</a>').add(
      $('<button class="btn btn-sm">' + DC.icon('plus') + 'Add person</button>').on('click', function () { DC.editPerson(null); }));
    $main.append(DC.pageHead('People records', 'Maintain records, fix data issues and confirm details against HR.', $actions));
    var issueOpts = '<option value="">All records</option>' + Object.keys(S.issueLabels).map(function (k) { return '<option value="' + k + '">' + esc(S.issueLabels[k]) + '</option>'; }).join('');
    var $filters = $('<div class="filters">' +
      '<input class="input search" type="search" placeholder="Search" aria-label="Search">' +
      '<select class="select" name="issue" aria-label="Data issue">' + issueOpts + '</select>' +
      '<select class="select" name="brand_id" aria-label="Company">' + DC.options(S.lists.brands, '', 'All companies') + '</select>' +
      '<select class="select" name="property_id" aria-label="Property">' + DC.options(S.lists.properties, '', 'All properties') + '</select>' +
      '<select class="select" name="status" aria-label="Status"><option value="current">Current team</option><option value="active">Active</option><option value="leave">On leave</option><option value="terminated">Former employees</option><option value="all">Everyone</option></select>' +
      '<span class="count"></span></div>');
    $filters.find('[name=issue]').val(state.issue);
    var $table = $('<div class="table-wrap"></div>');
    $main.append($filters, $table);

    function load() {
      DC.loading($table);
      DC.get('people.list', { status: state.status }).then(function (r) { people = r.people; render(); }).catch(function (e) { DC.forbiddenOr($table, e); });
    }
    function render() {
      var q = state.q.toLowerCase();
      var list = people.filter(function (p) {
        if (state.issue && (p.issues || []).indexOf(state.issue) === -1) return false;
        if (state.brand_id && (!p.brand || p.brand.id !== +state.brand_id)) return false;
        if (state.property_id && (!p.property || p.property.id !== +state.property_id)) return false;
        if (q && (p.name + ' ' + p.last_name + ' ' + p.code + ' ' + (p.job_title || '') + ' ' + (p.work_email || '')).toLowerCase().indexOf(q) === -1) return false;
        return true;
      });
      $filters.find('.count').text(DC.plural(list.length, 'record'));
      if (!list.length) { $table.html('<div class="empty"><strong>No records</strong>' + (people.length ? 'Nothing matches these filters.' : '<a href="#/admin/import">Import the employee spreadsheet</a> or add people one at a time.') + '</div>'); return; }
      var h = '<table class="table"><thead><tr><th>Person</th><th class="hide-sm">Property</th><th class="hide-sm">Supervisor</th><th>Celebrated by</th><th>Birthday</th><th class="hide-sm">Hire date</th><th>Needs</th><th></th></tr></thead><tbody>';
      list.forEach(function (p) {
        var flags = (p.issues || []).filter(function (i) { return i !== 'unverified'; });
        h += '<tr class="clickable' + (p.status === 'terminated' ? ' is-off' : '') + '" data-id="' + p.id + '">' +
          '<td><div class="person-cell">' + DC.avatar(p, 'sm') + '<div><strong>' + esc(p.name) + '</strong><span class="sub">' + esc(p.job_title || p.code) + '</span></div></div></td>' +
          '<td class="hide-sm col-w">' + (p.property ? esc(p.property.name) : '<span class="muted">—</span>') + (p.entity ? '<div class="sub">' + esc(p.entity.name) + '</div>' : '') + '</td>' +
          '<td class="hide-sm">' + (p.supervisor ? esc(p.supervisor.name) : '<span class="muted">—</span>') + '</td>' +
          '<td class="col-w">' + (p.owner && p.owner.id ? esc(p.owner.name) + '<div class="sub">' + esc(p.owner.label) + '</div>' : (p.status === 'active' ? '<span class="badge danger">None</span>' : '<span class="muted">—</span>')) + '</td>' +
          '<td class="nowrap">' + birthdayLabel(p) + '</td>' +
          '<td class="hide-sm nowrap">' + hireLabel(p) + '</td>' +
          '<td>' + (flags.length ? flags.map(function (i) { return '<span class="badge warn" style="margin:0 4px 4px 0">' + esc(S.issueLabels[i]) + '</span>'; }).join('') : (p.verified_at ? '<span class="badge ok">Verified</span>' : '<span class="muted small">—</span>')) + '</td>' +
          '<td><button type="button" class="icon-btn js-edit" aria-label="Edit ' + esc(p.name) + '">' + DC.icon('edit') + '</button></td></tr>';
      });
      $table.html(h + '</tbody></table>');
    }
    $filters.on('input', '.search', DC.debounce(function () { state.q = this.value.trim(); render(); }, 120));
    $filters.on('change', 'select', function () {
      state[this.name] = this.value;
      if (this.name === 'issue') history.replaceState(null, '', '#/admin/people' + (this.value ? '/issue/' + this.value : ''));
      if (this.name === 'status') load(); else render();
    });
    $table.on('click', '.js-edit', function (e) { e.stopPropagation(); DC.editPerson(+$(this).closest('tr').data('id')); });
    $table.on('click', 'tr.clickable', function () { DC.openPerson(+$(this).data('id')); });
    DC.onPeopleChanged(load);
    load();
  };
})(jQuery, window.DC);
