// Tagapatakbo ng component ng item page (ang object ng privateUI()) nang walang browser: binabasa ang isang
// render ng table na may suppliers (argument 1) at ang script ng pure functions (argument 2), nilalagyan ng
// pamalit ang mga bagay ng browser na ginagamit ng fit (ang sukat ng scroll area, ang window, ResizeObserver,
// $watch, ang orasan), at pinapatakbo ang mga hakbang na ibinigay sa stdin bilang JSON. Pagkatapos ng bawat
// hakbang, ibinabalik ang estado ng fit at ang value ng mga binding ng mismong markup (ang button, ang panel,
// ang style ng table). Walang assertion dito: nasa PHPUnit class na tumatawag ang mga inaasahang value.
const fs = require('fs');
const vm = require('vm');

const html = fs.readFileSync(process.argv[2], 'utf8');
const scripts = [...html.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].map((m) => m[1]);
const main = scripts.find((s) => s.includes('function privateUI'));
const decode = (s) => s.replace(/&quot;/g, '"').replace(/&#0?39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
const binding = (re) => { const m = html.match(re); if (!m) throw new Error('binding not found: ' + re); return decode(m[1]); };
// Ang mga binding ng markup, gaya ng pagkakasulat sa render.
const markup = {
  button: binding(/<span class="spl-more-n" x-text="([^"]*)"/),
  awayCount: binding(/One step away \(<span x-text="([^"]*)"/),
  awayList: binding(/<template x-for="id in ([^"]*)" :key="'cp-a-'\+id">/),
  shownCount: binding(/Shown \(<span x-text="([^"]*)"/),
  shownList: binding(/<template x-for="id in ([^"]*)" :key="'cp-s-'\+id">/),
  tableStyle: binding(/<table class="spl-table" :style="([^"]*)"/),
  meter: binding(/<div class="spl-cp-meter"\s+x-text="([^"]*)"/),
};

let clock = 1000000;
class FakeDate extends Date { static now() { return clock; } }
const timers = [];
const observers = [];
const el = { clientWidth: 0, querySelector: () => null };
const page = {
  window: { innerWidth: 0, location: { search: '?layout=old', pathname: '/item', href: '' }, addEventListener() {}, matchMedia: () => ({ matches: true }) },
  document: { querySelector: () => null, addEventListener() {} },
  localStorage: { data: {}, getItem(k) { return k in this.data ? this.data[k] : null; }, setItem(k, v) { this.data[k] = String(v); } },
  console, URLSearchParams, Date: FakeDate, Math, JSON, Object, Array, Number, String, Set, Map, Promise, isNaN, isFinite, parseFloat, parseInt,
  fetch: () => Promise.resolve({ ok: true, json: async () => ({}) }),
  getComputedStyle: () => ({ paddingLeft: '16px', paddingRight: '16px', borderLeftWidth: '0px', borderRightWidth: '0px' }),
  setTimeout: (fn) => { timers.push(fn); return timers.length; }, clearTimeout() {},
  ResizeObserver: class { constructor(cb) { this.cb = cb; observers.push(this); } observe() {} disconnect() {} },
  navigator: {}, alert() {}, confirm: () => true,
};
page.self = page;
page.window.localStorage = page.localStorage;
vm.createContext(page);
vm.runInContext(fs.readFileSync(process.argv[3], 'utf8'), page);
page.window.ItemTableFit = page.ItemTableFit;
vm.runInContext(main + '\n;self.__ui = privateUI();', page);
const ui = page.__ui;
const watchers = {};
ui.$watch = (key, fn) => { (watchers[key] = watchers[key] || []).push(fn); };
ui.$nextTick = (fn) => fn && fn();
const read = (expr) => vm.runInContext('with (self.__ui) { (' + expr + ') }', page);

function snapshot() {
  return {
    box: ui.fitBox, mode: ui.fitRes.mode, set: ui.fitSet,
    cols: ui.cols.map((c) => c.id), shown: ui.fitCols.map((c) => c.id),
    table: ui.fitW.table, widths: Object.assign({ page: ui.fitW.page, item: ui.fitW.item, supplier: ui.fitW.supplier }, ui.fitW.cols),
    button: read(markup.button), awayCount: read(markup.awayCount), awayList: Array.from(read(markup.awayList)),
    shownCount: read(markup.shownCount), shownList: Array.from(read(markup.shownList)),
    tableStyle: read(markup.tableStyle), meter: read(markup.meter), panel: ui.fitPanel === true, message: ui.fitMsg,
    timers: timers.length,
  };
}

let input = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => { input += chunk; });
process.stdin.on('end', () => {
  const out = [];
  for (const step of JSON.parse(input)) {
    if (typeof step.wait === 'number') clock += step.wait;
    if (step.do === 'load') {
      // Ang pagbukas ng page: ang mga column mula sa setting, tapos ang x-init ng scroll area.
      el.clientWidth = step.clientWidth; page.window.innerWidth = step.win;
      ui.initCols();
      ui.fitInit(el);
    } else if (step.do === 'resize') {
      // Nagbago ang laki ng scroll area (at / o ng window): ang tawag ng ResizeObserver.
      el.clientWidth = step.clientWidth; page.window.innerWidth = step.win;
      observers.forEach((o) => o.cb([]));
    } else if (step.do === 'suppliers') {
      ui.supplierList = step.list;
      (watchers.supplierList || []).forEach((fn) => fn());
    } else if (step.do === 'timers') {
      timers.splice(0).forEach((fn) => fn());
    } else if (step.do === 'call') {
      ui[step.method](...(step.args || []));
    } else if (step.do === 'run') {
      // Isang expression ng markup (hal. ang @click ng button), sa scope ng component.
      vm.runInContext('with (self.__ui) { ' + step.code + ' }', page);
    }
    out.push(snapshot());
  }
  process.stdout.write(JSON.stringify(out));
});
