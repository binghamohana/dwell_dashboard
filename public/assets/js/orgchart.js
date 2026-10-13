/* Dwell Central — interactive organizational chart (d3 tree layout + pan/zoom).
   Every person appears once: at their primary property in the company/property views, under
   their supervisor in the reporting view. Additional assignments show in the profile panel. */
(function ($, DC, d3) {
  'use strict';

  var S = DC.S;
  var esc = DC.esc;
  var CARD = { w: 264, h: 78 };
  var GROUP = { w: 248, h: 64 };
  var GAP_X = 74;
  var GAP_Y = 16;

  DC.views.org = function ($main, sub, params) {
    $main.addClass('flush');
    var view = ['companies', 'properties', 'reporting'].indexOf(sub[0]) !== -1 ? sub[0] : DC.store.get('org.view', 'companies');
    var filters = { brand_id: '', property_id: '', department_id: '' };
    var people = [];
    var byId = {};
    var root = null;
    var focusId = params.focus ? +params.focus : null;

    var L = S.lists;
    var $page = $('<div class="org-page"></div>');
    var $bar = $('<div class="org-toolbar">' +
      '<h1>Org chart</h1>' +
      '<div class="seg" role="group" aria-label="View"><button type="button" data-view="companies">Companies</button><button type="button" data-view="properties">Properties</button><button type="button" data-view="reporting">Reporting lines</button></div>' +
      '<div class="org-search">' + DC.icon('search') + '<input class="input" type="search" placeholder="Find a person" aria-label="Find a person" autocomplete="off"><div class="org-results" hidden></div></div>' +
      '<select class="select" name="brand_id" aria-label="Company">' + DC.options(L.brands, '', 'All companies') + '</select>' +
      '<select class="select" name="property_id" aria-label="Property">' + DC.options(L.properties, '', 'All properties') + '</select>' +
      '<select class="select" name="department_id" aria-label="Department">' + DC.options(L.departments, '', 'All departments') + '</select>' +
      '</div>');
    var $stage = $('<div class="org-stage"></div>');
    var $controls = $('<div class="org-controls">' +
      '<button type="button" class="icon-btn" data-act="in" aria-label="Zoom in" title="Zoom in">' + DC.icon('zoomin') + '</button>' +
      '<button type="button" class="icon-btn" data-act="out" aria-label="Zoom out" title="Zoom out">' + DC.icon('zoomout') + '</button>' +
      '<button type="button" class="icon-btn" data-act="fit" aria-label="Fit to screen" title="Fit to screen">' + DC.icon('fit') + '</button>' +
      '<button type="button" class="icon-btn" data-act="expand" aria-label="Expand all" title="Expand all">' + DC.icon('expand') + '</button>' +
      '<button type="button" class="icon-btn" data-act="collapse" aria-label="Collapse all" title="Collapse all">' + DC.icon('collapse') + '</button></div>');
    var $legend = $('<div class="org-legend"></div>');
    var $hint = $('<div class="org-hint" hidden></div>');
    $stage.append($controls, $legend, $hint);
    $page.append($bar, $stage);
    $main.append($page);

    var svg = d3.select($stage[0]).insert('svg', ':first-child').attr('role', 'img').attr('aria-label', 'Organizational chart');
    var defs = svg.append('defs');
    var g = svg.append('g');
    var gLinks = g.append('g');
    var gNodes = g.append('g');
    var zoom = d3.zoom().scaleExtent([0.15, 2.2]).on('zoom', function (e) { g.attr('transform', e.transform); });
    svg.call(zoom).on('dblclick.zoom', null);

    function setView(v) {
      view = v;
      DC.store.set('org.view', v);
      $bar.find('[data-view]').removeClass('is-on').filter('[data-view=' + v + ']').addClass('is-on');
      history.replaceState(null, '', '#/org/' + v);
      build(true);
    }

    function load() {
      $hint.prop('hidden', false).html('<div class="alert alert-info"><span class="spinner"></span> Loading…</div>');
      DC.get('people.list', { status: 'current' }).then(function (r) {
        people = r.people;
        byId = {};
        people.forEach(function (p) { byId[p.id] = p; });
        setView(view);
        if (focusId && byId[focusId]) setTimeout(function () { reveal(focusId, true); }, 380);
      }).catch(function (e) { DC.forbiddenOr($stage, e); });
    }

    // ------------------------------------------------------------ hierarchy

    function visiblePeople() {
      return people.filter(function (p) {
        if (filters.brand_id && (!p.brand || p.brand.id !== +filters.brand_id)) return false;
        if (filters.property_id && (!p.property || p.property.id !== +filters.property_id)) return false;
        if (filters.department_id && (!p.department || p.department.id !== +filters.department_id)) return false;
        return true;
      });
    }

    /** Nest people by supervisor within a group (people whose supervisor is elsewhere sit at the top). */
    function nest(list) {
      var inGroup = {};
      list.forEach(function (p) { inGroup[p.id] = { type: 'person', id: 'p' + p.id, person: p, children: [] }; });
      var tops = [];
      list.forEach(function (p) {
        var sup = p.supervisor && p.supervisor.id;
        if (sup && inGroup[sup] && sup !== p.id) inGroup[sup].children.push(inGroup[p.id]);
        else tops.push(inGroup[p.id]);
      });
      // Break accidental cycles: anything never reached from a top becomes a top.
      var seen = {};
      (function walk(nodes) { nodes.forEach(function (n) { if (!seen[n.id]) { seen[n.id] = true; walk(n.children); } }); })(tops);
      list.forEach(function (p) { if (!seen['p' + p.id]) { inGroup[p.id].children = []; tops.push(inGroup[p.id]); } });
      sortNodes(tops);
      return tops;
    }
    function sortNodes(nodes) {
      nodes.sort(function (a, b) {
        return (b.children.length > 0) - (a.children.length > 0) || a.person.last_name.localeCompare(b.person.last_name);
      });
      nodes.forEach(function (n) { if (n.children.length) sortNodes(n.children); });
    }

    function propertyBrand(propId, list) {
      var prop = DC.byId(L.properties, propId);
      if (prop && prop.brand_id) return prop.brand_id;
      var count = {};
      list.forEach(function (p) { if (p.brand) count[p.brand.id] = (count[p.brand.id] || 0) + 1; });
      var best = null;
      Object.keys(count).forEach(function (k) { if (best === null || count[k] > count[best]) best = k; });
      return best === null ? null : +best;
    }

    function groupNode(id, name, sub, color, kind, children) {
      return { type: kind, id: id, name: name, sub: sub, color: color, children: children };
    }

    function buildData() {
      var list = visiblePeople();
      var data;
      $hint.prop('hidden', true);
      if (view === 'reporting') {
        var tops = nest(list);
        var connected = tops.filter(function (n) { return n.children.length || (n.person.supervisor && n.person.supervisor.id); });
        var loose = tops.filter(function (n) { return connected.indexOf(n) === -1; });
        var kids = connected.slice();
        if (loose.length) {
          var byProp = {};
          loose.forEach(function (n) { var k = n.person.property ? n.person.property.name : 'No property'; (byProp[k] = byProp[k] || []).push(n); });
          var looseKids = Object.keys(byProp).sort().map(function (k) {
            return groupNode('loose:' + k, k, DC.plural(byProp[k].length, 'person', 'people'), '#747F89', 'sub', byProp[k]);
          });
          kids.push(groupNode('loose', 'No reporting line yet', DC.plural(loose.length, 'person', 'people') + ' without a supervisor', '#747F89', 'group', looseKids));
          if (!connected.length) {
            $hint.prop('hidden', false).html('<div class="alert alert-info">' + DC.icon('info') + '<span>No supervisors are recorded yet, so everyone is grouped by property. ' +
              (DC.can('people.edit') ? 'Add supervisors in <a href="#/admin/people">People records</a> or import the org chart.' : '') + '</span></div>');
          }
        }
        data = { type: 'root', id: 'root', name: S.org, sub: DC.plural(list.length, 'person', 'people'), children: kids };
      } else {
        var props = {};
        list.forEach(function (p) { var k = p.property ? p.property.id : 0; (props[k] = props[k] || []).push(p); });
        var propNodes = Object.keys(props).map(function (k) {
          var prop = DC.byId(L.properties, +k);
          var kind = prop ? ({ office: 'Office', team: 'Team', property: 'Property', other: '' }[prop.kind] || '') : '';
          return {
            brand: propertyBrand(+k, props[k]),
            node: groupNode('prop:' + k, prop ? prop.name : 'No property assigned', (kind ? kind + ' · ' : '') + DC.plural(props[k].length, 'person', 'people'), '#2E5A72', 'sub', nest(props[k])),
            sort: (prop && prop.kind === 'office' ? 0 : 1) + (prop ? prop.name : 'zzz')
          };
        }).sort(function (a, b) { return a.sort.localeCompare(b.sort); });
        if (view === 'properties') {
          data = { type: 'root', id: 'root', name: S.org, sub: DC.plural(propNodes.length, 'location') + ' · ' + DC.plural(list.length, 'person', 'people'), children: propNodes.map(function (x) { return x.node; }) };
        } else {
          var brands = {};
          propNodes.forEach(function (x) { var k = x.brand || 0; (brands[k] = brands[k] || []).push(x.node); });
          var brandNodes = Object.keys(brands).map(function (k) {
            var b = DC.byId(L.brands, +k);
            var count = brands[k].reduce(function (s, n) { return s + countPeople(n); }, 0);
            brands[k].forEach(function (n) { n.color = b ? b.color : '#747F89'; });
            return groupNode('brand:' + k, b ? b.name : 'No company assigned', DC.plural(brands[k].length, 'location') + ' · ' + DC.plural(count, 'person', 'people'), b ? b.color : '#747F89', 'group', brands[k]);
          }).sort(function (a, b) { return (a.id === 'brand:0') - (b.id === 'brand:0') || a.name.localeCompare(b.name); });
          data = { type: 'root', id: 'root', name: S.org, sub: DC.plural(list.length, 'person', 'people'), children: brandNodes };
        }
      }
      return data;
    }
    function countPeople(n) { return (n.type === 'person' ? 1 : 0) + (n.children || []).reduce(function (s, c) { return s + countPeople(c); }, 0); }

    function build(fit) {
      var data = buildData();
      root = d3.hierarchy(data, function (d) { return d.children && d.children.length ? d.children : null; });
      root.x0 = 0; root.y0 = 0;
      // Start: companies and properties visible, people folded away (click to open).
      root.descendants().forEach(function (d) {
        d._children = d.children;
        var open = d.depth === 0
          || (view === 'companies' && d.data.type === 'group')
          || (view === 'reporting' && d.depth === 1);
        if (!open) d.children = null;
      });
      renderLegend();
      update(root, fit ? 0 : 250);
      if (fit) fitView(0);
    }

    function renderLegend() {
      if (view === 'reporting') { $legend.html('<span>Click a person to see their profile · click the round button to show their team</span>'); return; }
      $legend.html(L.brands.map(function (b) { return '<span><i style="--c:' + esc(b.color) + '"></i>' + esc(b.name) + '</span>'; }).join('') + '<span class="muted">Click a company or property to expand</span>');
    }

    // ------------------------------------------------------------ drawing

    function size(d) { return d.data.type === 'person' ? CARD : GROUP; }

    function update(source, duration) {
      var layout = d3.tree().nodeSize([CARD.h + GAP_Y, CARD.w + GAP_X]).separation(function (a, b) { return a.parent === b.parent ? 1 : 1.12; });
      layout(root);
      var nodes = root.descendants();
      var links = root.links();
      var t = svg.transition().duration(duration);

      var node = gNodes.selectAll('g.oc-node').data(nodes, function (d) { return d.data.id; });
      var enter = node.enter().append('g')
        .attr('class', function (d) { return 'oc-node oc-' + d.data.type; })
        .attr('transform', function () { return 'translate(' + (source.y0 || 0) + ',' + (source.x0 || 0) + ')'; })
        .attr('tabindex', 0).attr('role', 'button')
        .attr('aria-label', function (d) { return d.data.type === 'person' ? d.data.person.name + (d.data.person.job_title ? ', ' + d.data.person.job_title : '') : d.data.name; })
        .style('opacity', 0)
        .on('click', function (e, d) { e.stopPropagation(); activate(d); })
        .on('keydown', function (e, d) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); activate(d); } });
      enter.each(function (d) { drawNode(d3.select(this), d); });

      var merged = enter.merge(node);
      merged.transition(t).style('opacity', 1).attr('transform', function (d) { return 'translate(' + d.y + ',' + (d.x - size(d).h / 2) + ')'; });
      merged.each(function (d) { updateToggle(d3.select(this), d); });
      node.exit().transition(t).style('opacity', 0).attr('transform', function () { return 'translate(' + source.y + ',' + (source.x - CARD.h / 2) + ')'; }).remove();

      var link = gLinks.selectAll('path.oc-link').data(links, function (d) { return d.target.data.id; });
      var diagonal = function (s, tt) {
        var sx = s.y + size(s).w, sy = s.x, tx = tt.y, ty = tt.x, mx = (sx + tx) / 2;
        return 'M' + sx + ',' + sy + 'C' + mx + ',' + sy + ' ' + mx + ',' + ty + ' ' + tx + ',' + ty;
      };
      link.enter().insert('path').attr('class', 'oc-link')
        .attr('d', function () { var o = { x: source.x0 || 0, y: source.y0 || 0, data: source.data }; return diagonal(o, o); })
        .merge(link).transition(t).attr('d', function (d) { return diagonal(d.source, d.target); });
      link.exit().transition(t).attr('d', function () { var o = { x: source.x, y: source.y, data: source.data }; return diagonal(o, o); }).remove();

      nodes.forEach(function (d) { d.x0 = d.x; d.y0 = d.y; });
    }

    function truncate(textSel, max) {
      var el = textSel.node();
      var full = textSel.text();
      if (!el || el.getComputedTextLength() <= max) return;
      textSel.append('title').text(full);
      var lo = 0, hi = full.length;
      while (lo < hi) {
        var mid = Math.ceil((lo + hi) / 2);
        el.firstChild.nodeValue = full.slice(0, mid) + '…';
        if (el.getComputedTextLength() <= max) lo = mid; else hi = mid - 1;
      }
      el.firstChild.nodeValue = full.slice(0, lo) + '…';
    }

    var clipN = 0;
    function drawNode(sel, d) {
      var s = size(d);
      if (d.data.type === 'person') {
        var p = d.data.person;
        var color = DC.brandColor(p);
        sel.style('--c', color);
        sel.append('rect').attr('class', 'oc-card').attr('width', s.w).attr('height', s.h).attr('rx', 14);
        sel.append('rect').attr('class', 'oc-band').attr('x', 0).attr('y', 14).attr('width', 3).attr('height', s.h - 28).attr('rx', 1.5).style('fill', color);
        var cx = 34, cy = s.h / 2, r = 21;
        sel.append('circle').attr('class', 'oc-avatar-bg').attr('cx', cx).attr('cy', cy).attr('r', r).style('fill', color);
        if (p.photo) {
          var id = 'occlip' + (++clipN);
          defs.append('clipPath').attr('id', id).append('circle').attr('cx', cx).attr('cy', cy).attr('r', r);
          sel.append('image').attr('href', p.photo).attr('x', cx - r).attr('y', cy - r).attr('width', r * 2).attr('height', r * 2).attr('clip-path', 'url(#' + id + ')').attr('preserveAspectRatio', 'xMidYMid slice');
        } else {
          sel.append('text').attr('class', 'oc-initials').attr('x', cx).attr('y', cy).style('fill', color).text(p.initials);
        }
        var tx = 64, maxW = s.w - tx - 14;
        truncate(sel.append('text').attr('class', 'oc-name').attr('x', tx).attr('y', 22).text(p.name), maxW);
        truncate(sel.append('text').attr('class', 'oc-title').attr('x', tx).attr('y', 39).text(p.job_title || 'No title recorded'), maxW);
        var meta = [p.property ? p.property.name : null, p.department ? p.department.name : null].filter(Boolean).join(' · ') || '—';
        truncate(sel.append('text').attr('class', 'oc-meta').attr('x', tx).attr('y', 55).text(meta), maxW);
        if (view !== 'reporting') {
          truncate(sel.append('text').attr('class', 'oc-meta').attr('x', tx).attr('y', 70).text(p.supervisor ? 'Reports to ' + p.supervisor.name : 'No supervisor recorded'), maxW);
        } else if (p.status === 'leave') {
          sel.append('text').attr('class', 'oc-meta').attr('x', tx).attr('y', 70).text('On leave');
        }
      } else {
        var c = d.data.color || '#2E5A72';
        sel.style('--c', c);
        sel.append('rect').attr('class', 'oc-card').attr('width', s.w).attr('height', s.h).attr('rx', d.data.type === 'root' ? 32 : 14);
        if (d.data.type === 'sub') sel.append('rect').attr('class', 'oc-band').attr('x', 0).attr('y', 12).attr('width', 4).attr('height', s.h - 24).attr('rx', 2).style('fill', c);
        truncate(sel.append('text').attr('class', 'oc-name').attr('x', 20).attr('y', d.data.type === 'root' ? 31 : 28).text(d.data.name), s.w - 60);
        truncate(sel.append('text').attr('class', 'oc-meta').attr('x', 20).attr('y', d.data.type === 'root' ? 49 : 46).text(d.data.sub || ''), s.w - 60);
      }
      var tg = sel.append('g').attr('class', 'oc-toggle').attr('transform', 'translate(' + s.w + ',' + (s.h / 2) + ')')
        .on('click', function (e) { e.stopPropagation(); toggle(d); });
      tg.append('circle').attr('r', 12);
      tg.append('text');
    }

    function updateToggle(sel, d) {
      var kids = d._children ? d._children.length : 0;
      var tg = sel.select('.oc-toggle');
      tg.style('display', kids ? null : 'none');
      // Collapsed: how many people are inside (not just the first level)
      var inside = kids ? d._children.reduce(function (s, c) { return s + countPeople(c.data); }, 0) : 0;
      tg.select('text').text(d.children ? '–' : String(inside || kids));
    }

    function activate(d) {
      if (d.data.type === 'person') DC.openPerson(d.data.person.id);
      else toggle(d);
    }

    function toggle(d) {
      if (!d._children) return;
      d.children = d.children ? null : d._children;
      update(d, 280);
    }

    /** Every node, including those inside collapsed branches (d3's descendants() skips them). */
    function allNodes() {
      var out = [];
      (function walk(d) { out.push(d); (d._children || d.children || []).forEach(walk); })(root);
      return out;
    }

    function setAll(open) {
      allNodes().forEach(function (d) { if (d._children) d.children = open ? d._children : null; });
      if (!open) { root.children = root._children; if (view === 'companies') root.children && root.children.forEach(function (c) { c.children = c._children; }); }
      update(root, 280);
      setTimeout(function () { fitView(400); }, 300);
    }

    function fitView(duration) {
      var nodes = root.descendants().filter(function (d) { return isShown(d); });
      if (!nodes.length) return;
      var minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
      nodes.forEach(function (d) {
        var s = size(d);
        minX = Math.min(minX, d.y); maxX = Math.max(maxX, d.y + s.w + 14);
        minY = Math.min(minY, d.x - s.h / 2); maxY = Math.max(maxY, d.x + s.h / 2);
      });
      var w = $stage.width(), h = $stage.height();
      var scale = Math.min(1, 0.92 * Math.min(w / (maxX - minX || 1), h / (maxY - minY || 1)));
      scale = Math.max(scale, 0.3);
      var tx = (w - (maxX - minX) * scale) / 2 - minX * scale;
      var ty = (h - (maxY - minY) * scale) / 2 - minY * scale;
      if ((maxX - minX) * scale > w) tx = 24 - minX * scale; // wide trees start at the left
      svg.transition().duration(duration || 0).call(zoom.transform, d3.zoomIdentity.translate(tx, ty).scale(scale));
    }
    function isShown(d) { for (var a = d.parent; a; a = a.parent) { if (!a.children) return false; } return true; }

    /** Open the branches above a person, centre them and highlight their card. */
    function reveal(personId, open) {
      var target = null;
      allNodes().forEach(function (d) { if (d.data.type === 'person' && d.data.person.id === personId) target = d; });
      if (!target) { DC.toast('That person isn’t shown with the current filters.'); return; }
      for (var a = target.parent; a; a = a.parent) { if (!a.children && a._children) a.children = a._children; }
      update(root, 280);
      setTimeout(function () {
        var k = Math.max(d3.zoomTransform(svg.node()).k, 0.9);
        var w = $stage.width(), h = $stage.height();
        svg.transition().duration(450).call(zoom.transform, d3.zoomIdentity.translate(w / 2 - (target.y + CARD.w / 2) * k, h / 2 - target.x * k).scale(k));
        gNodes.selectAll('g.oc-node').classed('is-hit', function (d) { return d === target; });
        setTimeout(function () { gNodes.selectAll('g.oc-node').classed('is-hit', false); }, 4000);
        if (open) DC.openPerson(personId);
      }, 300);
    }

    // ------------------------------------------------------------ toolbar

    $bar.on('click', '[data-view]', function () { setView($(this).data('view')); });
    $bar.on('change', 'select', function () { filters[this.name] = this.value; build(true); });
    $controls.on('click', '[data-act]', function () {
      var act = $(this).data('act');
      if (act === 'in') svg.transition().duration(250).call(zoom.scaleBy, 1.25);
      if (act === 'out') svg.transition().duration(250).call(zoom.scaleBy, 0.8);
      if (act === 'fit') fitView(400);
      if (act === 'expand') setAll(true);
      if (act === 'collapse') setAll(false);
    });

    var $search = $bar.find('.org-search input');
    var $results = $bar.find('.org-results');
    var hits = [];
    var active = -1;
    function showResults() {
      var q = $search.val().trim().toLowerCase();
      if (!q) { $results.prop('hidden', true); return; }
      hits = people.filter(function (p) { return (p.name + ' ' + p.first_name + ' ' + p.last_name + ' ' + (p.job_title || '')).toLowerCase().indexOf(q) !== -1; }).slice(0, 8);
      active = hits.length ? 0 : -1;
      $results.html(hits.length ? hits.map(function (p, i) {
        return '<button type="button" data-id="' + p.id + '" class="' + (i === 0 ? 'is-active' : '') + '">' + DC.avatar(p, 'sm') + '<span><strong>' + esc(p.name) + '</strong><span>' + esc([p.job_title, p.property && p.property.name].filter(Boolean).join(' · ')) + '</span></span></button>';
      }).join('') : '<div class="empty" style="padding:12px">No one found</div>').prop('hidden', false);
    }
    function pick(id) { $results.prop('hidden', true); $search.val('').trigger('blur'); reveal(id, false); }
    $search.on('input', showResults).on('keydown', function (e) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        active = Math.max(0, Math.min(hits.length - 1, active + (e.key === 'ArrowDown' ? 1 : -1)));
        $results.find('button').removeClass('is-active').eq(active).addClass('is-active');
      } else if (e.key === 'Enter' && hits[active]) { e.preventDefault(); pick(hits[active].id); }
      else if (e.key === 'Escape') { $results.prop('hidden', true); }
    });
    $results.on('click', 'button', function () { pick(+$(this).data('id')); });
    $(document).on('mousedown.orgsearch', function (e) { if (!$(e.target).closest('.org-search').length) $results.prop('hidden', true); });

    DC.onPeopleChanged(load);
    load();
  };
})(jQuery, window.DC, window.d3);
