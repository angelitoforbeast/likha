#!/usr/bin/env node
// /chatbot/pages on the spec 217 set (tests/perf/set217.php), in headless Chrome over the DevTools protocol: the server
// time and size of the page and its data, the DOM size, and the longest main-thread task of each action (load,
// scrolling, each Ad-based page's ad groups shown and hidden, Manage in Edit), at 1600 px and at 390 px, with the
// verdict of the cases S-217.1.1, 1.2 and 1.4.
//
// One-time setup (local test values only, no env file): php tests/perf/set217.php migrate && php tests/perf/set217.php seed
// Run: node tests/perf/pages217.mjs [--cpu=4] [--phone] [--only-phone] [--json=file] [--css=file] [--profile=<action>|load] [--trace=<action>|load] [--php=path]
//   --cpu=N slows the page's CPU N times (a normal office PC is slower than this machine; the verdicts use the run given).
//   --eval=<expression> prints it after the load and stops; --features runs only the S-217.1.3 checks after the load.
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
const {measure, startTrace, topFunctions, GRID_CHECK} = await import('./screens.mjs');
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
        // The row may still be its light stand-in until it is near the view: bring it in and wait for the button.
        await p.ev(`(() => { document.querySelector('#cb-table tr[data-page="${page}"]').scrollIntoView({block: 'center'}); return true; })()`);
        await p.waitFor(`document.querySelector('button[aria-label^="Show the ad groups of ${page}"]') && !document.querySelector('[aria-busy="true"]')`, 60000);
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

/**
 * S-217.1.3: every feature of the screen still works on the lazily built grid, in real Chrome (each check opens or
 * writes what it says and reads the result back). Native questions (confirm) are answered yes.
 */
