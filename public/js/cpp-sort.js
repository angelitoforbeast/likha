// Pag-sort ng mga table ng /ads_manager/cpp sa pag-click (o Enter / Space) ng header. Ang numero ay inuuna ang pinakamataas,
// ang text ay A-Z; ang ikalawang click ay baliktad, ang ikatlo ay balik sa orihinal na order. Ang walang value (dash o
// blangko) ay laging huli, ang TOTAL (data-sort-fixed) ay laging nasa ibaba, at ang mga row ng ad group (cpp-ag-row) ay
// sumasama sa row ng page sa itaas nila, sa sarili nilang order. Inililipat ang mismong mga <tr>, kaya ang Copy Table
// ay kumokopya sa order na nakikita. Plain na script, hindi module: iisang pangalan ang inilalagay, ang CppSort.
(function (root) {
  'use strict';

  var DASH = '\u2014';

  function list(x) { return Array.prototype.slice.call(x); }

  // Ang value ng cell: ang data-sort kapag meron (ISO na petsa -> numerong yyyymmdd), kung wala ang text na walang piso
  // at %. Ang kuwit ay inaalis lang kapag pang-libo ("1,200.00"); ang "\u20B1499, \u20B1599" ay listahan, kaya text.
  function key(text, raw) {
    var s = String(raw != null ? raw : text == null ? '' : text).trim();
    if (s === '' || s === DASH) return null;
    if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return Number(s.replace(/-/g, ''));
    var n = s.replace(/[\u20B1%]/g, '').trim();
    if (/^-?(\d{1,3}(,\d{3})+|\d+)(\.\d+)?$/.test(n)) return Number(n.replace(/,/g, ''));
    return s;
  }

  // Numero ang column kapag numero ang lahat ng may value.
  function numeric(keys) {
    var seen = false;
    for (var i = 0; i < keys.length; i++) {
      if (keys[i] == null) continue;
      if (typeof keys[i] !== 'number') return false;
      seen = true;
    }
    return seen;
  }

  // Ang susunod na sort ng table pagka-click sa column: numero desc -> asc -> wala, text asc -> desc -> wala.
  function next(state, col, isNumber) {
    var first = isNumber ? 'desc' : 'asc';
    if (!state || state.col !== col || !state.dir) return {col: col, dir: first};
    if (state.dir === first) return {col: col, dir: first === 'desc' ? 'asc' : 'desc'};
    return {col: null, dir: null};
  }

  // Ang order ng mga row (index) para sa sort na ito. rows: [{keys, child, fixed}] sa orihinal na order.
  function order(rows, col, dir) {
    var blocks = [], fixed = [];
    rows.forEach(function (r, i) {
      if (r.fixed) fixed.push(i);
      else if (r.child && blocks.length) blocks[blocks.length - 1].rows.push(i);
      else blocks.push({key: col == null ? null : r.keys[col], rows: [i], at: blocks.length});
    });
    if (dir) {
      var isNumber = numeric(blocks.map(function (b) { return b.key; }));
      blocks.sort(function (a, b) {
        if (a.key == null || b.key == null) return ((a.key == null) - (b.key == null)) || a.at - b.at;
        var c = isNumber ? a.key - b.key : String(a.key).localeCompare(String(b.key), undefined, {numeric: true, sensitivity: 'base'});
        return (dir === 'desc' ? -c : c) || a.at - b.at;
      });
    }
    var out = [];
    blocks.forEach(function (b) { out.push.apply(out, b.rows); });
    return out.concat(fixed);
  }

  // Ikinakabit sa table na kaguguhit lang: ang orihinal na order ay ang order ng pagguhit, at ang sort na nasa
  // states[name] (iisang object sa buong pagbisita) ay inilalapat agad, kaya hindi nawawala sa re-render.
  function attach(table, states, name) {
    if (!table) return;
    var head = table.querySelector('thead'), body = table.querySelector('tbody');
    if (!head || !body) return;
    var ths = list(head.querySelectorAll('th')), trs = list(body.children);
    var rows = trs.map(function (tr) {
      return {
        keys: list(tr.children).map(function (td) { return key(td.textContent, td.getAttribute('data-sort')); }),
        child: tr.classList.contains('cpp-ag-row'),
        fixed: tr.hasAttribute('data-sort-fixed'),
      };
    });
    var sortable = rows.filter(function (r) { return !r.child && !r.fixed; });

    function paint() {
      var s = states[name] || {col: null, dir: null};
      order(rows, s.col, s.dir).forEach(function (i) { body.append(trs[i]); });
      ths.forEach(function (th, c) {
        if (c === s.col && s.dir) th.setAttribute('aria-sort', s.dir === 'desc' ? 'descending' : 'ascending');
        else th.removeAttribute('aria-sort');
      });
    }

    ths.forEach(function (th, c) {
      th.setAttribute('tabindex', '0');
      th.style.cursor = 'pointer';
      function go() {
        states[name] = next(states[name], c, numeric(sortable.map(function (r) { return r.keys[c]; })));
        paint();
      }
      th.addEventListener('click', go);
      th.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
        e.preventDefault();
        go();
      });
    });
    paint();
  }

  // Para sa table na iginuguhit ng Alpine x-for (ang snapshot popup ng timeline): ang data ang sinosort, hindi ang mga
  // <tr>, dahil ang x-for ang may-ari ng order ng DOM. fields: ang pangalan ng value ng bawat column, sa order ng header.
  function sortItems(items, fields, state) {
    var s = state || {col: null, dir: null};
    var rows = items.map(function (it) { return {keys: fields.map(function (f) { return key(it[f]); })}; });
    return order(rows, s.col, s.dir).map(function (i) { return items[i]; });
  }

  function nextFor(state, items, fields, col) { return next(state, col, numeric(items.map(function (it) { return key(it[fields[col]]); }))); }

  // Ang aria-sort ng header (null = walang attribute), kung saan galing ang ▲ o ▼.
  function aria(state, col) {
    if (!state || state.col !== col || !state.dir) return null;
    return state.dir === 'desc' ? 'descending' : 'ascending';
  }

  root.CppSort = {key: key, numeric: numeric, next: next, order: order, attach: attach, sortItems: sortItems, nextFor: nextFor, aria: aria};
})(typeof self !== 'undefined' ? self : this);
