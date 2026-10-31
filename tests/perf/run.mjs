#!/usr/bin/env node
// Main-thread timing of the chatbot screens on a seeded local set, in headless Chrome over the DevTools protocol.
//
// One-time setup (local test values only; never the real .env):
//   1. Create .env.perf209 at the project root (sqlite under storage/perf209, QUEUE_CONNECTION=null, no outside keys;
//      the values are listed in the result of spec 209, "How to run").
//   2. php tests/perf/migrate.php && php tests/perf/seed.php
// Run (starts the local server and Chrome when they are not running, stops what it started):
//   node tests/perf/run.mjs [--only=pages,flow,...] [--json=file] [--php=path] [--chrome=path]
// It prints one row per action: the longest main-thread task, the longest animation frame (task plus style, layout
// and paint), the sum of long tasks, and the time until the action's result showed. Tasks under 50 ms are not
// reported by Chrome, so "<50" means no long task at all.
import {spawn, spawnSync} from 'node:child_process';
import {mkdirSync, writeFileSync, existsSync} from 'node:fs';
import {join, dirname} from 'node:path';
import {fileURLToPath} from 'node:url';
import {Cdp, Page, sleep} from './cdp.mjs';
import {tailwindCss} from './tailwind.mjs';
import {SCREENS} from './screens.mjs';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const args = Object.fromEntries(process.argv.slice(2).map(a => { const [k, ...v] = a.replace(/^--/, '').split('='); return [k, v.join('=') || true]; }));
const BASE = 'http://127.0.0.1:' + (args.port || 8209);
const DEBUG_PORT = Number(args['debug-port'] || 9339);
const PHP = args.php || process.env.PHP || (existsSync('C:/Users/Forbeast/.config/herd/bin/php84/php.exe') ? 'C:/Users/Forbeast/.config/herd/bin/php84/php.exe' : 'php');
const CHROME = args.chrome || process.env.CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const started = [];
// The PHP and project folder for tests/perf/churn.php (new chats between actions).
process.env.PERF_PHP = PHP;
process.env.PERF_ROOT = root;

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
process.on('SIGTERM', () => process.exit(143));

