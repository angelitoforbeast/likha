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

  // ── Ang fit: aling mga column ang kasya sa lapad ng kahon ng table ──────────────────────────────────────
  // Iisang set ng pinakamaliit na lapad at iisang ayos ng pag-alis, para pare-pareho ang panuntunan sa bawat
  // laki ng screen. Ang lapad ay kada catalog id; ang tatlong RTS / DEL / INT at ang apat na Prof.% ay tig-iisang
  // column sa table (UNIT), kaya iisa rin ang lapad at puwesto nila sa ayos ng pag-alis.
  var FIT_FROM = 1280;                       // mas makitid na window: nag-i-scroll ang table gaya ng dati
  var PAGE_MIN = 168, ITEM_MIN = 96;         // ang dalawang column na hindi kailanman inaalis
  var IDENTITY_MIN = PAGE_MIN + ITEM_MIN;
  var SUPPLIER_MIN = 72;
  var PAGE_MAX = 196, ITEM_MAX = 108, SUPPLIER_MAX = 76;   // hanggang dito lang lumalapad ang mga ito sa sobrang lapad

  var UNIT = {
    jnt_rts: 'jnt_rdt', jnt_del: 'jnt_rdt', jnt_transit: 'jnt_rdt',
    proj_pct: 'prof_pct', proj_pct_7d: 'prof_pct', proj_pct_3d: 'prof_pct', proj_pct_1d: 'prof_pct',
  };
  function unitOf(id) { return Object.prototype.hasOwnProperty.call(UNIT, id) ? UNIT[id] : id; }

  var MINW = {
    promo: 72, price: 58, np_per_order_1m: 64, adspent: 76, orders_1d: 56, proj_prof_1d: 78, item_val: 66,
    item_val_ceo: 66, cpp: 56, rts_set: 66, jnt_rdt: 104, tcpr: 52, breakeven_cpp: 74, proj_profit: 80,
    prof_pct: 94, hold: 48, action: 96, stock: 52, incoming: 76, units_per_day: 58, doi: 74, order_qty: 68,
    lifecycle: 98,
    // Ang mga column na nakatago ngayon sa settings: lapad na kasya ang header at ang laman nila sa 11px.
    orders: 56, proceed: 64, pcpp: 56, per_order: 60, np_per_order: 64, np_per_order_3d: 64, np_per_order_7d: 64,
    proj_prof_3d: 78, proj_prof_7d: 78, ship: 52, cod_fee: 64, claude_action: 96, claude_reason: 120,
    ceo_action: 96, ceo_reason: 120, category: 88,
  };

  // Ang nauuna ang unang umaalis. Ang mga nakatago ngayon sa settings ay nauuna sa lahat, ayon sa catalog.
  var DROP = [
    'orders', 'proceed', 'pcpp', 'per_order', 'np_per_order', 'np_per_order_3d', 'np_per_order_7d',
    'proj_prof_3d', 'proj_prof_7d', 'ship', 'cod_fee', 'claude_action', 'claude_reason', 'ceo_action',
    'ceo_reason', 'category',
    'promo', 'rts_set', 'breakeven_cpp', 'action', 'item_val', 'price', 'jnt_rdt', 'tcpr', 'hold', 'cpp',
    'orders_1d', 'np_per_order_1m', 'adspent', 'proj_prof_1d', 'units_per_day', 'incoming', 'stock',
    'lifecycle', 'order_qty', 'doi', 'item_val_ceo', 'proj_profit', 'prof_pct',
  ];

  // Ang dalawang set: kung aling mga column ang HINDI inaalok ng bawat isa. Hindi nito ginagalaw ang ayos.
  var SETS = {
    Sourcing: ['promo', 'price', 'rts_set', 'breakeven_cpp', 'action', 'item_val'],
    Sales: ['stock', 'incoming', 'units_per_day', 'doi', 'order_qty', 'lifecycle'],
  };
  var DEFAULT_SET = 'Sourcing';

  function minWidthOf(id) {
    var u = unitOf(id);
    return Object.prototype.hasOwnProperty.call(MINW, u) ? MINW[u] : null;
  }
  function dropRankOf(id) { return DROP.indexOf(unitOf(id)); }

  // Ang set na naka-save sa browser ay hindi mapagkakatiwalaan: eksaktong pangalan lang ang tinatanggap.
  function readSet(stored) {
    return (typeof stored === 'string' && Object.prototype.hasOwnProperty.call(SETS, stored)) ? stored : DEFAULT_SET;
  }
  function setNames() { return Object.keys(SETS); }

  // Ang apat na Prof.% ay iisang column na may pagpipiliang period. `members` = ang mga period na hindi
  // nakatago sa settings (laging 1M, 7D, 3D, 1D ang ayos); kapag wala ni isa, walang column.
  var PERIODS = [
    { key: '1m', label: '1M', id: 'proj_pct', agg: 'proj_pct', page: null, sort: 'proj_pct_computed' },
    { key: '7d', label: '7D', id: 'proj_pct_7d', agg: 'proj_pct_7d', page: 'proj_pct_last_7d', sort: 'proj_pct_last_7d' },
    { key: '3d', label: '3D', id: 'proj_pct_3d', agg: 'proj_pct_3d', page: 'proj_pct_last_3d', sort: 'proj_pct_last_3d' },
    { key: '1d', label: '1D', id: 'proj_pct_1d', agg: 'proj_pct_1d', page: 'proj_pct_last_day', sort: 'proj_pct_last_day' },
  ];
  function mergeProfPct(cols) {
    var rows = list(cols);
    var ids = PERIODS.map(function (p) { return p.id; });
    var present = rows.filter(function (c) { return c && ids.indexOf(c.id) >= 0; }).map(function (c) { return c.id; });
    if (!present.length) return rows.slice();
    var out = [], placed = false;
    rows.forEach(function (c) {
      if (!c || ids.indexOf(c.id) < 0) { out.push(c); return; }
      if (placed) return;
      placed = true;
      out.push({
        id: 'prof_pct', label: 'Prof.%', sort: 'proj_pct_computed', align: c.align || 'center', minw: MINW.prof_pct,
        members: ids.filter(function (id) { return present.indexOf(id) >= 0; }),
      });
    });
    return out;
  }
  // Ang mga period na inaalok ng column, at ang period na aktibo (ang hiniling kung inaalok, kung hindi ang una).
  function periodsOf(members) {
    var m = list(members);
    return PERIODS.filter(function (p) { return m.indexOf(p.id) >= 0; }).map(function (p) { return { key: p.key, label: p.label, sort: p.sort }; });
  }
  function pickPeriod(members, wanted) {
    var offered = periodsOf(members);
    for (var i = 0; i < offered.length; i++) if (offered[i].key === wanted) return wanted;
    return offered.length ? offered[0].key : null;
  }
  // Ang catalog id ng isang period (null kapag hindi kilala): dito nakatali ang mga panuntunan ng kulay ng settings.
  function periodId(period) {
    var id = null;
    PERIODS.forEach(function (x) { if (x.key === period) id = x.id; });
    return id;
  }
  // Ang value ng Prof.% ng isang row para sa isang period. kind 'page' = row ng isang page (ang 1M nito ay
  // kinukuwenta mula sa profit at gross); kung hindi, aggregate row (item o TOTAL).
  function profPct(row, period, kind) {
    var p = null;
    PERIODS.forEach(function (x) { if (x.key === period) p = x; });
    if (!p || !row) return { value: null, text: '—', sortKey: p ? p.sort : null };
    var v;
    if (kind === 'page') {
      if (p.page === null) {
        v = (row.projected_profit !== null && row.projected_profit !== undefined && Number(row.gross_sales) > 0)
          ? Number(row.projected_profit) / Number(row.gross_sales) * 100 : null;
      } else v = row[p.page];
    } else v = row[p.agg];
    var has = !(v === null || v === undefined || v === '' || !isFinite(Number(v)));
    return { value: has ? Number(v) : null, text: has ? Number(v).toFixed(1) + '%' : '—', sortKey: p.sort };
  }

  function colMin(c) {
    var m = c && isFinite(Number(c.min)) && Number(c.min) > 0 ? Number(c.min) : minWidthOf(c && c.id);
    return m === null ? 0 : m;
  }
  function rankIn(drop, id) {
    var i = drop.indexOf(unitOf(id));
    return i;   // -1 = wala sa listahan: unang umaalis
  }

  // Ang fit. Magsimula sa lahat ng column ng set; habang lampas ang kabuuan ng pinakamaliit nilang lapad sa
  // lapad ng kahon bawas ang identity at ang mga supplier, alisin ang nauuna sa ayos ng pag-alis. Kasya ang
  // eksaktong sukat. Hindi kailanman inaalis ang identity at ang mga column ng supplier.
  function fit(input) {
    var inp = input || {};
    var cols = list(inp.cols).filter(function (c) { return c && c.id; });
    var drop = Array.isArray(inp.drop) ? inp.drop : DROP;
    var identity = isFinite(Number(inp.identityMin)) && inp.identityMin !== null && inp.identityMin !== undefined ? Number(inp.identityMin) : IDENTITY_MIN;
    var supMin = isFinite(Number(inp.supplierMin)) && inp.supplierMin !== null && inp.supplierMin !== undefined ? Number(inp.supplierMin) : SUPPLIER_MIN;
    var n = Math.max(0, Math.floor(Number(inp.suppliers)) || 0);
    var box = Math.floor(Number(inp.box)) || 0;
    var fixed = identity + n * supMin;
    var sum = function (rows) { return rows.reduce(function (a, c) { return a + colMin(c); }, 0); };
    var ids = function (rows) { return rows.map(function (c) { return c.id; }); };

    // Makitid na window: lahat ng column ng set, at nag-i-scroll gaya ng dati.
    if (!(Number(inp.win) >= FIT_FROM)) {
      var all = fixed + sum(cols);
      return { mode: 'scroll', shown: ids(cols), away: [], used: all, spare: null, scrolls: all > box };
    }
    // Ang ayos ng pag-alis: ayon sa listahan; ang magkapareho ng puwesto ay ayon sa id, para hindi nakadepende
    // sa ayos ng pagkakasulat ng mga column.
    var leaving = cols.slice().sort(function (a, b) {
      var d = rankIn(drop, a.id) - rankIn(drop, b.id);
      return d !== 0 ? d : (a.id < b.id ? -1 : (a.id > b.id ? 1 : 0));
    });
    var avail = box - fixed;
    var away = [], total = sum(cols), gone = {};
    while (total > Math.max(avail, 0) || (avail < 0 && away.length < leaving.length)) {
      if (away.length >= leaving.length) break;
      var c = leaving[away.length];
      away.push(c.id);
      gone[c.id] = true;
      total -= colMin(c);
    }
    var shown = cols.filter(function (c) { return !gone[c.id]; });
    if (avail < 0) return { mode: 'fit', shown: [], away: away, used: fixed, spare: null, scrolls: true };
    return { mode: 'fit', shown: ids(shown), away: away, used: fixed + total, spare: avail - total, scrolls: false };
  }

  function findCol(input, id) {
    var hit = null;
    list(input && input.cols).concat(list(input && input.all)).forEach(function (c) { if (hit === null && c && c.id === id) hit = c; });
    return hit || { id: id };
  }
  // Ang ayos ng pagpapakita ng lahat ng column (input.all kung ibinigay, kung hindi ang cols).
  function displayOrder(input) {
    var src = list(input && input.all).length ? list(input.all) : list(input && input.cols);
    return src.map(function (c) { return c.id; });
  }
  function inDisplayOrder(input, shown) {
    var order = displayOrder(input);
    return shown.slice().sort(function (a, b) {
      var ia = order.indexOf(a), ib = order.indexOf(b);
      return (ia < 0 ? 1e9 : ia) - (ib < 0 ? 1e9 : ib);
    });
  }

  // Ibalik ang isang column: tinatanggap lang kapag ang pinakamaliit nitong lapad ay hindi lampas sa sobrang
  // lapad. Walang ibang column na gumagalaw. Kapag tinanggihan, sinasabi ang dalawang numero.
  function turnOn(input, result, id) {
    var res = result || {};
    var shown = list(res.shown);
    if (shown.indexOf(id) >= 0) return { accepted: true, reason: '', result: res };
    var need = colMin(findCol(input, id));
    var scroll = res.mode === 'scroll';
    var free = (res.spare === null || res.spare === undefined) ? 0 : Number(res.spare);
    if (!scroll && need > free) {
      return { accepted: false, reason: 'needs ' + need + ' px, ' + free + ' px free', result: res };
    }
    return {
      accepted: true, reason: '',
      result: {
        mode: res.mode, shown: inDisplayOrder(input, shown.concat([id])),
        away: list(res.away).filter(function (x) { return x !== id; }),
        used: Number(res.used) + need, spare: scroll ? null : free - need,
        scrolls: scroll ? (Number(res.used) + need > (Math.floor(Number(input && input.box)) || 0)) : res.scrolls,
      },
    };
  }
  // Itago ang isang nakikitang column: ang lapad nito ay nagiging sobrang lapad.
  function turnOff(input, result, id) {
    var res = result || {};
    var shown = list(res.shown);
    if (shown.indexOf(id) < 0) return res;
    var need = colMin(findCol(input, id));
    return {
      mode: res.mode, shown: shown.filter(function (x) { return x !== id; }), away: list(res.away).concat([id]),
      used: Number(res.used) - need, spare: (res.spare === null || res.spare === undefined) ? null : Number(res.spare) + need,
      scrolls: res.scrolls,
    };
  }

  // Ang buong pagpapasya para sa page: ang mga column ng set, ang fit, tapos ang mga pinili sa panel (ops), isa-isa
  // at ayon sa pagkakasunod ng pagpili: ang itinago ay nagpapalaya ng lapad nito, ang ibinalik ay tinatanggap lang
  // kapag kasya. Hindi inuulit ang fit dahil sa pinili: walang ibang column na gumagalaw. `cols` = lahat ng column na puwedeng ipakita, ayon sa naka-save na ayos
  // (wala na ang nakatago sa settings; iisa na ang RTS / DEL / INT at ang Prof.%).
  function layout(state) {
    var st = state || {};
    var cols = list(st.cols).filter(function (c) { return c && c.id; });
    var set = readSet(st.set);
    var excluded = SETS[set];
    var offered = cols.filter(function (c) { return excluded.indexOf(c.id) < 0; });
    var input = { box: st.box, win: st.win, suppliers: st.suppliers, cols: offered, all: cols };
    var res = fit(input);
    var refused = [];
    list(st.ops).forEach(function (op) {
      if (!op || !cols.some(function (c) { return c.id === op.id; })) return;
      if (op.on !== true) { res = turnOff(input, res, op.id); return; }
      var t = turnOn(input, res, op.id);
      if (t.accepted) res = t.result; else refused.push({ id: op.id, reason: t.reason });
    });
    var away = cols.map(function (c) { return c.id; }).filter(function (id) { return res.shown.indexOf(id) < 0; });
    return {
      set: set, mode: res.mode, shown: res.shown, fitAway: res.away, away: away, plusN: away.length,
      used: res.used, spare: res.spare, scrolls: res.scrolls, refused: refused,
      box: Math.floor(Number(st.box)) || 0,
    };
  }

  // Ang lapad ng bawat column. Kapag nag-i-scroll: ang pinakamaliit na lapad ng bawat isa. Kapag kasya: ang
  // sobrang lapad ay napupunta muna sa PAGE, ITEM at sa mga supplier (hanggang sa lapad nila sa malapad na
  // screen), at ang natitira ay hinahati nang pantay sa mga nakikitang column — walang iisang column na lumolobo.
  function widths(result, opts) {
    var res = result || {};
    var o = opts || {};
    var n = Math.max(0, Math.floor(Number(o.suppliers)) || 0);
    var shown = list(res.shown);
    var w = { page: PAGE_MIN, item: ITEM_MIN, supplier: SUPPLIER_MIN, cols: {}, table: 0 };
    shown.forEach(function (id) { w.cols[id] = colMin(findCol({ cols: list(o.cols) }, id)); });
    var spare = (res.mode === 'fit' && !res.scrolls && res.spare !== null && res.spare !== undefined) ? Math.max(0, Math.floor(Number(res.spare))) : 0;
    var take = function (max) { var t = Math.min(spare, max); spare -= t; return t; };
    w.page += take(PAGE_MAX - PAGE_MIN);
    w.item += take(ITEM_MAX - ITEM_MIN);
    if (n > 0) {
      var each = Math.min(SUPPLIER_MAX - SUPPLIER_MIN, Math.floor(spare / n));
      w.supplier += each;
      spare -= each * n;
    }
    if (shown.length) {
      var share = Math.floor(spare / shown.length), extra = spare - share * shown.length;
      shown.forEach(function (id, i) { w.cols[id] += share + (i < extra ? 1 : 0); });
    } else w.page += spare;
    w.table = w.page + w.item + n * w.supplier + shown.reduce(function (a, id) { return a + w.cols[id]; }, 0);
    return w;
  }

  // Ang ayos na ise-save pagkatapos ng drag. Ang mga nakikitang column lang ang nagpapalitan ng puwesto: ang mga
  // puwestong hawak nila sa buong ayos ay pinupuno ulit ayon sa bagong pagkakasunod. Ang mga wala sa table
  // (lumipat, nakatago) ay hindi gumagalaw, at walang catalog id na nawawala.
  function orderToSave(full, shownAfter, catalog) {
    var out = [], seen = {};
    list(full).concat(list(catalog)).forEach(function (id) {
      if (typeof id === 'string' && id !== '' && !seen[id]) { seen[id] = true; out.push(id); }
    });
    // Ang pinagsamang column (RTS / DEL / INT, Prof.%) ay ibinibigay bilang listahan ng mga id nito: sabay silang
    // lumilipat, pero nananatili ang pagkakasunod nila sa isa't isa gaya ng sa naka-save na ayos.
    var want = [], inWant = {};
    list(shownAfter).forEach(function (unit) {
      var ids = (Array.isArray(unit) ? unit : [unit]).filter(function (id) { return typeof id === 'string' && seen[id] && !inWant[id]; });
      ids.sort(function (a, b) { return out.indexOf(a) - out.indexOf(b); });
      ids.forEach(function (id) { if (!inWant[id]) { inWant[id] = true; want.push(id); } });
    });
    var k = 0;
    return out.map(function (id) { return inWant[id] ? want[k++] : id; });
  }

  // Ang format ng pera ng table na ito lang: hanggang ₱99,999.99 may sentimo; mula ₱100,000 wala na; sa TOTAL,
  // mula isang milyon ay "₱2.27M" (ang buong halaga ay nasa title); ang negatibo ay may totoong minus sa unahan.
  function tableMoney(v, isTotal) {
    var n = Number(v);
    if (v === null || v === undefined || !isFinite(n)) n = 0;
    var abs = Math.round(Math.abs(n) * 100) / 100;
    var sign = (n < 0 && abs > 0) ? '−' : '';
    var commas = function (s) { return s.replace(/\B(?=(\d{3})+(?!\d))/g, ','); };
    var cents = abs.toFixed(2).split('.');
    var title = sign + '₱' + commas(cents[0]) + '.' + cents[1];
    var text = title;
    if (isTotal === true && abs >= 1000000) {
      text = sign + '₱' + String(Number((abs / 1000000).toFixed(2))) + 'M';
    } else if (abs >= 100000) {
      text = sign + '₱' + commas(String(Math.round(abs)));
    }
    return { text: text, title: title };
  }

  // Ang label ng header ay puwedeng maputol pagkatapos ng tuldok o slash at bago ang panaklong (zero-width space),
  // para kasya sa dalawang linya ang mahabang pangalan sa makitid na column.
  function headLabel(label) {
    return String(label === null || label === undefined ? '' : label).replace(/([.\/])(?=\S)/g, '$1\u200B').replace(/(\S)\(/g, '$1\u200B(');
  }

  var api = {
    FIT_FROM: FIT_FROM,
    headLabel: headLabel,
    minWidthOf: minWidthOf,
    dropRankOf: dropRankOf,
    readSet: readSet,
    setNames: setNames,
    mergeProfPct: mergeProfPct,
    periodsOf: periodsOf,
    pickPeriod: pickPeriod,
    profPct: profPct,
    periodId: periodId,
    fit: fit,
    turnOn: turnOn,
    turnOff: turnOff,
    layout: layout,
    widths: widths,
    orderToSave: orderToSave,
    tableMoney: tableMoney,

    peso: peso,
    supplierColumns: supplierColumns,
    groupSpan: groupSpan,
    supplierCell: supplierCell,
    noSupplier: noSupplier,
    formPreset: formPreset,
  };

  root.ItemTableFit = api;
})(typeof self !== 'undefined' ? self : globalThis);
