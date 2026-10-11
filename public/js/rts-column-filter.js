// Ang lohika ng filter kada column ng RTS table (/jnt_rts): ang listahan ng magkakaibang value ng isang column
// na may bilang ng row, kung pumapasa ang isang row, ang filter na lumalabas sa panel, at kung ano ang natitira
// sa mga filter kapag napalitan ang mga row. Purong function lang ang narito: walang DOM, walang DataTables,
// walang binabasang global — kaya napapatakbo rin ito ng node para sa mga test.
// Plain na script ito, hindi module: iisang pangalan lang ang inilalagay nito sa page, ang RtsColumnFilter.
//
// Isang row = listahan ng cell, isa kada column: { text, key }. Ang text ang nakikita sa table (iyon ang
// chine-check); ang key ang pinagbabatayan ng ayos at ng kondisyon: numero sa column ng bilang at porsiyento
// (null kapag N/A), simula ng range sa column ng petsa.
// Isang filter = { values, min, max }: values = mga text na naka-check (null = lahat), min/max = "at least" at
// "at most" (null = wala). Ang mga filter ay isang object na ang key ay ang index ng column.
(function (root) {
  'use strict';

  function list(v) { return Array.isArray(v) ? v : []; }

  function own(o, k) { return Object.prototype.hasOwnProperty.call(o, k); }

  // Ang tinatype ng tao sa kondisyon: "20", "20%", "1,500.5". Blangko o hindi numero = walang kondisyon.
  function parseNumber(v) {
    if (typeof v === 'number') return isFinite(v) ? v : null;
    if (typeof v !== 'string') return null;
    var s = v.replace(/[,%\s]/g, '');
    if (s === '') return null;
    var n = Number(s);
    return isFinite(n) ? n : null;
  }

  function cellOf(row, col) {
    var c = list(row)[col];
    return c && typeof c === 'object' ? c : { text: '', key: null };
  }

  function byText(a, b) {
    var x = a.value.toLowerCase(), y = b.value.toLowerCase();
    if (x !== y) return x < y ? -1 : 1;
    return a.value < b.value ? -1 : (a.value > b.value ? 1 : 0);
  }

  // Ang magkakaibang value ng isang column at kung ilang row ang may ganoon. type: 'text' = A hanggang Z,
  // 'number' = ayon sa halaga (hindi bilang text: 9.50% bago ang 30.00%), 'date' = ayon sa simula ng range.
  // Ang walang key (N/A) ay nasa dulo.
  function distinctValues(rows, col, type) {
    var seen = {}, out = [];
    list(rows).forEach(function (row) {
      var c = cellOf(row, col), text = String(c.text);
      if (!own(seen, text)) {
        seen[text] = { value: text, count: 0, key: c.key === undefined ? null : c.key };
        out.push(seen[text]);
      }
      seen[text].count++;
    });
    out.sort(function (a, b) {
      if (type === 'text') return byText(a, b);
      if (a.key === null || b.key === null) {
        if (a.key !== b.key) return a.key === null ? 1 : -1;
        return byText(a, b);
      }
      if (a.key !== b.key) return a.key < b.key ? -1 : 1;
      return byText(a, b);
    });
    return out.map(function (v) { return { value: v.value, count: v.count }; });
  }

  // Pumapasa ang row kapag pumapasa ito sa bawat column na may filter: naka-check ang text nito, at pasok ang
  // numero nito sa kondisyon. Ang cell na walang numero (N/A) ay hindi pumapasa sa anumang kondisyon.
  function passes(row, filters) {
    if (!filters || typeof filters !== 'object') return true;
    for (var col in filters) {
      if (!own(filters, col)) continue;
      var f = filters[col];
      if (!f) continue;
      var c = cellOf(row, col);
      if (Array.isArray(f.values) && f.values.indexOf(String(c.text)) === -1) return false;
      if (f.min !== null && f.min !== undefined && !(typeof c.key === 'number' && c.key >= f.min)) return false;
      if (f.max !== null && f.max !== undefined && !(typeof c.key === 'number' && c.key <= f.max)) return false;
    }
    return true;
  }

  // Ang mga index ng row na pumapasa.
  function matching(rows, filters) {
    var out = [];
    list(rows).forEach(function (row, i) { if (passes(row, filters)) out.push(i); });
    return out;
  }

  // Ang filter mula sa panel: listed = lahat ng value sa listahan, checked = ang mga naka-check, at ang
  // dalawang input ng kondisyon. null kapag wala itong binabawas (lahat naka-check at walang kondisyon), para
  // hindi mamarkahang may filter ang column.
  function buildFilter(listed, checked, minText, maxText) {
    var all = list(listed).map(String), on = list(checked).map(String);
    var values = all.filter(function (v) { return on.indexOf(v) !== -1; });
    var min = parseNumber(minText), max = parseNumber(maxText);
    if (values.length === all.length) values = null;
    if (values === null && min === null && max === null) return null;
    return { values: values, min: min, max: max };
  }

  // Pagkatapos mapalitan ang mga row (bagong date range): natitira ang mga napiling value na mayroon pa rin,
  // at ang kondisyon. Ang filter na wala nang natirang value at walang kondisyon ay tinatanggal. Ang saved ay
  // galing sa storage ng browser, kaya hindi pinagkakatiwalaan ang hugis nito: ang sira ay tahimik na nilalaktawan.
  function reconcile(saved, rows) {
    var out = {};
    if (!saved || typeof saved !== 'object' || Array.isArray(saved)) return out;
    var all = list(rows), width = all.length ? list(all[0]).length : 0;
    Object.keys(saved).forEach(function (col) {
      var f = saved[col], i = Number(col);
      if (!f || typeof f !== 'object' || !(i >= 0 && i < width) || String(i) !== col) return;
      var values = null;
      if (Array.isArray(f.values)) {
        var have = {};
        all.forEach(function (row) { have[String(cellOf(row, i).text)] = true; });
        values = f.values.filter(function (v) { return typeof v === 'string' && own(have, v); });
        if (!values.length) values = null;
      }
      var min = typeof f.min === 'number' && isFinite(f.min) ? f.min : null;
      var max = typeof f.max === 'number' && isFinite(f.max) ? f.max : null;
      if (values === null && min === null && max === null) return;
      out[col] = { values: values, min: min, max: max };
    });
    return out;
  }

  root.RtsColumnFilter = {
    parseNumber: parseNumber,
    distinctValues: distinctValues,
    passes: passes,
    matching: matching,
    buildFilter: buildFilter,
    reconcile: reconcile,
  };
})(typeof self !== 'undefined' ? self : globalThis);
