// Browser check of the Ad groups panel (spec 212): the real panel script and the real server JSON in headless Chrome,
// at desktop width and at 390 x 844, default and with Show hidden on. Writes PNGs and a JSON of the checks.
// node shots.mjs <worktree> <fixture.json> <outdir>
import {spawn, spawnSync} from 'node:child_process';
import {readFileSync, writeFileSync, mkdirSync} from 'node:fs';
import {join} from 'node:path';
import {pathToFileURL} from 'node:url';

const [root, fixturePath, outDir] = process.argv.slice(2);
const {Cdp, Page, sleep} = await import(pathToFileURL(join(root, 'tests/perf/cdp.mjs')).href);
const {tailwindCss} = await import(pathToFileURL(join(root, 'tests/perf/tailwind.mjs')).href);
mkdirSync(outDir, {recursive: true});
const css = await tailwindCss(root);
const layoutCss = readFileSync(join(root, 'resources/views/layouts/chatblast.blade.php'), 'utf8');
const btn = `.cb-btn{display:inline-flex;align-items:center;gap:.25rem;border-radius:.375rem;border:1px solid #cbd5e1;background:#fff;padding:.375rem .75rem;font-size:.875rem;font-weight:500;color:#334155}
.cb-btn-primary{border-color:#2563eb;background:#2563eb;color:#fff}.cb-btn-sm{padding:.125rem .5rem;font-size:.75rem}`;
if (!layoutCss.includes('.cb-btn {')) throw new Error('cb-btn rules moved');
const fixture = readFileSync(fixturePath, 'utf8');
const panelJs = readFileSync(join(root, 'public/js/chatbot-adgroups.js'), 'utf8');
const html = `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<style>${css}\n${btn}</style></head><body class="bg-slate-100 text-gray-900">
<div class="mx-auto my-4 w-full max-w-3xl rounded-xl bg-white p-4 shadow" role="dialog" aria-label="Edit page">
<h2 class="text-base font-semibold">Edit · Precious Lopez</h2>
<section id="pe-ag" class="py-3 text-xs text-slate-600"><h3 class="text-sm font-semibold text-slate-800">Ad groups</h3><div id="host" class="mt-1"></div></section></div>
<script>${panelJs}</script>
<script>
const FIXTURE = ${fixture};
window.fetch = async (url, o) => ({ok: true, status: 200, json: async () => (o && o.method === 'POST' ? {ok: true, id: 1} : FIXTURE)});
const AG = ChatbotAdGroups.create({csrf: 'x', urls: {index: '/i', save: '/s', add: '/a', del: '/d', history: '/h', restore: '/r', mode: '/m', value: '/v', removeAd: '/rm', ad: '/ad'}, onChange() {}});
window.ready = AG.mount({page: 'Precious Lopez', cells: {price: '199'}, ai: {}}, document.getElementById('host')).then(() => true);
</script></body></html>`;
const file = join(outDir, 'harness.html');
writeFileSync(file, html);

const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const port = 9341;
const profile = join(outDir, 'chrome-profile');
const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, '--host-resolver-rules=MAP * ~NOTFOUND',
    '--disable-background-networking', '--disable-extensions', '--no-first-run', 'about:blank'], {stdio: 'ignore'});
let version = null;
for (let i = 0; i < 120 && !version; i++) { try { const r = await fetch(`http://127.0.0.1:${port}/json/version`); if (r.ok) version = await r.json(); } catch { await sleep(250); } }
if (!version) throw new Error('Chrome did not start');
const cdp = await Cdp.connect(version.webSocketDebuggerUrl);
const checks = {};
try {
    for (const [name, w, h, mobile] of [['desktop', 1440, 900, false], ['phone-390x844', 390, 844, true]]) {
        const page = await Page.open(cdp, {width: w, height: h});
        await page.send('Emulation.setDeviceMetricsOverride', {width: w, height: h, deviceScaleFactor: 1, mobile});
        await page.goto(pathToFileURL(file).href);
        await page.waitFor('window.ready !== undefined');
        await page.ev('window.ready');
        await sleep(300);
        for (const state of ['default', 'show-hidden']) {
            if (state === 'show-hidden') { await page.ev(`document.querySelector('[data-ag-hidden-toggle]').focus(); true`); await page.send('Input.dispatchKeyEvent', {type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, text: '\r'}); await page.send('Input.dispatchKeyEvent', {type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13}); await sleep(300); }
            checks[name + ' ' + state] = await page.ev(`(() => {
                const t = document.querySelector('[data-ag-hidden-toggle]');
                const small = [...document.querySelectorAll('#host button')].filter(b => { const r = b.getBoundingClientRect(); return r.width > 0 && (r.height < 24 || r.width < 24); }).map(b => b.textContent);
                return {scrollWidth: document.documentElement.scrollWidth, innerWidth, sideways: document.documentElement.scrollWidth > innerWidth,
                    toggle: t && {text: t.textContent, pressed: t.getAttribute('aria-pressed'), h: Math.round(t.getBoundingClientRect().height)},
                    groups: [...document.querySelectorAll('#host [data-ag-group]')].map(g => g.querySelector('h4').textContent), buttonsUnder24px: small};
            })()`);
            const {data} = await page.send('Page.captureScreenshot', {format: 'png', captureBeyondViewport: true, clip: {x: 0, y: 0, width: w, height: await page.ev('document.documentElement.scrollHeight'), scale: 1}});
            writeFileSync(join(outDir, `panel-${name}-${state}.png`), Buffer.from(data, 'base64'));
            const box = await page.ev(`(() => { const r = document.querySelector("#host li[data-ag-id]").closest("ul").getBoundingClientRect(); return {y: Math.max(0, r.top + scrollY - 40), h: r.height + 140}; })()`);
            const crop = await page.send("Page.captureScreenshot", {format: "png", captureBeyondViewport: true, clip: {x: 0, y: box.y, width: w, height: box.h, scale: 1}});
            writeFileSync(join(outDir, `ids-${name}-${state}.png`), Buffer.from(crop.data, "base64"));
        }
    }
} finally {
    cdp.close();
    if (process.platform === 'win32') spawnSync('taskkill', ['/pid', String(chrome.pid), '/T', '/F'], {stdio: 'ignore'}); else chrome.kill();
}
writeFileSync(join(outDir, 'checks.json'), JSON.stringify(checks, null, 2));
console.log(JSON.stringify(checks, null, 2));
process.exit(0);
