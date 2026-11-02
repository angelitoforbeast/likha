#!/usr/bin/env node
// /chatbot/pages on the spec 217 set (tests/perf/set217.php), in headless Chrome over the DevTools protocol: the server
// time and size of the page and its data, the DOM size, and the longest main-thread task of each action (load,
// scrolling, each Ad-based page's ad groups shown and hidden, Manage in Edit), at 1600 px and at 390 px, with the
// verdict of the cases S-217.1.1, 1.2 and 1.4.
//
// One-time setup (local test values only, no env file): php tests/perf/set217.php migrate && php tests/perf/set217.php seed
// Run: node tests/perf/pages217.mjs [--cpu=4] [--phone] [--only-phone] [--json=file] [--css=file] [--profile=<action>|load] [--trace=<action>|load] [--php=path]
//   --cpu=N slows the page's CPU N times (a normal office PC is slower than this machine; the verdicts use the run given).
//   --eval=<expression> prints it after the load and stops.
//   --css=file appends rules to the stylesheet, to try a CSS change before it is made.
// It starts the local server (php -S on 127.0.0.1:8217) and Chrome, and stops them at the end. Nothing outside
// 127.0.0.1 resolves in that Chrome.
import {spawn, spawnSync} from 'node:child_process';
import {mkdirSync, writeFileSync, existsSync, readFileSync} from 'node:fs';
import {join, dirname} from 'node:path';
import {fileURLToPath} from 'node:url';
import {Cdp, Page, sleep} from './cdp.mjs';
import {tailwindCss} from './tailwind.mjs';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const args = Object.fromEntries(process.argv.slice(2).map(a => { const [k, ...v] = a.replace(/^--/, '').split('='); return [k, v.join('=') || true]; }));
if (args.profile && args.profile !== 'load') process.env.PERF_PROFILE = String(args.profile);
if (args.trace && args.trace !== 'load') process.env.PERF_TRACE = String(args.trace);
const {measure, startTrace, topFunctions} = await import('./screens.mjs');
const PORT = 8217, BASE = 'http://127.0.0.1:' + PORT, DEBUG_PORT = 9417;
const PHP = args.php || (existsSync('C:/Users/Forbeast/.config/herd/bin/php84/php.exe') ? 'C:/Users/Forbeast/.config/herd/bin/php84/php.exe' : 'php');
const CHROME = args.chrome || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const AD_PAGES = ['Perf Page 007', 'Perf Page 014', 'Perf Page 021'];
// The targets of S-217.1.1: the server under 1 s, usable under 2 s, no task over 200 ms after that (and while scrolling).
const SERVER_MS = 1000, USABLE_MS = 2000, TASK_MS = 200;
const started = [];

const up = async (url, tries = 1) => {
    for (let i = 0; i < tries; i++) {
        try { const r = await fetch(url); if (r.ok) return r; } catch (e) { /* not yet */ }
        await sleep(250);
    }
    return null;
};
function stopAll() {
    for (const p of started) {
        if (process.platform === 'win32') spawnSync('taskkill', ['/pid', String(p.pid), '/T', '/F'], {stdio: 'ignore'}); else p.kill();
    }
}
process.on('exit', stopAll);
process.on('SIGINT', () => process.exit(130));

const max = list => list.reduce((a, [, d]) => Math.max(a, d), 0);
const click = expr => `(${expr}).click()`;
const DOM = `document.getElementsByTagName('*').length`;
// Usable: rows are shown and no table is still bringing rows in (aria-busy).
const settled = `document.querySelectorAll('#cb-table tbody tr[data-page]').length > 0 && !document.querySelector('[aria-busy="true"]')`;
const SUB_ROWS = `document.querySelectorAll('#cb-table tr.ag-sub').length`;
const GROUP_NODES = `document.querySelectorAll('#cb-table [data-ag-group], #cb-table [data-ag-id]').length`;

