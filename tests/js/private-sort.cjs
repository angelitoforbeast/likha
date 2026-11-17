// Runner only: reads a render of /owner/private (argument 1), takes the inline script that defines
// privateUI(), and runs the component's sortedRows() on the rows and sort settings given on stdin as JSON
// ({rows, sorts: [{col, dir}]}). Prints the item_name order for each sort. No assertions here: the expected
// orders live in the PHPUnit class that calls it. Browser objects the script touches at load are stubbed.
const fs = require('fs');
const vm = require('vm');

const html = fs.readFileSync(process.argv[2], 'utf8');
const scripts = [...html.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].map((m) => m[1]);
const main = scripts.find((s) => s.includes('function privateUI'));
if (!main) { process.stderr.write('no privateUI script in ' + process.argv[2]); process.exit(1); }

const noop = () => {};
const element = () => ({ addEventListener: noop, querySelector: () => null, querySelectorAll: () => [], classList: { add: noop, remove: noop, toggle: noop, contains: () => false }, style: {}, dataset: {} });
const page = {
  window: { location: { search: '', pathname: '/owner/private', href: '' }, addEventListener: noop, matchMedia: () => ({ matches: false, addEventListener: noop }), innerWidth: 1366 },
  document: { addEventListener: noop, querySelector: () => null, querySelectorAll: () => [], getElementById: () => null, createElement: element, body: element(), documentElement: element() },
  localStorage: { getItem: () => null, setItem: noop, removeItem: noop },
  console, URLSearchParams, Date, Math, JSON, Object, Array, Number, String, Set, Map, Promise, RegExp, Intl, isNaN, isFinite, parseFloat, parseInt,
  fetch: () => Promise.resolve({ ok: true, json: async () => ({}) }),
  setTimeout: () => 0, clearTimeout: noop, setInterval: () => 0, clearInterval: noop,
  ResizeObserver: class { observe() {} disconnect() {} }, MutationObserver: class { observe() {} disconnect() {} },
  navigator: {}, alert: noop, confirm: () => true,
};
page.self = page;
page.window.localStorage = page.localStorage;
page.window.document = page.document;
vm.createContext(page);
vm.runInContext(main + '\n;self.__ui = privateUI();', page);
const ui = page.__ui;

let input = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => { input += chunk; });
process.stdin.on('end', () => {
  const { rows, sorts } = JSON.parse(input);
  ui.filteredRows = () => rows;
  const out = sorts.map(({ col, dir }) => {
    ui.sortCol = col; ui.sortDir = dir;
    return ui.sortedRows().map((r) => (col === 'item_name' ? r.item_name : [r.item_name, r[col]]));
  });
  process.stdout.write(JSON.stringify(out));
});
