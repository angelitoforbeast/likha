// Ang lohika ng item table na may suppliers na puwedeng magkamali nang walang nakakakita: ang mga column ng
// supplier, ang laman ng bawat cell, at ang babala ng item na walang supplier. Purong function lang ang narito:
// walang DOM, walang Alpine, walang binabasang global — kaya napapatakbo rin ito ng node para sa mga test.
// Plain na script ito, hindi module: iisang pangalan lang ang inilalagay nito sa page, ang ItemTableFit.
(function (root) {
  'use strict';

  // Ang id ng supplier ay number sa isang sagot ng server at string sa iba: laging number ang paghahambing.
  function idOf(v) {
    var n = Number(v);
    return (v === null || v === undefined || v === '' || !isFinite(n)) ? null : n;
  }

  function list(v) { return Array.isArray(v) ? v : []; }

  // ₱1,234.50 — sariling formatter para pareho ang labas sa browser at sa node.
  function peso(v) {
    var n = Number(v);
    if (!isFinite(n)) n = 0;
    var parts = Math.abs(n).toFixed(2).split('.');
    var whole = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return (n < 0 ? '−' : '') + '₱' + whole + '.' + parts[1];
  }

  // Isang column kada supplier, ayon sa id (ang pagkakasunod ng pagkakadagdag sa kanila), may bilang na 1, 2, 3…
  // Ang bilang ay ang puwesto, hindi ang id. Kopya ang inaayos: ang listahan ng form ay nananatiling alphabetical.
  function supplierColumns(rows) {
    var known = [];
    list(rows).forEach(function (r) {
      var id = idOf(r && r.id);
      if (id !== null) known.push({ id: id, name: r.name });
    });
    known.sort(function (a, b) { return a.id - b.id; });
    return known.map(function (r, i) {
      var full = String(r.name === null || r.name === undefined ? '' : r.name).trim();
      var short = full.split(/\s+/)[0] || '';
      var pos = i + 1;
      return { pos: pos, id: r.id, short: short, full: full, header: short ? pos + ' · ' + short : String(pos) };
    });
  }

  // Ang grupo ng supplier ay hindi kailanman zero ang lapad: kapag walang supplier, isang column pa rin ito.
  function groupSpan(count) {
    var n = Math.floor(Number(count));
    return isFinite(n) && n > 1 ? n : 1;
  }

  // Ang cell ng isang supplier para sa isang item: ang quote niya (kung mayroon), kung hindi ang huli niyang PO,
  // kung hindi blangko. Ang "cheapest" ay ang marka ng server; walang paghahambing ng presyo rito, at ang cost
  // ng PO ay hindi kailanman minamarkahan.
  function supplierCell(quotes, poRows, supplierId) {
    var sid = idOf(supplierId);
    var quote = null, po = null;
    list(quotes).forEach(function (q) {
      if (quote === null && q && sid !== null && idOf(q.supplier_id) === sid) quote = q;
    });
    list(poRows).forEach(function (p) {
      if (po === null && p && sid !== null && idOf(p.supplier_id) === sid && Number(p.unit_cost) > 0) po = p;
    });

    var cell = {
      kind: quote ? 'quote' : (po ? 'po' : 'empty'),
      quote: quote, price: null, moq: null, priceText: '', moqText: '',
      cheapest: false, poDot: false, poTag: false, poLine: '',
    };
    if (po) {
      cell.poLine = 'Last PO ' + peso(po.unit_cost) + (po.order_date ? ', ' + po.order_date : '') + (po.order_no ? ', ' + po.order_no : '');
    }
    if (quote) {
      var hasPrice = !(quote.price === null || quote.price === undefined || quote.price === '');
      cell.price = hasPrice ? Number(quote.price) : null;
      cell.priceText = hasPrice ? peso(quote.price) : '—';
      // Ang MOQ na 0 ay value pa rin: null / undefined lang ang wala.
      cell.moq = (quote.moq === null || quote.moq === undefined) ? null : quote.moq;
      cell.moqText = cell.moq === null ? '' : 'MOQ ' + cell.moq;
      cell.cheapest = quote.cheapest === true;
      cell.poDot = po !== null;
    } else if (po) {
      cell.price = Number(po.unit_cost);
      cell.priceText = peso(po.unit_cost);
      cell.poTag = true;
    }
    return cell;
  }

  // Babala lang kapag walang kahit isang quote at walang kahit isang PO ang item — kapareho ng bilang ng
  // "Need a supplier". Ang quote ng supplier na wala na sa listahan ay quote pa rin.
  function noSupplier(quotes, poRows) {
    return list(quotes).length === 0 && list(poRows).length === 0;
  }

  // Ang laman ng form na binuksan mula sa isang column. Ang supplier ay laging ang sa column: update-or-create
  // sa item + supplier ang save, kaya ang quote ng ibang supplier ay hindi kailanman dinadala rito.
  function formPreset(supplierId, quote) {
    var sid = idOf(supplierId);
    var own = quote && sid !== null && idOf(quote.supplier_id) === sid ? quote : null;
    var val = function (v) { return (v === null || v === undefined) ? '' : v; };
    return {
      id: own ? own.id : null,
      supplier_id: sid === null ? '' : String(sid),
      price: own ? val(own.price) : '',
      moq: own ? val(own.moq) : '',
      link: own ? val(own.link) : '',
    };
  }

  var api = {
    peso: peso,
    supplierColumns: supplierColumns,
    groupSpan: groupSpan,
    supplierCell: supplierCell,
    noSupplier: noSupplier,
    formPreset: formPreset,
  };

  root.ItemTableFit = api;
})(typeof self !== 'undefined' ? self : globalThis);