/** Load /chatbot/pages: server time and size of the page and /chatbot/data, when it is usable, DOM size, and the longest task until 2 s after. */
async function load(p, out, label) {
    const trace = args.trace === 'load' ? await startTrace(p.cdp) : null;
    if (args.profile === 'load') { await p.send('Profiler.enable'); await p.send('Profiler.setSamplingInterval', {interval: 200}); await p.send('Profiler.start'); }
    await p.send('Page.navigate', {url: BASE + '/chatbot/pages'});
    await p.waitFor('document.readyState === "complete"', 120000);
    const usable = await p.waitFor(settled, 120000);
    await sleep(2000);
    if (args.profile === 'load') process.stderr.write(topFunctions((await p.send('Profiler.stop')).profile));
    if (trace) process.stderr.write(await trace());
    const t = await p.ev(`(() => {
        const nav = performance.getEntriesByType('navigation')[0];
        const data = performance.getEntriesByType('resource').filter(e => e.name.includes('/chatbot/data'))[0];
        return {htmlMs: nav.responseStart - nav.requestStart, htmlKb: nav.decodedBodySize / 1024, dataMs: data ? data.responseStart - data.requestStart : null,
            dataKb: data ? data.decodedBodySize / 1024 : null, rows: document.querySelectorAll('#cb-table tbody tr[data-page]:not([hidden])').length};
    })()`);
    const {lt, loaf} = await p.tasks(0);
    const r = {action: label + ': load', server: `page ${Math.round(t.htmlMs)} ms / ${t.htmlKb.toFixed(0)} KB, data ${Math.round(t.dataMs)} ms / ${t.dataKb.toFixed(0)} KB`,
        usable: Math.round(usable), longest: max(lt), frame: max(loaf), afterLoad: max(lt.filter(([s]) => s >= usable)), dom: await p.ev(DOM),
        note: t.rows + ' rows', serverMs: Math.max(t.htmlMs, t.dataMs)};
    out.push(r);
    return r;
}

/** Scrolls the table box to the end in 400 px steps, one per frame, and back up: the longest task meanwhile. */
async function scroll(p, out, label) {
    const r = await measure(p, label + ': scroll the table', `window.__scrolled = false; (async () => {
        const b = document.getElementById('cb-box');
        for (let y = 0; y <= b.scrollHeight; y += 400) { b.scrollTop = y; await new Promise(r => requestAnimationFrame(r)); }
        for (let y = b.scrollHeight; y >= 0; y -= 400) { b.scrollTop = y; await new Promise(r => requestAnimationFrame(r)); }
        window.__scrolled = true; })()`, `window.__scrolled`, {settle: 300});
    out.push({...r, dom: await p.ev(DOM)});
    return r;
}

/** Each Ad-based page's ad groups shown, the table scrolled, Manage in Edit, and each hidden again: S-217.1.2. */
async function adGroups(p, out, label) {
    const before = {dom: await p.ev(DOM), subs: await p.ev(SUB_ROWS), groups: await p.ev(GROUP_NODES)};
    const shown = [];
    for (const page of AD_PAGES) {
        await p.ev(`(() => { const b = document.querySelector('button[aria-label^="Show the ad groups of ${page}"]'); if (b) b.scrollIntoView({block: 'center'}); return true; })()`);
        await sleep(300);
        const r = await measure(p, label + ': show ad groups of ' + page, click(`document.querySelector('button[aria-label^="Show the ad groups of ${page}"]')`),
            `[...document.querySelectorAll('#cb-table tr.ag-sub')].some(tr => tr.dataset.agGroup)`, {settle: 800});
        const s = await p.ev(`(() => { const e = performance.getEntriesByType('resource').filter(e => e.name.includes('/chatbot/ad-groups?page=${encodeURIComponent(page)}')).pop();
            return e ? Math.round(e.responseStart - e.requestStart) + ' ms / ' + (e.decodedBodySize / 1024).toFixed(0) + ' KB' : '-'; })()`);
        out.push({...r, server: 'ad-groups ' + s, dom: await p.ev(DOM)});
        shown.push(r);
    }
    const open = {subs: await p.ev(SUB_ROWS), groups: await p.ev(GROUP_NODES)};
    const sc = await scroll(p, out, label + ' (3 pages open)');
    const edit = await measure(p, label + ': Manage in Edit (' + AD_PAGES[0] + ')', click(`document.querySelector('button[aria-label="Manage the ad groups of ${AD_PAGES[0]} in its Edit window"]')`),
        `!document.getElementById('pe').classList.contains('hidden') && document.querySelector('#pe-ag [data-ag-group]')`, {settle: 800});
    out.push({...edit, dom: await p.ev(DOM)});
    await p.ev(`(() => { document.getElementById('pe-close').click(); return true; })()`);
    await sleep(300);
    const hidden = [];
    for (const page of AD_PAGES) {
        const r = await measure(p, label + ': hide ad groups of ' + page, click(`document.querySelector('button[aria-label^="Hide the ad groups of ${page}"]')`),
            `!document.querySelector('button[aria-label^="Hide the ad groups of ${page}"]')`, {settle: 500});
        out.push({...r, dom: await p.ev(DOM)});
        hidden.push(r);
    }
    const after = {dom: await p.ev(DOM), subs: await p.ev(SUB_ROWS), groups: await p.ev(GROUP_NODES)};
    out.push({action: label + ': S-217.1.2 groups and ad lists built only when shown, freed when hidden',
        pass: before.subs === 0 && before.groups === 0 && open.groups > 0 && after.subs === 0 && after.groups === 0 && after.dom <= before.dom * 1.05,
        note: `before: ${before.subs} sub-rows, ${before.groups} group/ad nodes, ${before.dom} elements; 3 shown: ${open.subs} sub-rows, ${open.groups} group/ad nodes; hidden again: ${after.subs} sub-rows, ${after.groups} group/ad nodes, ${after.dom} elements`});
    return {longest: Math.max(...[...shown, sc, edit, ...hidden].map(r => r.longest || 0))};
}

