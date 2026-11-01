// Browser check for spec 213: the read-only Ad groups rows (subRows) of a page in headless Chrome, at 1440 x 900 and
// 390 x 844, for "only ads not in a group hidden" (like the live page) and "mixed". Real panel script, locally built
// Tailwind, a synthetic page. Writes PNGs and checks.json next to this file.
// node docs/specs/213-evidence/shots.mjs <worktree> <outdir>
import {spawn, spawnSync} from 'node:child_process';
import {readFileSync, writeFileSync, mkdirSync} from 'node:fs';
import {join} from 'node:path';
import {pathToFileURL} from 'node:url';

const [root, outDir] = process.argv.slice(2);
const {Cdp, Page, sleep} = await import(pathToFileURL(join(root, 'tests/perf/cdp.mjs')).href);
const {tailwindCss} = await import(pathToFileURL(join(root, 'tests/perf/tailwind.mjs')).href);
mkdirSync(outDir, {recursive: true});
const css = await tailwindCss(root);
const btn = '.cb-btn{display:inline-flex;align-items:center;gap:.25rem;border-radius:.375rem;border:1px solid #cbd5e1;background:#fff;padding:.375rem .75rem;font-size:.875rem;font-weight:500;color:#334155}.cb-btn-sm{padding:.125rem .5rem;font-size:.75rem}';
const panelJs = readFileSync(join(root, 'public/js/chatbot-adgroups.js'), 'utf8');
const html = (fixture) => `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<style>${css}\n${btn}</style></head><body class="bg-white text-gray-900"><div class="overflow-x-auto"><table class="w-full text-sm"><tbody id="rows"></tbody></table></div>
<script>${panelJs}</script><script>
const row = (id, quiet) => ({ad_id: id, kind: 'ad', name: 'Serum Sale 🌸 / Kababaihan 25–45 / Ad ' + id, status: quiet ? 'off' : 'on', off_since: quiet ? '2026-10-01' : null, off_days: quiet ? 19 : null, new: false, customers: 3, last: '2026-10-20 07:00', quiet});
const FIXTURE = ${JSON.stringify(fixture)};
window.fetch = async () => ({ok: true, status: 200, json: async () => FIXTURE});
const AG = ChatbotAdGroups.create({csrf: 'x', urls: {index: '/i'}, onChange() {}});
const r = {page: 'Precious Lopez', cells: {}, ai: {}};
const ctx = {cols: 7, botCols: [], defaultRow: () => document.createElement('tr'), manage() {}};
const paint = () => document.getElementById('rows').replaceChildren(...AG.subRows(r, ctx));
AG.subRows(r, ctx);
window.ready = new Promise(res => setTimeout(() => { paint(); res(true); }, 50));
</script></body></html>`;
const ad = (id, quiet) => ({ad_id: id, kind: 'ad', name: 'Serum Sale 🌸 / Kababaihan 25–45 / Ad ' + id, status: quiet ? 'off' : 'on', off_since: quiet ? '2026-10-01' : null, off_days: quiet ? 19 : null, new: false, customers: 3, last: '2026-10-20 07:00', quiet});
const page = (hg, qi, un) => ({mode: 'ad', as_of: '2026-10-19', keys: [], flows: [], unassigned: [], unassigned_total: un + 12, new_unassigned: 0, hidden: {groups: hg, ids: qi, unassigned: un},
    groups: [{id: 1, name: 'Serum 🌸 (Nobyembre promo)', hidden: false, all_off: false, values: {}, ads: [ad('120210000000040001', false)].concat(Array.from({length: qi}, (_, i) => ad('12021000000004100' + i, true)))}]
        .concat(Array.from({length: hg}, (_, i) => ({id: 10 + i, name: 'Toner luma ' + i, hidden: true, all_off: true, values: {}, ads: [ad('12021000000004200' + i, true)]})))});

const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const port = 9343;
const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${join(outDir, 'chrome-profile')}`, '--host-resolver-rules=MAP * ~NOTFOUND',
    '--disable-background-networking', '--disable-extensions', '--no-first-run', 'about:blank'], {stdio: 'ignore'});
let version = null;
for (let i = 0; i < 120 && !version; i++) { try { const r = await fetch(`http://127.0.0.1:${port}/json/version`); if (r.ok) version = await r.json(); } catch { await sleep(250); } }
if (!version) throw new Error('Chrome did not start');
const cdp = await Cdp.connect(version.webSocketDebuggerUrl);
const checks = {};
try {
    for (const [name, fixture] of [['only-ads-not-in-a-group', page(0, 0, 209)], ['mixed', page(2, 3, 50)]]) {
        const file = join(outDir, `harness-${name}.html`);
        writeFileSync(file, html(fixture));
        for (const [size, w, h, mobile] of [['desktop', 1440, 900, false], ['phone-390x844', 390, 844, true]]) {
            const p = await Page.open(cdp, {width: w, height: h});
            await p.send('Emulation.setDeviceMetricsOverride', {width: w, height: h, deviceScaleFactor: 1, mobile});
            await p.goto(pathToFileURL(file).href);
            await p.waitFor('window.ready !== undefined');
            await p.ev('window.ready');
            await sleep(200);
            checks[name + ' ' + size] = await p.ev(`(() => { const s = document.querySelector('[data-ag-hidden-line]'), b = document.querySelector('[data-ag-hidden-toggle]'), rs = s.getBoundingClientRect();
                return {line: s.textContent, lineWidth: Math.round(rs.width), lineHeight: Math.round(rs.height), wraps: rs.height > 20, scrollWidth: document.documentElement.scrollWidth, innerWidth, sideways: document.documentElement.scrollWidth > innerWidth,
                    button: b && {text: b.textContent, pressed: b.getAttribute('aria-pressed')}}; })()`);
            const {data} = await p.send('Page.captureScreenshot', {format: 'png', clip: {x: 0, y: 0, width: w, height: Math.min(h, 420), scale: 1}});
            writeFileSync(join(outDir, `readonly-${name}-${size}.png`), Buffer.from(data, 'base64'));
        }
    }
} finally {
    cdp.close();
    if (process.platform === 'win32') spawnSync('taskkill', ['/pid', String(chrome.pid), '/T', '/F'], {stdio: 'ignore'}); else chrome.kill();
}
writeFileSync(join(outDir, 'checks.json'), JSON.stringify(checks, null, 2));
console.log(JSON.stringify(checks, null, 2));
process.exit(0);