async function features(p, out, label) {
    const RUN = Date.now().toString(36);
    const row = page => `document.querySelector('#cb-table tr[data-page="${page}"]:not(.ag-sub)')`;
    const cellOf = (page, key) => `${row(page)}.querySelector('td[data-key="${key}"]')`;
    const shown = id => `!document.getElementById('${id}').classList.contains('hidden')`;
    const results = [];
    const check = async (action, run, ok, timeout = 15000) => {
        let pass = false, note = '';
        try { await p.ev(`(async () => { ${run}; return true; })()`); pass = !!(await p.waitFor(ok, timeout)); } catch (e) { note = e.message.slice(0, 200); }
        results.push(pass);
        out.push({action: label + ': S-217.1.3 ' + action, pass, note});
    };
    const paste = (page, key, tsv) => `const dt = new DataTransfer(); dt.setData('text/plain', ${JSON.stringify(tsv)}); ${cellOf(page, key)}.dispatchEvent(new ClipboardEvent('paste', {clipboardData: dt, bubbles: true, cancelable: true}))`;
    await check('inline ✎ edit saves', `${cellOf('Perf Page 003', 'main_flow_2')}.querySelector('.cb-edit').click(); const t = document.getElementById('long-text'); t.value = 'PERF217 inline ${RUN}'; t.dispatchEvent(new Event('input')); document.getElementById('long-close').click()`,
        `${cellOf('Perf Page 003', 'main_flow_2')}.textContent.includes('PERF217 inline ${RUN}') && !${cellOf('Perf Page 003', 'main_flow_2')}.textContent.includes('Saving')`);
    await check('multi-cell paste shows 4 cells on 2 pages', `${cellOf('Perf Page 004', 'main_flow')}.click(); ${paste('Perf Page 004', 'main_flow', `P1 ${RUN}\tP2 ${RUN}\nP3 ${RUN}\tP4 ${RUN}`)}`,
        `/4 cells changed on 2 pages/.test(document.getElementById('bar-text').textContent)`);
    await check('the pasted cells save', `document.getElementById('bar-save').click()`,
        `/Saved 4 fields on 2 pages/.test(document.getElementById('bar-text').textContent) && ${cellOf('Perf Page 005', 'main_flow_2')}.textContent.includes('P4 ${RUN}')`);
    await check('Undo puts them back', `document.getElementById('bar-undo').click()`,
        `/^Undone: 4 fields/.test(document.getElementById('notice').textContent) && !${cellOf('Perf Page 005', 'main_flow_2')}.textContent.includes('P4 ${RUN}')`);
    await check('Edit window saves a field', `document.querySelector('button[aria-label="Edit Perf Page 006"]').click(); const i = document.querySelector('#pe-fields [data-key="main_flow"] .pe-in'); i.value = 'PERF217 edit ${RUN}'; i.dispatchEvent(new Event('input', {bubbles: true})); document.getElementById('pe-save').click()`,
        `${shown('pe')} === false && ${cellOf('Perf Page 006', 'main_flow')}.textContent.includes('PERF217 edit ${RUN}')`);
    await check('History lists the versions', `document.querySelector('button[aria-label="Edit Perf Page 006"]').click(); document.querySelector('#pe-fields button[aria-label="History of Main Flow"]').click()`,
        `${shown('hist')} && [...document.querySelectorAll('#hist-list button')].some(b => b.textContent === 'Restore')`);
    await check('Restore brings back the older value', `[...document.querySelectorAll('#hist-list button')].find(b => b.textContent === 'Restore').click()`,
        `/^Restored/.test(document.getElementById('hist-note').textContent) && !${cellOf('Perf Page 006', 'main_flow')}.textContent.includes('PERF217 edit ${RUN}')`);
    await p.ev(`(() => { document.querySelector('#hist [data-close-dialog]').click(); document.getElementById('pe-close').click(); return true; })()`);
    await check('the Check column shows a result', 'true', `!document.querySelector('#cb-table td[data-check="Perf Page 002"]').textContent.includes('Checking')`, 60000);
    await check('search filter', `const f = document.getElementById('f-q'); f.value = 'Page 01'; f.dispatchEvent(new Event('input', {bubbles: true}))`,
        `/^10 of 80/.test(document.getElementById('count').textContent) && [...document.querySelectorAll('#cb-table tbody tr[data-page]:not([hidden]):not(.ag-sub)')].length === 10`);
    await check('clearing the filter shows every page', `const f = document.getElementById('f-q'); f.value = ''; f.dispatchEvent(new Event('input', {bubbles: true}))`,
        `/^80 of 80/.test(document.getElementById('count').textContent) && [...document.querySelectorAll('#cb-table tbody tr[data-page]:not([hidden]):not(.ag-sub)')].length === 80`);
    await check('Replies switch to Ad-based', `const s = document.querySelector('select[aria-label="Reply values of Perf Page 002"]'); s.value = 'ad'; s.dispatchEvent(new Event('change'))`,
        `document.querySelector('button[aria-label^="Show the ad groups of Perf Page 002"], button[aria-label^="Hide the ad groups of Perf Page 002"]')`);
    await check('Replies switch back to Page-based', `const s = document.querySelector('select[aria-label="Reply values of Perf Page 002"]'); s.value = 'page'; s.dispatchEvent(new Event('change'))`,
        `document.querySelector('select[aria-label="Reply values of Perf Page 002"]')?.value === 'page' && !document.querySelector('#cb-table tr.ag-sub')`);
    await check('a far row is built when scrolled to', `const b = document.getElementById('cb-box'); b.scrollTop = b.scrollHeight`,
        `${row('Perf Page 080')} && ${row('Perf Page 080')}.querySelector('td.cb-cell') && !document.querySelector('[aria-busy="true"]')`);
    await p.ev(`(() => { document.getElementById('cb-box').scrollTop = 0; return true; })()`);
    const g = await p.ev(GRID_CHECK);
    out.push({action: label + ': S-217.1.3 the grid matches the server', pass: g.wrongCount === 0 && !g.dupes.length && !g.inert && g.visible === 80,
        note: `${g.visible} rows shown (${g.count}), ${g.wrongCount} values differ, ${g.dupes.length} duplicate rows, ${g.inert} inert`});
    results.push(g.wrongCount === 0 && !g.dupes.length && !g.inert && g.visible === 80);
    if (p.errors.length) { out.push({action: label + ': S-217.1.3 page errors', pass: false, note: JSON.stringify(p.errors.slice(-3))}); results.push(false); }
    return results.every(Boolean);
}

