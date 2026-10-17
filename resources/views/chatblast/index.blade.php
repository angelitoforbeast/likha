<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Chatblast</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        #cb-table thead th { position: sticky; top: 0; z-index: 2; }
        #cb-table tbody tr { cursor: pointer; }
        #cb-table tbody tr.selected-row { background: #eff6ff; }
        .chip.active { outline: 2px solid #1d4ed8; }
        pre.cb { white-space: pre-wrap; word-break: break-word; }
    </style>
</head>
<body class="bg-gray-50 text-gray-900">
<div class="p-4 sm:p-6"
     id="cb"
     data-list-url="{{ route('chatblast.list') }}"
     data-show-url="{{ url('/chatblast/messages') }}"
     data-build-url="{{ route('chatblast.build') }}"
     data-send-url="{{ route('chatblast.send') }}"
     data-send-filtered-url="{{ route('chatblast.send-filtered') }}"
     data-retry-url="{{ route('chatblast.retry-failed') }}"
     data-pause-url="{{ route('chatblast.pause') }}"
     data-resume-url="{{ route('chatblast.resume') }}"
     data-test-url="{{ route('chatblast.test') }}"
     data-test-psid-url="{{ route('chatblast.test-psid.search') }}">

    <div class="flex items-center justify-between mb-3">
        <h1 class="text-2xl font-semibold">Chatblast</h1>
        <div class="flex gap-4 text-sm">
            <a href="{{ route('chatblast.campaigns.index') }}" class="text-blue-700 hover:underline">Campaigns at mga mensahe (Araw 1, Araw 2, …)</a>
            <a href="{{ route('page-settings.index') }}" class="text-blue-700 hover:underline">Page Settings</a>
            <a href="{{ url('/') }}" class="text-gray-600 hover:underline">Home</a>
        </div>
    </div>

    {{-- Mga filter --}}
    <form id="filters" class="flex flex-wrap items-end gap-3 mb-3 text-sm">
        <label class="flex flex-col text-xs text-gray-600">Mula<input type="date" name="from" value="{{ $today }}" class="border rounded px-2 py-1 text-sm text-gray-900"></label>
        <label class="flex flex-col text-xs text-gray-600">Hanggang<input type="date" name="to" value="{{ $today }}" class="border rounded px-2 py-1 text-sm text-gray-900"></label>
        <label class="flex flex-col text-xs text-gray-600">Page
            <select name="page" class="border rounded px-2 py-1 text-sm text-gray-900">
                <option value="">Lahat</option>
                @foreach($pages as $p)<option value="{{ $p }}">{{ $p }}</option>@endforeach
            </select>
        </label>
        <label class="flex flex-col text-xs text-gray-600">Campaign
            <select name="kind" class="border rounded px-2 py-1 text-sm text-gray-900">
                <option value="">Lahat</option>
                @foreach($campaigns as $c)<option value="{{ $c->kind }}">{{ $c->name }}{{ $c->active ? '' : ' (off)' }}</option>@endforeach
            </select>
        </label>
        <label class="flex flex-col text-xs text-gray-600">Hanapin<input name="q" placeholder="pangalan o waybill" class="border rounded px-2 py-1 text-sm text-gray-900 w-56"></label>
        <input type="hidden" name="status" value="">
        <button class="bg-gray-200 hover:bg-gray-300 px-3 py-1 rounded">Ipakita</button>
    </form>

    {{-- Bilang kada status (pindutin para salain) at ang lagay ng pagpapadala --}}
    <div id="chips" class="flex flex-wrap gap-2 mb-2 text-xs" aria-label="Bilang kada status"></div>
    <div class="flex flex-wrap items-center gap-3 mb-3 text-sm">
        <span id="state" class="text-xs text-gray-700" aria-live="polite"></span>
        <span id="test-psid-owner" class="text-xs text-gray-700"></span>
        <button id="pause" type="button" class="hidden bg-amber-500 hover:bg-amber-600 text-white px-3 py-1 rounded">Pause</button>
        <button id="resume" type="button" class="hidden bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded">Resume</button>
    </div>

    {{-- Mga aksyon --}}
    <div class="flex flex-wrap items-center gap-2 mb-3 text-sm">
        <button id="build" type="button" class="bg-gray-800 hover:bg-gray-900 text-white px-3 py-1 rounded">Kunin ang mga bago</button>
        <button id="send-selected" type="button" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded disabled:opacity-50" disabled>Send selected (0)</button>
        <button id="send-filtered" type="button" class="bg-blue-700 hover:bg-blue-800 text-white px-3 py-1 rounded">Send lahat ng ready</button>
        <button id="retry" type="button" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded">Retry failed</button>
        <span id="notice" class="text-xs" aria-live="polite"></span>
    </div>

    <details class="mb-3 bg-white rounded shadow px-4 py-2 text-sm">
        <summary class="cursor-pointer font-medium">Test PSID</summary>
        <div class="grid lg:grid-cols-3 gap-4 mt-2">
            <form id="test-psid-form">
                <p class="text-xs text-gray-600 mb-1">Test PSID ng page (ang sarili mong account sa Botcake customers ng page): dito napupunta ang "Send test".</p>
                <select name="page" class="border rounded px-2 py-1 text-sm mb-1">
                    @foreach($pages as $p)<option value="{{ $p }}">{{ $p }}</option>@endforeach
                </select>
                <input name="q" placeholder="hanapin ang pangalan" class="border rounded px-2 py-1 text-sm">
                <button class="bg-gray-200 hover:bg-gray-300 px-3 py-1 rounded">Hanapin</button>
                <div id="test-psid-current" class="text-xs text-gray-600 mt-1"></div>
                <ul id="test-psid-results" class="text-sm mt-1 space-y-1"></ul>
            </form>
        </div>
    </details>

    <div class="bg-white rounded shadow overflow-auto max-h-[70vh]">
        <table id="cb-table" class="text-sm border-collapse min-w-full">
            <thead>
            <tr class="bg-gray-100 text-left text-xs text-gray-600">
                <th class="bg-gray-100 px-2 py-2 border-b"><input type="checkbox" id="check-all" aria-label="Piliin lahat"></th>
                @foreach(['Oras', 'Page', 'Customer', 'Waybill', 'J&T', 'PSID', 'Mensahe', 'Status', 'Subok', 'HTTP', 'Naipadala'] as $h)
                    <th class="bg-gray-100 px-2 py-2 border-b whitespace-nowrap">{{ $h }}</th>
                @endforeach
            </tr>
            </thead>
            <tbody id="cb-rows"></tbody>
        </table>
    </div>
    <div class="flex items-center gap-3 mt-2 text-sm">
        <button id="prev" type="button" class="bg-gray-200 hover:bg-gray-300 px-3 py-1 rounded disabled:opacity-50">‹</button>
        <span id="pager" class="text-xs text-gray-600"></span>
        <button id="next" type="button" class="bg-gray-200 hover:bg-gray-300 px-3 py-1 rounded disabled:opacity-50">›</button>
    </div>

    {{-- Side panel: lahat ng kailangan sa debug ng isang mensahe --}}
    <aside id="panel" class="hidden fixed right-0 top-0 h-full w-[34rem] max-w-full bg-white shadow-xl border-l p-4 overflow-auto text-sm z-50" aria-label="Detalye ng mensahe">
        <div class="flex justify-between items-center mb-2">
            <div id="panel-title" class="font-semibold"></div>
            <button id="panel-close" type="button" class="text-gray-500 hover:text-gray-800 px-2" aria-label="Isara">✕</button>
        </div>
        <div id="panel-body"></div>
    </aside>
</div>

<script>
(function () {
    const box = document.getElementById('cb'), url = box.dataset;
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const LABEL = {ready: 'ready', no_psid: 'walang PSID', no_config: 'walang config', queued: 'queued', sending: 'sending', sent: 'sent',
        failed: 'failed', field_mismatch: 'field mismatch', skipped: 'skipped', unknown: 'hindi tiyak'};
    const COLOR = {ready: 'bg-blue-100 text-blue-800', no_psid: 'bg-gray-200 text-gray-700', no_config: 'bg-gray-200 text-gray-700',
        queued: 'bg-indigo-100 text-indigo-800', sending: 'bg-indigo-200 text-indigo-900', sent: 'bg-green-100 text-green-800',
        failed: 'bg-red-100 text-red-800', field_mismatch: 'bg-orange-100 text-orange-800', skipped: 'bg-yellow-100 text-yellow-800',
        unknown: 'bg-purple-100 text-purple-800'};
    const ORDER = ['ready', 'queued', 'sending', 'sent', 'failed', 'field_mismatch', 'unknown', 'skipped', 'no_psid', 'no_config'];
    let page = 1, last = null, timer = null, openId = null;
    const selected = new Set();
    const CAMPAIGNS = @json($campaigns->pluck('name', 'kind'));

    function el(tag, attrs = {}, children = []) {
        const e = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs)) {
            if (v == null) continue;
            if (k === 'class') e.className = v; else if (k === 'text') e.textContent = v; else e.setAttribute(k, v);
        }
        for (const c of [].concat(children)) if (c != null) e.append(c);
        return e;
    }
    const fmt = n => Number(n || 0).toLocaleString('en-US');
    const badge = s => el('span', {class: 'px-2 py-0.5 rounded text-xs whitespace-nowrap ' + (COLOR[s] || 'bg-gray-100'), text: LABEL[s] || s});

    function notice(text, cls = 'text-gray-600') {
        const n = document.getElementById('notice');
        n.className = 'text-xs ' + cls;
        n.textContent = text;
    }

    function filters() {
        const f = new FormData(document.getElementById('filters'));
        const q = {};
        for (const [k, v] of f.entries()) if (v !== '') q[k] = v;
        return q;
    }

    async function post(u, body = {}) {
        const res = await fetch(u, {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}, body: JSON.stringify(body)});
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.error || data.message || ('HTTP ' + res.status));
        return data;
    }

    // ---- listahan ----

    // Ang bilang kada status ay mabigat sa maraming row: kinukuha lang ito kada 30 s habang nagpo-poll, o sa bawat aksyon.
    let countsAt = 0, counts = {}, total = 0;

    async function load(light = false) {
        const params = {...filters(), p: page};
        if (light && Date.now() - countsAt < 30000) params.light = '1';
        const res = await fetch(url.listUrl + '?' + new URLSearchParams(params), {headers: {'Accept': 'application/json'}});
        if (!res.ok) { notice('Hindi nakuha ang listahan (HTTP ' + res.status + ').', 'text-red-600'); return; }
        last = await res.json();
        if (last.counts) { counts = last.counts; total = last.total; countsAt = Date.now(); }
        last.counts = counts;
        renderChips(counts);
        renderState(last.state);
        renderTestPsid(last.test_psid);
        renderRows(last.rows);
        const pages = Math.max(1, Math.ceil(total / last.per_page));
        document.getElementById('pager').textContent = 'Pahina ' + last.page + ' / ' + pages + ' · ' + fmt(total) + ' row';
        document.getElementById('prev').disabled = last.page <= 1;
        document.getElementById('next').disabled = last.page >= pages;
        schedule();
    }

    function renderChips(counts) {
        const current = document.querySelector('#filters [name=status]').value;
        const total = Object.values(counts).reduce((a, b) => a + b, 0);
        const chip = (status, label, n) => {
            const b = el('button', {type: 'button', class: 'chip px-2 py-1 rounded ' + (status ? COLOR[status] : 'bg-white border') + (current === status ? ' active' : ''), 'data-status': status},
                [label + ' ', el('b', {text: fmt(n)})]);
            b.addEventListener('click', () => { document.querySelector('#filters [name=status]').value = current === status ? '' : status; page = 1; load(); });
            return b;
        };
        document.getElementById('chips').replaceChildren(chip('', 'lahat', total), ...ORDER.filter(s => counts[s]).map(s => chip(s, LABEL[s], counts[s])));
    }

    function renderState(s) {
        document.getElementById('state').textContent = 'Pagpapadala: ' + (s.paused ? 'naka-pause' : 'tumatakbo') + ' · nasa queue: ' + fmt(s.queued)
            + ' · naipadala sa huling oras: ' + fmt(s.sent);
        document.getElementById('pause').classList.toggle('hidden', s.paused);
        document.getElementById('resume').classList.toggle('hidden', !s.paused);
    }

    function renderRows(rows) {
        const body = rows.map(r => {
            const check = el('input', {type: 'checkbox', 'aria-label': 'Piliin ' + r.waybill});
            check.checked = selected.has(r.id);
            check.addEventListener('click', e => { e.stopPropagation(); check.checked ? selected.add(r.id) : selected.delete(r.id); refreshSelected(); });
            const tr = el('tr', {class: 'border-b hover:bg-gray-50' + (r.id === openId ? ' selected-row' : ''), tabindex: '0', 'data-id': r.id}, [
                el('td', {class: 'px-2 py-1'}, check),
                el('td', {class: 'px-2 py-1 whitespace-nowrap text-xs text-gray-600', text: r.at}),
                el('td', {class: 'px-2 py-1 whitespace-nowrap', text: r.page}),
                el('td', {class: 'px-2 py-1 whitespace-nowrap', text: (r.customer || '') + (r.is_test ? ' (test)' : '')}),
                el('td', {class: 'px-2 py-1 font-mono text-xs whitespace-nowrap', text: r.waybill}),
                el('td', {class: 'px-2 py-1 whitespace-nowrap text-xs', text: r.jnt || ''}),
                el('td', {class: 'px-2 py-1 font-mono text-xs', text: r.psid_last4 ? '…' + r.psid_last4 : '—'}),
                el('td', {class: 'px-2 py-1 max-w-[22rem] truncate', title: r.text || ''}, [el('span', {class: 'text-xs text-gray-500 mr-1', text: r.step}), r.first_line]),
                el('td', {class: 'px-2 py-1', title: r.reason || ''}, badge(r.status)),
                el('td', {class: 'px-2 py-1 text-right', text: String(r.attempts || 0)}),
                el('td', {class: 'px-2 py-1 text-right', text: r.http ? String(r.http) : ''}),
                el('td', {class: 'px-2 py-1 whitespace-nowrap text-xs', text: r.sent_at || ''}),
            ]);
            tr.addEventListener('click', () => openPanel(r.id));
            tr.addEventListener('keydown', e => { if (e.key === 'Enter') openPanel(r.id); });
            return tr;
        });
        document.getElementById('cb-rows').replaceChildren(...(body.length ? body
            : [el('tr', {}, el('td', {colspan: '12', class: 'px-3 py-4 text-gray-500', text: 'Walang mensahe sa filter na ito. Pindutin ang "Kunin ang mga bago".'}))]));
        document.getElementById('check-all').checked = rows.length > 0 && rows.every(r => selected.has(r.id));
    }

    function refreshSelected() {
        const b = document.getElementById('send-selected');
        b.textContent = 'Send selected (' + selected.size + ')';
        b.disabled = selected.size === 0;
    }

    // Habang may naka-queue o ipinapadala: kunin ulit kada ilang segundo.
    function schedule() {
        clearTimeout(timer);
        const active = last && (last.state.queued > 0 || last.rows.some(r => r.status === 'queued' || r.status === 'sending'));
        if (active && !document.hidden) timer = setTimeout(() => { load(true); if (openId) openPanel(openId, true); }, 4000);
    }

    // Kanino ang Test PSID ng page na naka-filter: para makita bago mag-"Send test" na hindi kapangalan ang tatanggap.
    function renderTestPsid(t) {
        const page = filters().page;
        document.getElementById('test-psid-owner').textContent = !page ? ''
            : (t ? 'Test PSID ng ' + page + ': ' + t.name + ' (…' + t.psid_last4 + ')' : 'Walang Test PSID ang ' + page);
    }
    document.addEventListener('visibilitychange', schedule);

    // ---- side panel ----

    async function openPanel(id, quiet = false) {
        openId = id;
        const res = await fetch(url.showUrl + '/' + id, {headers: {'Accept': 'application/json'}});
        if (!res.ok) { if (!quiet) notice('Hindi nakuha ang detalye.', 'text-red-600'); return; }
        const m = await res.json();
        document.getElementById('panel-title').replaceChildren(m.waybill + ' · ', badge(m.status), m.is_test ? ' (test)' : '');
        const kv = (k, v) => el('div', {class: 'flex gap-2'}, [el('span', {class: 'text-gray-500 w-28 shrink-0', text: k}), el('span', {class: 'break-all', text: v == null || v === '' ? '—' : String(v)})]);
        const steps = (m.steps || []).map(s => el('div', {class: 'border rounded p-2 mb-2'}, [
            el('div', {class: 'font-medium', text: s.step + ' · ' + s.method + ' ' + s.path}),
            el('div', {class: 'text-xs text-gray-600', text: 'HTTP ' + s.http + ' · ' + s.attempts + ' subok · ' + s.at}),
            el('div', {class: 'text-xs text-gray-500 mt-1', text: 'Request (walang token):'}),
            el('pre', {class: 'cb text-xs bg-gray-50 p-1 rounded', text: JSON.stringify(s.request, null, 1)}),
            el('div', {class: 'text-xs text-gray-500 mt-1', text: 'Sagot:'}),
            el('pre', {class: 'cb text-xs bg-gray-50 p-1 rounded', text: s.response}),
        ]));
        const actions = el('div', {class: 'flex gap-2 my-2'});
        if (['ready', 'failed', 'field_mismatch', 'skipped'].includes(m.status) && m.psid) {
            const send = el('button', {type: 'button', class: 'bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded', text: 'Send'});
            send.addEventListener('click', () => sendIds([m.id], 'Ipadala sa customer ang mensaheng ito?'));
            actions.append(send);
        }
        if (!m.is_test) {
            const test = el('button', {type: 'button', class: 'bg-gray-200 hover:bg-gray-300 px-3 py-1 rounded', text: 'Send test'});
            test.addEventListener('click', async () => {
                try { await post(url.testUrl, {id: m.id}); notice('Naka-queue ang test sa Test PSID ng page.', 'text-green-700'); load(); }
                catch (e) { notice(e.message, 'text-red-600'); }
            });
            actions.append(test);
        }
        document.getElementById('panel-body').replaceChildren(
            m.reason ? el('div', {class: 'mb-2 px-2 py-1 rounded bg-amber-50 text-amber-900', text: m.reason}) : '',
            actions,
            el('div', {class: 'text-xs text-gray-500', text: 'Buong text:'}),
            el('pre', {class: 'cb bg-gray-50 border rounded p-2 mb-2', text: m.text || ''}),
            kv('Page', m.page), kv('Customer', m.customer), kv('PSID', m.psid), kv('Campaign', CAMPAIGNS[m.kind] || m.kind), kv('Araw', m.step), kv('J&T', m.jnt_status),
            kv('Subok', m.attempts), kv('Huling HTTP', m.last_http), kv('Ginawa', m.created_at + ' · ' + m.created_by),
            kv('Na-queue', m.queued_at), kv('Naipadala', m.sent_at ? m.sent_at + ' · ' + (m.sent_by || '') : null),
            kv('Test ng', m.test_of), kv('Order id', m.macro_output_id),
            el('div', {class: 'font-medium mt-3 mb-1', text: 'Mga hakbang'}),
            ...(steps.length ? steps : [el('div', {class: 'text-gray-500', text: 'Wala pang hakbang (hindi pa naipapadala).'})]),
        );
        document.getElementById('panel').classList.remove('hidden');
        for (const tr of document.querySelectorAll('#cb-rows tr')) tr.classList.toggle('selected-row', Number(tr.dataset.id) === id);
    }
    document.getElementById('panel-close').addEventListener('click', () => { openId = null; document.getElementById('panel').classList.add('hidden'); });

    // ---- aksyon ----

    async function sendIds(ids, question) {
        if (!confirm(question)) return;
        try {
            const d = await post(url.sendUrl, {ids});
            notice(fmt(d.queued) + ' ang naka-queue.', 'text-green-700');
            ids.forEach(i => selected.delete(i));
            refreshSelected();
            load();
            if (openId) openPanel(openId, true);
        } catch (e) { notice(e.message, 'text-red-600'); }
    }

    document.getElementById('send-selected').addEventListener('click', () => sendIds([...selected], 'Ipadala sa ' + selected.size + ' customer?'));

    document.getElementById('send-filtered').addEventListener('click', async () => {
        const ready = (last && last.counts.ready) || 0;
        if (!confirm('Ipadala ang lahat ng ready sa filter na ito (' + fmt(ready) + ')?')) return;
        try { const d = await post(url.sendFilteredUrl, filters()); notice(fmt(d.queued) + ' ang naka-queue.', 'text-green-700'); load(); }
        catch (e) { notice(e.message, 'text-red-600'); }
    });

    document.getElementById('retry').addEventListener('click', async () => {
        if (!confirm('Ipadala ulit ang failed at field mismatch sa filter na ito?')) return;
        try { const d = await post(url.retryUrl, filters()); notice(fmt(d.queued) + ' ang naka-queue ulit.', 'text-green-700'); load(); }
        catch (e) { notice(e.message, 'text-red-600'); }
    });

    document.getElementById('build').addEventListener('click', async e => {
        const btn = e.currentTarget;
        btn.disabled = true;
        try {
            const r = (await post(url.buildUrl, {kind: filters().kind})).result;
            notice(fmt(r.added) + ' bago (' + fmt(r.ready) + ' ready, ' + fmt(r.no_psid) + ' walang PSID, ' + fmt(r.no_config) + ' walang config); ' + fmt(r.updated) + ' na-update; J&T: '
                + fmt(r.tracked) + ' na-track' + (r.rider_pending ? ', ' + fmt(r.rider_pending) + ' rider pending (susunod na kuha o bago ipadala)' : '') + '.', 'text-green-700');
            load();
        } catch (err) { notice(err.message, 'text-red-600'); }
        btn.disabled = false;
    });

    for (const [id, u] of [['pause', url.pauseUrl], ['resume', url.resumeUrl]]) {
        document.getElementById(id).addEventListener('click', async () => {
            try { renderState((await post(u)).state); load(); } catch (e) { notice(e.message, 'text-red-600'); }
        });
    }

    document.getElementById('check-all').addEventListener('change', e => {
        for (const r of (last ? last.rows : [])) e.target.checked ? selected.add(r.id) : selected.delete(r.id);
        renderRows(last ? last.rows : []);
        refreshSelected();
    });

    document.getElementById('filters').addEventListener('submit', e => { e.preventDefault(); page = 1; load(); });
    document.getElementById('prev').addEventListener('click', () => { page = Math.max(1, page - 1); load(); });
    document.getElementById('next').addEventListener('click', () => { page++; load(); });

    // ---- Test PSID ----

    const pf = document.getElementById('test-psid-form');
    pf.addEventListener('submit', async e => {
        e.preventDefault();
        const res = await fetch(url.testPsidUrl + '?' + new URLSearchParams({page: pf.page.value, q: pf.q.value}), {headers: {'Accept': 'application/json'}});
        const d = await res.json().catch(() => ({customers: []}));
        document.getElementById('test-psid-current').textContent = d.current ? 'Kasalukuyang Test PSID: …' + d.current : 'Wala pang Test PSID ang page.';
        document.getElementById('test-psid-results').replaceChildren(...(d.customers || []).map(c => {
            const b = el('button', {type: 'button', class: 'text-blue-700 hover:underline', text: c.name + ' (…' + c.psid_last4 + ')'});
            b.addEventListener('click', async () => {
                if (!confirm('Gawing Test PSID ng ' + pf.page.value + ' si "' + c.name + '" (PSID …' + c.psid_last4 + ')? Dito mapupunta ang lahat ng "Send test" ng page.')) return;
                try { const s = await post(url.testPsidUrl, {page: pf.page.value, customer_id: c.id}); document.getElementById('test-psid-current').textContent = 'Na-save: ' + s.name + ' (…' + s.psid_last4 + ')'; load(); }
                catch (err) { notice(err.message, 'text-red-600'); }
            });
            return el('li', {}, b);
        }));
        if (!(d.customers || []).length) document.getElementById('test-psid-results').append(el('li', {class: 'text-gray-500', text: 'Walang tugma (kahit 2 letra ng simula ng pangalan).'}));
    });

    load();
})();
</script>
</body>
</html>
