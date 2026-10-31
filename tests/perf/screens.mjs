// The screens and actions the timing script measures. Each action: what is done in the page, the condition that
// shows its result, and how long to keep watching afterwards for trailing work (a re-render a moment later).
import {sleep} from './cdp.mjs';

const RUN = Date.now().toString(36); // each run writes new values, so a rerun on the same database still changes them
const TARGET = 200; // no main-thread task over 200 ms for an action
const LOAD_TARGET = 500; // first load: under 500 ms after the data arrives
// How long one action may take before it is recorded as unfinished (the code before the fix needs minutes).
const TIMEOUT = Number(process.env.PERF_TIMEOUT || 600000);

const sum = list => list.reduce((a, [, d]) => a + d, 0);
const max = list => list.reduce((a, [, d]) => Math.max(a, d), 0);

/**
 * Runs one action and reads the long tasks and frames that started from just before it until the end of settle.
 * An action whose result does not show in time is still recorded (with what the page did meanwhile).
 */
export async function measure(page, action, run, done, {settle = 500, target = TARGET, timeout = TIMEOUT, note} = {}) {
    const t0 = await page.now();
    let t1, late = null;
    // PERF_PROFILE=<part of an action name>: a CPU profile of that action, its heaviest functions on stderr.
    const profile = process.env.PERF_PROFILE && action.includes(process.env.PERF_PROFILE);
    if (profile) { await page.send('Profiler.enable'); await page.send('Profiler.setSamplingInterval', {interval: 200}); await page.send('Profiler.start'); }
    // PERF_TRACE=<part of an action name>: the time the renderer spent per kind of work (script, style, layout, paint).
    const trace = process.env.PERF_TRACE && action.includes(process.env.PERF_TRACE) ? await startTrace(page.cdp) : null;
    try {
        await page.ev(`(() => { ${run}; return true; })()`, timeout);
        // Done: the result shows and no table is still bringing in rows (aria-busy).
        t1 = await page.waitFor(`(${done}) && !document.querySelector('[aria-busy="true"]')`, timeout);
        await sleep(settle);
    } catch (e) {
        late = e.message.slice(0, 160);
        t1 = await page.now();
    }
    if (profile) process.stderr.write(topFunctions((await page.send('Profiler.stop')).profile));
    if (trace) process.stderr.write(await trace());
    const {lt, loaf} = await page.tasks(t0);
    return {action, longest: max(lt), frame: max(loaf), total: sum(lt), count: lt.length, elapsed: t1 - t0, target, note: late ? 'NOT FINISHED: ' + late : note};
}

/** Starts a timeline trace; the returned function stops it and sums the main-thread work by event name. */
export async function startTrace(cdp) {
    const events = [];
    let done;
    const finished = new Promise(r => { done = r; });
    cdp.on(m => {
        if (m.method === 'Tracing.dataCollected') events.push(...m.params.value);
        if (m.method === 'Tracing.tracingComplete') done();
    });
    await cdp.send('Tracing.start', {traceConfig: {includedCategories: ['devtools.timeline', 'disabled-by-default-devtools.timeline']}, transferMode: 'ReportEvents'});
    return async () => {
        await cdp.send('Tracing.end');
        await finished;
        // The busiest renderer main thread is the page's.
        const mains = new Set(events.filter(e => e.name === 'thread_name' && e.args?.name === 'CrRendererMain').map(e => e.pid + ':' + e.tid)), busy = new Map();
        for (const e of events) if (e.ph === 'X' && e.name === 'RunTask' && mains.has(e.pid + ':' + e.tid)) busy.set(e.pid + ':' + e.tid, (busy.get(e.pid + ':' + e.tid) || 0) + (e.dur || 0));
        const main = [...busy].sort((a, b) => b[1] - a[1])[0]?.[0];
        const by = new Map();
        for (const e of events) {
            if (e.ph !== 'X' || !main || e.pid + ':' + e.tid !== main || !e.dur) continue;
            if (!['RunTask', 'UpdateLayoutTree', 'Layout', 'Paint', 'PrePaint', 'Layerize', 'FunctionCall', 'EvaluateScript', 'ParseHTML', 'HitTest', 'ScrollLayer', 'UpdateLayer', 'RecalculateStyles', 'Commit', 'TimerFire', 'FireAnimationFrame', 'EventDispatch', 'v8.callFunction'].includes(e.name)) continue;
            const s = by.get(e.name) || [0, 0, 0];
            s[0] += e.dur / 1000; s[1]++; s[2] = Math.max(s[2], e.dur / 1000);
            by.set(e.name, s);
        }
        // The longest tasks and what was inside them.
        const mine = events.filter(e => e.ph === 'X' && e.dur && e.pid + ':' + e.tid === main);
        const tasks = mine.filter(e => e.name === 'RunTask').sort((a, b) => b.dur - a.dur).slice(0, 4).map(t => {
            const inside = new Map();
            for (const e of mine) if (e !== t && e.name !== 'RunTask' && e.ts >= t.ts && e.ts + e.dur <= t.ts + t.dur) inside.set(e.name, (inside.get(e.name) || 0) + e.dur / 1000);
            return `      task ${(t.dur / 1000).toFixed(0)} ms: ` + [...inside].sort((a, b) => b[1] - a[1]).slice(0, 7).map(([k, v]) => k + ' ' + v.toFixed(0)).join(', ');
        });
        return tasks.join('\n') + '\n    trace (total ms, count, longest ms):\n' + [...by].sort((a, b) => b[1][0] - a[1][0]).map(([k, [t, n, m]]) => `      ${k}: ${t.toFixed(1)} ms, ${n}x, longest ${m.toFixed(1)} ms`).join('\n') + '\n';
    };
}