async function main() {
    if (!existsSync(join(root, '.env.perf209'))) throw new Error('Missing .env.perf209: see the header of this file.');
    if (!await up(BASE + '/login')) {
        const srv = spawn(PHP, ['artisan', 'serve', '--env=perf209', '--host=127.0.0.1', '--port=' + (args.port || 8209), '--no-reload'], {cwd: root, env: {...process.env, APP_ENV: 'perf209'}, stdio: 'ignore'});
        started.push(srv);
        if (!await up(BASE + '/login', 120)) throw new Error('The local server did not start on ' + BASE);
    }
    let version = await up(`http://127.0.0.1:${DEBUG_PORT}/json/version`);
    if (!version) {
        const profile = join(root, 'storage', 'perf209', 'chrome-' + Date.now());
        mkdirSync(profile, {recursive: true});
        // No outside host is reachable: everything but the local server fails to resolve, and Chrome's own
        // background traffic is off.
        const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${DEBUG_PORT}`, `--user-data-dir=${profile}`, '--window-size=1600,1000',
            '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1', '--disable-background-networking', '--disable-component-update', '--disable-sync',
            '--disable-extensions', '--no-first-run', '--no-default-browser-check', '--disable-default-apps', '--metrics-recording-only', 'about:blank'], {stdio: 'ignore'});
        started.push(chrome);
        version = await up(`http://127.0.0.1:${DEBUG_PORT}/json/version`, 120);
        if (!version) throw new Error('Chrome did not start');
    }
    const cdp = await Cdp.connect((await version.json()).webSocketDebuggerUrl);
    const css = await tailwindCss(root);
    const page = await Page.open(cdp);
    // The layout's Tailwind CDN script: answered here with the locally built stylesheet.
    await page.send('Fetch.enable', {patterns: [{urlPattern: '*cdn.tailwindcss.com*'}]});
    const twJs = 'document.head.append(Object.assign(document.createElement("style"), {textContent: ' + JSON.stringify(css) + '}));';
    cdp.on(msg => {
        if (msg.sessionId !== page.sid || msg.method !== 'Fetch.requestPaused') return;
        page.send('Fetch.fulfillRequest', {requestId: msg.params.requestId, responseCode: 200, responseHeaders: [{name: 'Content-Type', value: 'application/javascript'}],
            body: Buffer.from(twJs).toString('base64')}).catch(() => {});
    });

    // Sign in as the seeded local test user (tests/perf/seed.php).
    await page.goto(BASE + '/login');
    await page.ev(`(() => { const f = document.querySelector('form[action$="/login"]'); f.email.value = 'perf209@example.test'; f.password.value = 'perf209-local-only'; f.submit(); return true; })()`);
    await page.waitFor('location.pathname !== "/login" && document.readyState === "complete"', 60000);

    const only = args.only ? String(args.only).split(',') : null;
    const results = [];
    for (const screen of SCREENS) {
        if (only && !only.includes(screen.name)) continue;
        process.stderr.write(`\n== ${screen.name} (${screen.path})\n`);
        // Each action is printed as soon as it is measured; an error keeps the ones before it.
        const out = [];
        out.push = r => {
            results.push({screen: screen.name, ...r});
            process.stderr.write(`   ${r.action}: longest ${fmt(r.longest)} ms, frame ${fmt(r.frame)} ms, elapsed ${r.elapsed == null ? '-' : Math.round(r.elapsed)} ms${r.note ? ' (' + r.note + ')' : ''}\n`);
            if (args.json) writeFileSync(String(args.json), JSON.stringify(results, null, 2));
            return Array.prototype.push.call(out, r);
        };
        try {
            await screen.run(page, BASE, out);
        } catch (e) {
            results.push({screen: screen.name, action: 'ERROR', note: e.message});
            process.stderr.write('   ERROR ' + e.message + '\n');
            const state = await page.ev(`({url: location.href, title: document.title, notice: document.getElementById('notice')?.textContent, body: document.body.innerText.slice(0, 300)})`).catch(x => x.message);
            process.stderr.write('   page: ' + JSON.stringify(state) + '\n   errors: ' + JSON.stringify(page.errors.slice(-5)) + '\n');
        }
    }
    printTable(results);
    if (args.json) writeFileSync(String(args.json), JSON.stringify(results, null, 2));
    cdp.close();
}

const fmt = v => v == null ? '-' : (v < 50 ? '<50' : String(Math.round(v)));
function printTable(rows) {
    const head = ['Screen', 'Action', 'Longest task', 'Longest frame', 'Long tasks total', 'Elapsed', 'Target', 'Pass'];
    const body = rows.map(r => [r.screen, r.action, fmt(r.longest), fmt(r.frame), r.total == null ? '-' : String(Math.round(r.total)),
        r.elapsed == null ? '-' : String(Math.round(r.elapsed)), r.target ? '<' + r.target : '-', r.action === 'ERROR' ? 'ERROR' : (r.target ? ((r.longest || 0) < r.target ? 'yes' : 'NO') : (r.pass === undefined ? '-' : (r.pass ? 'yes' : 'NO')))]);
    const w = head.map((h, i) => Math.max(h.length, ...body.map(b => String(b[i]).length)));
    const line = cells => '| ' + cells.map((c, i) => String(c).padEnd(w[i])).join(' | ') + ' |';
    console.log(line(head));
    console.log('|' + w.map(n => '-'.repeat(n + 2)).join('|') + '|');
    for (const b of body) console.log(line(b));
    for (const r of rows) if (r.action === 'ERROR' || r.note) console.log(`  ${r.screen} · ${r.action}: ${r.note}`);
}

main().then(() => process.exit(0), e => { console.error(e); process.exit(1); });
