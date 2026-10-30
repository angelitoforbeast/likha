// Ang mga tuntunin ng /chatbot/live na puwedeng magkamali nang walang nakakakita: kung aling event ang may ilaw at saan ito
// dadaan, ang money packet ng order (ang chip, ang pagsasama ng sunod-sunod na order, walang order na nawawala), at kung
// kailan aakyat o bababa ang nakikitang bilang. Purong function lang: walang DOM, walang orasan, walang global na binabasa,
// kaya napapatakbo rin ng node para sa mga test. Plain na script, hindi module: iisang pangalan ang inilalagay, ang LiveRules.
(function (root) {
  'use strict';

  var BOTS = ['forbeast', 'incepxionbot', 'botcake'];

  function botOf(e) { return e && BOTS.indexOf(e.bot) >= 0 ? e.bot : null; }

  // 1234567.8 → "1,234,568": sariling formatter para pareho ang labas sa browser at sa node.
  function num(v) {
    var n = Math.round(Number(v) || 0), s = String(Math.abs(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return (n < 0 ? '-' : '') + s;
  }

  function peso(v) { return '₱' + num(v); }

  // Ang dadaanan ng ilaw: [path, pasulong]. Ang path ng input (inq, seq, ai) ay papunta sa core; ang path ng bot at ng
  // Orders ay mula sa core palabas.
  function route(e) {
    var bot = botOf(e);
    if (e.type === 'message') return [['inq', true]];
    if (e.type === 'ai') return [['ai', true]];
    if (e.type === 'confirmed') return bot ? [[bot, true]] : [];
    if (e.type === 'follow_up') return bot ? [['seq', true], [bot, true]] : [['seq', true]];
    if (e.type === 'order') return bot ? [[bot, false], ['ord', true]] : [['ord', true]];
    return [];
  }

  // Ang chip ng money packet: ang alam na halaga lang (hindi kailanman tinatantiya); walang halaga = "+1 ORDER".
  function label(orders) {
    var n = orders.length, known = orders.filter(function (o) { return typeof o.amount === 'number' && isFinite(o.amount); });
    var sum = known.reduce(function (a, o) { return a + o.amount; }, 0);
    if (known.length) return '+' + peso(sum) + (n > 1 ? ' · ' + n + ' orders' : '');
    return n > 1 ? '+' + n + ' ORDERS' : '+1 ORDER';
  }

  function sumKnown(orders) {
    return orders.reduce(function (a, o) { return a + (typeof o.amount === 'number' && isFinite(o.amount) ? o.amount : 0); }, 0);
  }

  // Ang plano ng isang poll. Ang hindi order: ikinakalat sa ~4 s; kapag lampas sa max, isa sa bawat ilan lang (sampled ang
  // iba). Ang order: isang money packet kada slot (1.5 s), hanggang sa kasya sa 5 s ng poll; ang sobra ay pinagsasama sa
  // huling packet, kaya walang order na nawawala. Reduced motion: walang packet; ang hit at ang chip ay agad (at: 0).
  function schedule(events, opts) {
    var o = opts || {}, reduced = !!o.reduced, slot = o.slot || 1500, max = o.max || 60, spread = o.spread || 4200, poll = o.poll || 5000;
    var list = Array.isArray(events) ? events : [];
    var orders = list.filter(function (e) { return e && e.type === 'order'; });
    var rest = list.filter(function (e) { return e && e.type !== 'order'; });
    var step = Math.ceil(rest.length / max), shown = step > 1 ? rest.filter(function (e, i) { return i % step === 0; }) : rest;
    var gap = Math.min(600, spread / Math.max(1, shown.length));
    var actions = shown.map(function (e, i) {
      return {kind: reduced ? 'hit' : 'packet', at: reduced ? 0 : Math.round(i * gap), type: e.type, bot: botOf(e), route: route(e), event: e};
    });
    var slots = Math.max(1, Math.floor(poll / slot));
    var groups = orders.length <= slots ? orders.map(function (x) { return [x]; })
      : orders.slice(0, slots - 1).map(function (x) { return [x]; }).concat([orders.slice(slots - 1)]);
    groups.forEach(function (g, i) {
      actions.push({kind: reduced ? 'instant' : 'money', at: reduced ? 0 : i * slot, type: 'order', bot: botOf(g[0]), route: route(g[0]),
        label: label(g), orders: g.length, peso: sumKnown(g), event: g[g.length - 1]});
    });
    actions.sort(function (a, b) { return a.at - b.at; });
    return {actions: actions, sampled: rest.length - shown.length};
  }

  // Ang nakikitang bilang laban sa bilang ng server. Pataas: hinihintay hanggang `hold` ms (ang packet na paparating ang
  // magpapaakyat; ang hold na 0 ay tuloy agad). Pababa: hindi sa loob ng isang minuto mula sa huling pag-akyat (ang lokal na
  // pag-akyat ay nauuna sa cache ng server); pagkatapos noon, ang server ang sinusunod (hal. bagong araw).
  function settle(st, server, now, hold, floor) {
    var s = {shown: st.shown, server: server, heldSince: st.heldSince, lastUp: st.lastUp};
    var f = floor === undefined ? 60000 : floor;
    if (server > s.shown) {
      if (s.heldSince === null || s.heldSince === undefined) s.heldSince = now;
      if (!hold || now - s.heldSince >= hold) { s.shown = server; s.heldSince = null; s.lastUp = now; }
    } else {
      s.heldSince = null;
      if (server < s.shown && (s.lastUp === null || s.lastUp === undefined || now - s.lastUp >= f)) s.shown = server;
    }
    return s;
  }

  // Ang paglapag ng packet: ang nakikita ay umaakyat agad nang delta; ang hinihintay na bilang ng server ay naabot na.
  function land(st, delta, now) {
    var s = {shown: st.shown + (Number(delta) || 0), server: st.server, heldSince: st.heldSince, lastUp: st.lastUp};
    if (delta > 0) s.lastUp = now;
    if (s.server === null || s.server === undefined || s.server <= s.shown) s.heldSince = null;
    return s;
  }

  // Ang bilang habang umaakyat: ease-out-expo mula sa dati papunta sa bago, t mula 0 hanggang 1.
  function roll(from, to, t) {
    var k = t >= 1 ? 1 : t <= 0 ? 0 : 1 - Math.pow(2, -10 * t);
    return from + (to - from) * k;
  }

  // Ang "signals · last 5 min" ng core: ang kabuuan ng mga bilang sa huling 5 minuto (walang bagong query).
  function signals(c) {
    var r = function (k) { return c && c[k] ? Number(c[k].recent) || 0 : 0; };
    return r('messages') + r('confirmed') + r('follow_ups') + r('ai') + r('orders');
  }

  // Ang loot ng isang money action: may halaga = 6 na barya, at ang halaga ay hinati sa 6 na bahagi (buong piso, ang sobra sa
  // mga unang barya, ang sentimo sa huli) para umakyat ang kabuuan sa bawat paglapag at eksaktong ang halaga sa dulo; walang
  // halaga = isang "+1" na plate, walang piso. Reduced motion: walang barya, ang buong halaga ay isang hakbang.
  var COINS = 6, STAGGER = 110, BURST = 380, ARC = 900;
  function round2(v) { return Math.round(v * 100) / 100; }
  function loot(pesos, opts) {
    var reduced = !!(opts && opts.reduced), amount = round2(Math.max(0, Number(pesos) || 0));
    if (amount <= 0) return {coins: reduced ? 0 : 1, plate: !reduced, parts: [0], label: '+1', peso: 0};
    var label = '+' + peso(amount);
    if (reduced) return {coins: 0, plate: false, parts: [amount], label: label, peso: amount};
    var base = Math.floor(amount / COINS), extra = round2(amount - base * COINS), whole = Math.floor(extra), parts = [];
    for (var i = 0; i < COINS; i++) parts.push(base + (i < whole ? 1 : 0));
    parts[COINS - 1] = round2(parts[COINS - 1] + extra - whole);
    return {coins: COINS, plate: false, parts: parts, label: label, peso: amount};
  }

  // Kailan lalapag ang bawat barya mula sa simula ng pagsabog (ms): lumalabas nang 110 ms ang pagitan, 380 ms ang pagsabog, 900 ms ang arko.
  function landings(n) {
    var out = [];
    for (var i = 0; i < n; i++) out.push(i * STAGGER + BURST + ARC);
    return out;
  }

  // Ang 2D/3D na napili sa browser na ito (localStorage, o anumang key/value na object). Walang storage, sira o ibang value = 2D.
  var VIEW_KEY = 'likha-live-view';
  function readView(store) {
    try {
      var v = typeof store.getItem === 'function' ? store.getItem(VIEW_KEY) : store[VIEW_KEY];
      return v === '3d' ? '3d' : '2d';
    } catch (e) { return '2d'; }
  }
  function saveView(store, mode) {
    try {
      var v = mode === '3d' ? '3d' : '2d';
      if (typeof store.setItem === 'function') store.setItem(VIEW_KEY, v); else store[VIEW_KEY] = v;
      return true;
    } catch (e) { return false; }
  }

  root.LiveRules = {num: num, peso: peso, route: route, label: label, schedule: schedule, settle: settle, land: land, roll: roll, signals: signals,
    loot: loot, landings: landings, readView: readView, saveView: saveView};
})(typeof self !== 'undefined' ? self : this);