/** The functions with the most time in a CPU profile (self and with their callees). */
function topFunctions(profile) {
    const byId = new Map(profile.nodes.map(n => [n.id, n])), parent = new Map();
    for (const n of profile.nodes) for (const c of n.children || []) parent.set(c, n.id);
    const name = n => n.callFrame.functionName + ' ' + n.callFrame.url.split('/').pop().split('?')[0] + ':' + n.callFrame.lineNumber;
    const self = new Map(), incl = new Map();
    profile.samples.forEach((id, i) => {
        const dt = profile.timeDeltas[i] || 0, seen = new Set();
        self.set(name(byId.get(id)), (self.get(name(byId.get(id))) || 0) + dt);
        for (let c = id; c; c = parent.get(c)) { const k = name(byId.get(c)); if (!seen.has(k)) { seen.add(k); incl.set(k, (incl.get(k) || 0) + dt); } }
    });
    const top = m => [...m].sort((a, b) => b[1] - a[1]).slice(0, 14).map(([k, us]) => '      ' + (us / 1000).toFixed(1) + ' ms  ' + k).join('\n');
    return '    self:\n' + top(self) + '\n    with callees:\n' + top(incl) + '\n';
}

const q = s => JSON.stringify(s);
const row = page => `document.querySelector('#cb-table tr[data-page=${q(page)}]')`;
const cellOf = (page, key) => `${row(page)}.querySelector('td[data-key=${q(key)}]')`;
const click = sel => `(${sel}).click()`;

