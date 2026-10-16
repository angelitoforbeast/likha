// Tagapatakbo ng component ng /summary/overall (ang object ng overallUI()) nang walang browser: binabasa ang
// render ng page (argument 1), kinukuha ang inline script, at tinatawag ang mga method na nasa stdin bilang
// JSON ([{fn, args}]). Ipini-print ang mga sagot bilang JSON. Walang assertion dito: nasa PHPUnit ang inaasahan.
const fs = require('fs');
const vm = require('vm');

const html = fs.readFileSync(process.argv[2], 'utf8');
const scripts = [...html.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].map((m) => m[1]);
const main = scripts.find((s) => s.includes('function overallUI'));
if (!main) throw new Error('overallUI script not found');

const store = {};
const ctx = { localStorage: { getItem: (k) => store[k] ?? null, setItem: (k, v) => { store[k] = String(v); } } };
vm.runInNewContext(main + '\nthis.__ui = overallUI(true, false);', ctx);
const ui = ctx.__ui;

const calls = JSON.parse(fs.readFileSync(0, 'utf8'));
const out = calls.map(({ fn, args }) => {
  try { return { value: JSON.parse(JSON.stringify(ui[fn](...args) ?? null)) }; }
  catch (e) { return { error: String(e && e.message || e) }; }
});
process.stdout.write(JSON.stringify(out));