async function main() {
    const env = {...process.env, ...JSON.parse(spawnSync(PHP, ['tests/perf/set217.php', 'env'], {cwd: root, encoding: 'utf8'}).stdout)};
    if (!existsSync(join(root, 'storage/perf217/db.sqlite'))) throw new Error('Run php tests/perf/set217.php migrate and seed first.');
    if (!await up(BASE + '/login')) {
        const srv = spawn(PHP, ['-S', '127.0.0.1:' + PORT, join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], {cwd: join(root, 'public'), env, stdio: 'ignore'});
        started.push(srv);
        if (!await up(BASE + '/login', 120)) throw new Error('The local server did not start on ' + BASE);
    }
    let version = await up(`http://127.0.0.1:${DEBUG_PORT}/json/version`);
    if (!version) {
        const profile = join(root, 'storage', 'perf217', 'chrome-' + Date.now());
        mkdirSync(profile, {recursive: true});
        const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${DEBUG_PORT}`, `--user-data-dir=${profile}`, '--window-size=1600,1000',
            '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1', '--disable-background-networking', '--disable-component-update', '--disable-sync',
            '--disable-extensions', '--no-first-run', '--no-default-browser-check', '--disable-default-apps', '--metrics-recording-only', 'about:blank'], {stdio: 'ignore'});
        started.push(chrome);
        version = await up(`http://127.0.0.1:${DEBUG_PORT}/json/version`, 120);
        if (!version) throw new Error('Chrome did not start');
    }
    const cdp = await Cdp.connect((await version.json()).webSocketDebuggerUrl);
    // The layout's own rules (<style type="text/tailwindcss">: the buttons, cards and the one scrolling box of .cb-scroll)
    // go through the same compiler, so the table scrolls inside its box as it does live.
    const layout = readFileSync(join(root, 'resources/views/layouts/chatblast.blade.php'), 'utf8');
    const own = [...layout.matchAll(/<style type="text\/tailwindcss">([\s\S]*?)<\/style>/g)].map(m => m[1]).join('\n');
    const css = (await tailwindCss(root, own)) + (args.css ? ' ' + readFileSync(String(args.css), 'utf8') : '');
    const twJs = 'document.head.append(Object.assign(document.createElement("style"), {textContent: ' + JSON.stringify(css) + '}));';
    const results = [];
    const out = {push: r => { results.push(r); process.stderr.write(`   ${r.action}: ${r.pass !== undefined ? (r.pass ? 'PASS' : 'FAIL') + ' ' : ''}longest ${fmt(r.longest)} ms${r.dom ? ', ' + r.dom + ' elements' : ''}${r.server ? ', ' + r.server : ''}${r.note ? ' (' + r.note + ')' : ''}\n`); }};
    const cpu = args.cpu ? ' cpu/' + args.cpu : '';

    for (const [label, phone] of [['desktop 1600' + cpu, false], ['phone 390' + cpu, true]]) {
        if ((phone && !args.phone && !args['only-phone']) || (!phone && args['only-phone'])) continue;
        const page = await Page.open(cdp, {width: 1600, height: 1000});
        if (phone) await page.send('Emulation.setDeviceMetricsOverride', {width: 390, height: 844, deviceScaleFactor: 2, mobile: true});
        if (args.cpu) await page.send('Emulation.setCPUThrottlingRate', {rate: Number(args.cpu)});
        // The layout's Tailwind CDN script: answered with the locally built stylesheet (no outside connection).
        await page.send('Fetch.enable', {patterns: [{urlPattern: '*cdn.tailwindcss.com*'}]});
        cdp.on(msg => {
            if (msg.sessionId !== page.sid || msg.method !== 'Fetch.requestPaused') return;
            page.send('Fetch.fulfillRequest', {requestId: msg.params.requestId, responseCode: 200, responseHeaders: [{name: 'Content-Type', value: 'application/javascript'}],
                body: Buffer.from(twJs).toString('base64')}).catch(() => {});
        });
        await page.goto(BASE + '/login');
        await page.ev(`(() => { const f = document.querySelector('form[action$="/login"]'); f.email.value = 'perf217@example.test'; f.password.value = 'perf217-local-only'; f.submit(); return true; })()`);
        await page.waitFor('location.pathname !== "/login" && document.readyState === "complete"', 60000);
        process.stderr.write(`\n== ${label}\n`);
        const l = await load(page, out, label);
        // --eval=<expression>: printed after the load, then the run stops (to look at the page).
        if (args.eval) { console.log(JSON.stringify(await page.ev(String(args.eval)))); break; }
        const s = await scroll(page, out, label);
        const ag = await adGroups(page, out, label);
        const ok = l.serverMs < SERVER_MS && l.usable < USABLE_MS && l.afterLoad < TASK_MS && s.longest < TASK_MS;
        out.push({action: label + ': ' + (phone ? 'S-217.1.4 loads and scrolls without freezing at 390 px' : 'S-217.1.1 server < 1 s, usable < 2 s, no task over 200 ms after'),
            pass: ok && (!phone || ag.longest < TASK_MS * 5),
            note: `server ${Math.round(l.serverMs)} ms, usable at ${l.usable} ms, longest task after usable ${fmt(l.afterLoad)} ms, while scrolling ${fmt(s.longest)} ms; ad groups and Edit: longest ${fmt(ag.longest)} ms`});
        if (page.errors.length) out.push({action: label + ': page errors', pass: false, note: JSON.stringify(page.errors.slice(-3))});
        await cdp.send('Target.closeTarget', {targetId: page.targetId});
    }
    printTable(results);
    if (args.json) writeFileSync(String(args.json), JSON.stringify(results, null, 2));
    cdp.close();
}

const fmt = v => v == null ? '-' : (v < 50 ? '<50' : String(Math.round(v)));
function printTable(rows) {
    const head = ['Action', 'Pass', 'Longest task', 'Longest frame', 'Elapsed / usable', 'Elements', 'Server', 'Note'];
    const body = rows.map(r => [r.action, r.pass === undefined ? '-' : (r.pass ? 'yes' : 'NO'), fmt(r.longest), fmt(r.frame),
        r.usable != null ? 'usable at ' + r.usable : (r.elapsed == null ? '-' : String(Math.round(r.elapsed))),
        r.dom ?? '-', r.server ?? '-', (r.afterLoad != null ? 'longest after usable: ' + fmt(r.afterLoad) + '; ' : '') + (r.note ?? '')]);
    const w = head.map((h, i) => Math.max(h.length, ...body.map(b => String(b[i]).length)));
    const line = cells => '| ' + cells.map((c, i) => String(c).padEnd(w[i])).join(' | ') + ' |';
    console.log(line(head));
    console.log('|' + w.map(n => '-'.repeat(n + 2)).join('|') + '|');
    for (const b of body) console.log(line(b));
}

main().then(() => process.exit(0), e => { console.error(e); process.exit(1); });