/** /chatbot/pages: the grid of every page and value column, its modals and the bar. */
async function pages(p, base, out) {
    const trace = process.env.PERF_TRACE === 'first load' ? await startTrace(p.cdp) : null;
    const profile = process.env.PERF_PROFILE === 'first load';
    if (profile) { await p.send('Profiler.enable'); await p.send('Profiler.setSamplingInterval', {interval: 200}); await p.send('Profiler.start'); }
    await p.goto(base + '/chatbot/pages');
    // Shown: every row in the table and none still waiting to be revealed (the grid shows its rows over a few frames).
    const rendered = await p.waitFor(`document.querySelectorAll('#cb-table tbody tr[data-page]').length > 0 && !document.querySelector('#cb-table[aria-busy]')`, TIMEOUT);
    process.stderr.write('   rendered, waiting for the checks\n');
    await sleep(400);
    if (profile) process.stderr.write(topFunctions((await p.send('Profiler.stop')).profile));
    if (trace) process.stderr.write(await trace());
    const dataEnd = await p.ev(`(performance.getEntriesByType('resource').find(e => e.name.includes('/chatbot/data')) || {}).responseEnd || 0`);
    let {lt, loaf} = await p.tasks(dataEnd - 1, rendered + 400);
    out.push({action: 'first load (after the data arrives)', longest: max(lt), frame: max(loaf), total: sum(lt), count: lt.length, elapsed: rendered - dataEnd, target: LOAD_TARGET,
        note: (await p.ev(`document.querySelectorAll('#cb-table tbody tr[data-page]').length`)) + ' rows, ' + (await p.ev(`document.querySelectorAll('#cb-table td').length`)) + ' cells'});
    const checksDone = await p.waitFor(`![...document.querySelectorAll('#cb-table td[data-check]')].some(td => td.textContent.includes('Checking'))`, 3 * TIMEOUT, 250);
    ({lt, loaf} = await p.tasks(rendered + 400, checksDone + 1));
    out.push({action: 'each Check that finishes after load', longest: max(lt), frame: max(loaf), total: sum(lt), count: lt.length, elapsed: checksDone - rendered, target: 50,
        note: 'all ' + (await p.ev(`document.querySelectorAll('#cb-table td[data-check]').length`)) + ' checks done'});

    out.push(await measure(p, 'open a row (click a cell)', click(cellOf('Perf Page 005', 'main_flow')), `${row('Perf Page 005')}.classList.contains('cb-open')`));
    out.push(await measure(p, 'close the row', click(cellOf('Perf Page 005', 'main_flow')), `!${row('Perf Page 005')}.classList.contains('cb-open')`));

    // One cell: the editor's text, then closing it saves it (Saving…, Saved, and the mark cleared 2.5 s later).
    await p.ev(`(() => { ${cellOf('Perf Page 003', 'main_flow_2')}.querySelector('.cb-edit').click(); return true; })()`);
    out.push(await measure(p, 'single cell edit and save', `document.getElementById('long-text').value = 'PERF-CELL ${RUN} one\\ntwo\\nthree\\nfour'; document.getElementById('long-close').click()`,
        `${cellOf('Perf Page 003', 'main_flow_2')}?.textContent.includes('PERF-CELL ${RUN}') && !${cellOf('Perf Page 003', 'main_flow_2')}.textContent.includes('Saving')`, {settle: 3300}));

    // The Edit window of one page: 58 fields changed, then Save.
    out.push(await measure(p, 'open Edit of a page', click(`document.querySelector('button[aria-label="Edit Perf Page 010"]')`), `!document.getElementById('pe').classList.contains('hidden')`));
    await p.ev(`(() => {
        const fields = [...document.querySelectorAll('#pe-fields .pe-field[data-key]')].filter(f => f.querySelector('.pe-in')).slice(0, 58);
        for (const f of fields) { const i = f.querySelector('.pe-in'); i.value = i.tagName === 'TEXTAREA' ? 'PERF-EDIT ${RUN} ' + f.dataset.key + '\\nsecond line' : 'http://127.0.0.1:8209/favicon.ico?edit=${RUN}-' + f.dataset.key; i.dispatchEvent(new Event('input', {bubbles: true})); }
        return document.getElementById('pe-save').textContent;
    })()`);
    out.push(await measure(p, 'Save 58 fields in the Edit window', click(`document.getElementById('pe-save')`),
        `document.getElementById('pe').classList.contains('hidden') && /Saved 58 fields on 1 page\\./.test(document.getElementById('bar-text').textContent) && ${cellOf('Perf Page 010', 'main_flow')}.textContent.includes('PERF-EDIT')`, {settle: 800}));
    out.push(await measure(p, 'Undo the 58 fields', click(`document.getElementById('bar-undo')`),
        `/^Undone: 58 fields/.test(document.getElementById('notice').textContent) && !${cellOf('Perf Page 010', 'main_flow')}.textContent.includes('PERF-EDIT')`, {settle: 800}));

    // A 10 x 10 block pasted from Seq Img 1 to Seq 5 of pages 020–029, then Save.
    out.push(await measure(p, 'select a cell (opens its row)', click(cellOf('Perf Page 020', 'seq_img_1')), `${row('Perf Page 020')}.querySelector('td.cb-sel')`));
    out.push(await measure(p, 'paste a 10x10 block', `
        const lines = [];
        for (let r = 0; r < 10; r++) { const l = []; for (let c = 0; c < 10; c++) l.push(c % 2 === 0 ? 'http://127.0.0.1:8209/favicon.ico?paste=${RUN}-' + r + '-' + c : 'PERF-PASTE ${RUN} ' + r + '-' + c); lines.push(l.join('\\t')); }
        const dt = new DataTransfer(); dt.setData('text/plain', lines.join('\\n'));
        ${cellOf('Perf Page 020', 'seq_img_1')}.dispatchEvent(new ClipboardEvent('paste', {clipboardData: dt, bubbles: true, cancelable: true}))`,
        `/100 cells changed on 10 pages/.test(document.getElementById('bar-text').textContent)`));
    out.push(await measure(p, 'Save the pasted block', click(`document.getElementById('bar-save')`),
        `/Saved 100 fields on 10 pages/.test(document.getElementById('bar-text').textContent) && ${cellOf('Perf Page 029', 'seq_5')}.textContent.includes('PERF-PASTE ${RUN} 9-9')`, {settle: 800}));

    // Filters: the search box, and one value of the Flow header filter.
    out.push(await measure(p, 'filter: search "Page 1"', `const f = document.getElementById('f-q'); f.value = 'Page 1'; f.dispatchEvent(new Event('input', {bubbles: true}))`,
        `/^\\d+ of 160/.test(document.getElementById('count').textContent) && !/^160 of/.test(document.getElementById('count').textContent)`));
    out.push(await measure(p, 'filter: clear the search', `const f = document.getElementById('f-q'); f.value = ''; f.dispatchEvent(new Event('input', {bubbles: true}))`,
        `/^160 of 160/.test(document.getElementById('count').textContent)`));
    await p.ev(`(() => { document.querySelector('button[aria-label="Filter Flow"]').click(); return true; })()`);
    out.push(await measure(p, 'filter: untick one Flow value', `[...document.querySelectorAll('#hf-list label')].find(l => l.textContent === 'Short Flow A').querySelector('input').click()`,
        `/^120 of 160/.test(document.getElementById('count').textContent)`));
    out.push(await measure(p, 'filter: Check all', click(`document.getElementById('hf-all')`), `/^160 of 160/.test(document.getElementById('count').textContent)`));
    await p.ev(`(() => { document.getElementById('hf-close').click(); return true; })()`);

    out.push(await measure(p, 'open Connections', click(`document.querySelector('button[aria-label="Connections of Perf Page 002"]')`),
        `document.querySelector('#conn .conn-card[data-provider="botcake"] .conn-state').textContent !== ''`, {settle: 500}));
    await p.ev(`(() => { document.querySelector('#conn [data-close-dialog]').click(); return true; })()`);
    out.push(await measure(p, 'Check again (one page)', click(`document.querySelector('button[aria-label="Check Perf Page 001 again"]')`),
        `document.querySelector('button[aria-label="Check Perf Page 001 again"]') && !document.querySelector('#cb-table td[data-check="Perf Page 001"]').textContent.includes('Checking')`, {settle: 500}));
    out.push(await measure(p, 'show Ad groups of a page', click(`document.querySelector('button[aria-label^="Show the ad groups of Perf Page 007"]')`),
        `[...document.querySelectorAll('#cb-table tr.ag-sub')].some(tr => tr.textContent.includes('Default'))`, {settle: 800}));
    out.push(await measure(p, 'hide Ad groups', click(`document.querySelector('button[aria-label^="Hide the ad groups of Perf Page 007"]')`),
        `!document.querySelector('#cb-table tr.ag-sub')`, {settle: 500}));
    return out;
}