/**
 * S-217.2 (the group Edit), 3.1 (the order shown), 4.1 (the Ad dropdown of /chatbot/test) and 5 (Manage in Edit, Default)
 * in real Chrome on Perf Page 007 (Ad-based, 6 groups). The paste's AI answer is faked (page.fakeMap); everything else is
 * the real page and server. Native questions (confirm) are answered yes.
 */
async function groupCases(p, out, label) {
    const RUN = Date.now().toString(36), PAGE = 'Perf Page 007';
    const shown = id => `!document.getElementById('${id}').classList.contains('hidden')`;
    const panel = `fetch('/chatbot/ad-groups?page=' + encodeURIComponent(${JSON.stringify(PAGE)}), {headers: {Accept: 'application/json'}}).then(r => r.json())`;
    const check = async (cs, action, run, ok, timeout = 15000) => {
        let pass = false, note = '';
        try { await p.ev(`(async () => { ${run}; return true; })()`); pass = !!(await p.waitFor(ok, timeout)); } catch (e) { note = e.message.slice(0, 220); }
        out.push({action: label + ': ' + cs + ' ' + action, pass, note});
        return pass;
    };
    const input = key => `document.querySelector('#pe-fields [data-key="${key}"] .pe-in')`;
    await p.goto(BASE + '/chatbot/pages');
    await p.waitFor(settled, 120000);
    await p.ev(`(() => { document.querySelector('#cb-table tr[data-page="${PAGE}"]').scrollIntoView({block: 'start'}); return true; })()`);
    await p.waitFor(`document.querySelector('button[aria-label^="Show the ad groups of ${PAGE}"]')`, 30000);
    await p.ev(`(() => { document.querySelector('button[aria-label^="Show the ad groups of ${PAGE}"]').click(); return true; })()`);
    await p.waitFor(`document.querySelector('#cb-table tr.ag-sub[data-ag-group]')`, 30000);
    const data = await p.ev(panel);
    const g1 = data.groups[0], g2 = data.groups[1];

    await check('S-217.3.1', 'the table shows the groups in the server\'s order (running first)', 'true',
        `JSON.stringify([...document.querySelectorAll('#cb-table tr.ag-sub[data-ag-group]')].map(tr => Number(tr.dataset.agGroup))) === ${JSON.stringify(JSON.stringify(data.groups.filter(g => !g.hidden).map(g => g.id)))}`);
    await check('S-217.2.1', 'each group row in the list has an Edit button', 'true',
        `[...document.querySelectorAll('#cb-table tr.ag-sub[data-ag-group]')].every(tr => tr.querySelector('button[aria-label^="Edit "][aria-label$=" of ${PAGE}"]'))`);
    await check('S-217.5.2', 'the Default row is "Default (page values)" with the page row\'s values', 'true', `(() => {
        const d = document.querySelector('#cb-table tr.ag-sub[data-page="${PAGE}"]'), pg = document.querySelector('#cb-table tr[data-page="${PAGE}"]:not(.ag-sub)');
        const vals = tr => [...tr.querySelectorAll('td.cb-cell[data-key]')].map(td => td.dataset.key + '=' + (td.querySelector('.cb-txt, span.truncate')?.textContent ?? ''));
        return d.textContent.includes('Default (page values)') && JSON.stringify(vals(d)) === JSON.stringify(vals(pg)) && vals(d).length > 20; })()`);
    await check('S-217.5.1', 'Manage in Edit is a normal-size primary button next to the count', 'true', `(() => {
        const b = [...document.querySelectorAll('#cb-table tr.ag-sub button')].find(x => x.textContent === 'Manage in Edit');
        return b && b.classList.contains('cb-btn-primary') && !b.classList.contains('cb-btn-sm') && /ads? not in a group yet/.test(b.previousElementSibling?.textContent || ''); })()`);
    await check('S-217.5.1', 'Manage in Edit opens the Edit window at the Ad groups section', `[...document.querySelectorAll('#cb-table tr.ag-sub button')].find(x => x.textContent === 'Manage in Edit').click()`,
        `${shown('pe')} && document.getElementById('pe-name').textContent === '${PAGE}' && document.querySelector('#pe-ag [data-ag-group]') && (() => { const a = document.getElementById('pe-ag').getBoundingClientRect(), f = document.getElementById('pe-fields').getBoundingClientRect(); return a.top < f.bottom && a.bottom > f.top; })()`);
    await check('S-217.2.1', 'Manage in Edit has an Edit for each group, and it opens the group Edit', `document.querySelector('#pe-ag button[aria-label="Edit ${g2.name} of ${PAGE}"]').click()`,
        `${shown('pe')} && document.getElementById('pe-name').textContent === '${PAGE} · ${g2.name}' && !document.getElementById('pe-ag')`);
    await check('S-217.2.1', 'closing it goes back to the page Edit', `document.getElementById('pe-close').click()`,
        `${shown('pe')} && document.getElementById('pe-name').textContent === '${PAGE}' && document.getElementById('pe-ag')`);
    await p.ev(`(() => { document.getElementById('pe-close').click(); return true; })()`);
    await p.waitFor(`!${shown('pe')}`);

    // The group Edit from the list: title, the page Edit's fields, own values, and empty = "uses default" with the page value.
    const pageRow = await p.ev(`fetch('/chatbot/data', {headers: {Accept: 'application/json'}}).then(r => r.json()).then(d => ({cells: d.rows.find(r => r.page === ${JSON.stringify(PAGE)}).cells, cols: d.columns}))`);
    const own = g1.values, emptyKey = Object.keys(pageRow.cells).find(k => !own[k] && pageRow.cells[k] && !/^(flow|shop_details)$/.test(k) && !/img|picture/.test(k));
    await check('S-217.2.1', 'the group Edit opens titled with the page and the group', `document.querySelector('#cb-table button[aria-label="Edit ${g1.name} of ${PAGE}"]').click()`,
        `${shown('pe')} && document.getElementById('pe-name').textContent === '${PAGE} · ${g1.name}'`);
    await check('S-217.2.1', 'it shows the page Edit\'s fields, the group\'s own values, and the page value muted where empty', 'true', `(() => {
        const keys = [...document.querySelectorAll('#pe-fields .pe-field')].map(f => f.dataset.key), want = ${JSON.stringify(pageRow.cols.filter(c => c.key !== 'flow').map(c => c.key))};
        const def = document.querySelector('#pe-fields [data-key="${emptyKey}"] .pe-default');
        return JSON.stringify(keys) === JSON.stringify(want) && ${input('main_flow')}.value === ${JSON.stringify(own.main_flow || '')}
            && ${input(emptyKey)}.value === '' && def && !def.classList.contains('hidden') && def.textContent === 'uses default: ' + ${JSON.stringify(pageRow.cells[emptyKey])}; })()`);
    await check('S-217.2.3', 'the group Edit has no field for the identity and switch keys', 'true',
        `![...document.querySelectorAll('#pe-fields .pe-field')].some(f => ['token', 'provider', 'active', 'page_id', 'mode', 'gateway', 'botcake_id'].includes(f.dataset.key)) && !document.querySelector('#pe-fields .pe-in[id="pe-f-shop_details"]')`);

    // S-217.2.2: a row from Google Sheets, mapped by the (faked) AI through /chatbot/paste-map, preview, Apply, Save.
    p.fakeMap = {0: 'main_flow', 1: 'price', 2: 'seq_1'};
    p.pasteMaps.length = 0;
    await check('S-217.2.2', 'a pasted row goes to /chatbot/paste-map as for the page, and the preview shows', `const t = document.getElementById('pe-paste'); t.value = 'G1 main ${RUN}\\t555\\t'; t.dispatchEvent(new Event('input'))`,
        `${shown('pe-map')} && document.querySelectorAll('#pe-map-list .pe-map-item').length === 2 && !document.getElementById('pe-map-apply').disabled`);
    out.push({action: label + ': S-217.2.2 the paste-map request is the page\'s (page name and the pasted cells)', pass: p.pasteMaps.length === 1 && p.pasteMaps[0].page_name === PAGE && JSON.stringify(p.pasteMaps[0].cells) === JSON.stringify([`G1 main ${RUN}`, '555', '']),
        note: JSON.stringify(p.pasteMaps.map(m => ({page_name: m.page_name, cells: m.cells})))});
    await check('S-217.2.5', 'Apply with "Paste blanks as empty" off fills the values and leaves the blank\'s field as it was', `document.getElementById('pe-blanks').checked = false; document.getElementById('pe-map-apply').click()`,
        `${input('main_flow')}.value === 'G1 main ${RUN}' && ${input('price')}.value === '555' && ${input('seq_1')}.value === ${JSON.stringify(own.seq_1 || '')}`);
    await check('S-217.2.5', 'with "Paste blanks as empty" on, the blank empties the field (uses default), as on the page', `const b = document.getElementById('pe-blanks'); b.checked = true; b.dispatchEvent(new Event('change'))`,
        `${input('seq_1')}.value === '' && !document.querySelector('#pe-fields [data-key="seq_1"] .pe-default').classList.contains('hidden')`);
    await check('S-217.2.2', 'Save writes the group values', `document.getElementById('pe-save').click()`,
        `${shown('pe')} === false && /^Saved 3 values of "${g1.name}"/.test(document.getElementById('notice').textContent)`);
    const after = (await p.ev(panel)).groups.find(g => g.id === g1.id).values;
    out.push({action: label + ': S-217.2.2 the server has the group values (the blank back to the page value)', pass: after.main_flow === `G1 main ${RUN}` && after.price === '555' && !after.seq_1,
        note: JSON.stringify({main_flow: after.main_flow, price: after.price, seq_1: after.seq_1 ?? null})});
    await check('S-217.2.2', 'History of the group (from the group Edit) lists the old value with Restore', `document.querySelector('#cb-table button[aria-label="Edit ${g1.name} of ${PAGE}"]').click(); document.querySelector('#pe-fields button[aria-label="History of Main Flow"]').click()`,
        `[...document.querySelectorAll('[role="dialog"][aria-label="History · ${g1.name}"] button')].some(b => b.getAttribute('aria-label')?.startsWith('Restore Main Flow'))`);
    await check('S-217.2.2', 'Restore brings the old value back', `[...document.querySelectorAll('[role="dialog"][aria-label="History · ${g1.name}"] button')].find(b => b.getAttribute('aria-label')?.startsWith('Restore Main Flow')).click()`,
        `/^Restored Main Flow/.test([...document.querySelectorAll('[role="dialog"][aria-label="History · ${g1.name}"] p')].map(x => x.textContent).join(' '))`);
    const restored = (await p.ev(panel)).groups.find(g => g.id === g1.id).values;
    out.push({action: label + ': S-217.2.2 the restored value is the group\'s again', pass: restored.main_flow === own.main_flow, note: 'main_flow: ' + JSON.stringify((restored.main_flow || '').slice(0, 40))});
    await p.ev(`(() => { document.querySelector('[role="dialog"][aria-label="History · ${g1.name}"] button[aria-label="Close"]').click(); document.getElementById('pe-close').click(); return true; })()`);

    // S-217.4.1: the Ad dropdown of /chatbot/test.
    await p.goto(BASE + '/chatbot/test');
    const pick = page => `(() => { const s = document.getElementById('t-page'); s.value = ${JSON.stringify(page)}; s.dispatchEvent(new Event('change')); return true; })()`;
    await check('S-217.4.1', 'an Ad-based page has the Ad dropdown: its groups, then the ads not in a group, On first', pick(PAGE),
        `${shown('t-ad-box')} && [...document.querySelectorAll('#t-ad optgroup')].map(o => o.label).join('|') === ${JSON.stringify([...data.groups.map(g => g.name), 'Not in a group'].join('|'))}
            && [...document.querySelectorAll('#t-ad optgroup')].every(o => { const on = [...o.children].map(x => / · On · /.test(x.textContent)); return on.indexOf(false) === -1 || !on.slice(on.indexOf(false)).includes(true); })`);
    await check('S-217.4.3', 'a Page-based page has no Ad dropdown', pick('Perf Page 001'), `!${shown('t-ad-box')}`);
    const ad = g1.ads[0].ad_id;
    await check('S-217.4.2', 'a test as a customer from an ad in a group says which group', `${pick(PAGE)}; await new Promise(r => setTimeout(r, 1500)); document.getElementById('t-ad').value = '${ad}'; document.getElementById('t-msg').value = 'Hi'; document.getElementById('t-form').requestSubmit()`,
        `document.getElementById('t-log').textContent.includes('Ad ${ad} → group ${g1.name}')`, 30000);
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
        // The layout's Tailwind CDN script: answered with the locally built stylesheet (no outside connection). The AI of
        // the Edit paste (/chatbot/paste-map): answered here with page.fakeMap (cell => key), and the request is kept.
        await page.send('Fetch.enable', {patterns: [{urlPattern: '*cdn.tailwindcss.com*'}, {urlPattern: '*/chatbot/paste-map*'}]});
        page.pasteMaps = [];
        cdp.on(msg => {
            if (msg.sessionId !== page.sid || msg.method !== 'Fetch.requestPaused') return;
            const map = msg.params.request.url.includes('/chatbot/paste-map');
            if (map) page.pasteMaps.push(JSON.parse(msg.params.request.postData || '{}'));
            const body = map ? JSON.stringify({mapping: Object.entries(page.fakeMap || {}).map(([cell, key]) => ({cell: Number(cell), key, confidence: 'high'}))}) : twJs;
            page.send('Fetch.fulfillRequest', {requestId: msg.params.requestId, responseCode: 200, responseHeaders: [{name: 'Content-Type', value: map ? 'application/json' : 'application/javascript'}],
                body: Buffer.from(body).toString('base64')}).catch(() => {});
        });
        await page.goto(BASE + '/login');
        await page.ev(`(() => { const f = document.querySelector('form[action$="/login"]'); f.email.value = 'perf217@example.test'; f.password.value = 'perf217-local-only'; f.submit(); return true; })()`);
        await page.waitFor('location.pathname !== "/login" && document.readyState === "complete"', 60000);
        process.stderr.write(`\n== ${label}\n`);
        const l = await load(page, out, label);
        // --eval=<expression>: printed after the load, then the run stops (to look at the page).
        if (args.eval) { console.log(JSON.stringify(await page.ev(String(args.eval)))); break; }
        if (args.features) { out.push({action: label + ': S-217.1.3 every feature of the screen still works', pass: await features(page, out, label)}); break; }
        if (args.groups) { await groupCases(page, out, label); break; }
        const s = await scroll(page, out, label);
        const ag = await adGroups(page, out, label);
        const ok = l.serverMs < SERVER_MS && l.usable < USABLE_MS && l.afterLoad < TASK_MS && s.longest < TASK_MS;
        out.push({action: label + ': ' + (phone ? 'S-217.1.4 loads and scrolls without freezing at 390 px' : 'S-217.1.1 server < 1 s, usable < 2 s, no task over 200 ms after'),
            pass: ok && (!phone || ag.longest < TASK_MS * 5),
            note: `server ${Math.round(l.serverMs)} ms, usable at ${l.usable} ms, longest task after usable ${fmt(l.afterLoad)} ms, while scrolling ${fmt(s.longest)} ms; ad groups and Edit: longest ${fmt(ag.longest)} ms`});
        if (page.errors.length) out.push({action: label + ': page errors', pass: false, note: JSON.stringify(page.errors.slice(-3))});
        // The features on the desktop run (they write values, so once per run).
        if (!phone) out.push({action: label + ': S-217.1.3 every feature of the screen still works', pass: await features(page, out, label)});
        if (!phone) await groupCases(page, out, label);
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
