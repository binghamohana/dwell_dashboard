/* Dwell Central — celebrations calendar (month grid and list), with filters. */
(function ($, DC) {
  'use strict';

  var S = DC.S;
  var esc = DC.esc;

  DC.views.celebrations = function ($main, sub) {
    var month = sub[0] && /^\d{4}-\d{2}$/.test(sub[0]) ? sub[0] + '-01' : DC.date.monthStart(S.today);
    var saved = DC.store.get('cal', {});
    var f = { type: '', brand_id: saved.brand_id || '', property_id: saved.property_id || '', department_id: saved.department_id || '', owner: '' };
    var layout = DC.store.get('cal.layout', DC.isMobile() ? 'list' : 'month');
    var events = [];

    $main.append(DC.pageHead('Celebrations', 'Birthdays and work anniversaries' + (DC.can('people.all') ? ' across the organization' : ' for the people you look after') + '. Birth years and ages are never shown.'));
    var L = S.lists;
    var $bar = $('<div class="cal-toolbar">' +
      '<button type="button" class="icon-btn js-prev" aria-label="Previous month">' + DC.icon('left') + '</button>' +
      '<button type="button" class="icon-btn js-next" aria-label="Next month">' + DC.icon('right') + '</button>' +
      '<h2 class="js-title"></h2>' +
      '<button type="button" class="btn btn-sm btn-ghost js-today">This month</button>' +
      '<div class="seg" role="group" aria-label="Layout"><button type="button" data-layout="month">Month</button><button type="button" data-layout="list">List</button></div>' +
      '</div>');
    var $filters = $('<div class="filters">' +
      '<select class="select" name="type" aria-label="Type"><option value="">Birthdays &amp; anniversaries</option><option value="birthday">Birthdays</option><option value="anniversary">Work anniversaries</option></select>' +
      '<select class="select" name="brand_id" aria-label="Company">' + DC.options(L.brands, f.brand_id, 'All companies') + '</select>' +
      '<select class="select" name="property_id" aria-label="Property">' + DC.options(L.properties, f.property_id, 'All properties') + '</select>' +
      '<select class="select" name="department_id" aria-label="Department">' + DC.options(L.departments, f.department_id, 'All departments') + '</select>' +
      '<label class="check" style="font-size:13.5px"><input type="checkbox" name="owner" value="missing"> Without an owner</label>' +
      '<span class="count"></span></div>');
    var $body = $('<div></div>');
    var $legend = $('<div class="legend"><span><i style="background:var(--bday-soft);box-shadow:inset 0 0 0 1px var(--bday)"></i>Birthday</span>' +
      '<span><i style="background:var(--anniv-soft);box-shadow:inset 0 0 0 1px var(--anniv)"></i>Work anniversary</span>' +
      '<span><i style="box-shadow:inset 0 0 0 1.5px var(--danger)"></i>No celebration owner</span>' +
      '<span>Faded = marked as celebrated</span></div>');
    $main.append($bar, $filters, $body, $legend);
    $bar.find('[data-layout=' + layout + ']').addClass('is-on');

    function range() {
      if (layout === 'list') return [month, DC.date.monthEnd(month)];
      var start = DC.date.addDays(month, -DC.date.weekday(month));
      var end = DC.date.monthEnd(month);
      end = DC.date.addDays(end, 6 - DC.date.weekday(end));
      return [start, end];
    }

    function load() {
      $bar.find('.js-title').text(DC.date.monthYear(month));
      history.replaceState(null, '', '#/celebrations/' + month.slice(0, 7));
      var r = range();
      DC.loading($body);
      DC.get('celebrations.range', $.extend({ from: r[0], to: r[1] }, f)).then(function (res) {
        events = res.events;
        render(r);
      }).catch(function (e) { DC.forbiddenOr($body, e); });
    }

    function chip(ev) {
      var cls = 'cal-ev ' + ev.type + (!ev.owner.id ? ' no-owner' : '') + (ev.follow_up && ev.follow_up.status === 'completed' ? ' done' : '');
      return '<button type="button" class="' + cls + '" data-key="' + esc(ev.key) + '" title="' + esc(ev.title) + '">' +
        DC.icon(ev.type === 'birthday' ? 'cake' : 'award') + '<span class="t">' + esc(ev.person.name.split(' ')[0] + ' ' + (ev.person.name.split(' ').slice(1).join(' ').charAt(0) || '') + (ev.type === 'anniversary' ? ' · ' + ev.years + 'y' : '')) + '</span></button>';
    }

    function render(r) {
      var inMonth = events.filter(function (e) { return e.date.slice(0, 7) === month.slice(0, 7); });
      $filters.find('.count').text(DC.plural(inMonth.length, 'celebration') + ' in ' + DC.date.MONTHS[+month.slice(5, 7) - 1]);
      if (layout === 'list') {
        if (!inMonth.length) { $body.html('<div class="panel"><div class="empty"><strong>No celebrations this month</strong>Only confirmed dates for active team members appear.</div></div>'); return; }
        $body.html('<div class="panel agenda"><div class="list">' + inMonth.map(DC.eventRow).join('') + '</div></div>');
        return;
      }
      var byDate = {};
      events.forEach(function (e) { (byDate[e.date] = byDate[e.date] || []).push(e); });
      var h = '<div class="cal-grid">' + ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].map(function (d) { return '<div class="cal-dow">' + d + '</div>'; }).join('');
      for (var d = r[0]; d <= r[1]; d = DC.date.addDays(d, 1)) {
        var out = d.slice(0, 7) !== month.slice(0, 7);
        h += '<div class="cal-day' + (out ? ' out' : '') + (d === S.today ? ' today' : '') + '"><span class="cal-num">' + (+d.slice(8)) + '</span>' +
          (byDate[d] || []).map(chip).join('') + '</div>';
      }
      $body.html(h + '</div>');
    }

    function find(key) { return events.filter(function (e) { return e.key === key; })[0]; }
    $body.on('click', '[data-key]', function () { var ev = find($(this).data('key')); if (ev) DC.openEvent(ev, load); });
    $bar.on('click', '.js-prev', function () { month = DC.date.addMonths(month, -1); load(); });
    $bar.on('click', '.js-next', function () { month = DC.date.addMonths(month, 1); load(); });
    $bar.on('click', '.js-today', function () { month = DC.date.monthStart(S.today); load(); });
    $bar.on('click', '[data-layout]', function () {
      layout = $(this).data('layout'); DC.store.set('cal.layout', layout);
      $bar.find('[data-layout]').removeClass('is-on'); $(this).addClass('is-on'); load();
    });
    $filters.on('change', 'select, input', function () {
      f[this.name] = this.type === 'checkbox' ? (this.checked ? this.value : '') : this.value;
      DC.store.set('cal', { brand_id: f.brand_id, property_id: f.property_id, department_id: f.department_id });
      load();
    });
    DC.onPeopleChanged(load);
    load();
  };
})(jQuery, window.DC);