/** A page load: every long task from the navigation until the page shows its data, plus a moment for trailing work. */
async function loaded(p, out, base, path, ready, {settle = 1500, action = 'load'} = {}) {
    await p.goto(base + path);
    let t1, late = null;
    try { t1 = await p.waitFor(ready, TIMEOUT); await sleep(settle); } catch (e) { late = e.message.slice(0, 160); t1 = await p.now(); }
    const {lt, loaf} = await p.tasks(0);
    out.push({action, longest: max(lt), frame: max(loaf), total: sum(lt), count: lt.length, elapsed: t1, target: LOAD_TARGET,
        note: late ? 'NOT FINISHED: ' + late : (await p.ev(`document.getElementsByTagName('*').length`)) + ' elements'});
}

const SCREEN_LIST = {
    async flows(p, base, out) {
        await loaded(p, out, base, '/chatbot', `document.readyState === 'complete'`);
    },
    async editor(p, base, out) {
        const id = await p.ev(`fetch('/chatbot').then(r => r.text()).then(t => (t.match(/\\/chatbot\\/flows\\/(\\d+)"[^>]*>\\s*Main Sales Flow/) || t.match(/\\/chatbot\\/flows\\/(\\d+)/) || [])[1])`);
        await loaded(p, out, base, '/chatbot/flows/' + id, `document.querySelectorAll('#world .nd').length > 0`);
        // Arrange moves every step; the draft saves itself 0.8 s later.
        out.push(await measure(p, 'Arrange (66 steps) and its save', click(`document.getElementById('arrange')`), `/^Draft saved/.test(document.getElementById('status').textContent)`, {settle: 800}));
    },
    async sequences(p, base, out) {
        await loaded(p, out, base, '/chatbot/sequences', `document.readyState === 'complete'`);
        const href = await p.ev(`(document.querySelector('a[href*="/chatbot/sequences/"]') || {}).href`);
        await loaded(p, out, '', href, `document.querySelectorAll('#sq-steps > *').length > 0`, {action: 'open a sequence (10 steps)'});
        out.push(await measure(p, 'add a step', `document.getElementById('sq-add').click()`, 'true', {settle: 500}));
        // Save of the sequence with its 10 steps (reloaded first: the new step has no flow yet).
        await p.goto(href);
        await p.waitFor(`document.querySelectorAll('#sq-steps > *').length > 0`);
        out.push(await measure(p, 'Save (10 steps)', `document.getElementById('sq-save').click()`, `/^Saved/.test(document.getElementById('sq-note').textContent)`, {settle: 800}));
    },
    async chats(p, base, out) {
        await loaded(p, out, base, '/chatbot/chats', `document.readyState === 'complete'`);
    },
    async inbox(p, base, out) {
        await loaded(p, out, base, '/chatbot/inbox', `document.querySelectorAll('#ib-rows > li').length > 0`);
        out.push(await measure(p, 'open a conversation', `document.querySelector('#ib-rows > li').firstChild.click()`, `document.querySelectorAll('#ib-items > *').length > 0`, {settle: 1000}));
    },
    async replies(p, base, out) {
        await loaded(p, out, base, '/chatbot/replies', `document.getElementById('rp-count').textContent !== ''`);
        out.push(await measure(p, 'filter: Sent only', `document.getElementById('rp-count').textContent = ''; const f = document.getElementById('rp-filters'); f.status.value = 'sent'; f.requestSubmit()`,
            `document.getElementById('rp-count').textContent !== ''`, {settle: 800}));
    },
    async results(p, base, out) {
        await loaded(p, out, base, '/chatbot/results', `document.readyState === 'complete'`);
    },
    async health(p, base, out) {
        await loaded(p, out, base, '/chatbot/health', `document.readyState === 'complete'`);
    },
    async speed(p, base, out) {
        await loaded(p, out, base, '/chatbot/speed', `document.readyState === 'complete'`, {settle: 3000});
    },
    async live(p, base, out) {
        // The feed refreshes itself: 15 s of it.
        await loaded(p, out, base, '/chatbot/live', `document.readyState === 'complete'`, {settle: 15000, action: 'load and 15 s of live updates'});
    },
    async 'page-settings'(p, base, out) {
        await loaded(p, out, base, '/chatblast/page-settings', `document.querySelectorAll('#grid-table tbody tr').length > 20`);
        out.push(await measure(p, 'filter: search "Page 1"', `const f = document.getElementById('filter'); f.value = 'Page 1'; f.dispatchEvent(new Event('input', {bubbles: true}))`, 'true', {settle: 800}));
        out.push(await measure(p, 'filter: clear', `const f = document.getElementById('filter'); f.value = ''; f.dispatchEvent(new Event('input', {bubbles: true}))`, 'true', {settle: 800}));
        // The cell editor of one page ID: opened, then Escape (nothing is saved).
        out.push(await measure(p, 'open a cell editor and Escape', `
            document.querySelector('#grid-table .cell[data-key="botcake.page_id"]').click();
            document.querySelector('#grid-table input.font-mono').dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true}))`,
            `!document.querySelector('#grid-table input.font-mono')`, {settle: 800}));
    },
};

export const SCREENS = [
    {name: 'pages', path: '/chatbot/pages', run: pages},
    ...Object.entries(SCREEN_LIST).map(([name, run]) => ({name, path: name, run})),
];
