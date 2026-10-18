<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Page Settings</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        #grid-table th.sticky-col, #grid-table td.sticky-col { position: sticky; left: 0; z-index: 2; }
        #grid-table thead th { position: sticky; top: 0; z-index: 3; }
        #grid-table thead th.sticky-col { z-index: 4; }
        #grid-table td.cell { cursor: text; }
        #grid-table td.cell.readonly { cursor: default; }
        #grid-table td.cell:hover .hist { visibility: visible; }
        #grid-table .hist { visibility: hidden; }
    </style>
</head>
<body class="bg-gray-50 text-gray-900">

<div class="p-4 sm:p-6">
    <div class="flex items-center justify-between mb-3">
        <h1 class="text-2xl font-semibold">Page Settings</h1>
        <div class="flex gap-4 text-sm">
            <a href="{{ route('page-settings.psid-coverage') }}" class="text-blue-700 hover:underline">PSID coverage (orders na walang PSID)</a>
            <a href="{{ url('/') }}" class="text-gray-600 hover:underline">Home</a>
        </div>
    </div>

    @if(session('success'))
        <div class="mb-3 px-4 py-2 rounded bg-green-100 text-green-800 text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-3 px-4 py-2 rounded bg-red-100 text-red-800 text-sm">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="mb-3 px-4 py-2 rounded bg-red-100 text-red-800 text-sm">
            @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
        </div>
    @endif

    <div class="flex flex-wrap items-end gap-4 mb-3 text-sm">
        <input id="filter" placeholder="Hanapin ang page" class="border rounded px-2 py-1">

        <form id="add-page" class="flex items-center gap-1">
            <input name="page_name" placeholder="Pangalan ng page (gaya ng PAGE sa orders)" class="border rounded px-2 py-1 w-72" required>
            <button class="bg-gray-200 hover:bg-gray-300 px-3 py-1 rounded">+ Page</button>
        </form>

        <form id="add-column" class="flex items-center gap-1">
            <input name="provider" value="botcake" placeholder="provider" class="border rounded px-2 py-1 w-24" required>
            <input name="key" placeholder="key (hal. flow.reminder)" class="border rounded px-2 py-1 w-44" required>
            <label class="flex items-center gap-1"><input type="checkbox" name="is_secret" value="1"> secret</label>
            <button class="bg-gray-200 hover:bg-gray-300 px-3 py-1 rounded">+ Column</button>
        </form>

        <span id="notice" class="text-xs"></span>
    </div>

    <div class="flex flex-wrap items-center gap-3 mb-3 text-sm">
        <button id="test-all" type="button" class="bg-gray-200 hover:bg-gray-300 px-3 py-1 rounded disabled:opacity-50">Test lahat ng token</button>
        <span id="test-all-status" class="text-xs text-gray-600" aria-live="polite"></span>
        <button id="sync-all" type="button" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded disabled:opacity-50">Sync lahat</button>
        <button id="sync-all-pause" type="button" data-action="pause" class="sync-all-control hidden bg-amber-500 hover:bg-amber-600 text-white px-3 py-1 rounded">Pause</button>
        <button id="sync-all-resume" type="button" data-action="resume" class="sync-all-control hidden bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded">Resume</button>
        <button id="sync-all-stop" type="button" data-action="stop" class="sync-all-control hidden bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded">Stop</button>
        <span id="sync-all-status" class="text-xs text-gray-600" aria-live="polite"></span>
    </div>

    {{-- Ang kasalukuyan o huling Sync lahat run, at ang huling 10 run. Ina-update ng poll. --}}
    <div id="sync-all-panel" class="hidden mb-3 px-4 py-2 rounded bg-blue-50 text-blue-950 text-xs">
        <div id="sync-all-line" class="mb-1"></div>
        <details>
            <summary class="cursor-pointer text-blue-800">Huling 10 Sync lahat</summary>
            <table class="mt-1 border-collapse">
                <thead><tr class="text-left text-gray-600">
                    <th class="pr-3">Sinimulan</th><th class="pr-3">Ni</th><th class="pr-3">Status</th><th class="pr-3">Page</th>
                    <th class="pr-3">Failed</th><th class="pr-3">Laktaw</th><th class="pr-3">Bago</th><th class="pr-3">Na-match</th>
                    <th class="pr-3">Ad id</th><th class="pr-3">Tagal</th>
                </tr></thead>
                <tbody id="sync-all-history"></tbody>
            </table>
        </details>
    </div>

    {{-- Ang plano ng Sync lahat, mula sa "Susunod na sync" ng bawat page. --}}
    <p class="mb-3 text-xs text-gray-700">Plano ng Sync lahat: <span id="plan-summary">{{ $grid['plan_summary'] ?: 'wala' }}</span></p>

    {{-- Ang lock ng Botcake sync: habang hawak, "Skipped" ang ibang sync (schedule, button, command). --}}
    <div id="sync-lock" class="mb-3 px-4 py-2 rounded bg-amber-100 text-amber-900 text-sm {{ $grid['sync_lock'] ? '' : 'hidden' }}">
        May tumatakbong Botcake sync mula <span id="sync-lock-since">{{ $grid['sync_lock'] }}</span>. Ang ibang sync ay lalaktaw hanggang matapos ito (o hanggang 2 oras kung naputol).
    </div>

    <p class="mb-2 text-xs text-gray-500">
        I-click ang cell at mag-type: kusa itong nase-save (Enter, pag-alis sa cell, o ilang segundong walang type). Blangko = burahin ang setting.
        Token / secret: blangko = walang palit; ang laman ay hindi kailanman ipinapakita (huling 4 lang). Kada ilang segundo, kinukuha ang binago ng iba.
        ID ng Botcake, flow at field: numero lang.
    </p>

    <div id="grid"
         class="bg-white rounded shadow overflow-auto max-h-[75vh]"
         data-grid-url="{{ route('page-settings.grid') }}"
         data-cell-url="{{ route('page-settings.cell') }}"
         data-history-url="{{ route('page-settings.history') }}"
         data-page-check-url="{{ route('page-settings.page-check') }}"
         data-test-token-url="{{ route('page-settings.test-token') }}"
         data-test-token-one-url="{{ route('page-settings.test-token-one') }}"
         data-sync-url="{{ route('page-settings.sync') }}"
         data-sync-all-url="{{ route('page-settings.sync-all') }}"
         data-coverage-url="{{ route('page-settings.psid-coverage.orders') }}"
         data-customers-url="{{ route('page-settings.customers') }}">
        <table id="grid-table" class="text-sm border-collapse min-w-full"></table>
    </div>

    <div id="history" class="hidden fixed right-0 top-0 h-full w-96 max-w-full bg-white shadow-xl border-l p-4 overflow-auto text-sm z-50">
        <div class="flex justify-between items-center mb-2">
            <div id="history-title" class="font-semibold break-all"></div>
            <button id="history-close" class="text-gray-500 hover:text-gray-800 px-2">✕</button>
        </div>
        <div id="history-current" class="mb-3"></div>
        <ul id="history-list" class="space-y-1 text-xs text-gray-700"></ul>
    </div>
</div>

<script>
(function () {
    const initial = @json($grid);
    const box = document.getElementById('grid');
    const table = document.getElementById('grid-table');
    const url = box.dataset;
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const POLL_MS = 5000, PAUSE_MS = 1500;
    const ORDERS_LABEL = @json('Orders (' . $grid['order_days'] . ' araw)');

    let columns = initial.columns;
    const rows = new Map(initial.rows.map(r => [r.key, r]));
    let since = initial.now;
    let editing = null;      // {pk, key, td, input, original, timer}
    let needsRender = false;
    const marks = new Map(); // "pk|key" → {text, cls, title}

    const cellId = (pk, key) => pk + '|' + key;

    function el(tag, attrs = {}, children = []) {
        const e = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs)) {
            if (k === 'class') e.className = v; else if (k === 'text') e.textContent = v; else e.setAttribute(k, v);
        }
        for (const c of [].concat(children)) if (c != null) e.append(c);
        return e;
    }

    // replaceChildren() turns a null into the text "null": always drop the empty parts first
    function put(node, ...kids) {
        node.replaceChildren(...kids.filter(k => k != null));
    }

    function notice(text, cls = 'text-gray-600') {
        const n = document.getElementById('notice');
        n.className = 'text-xs ' + cls;
        n.textContent = text;
    }

    // Pinakamaraming order muna; ang walang order sa window ay nasa ibaba, ayon sa pangalan.
    const gridOrder = (a, b) => ((b.info.orders || 0) - (a.info.orders || 0)) || a.name.localeCompare(b.name, undefined, {numeric: true, sensitivity: 'base'});

    function sortedRows() {
        const q = document.getElementById('filter').value.trim().toLowerCase();
        return [...rows.values()]
            .filter(r => !q || r.name.toLowerCase().includes(q))
            .sort(gridOrder);
    }

    function ensureColumn(key, secret) {
        if (columns.some(c => c.key === key)) return false;
        const dot = key.indexOf('.');
        columns.push({key, provider: key.slice(0, dot), name: key.slice(dot + 1), label: key, secret: !!secret, readonly: false});
        const known = columns.filter(c => c.label !== c.key), other = columns.filter(c => c.label === c.key);
        other.sort((a, b) => a.key.localeCompare(b.key));
        columns = known.concat(other);
        return true;
    }

    function ensureRow(pk, name) {
        if (rows.has(pk)) return false;
        rows.set(pk, {key: pk, name, cells: {}, info: {customers: 0, last_sync: null, botcake: false, orders: 0}});
        return true;
    }

    // ---- render ----

    function render() {
        if (editing) { needsRender = true; return; }
        needsRender = false;

        const head = el('tr', {class: 'bg-gray-100 text-left text-xs text-gray-600'}, [
            el('th', {class: 'sticky-col bg-gray-100 px-3 py-2 border-b border-r min-w-[14rem]', text: 'Page'}),
            ...columns.map(c => el('th', {class: 'bg-gray-100 px-3 py-2 border-b border-r whitespace-nowrap', title: c.key}, [
                c.label,
                c.readonly ? el('span', {class: 'ml-1 text-gray-400', text: '(system)'}) : null,
            ])),
            el('th', {class: 'bg-gray-100 px-3 py-2 border-b border-r whitespace-nowrap', text: ORDERS_LABEL}),
            el('th', {class: 'bg-gray-100 px-3 py-2 border-b border-r', text: 'Customers'}),
            el('th', {class: 'bg-gray-100 px-3 py-2 border-b border-r whitespace-nowrap', text: 'Huling sync'}),
            el('th', {class: 'bg-gray-100 px-3 py-2 border-b border-r whitespace-nowrap', text: 'Susunod na sync'}),
            el('th', {class: 'bg-gray-100 px-3 py-2 border-b', text: ''}),
        ]);

        // Walang order sa window: naka-gray ang buong row (maaari pa ring i-edit; hindi nawawala ang settings).
        const body = sortedRows().map(r => el('tr', {class: 'hover:bg-blue-50/40' + (r.info.orders ? '' : ' text-gray-400'), 'data-pk': r.key}, [
            el('td', {class: 'sticky-col ' + (r.info.orders ? 'bg-white' : 'bg-gray-50') + ' px-3 py-1 border-b border-r font-medium whitespace-nowrap', text: r.name}),
            ...columns.map(c => cellTd(r, c)),
            el('td', {class: 'px-3 py-1 border-b border-r text-right', 'data-info': 'orders'}, String(r.info.orders || 0)),
            el('td', {class: 'px-3 py-1 border-b border-r text-right', 'data-info': 'customers'}, infoCustomers(r)),
            paintSync(el('td', {class: 'px-3 py-1 border-b border-r whitespace-nowrap text-xs', 'data-info': 'sync'}), r),
            el('td', {class: 'px-3 py-1 border-b border-r whitespace-nowrap text-xs', 'data-info': 'next'}, infoNext(r)),
            el('td', {class: 'px-3 py-1 border-b whitespace-nowrap', 'data-info': 'actions'}, actions(r)),
        ]));

        table.replaceChildren(el('thead', {}, head), el('tbody', {}, body.length ? body
            : el('tr', {}, el('td', {class: 'px-3 py-3 text-gray-500', colspan: String(columns.length + 6), text: 'Wala pang page. Gamitin ang + Page.'}))));
    }

    function cellTd(row, col) {
        const td = el('td', {class: 'cell px-3 py-1 border-b border-r align-top min-w-[9rem]' + (col.readonly ? ' readonly bg-gray-50 text-gray-600' : ''),
            'data-pk': row.key, 'data-key': col.key});
        if (!col.readonly) td.tabIndex = 0;
        paint(td, row, col);
        return td;
    }

    function paint(td, row, col) {
        const cell = row.cells[col.key];
        const mark = marks.get(cellId(row.key, col.key));
        td.title = cell ? (cell.by + ' · ' + cell.at) : '';
        td.classList.toggle('bg-red-50', !!(mark && mark.cls === 'text-red-600'));
        put(td,
            el('span', {class: 'font-mono break-all', text: cell ? cell.value : ''}),
            mark ? el('span', {class: 'ml-1 text-xs ' + mark.cls, title: mark.title || '', text: mark.text}) : null,
            mark && mark.title ? el('div', {class: 'text-xs text-red-600', text: mark.title}) : null,
            col.readonly ? null : el('button', {class: 'hist ml-1 text-xs text-gray-400 hover:text-gray-700', type: 'button', title: 'History', text: '⋯'}),
        );
    }

    function repaint(pk, key) {
        if (editing && editing.pk === pk && editing.key === key) {
            // Habang nagta-type: ang mark lang ang ina-update, hindi ang input.
            const m = marks.get(cellId(pk, key));
            editing.mark.textContent = m ? (m.title || m.text) : '';
            editing.mark.className = 'block text-xs ' + (m ? m.cls : '');
            return;
        }
        const td = table.querySelector(`td.cell[data-pk="${CSS.escape(pk)}"][data-key="${CSS.escape(key)}"]`);
        const row = rows.get(pk), col = columns.find(c => c.key === key);
        if (td && row && col) paint(td, row, col);
    }

    function infoCustomers(r) { return String(r.info.customers ?? 0); }

    function infoSync(r) {
        const s = r.info.last_sync;
        if (!s) return 'wala pa';
        // hal. "Okt 17 → Okt 18 · by date · 2026-10-18 01:04 · date · +9,985 bago · 1,279 na-match"
        const n = v => Number(v || 0).toLocaleString('en-US');
        const counts = (s.new || s.matched) ? '+' + n(s.new) + ' bago · ' + n(s.matched) + ' na-match' : null;
        return [s.dates, s.kind, s.at, s.stop_reason || s.status, counts].filter(Boolean).join(' · ') + (s.error ? ' · ' + s.error : '')
            + (s.failed && r.info.next ? ' · susunod: ' + r.info.next.label : '');
    }

    // Pula ang "Huling sync" kapag failed ang huling run.
    function paintSync(td, r) {
        const s = r.info.last_sync;
        td.textContent = infoSync(r);
        td.classList.toggle('text-red-600', !!(s && s.failed));
        return td;
    }

    function infoNext(r) { return r.info.next ? r.info.next.label : ''; }

    // Kapareho ng BotcakeSyncAll::summary(): bilang kada uri ng susunod na sync.
    const SUMMARY = {window: 'window', continue: 'tuloy', by_date: 'by date', fresh: 'laktaw (sariwa)', missing: 'walang ID/token', invalid: 'token invalid'};
    function refreshSummary() {
        const count = {};
        for (const r of rows.values()) if (r.info.next) count[r.info.next.kind] = (count[r.info.next.kind] || 0) + 1;
        const text = Object.entries(SUMMARY).filter(([k]) => count[k]).map(([k, label]) => count[k] + ' ' + label).join(', ');
        document.getElementById('plan-summary').textContent = text || 'wala';
    }

    function postForm(action, pageName, label, cls) {
        const f = el('form', {method: 'POST', action, class: 'inline'}, [
            el('input', {type: 'hidden', name: '_token', value: csrf}),
            el('input', {type: 'hidden', name: 'page_name', value: pageName}),
            el('button', {class: cls, text: label}),
        ]);
        return f;
    }

    function actions(r) {
        const out = [];
        if (r.info.botcake) {
            out.push(postForm(url.testTokenUrl, r.name, 'Test token', 'bg-gray-200 hover:bg-gray-300 px-2 py-0.5 rounded mr-1 text-xs'));
            out.push(postForm(url.syncUrl, r.name, 'Sync now', 'bg-blue-600 hover:bg-blue-700 text-white px-2 py-0.5 rounded mr-1 text-xs'));
        }
        out.push(el('a', {class: 'text-blue-700 hover:underline text-xs mr-2', href: url.customersUrl + '?page=' + encodeURIComponent(r.name), text: 'Customers'}));
        out.push(el('a', {class: 'text-blue-700 hover:underline text-xs', href: url.coverageUrl + '?page_name=' + encodeURIComponent(r.name), text: 'Orders'}));
        return out;
    }

    // Kapag nagbago ang Botcake ID o token: ang mga button lang ng row ang binabago, hindi ang buong table
    // (ang pagbuo ulit ng table habang nagki-click ay nagpapawala ng click).
    function refreshActions(pk) {
        const td = table.querySelector(`tr[data-pk="${CSS.escape(pk)}"] [data-info="actions"]`);
        const r = rows.get(pk);
        if (td && r) put(td, ...actions(r));
    }

    function refreshInfo(pk) {
        const tr = table.querySelector(`tr[data-pk="${CSS.escape(pk)}"]`);
        const r = rows.get(pk);
        if (!tr || !r) return;
        tr.querySelector('[data-info="customers"]').textContent = infoCustomers(r);
        paintSync(tr.querySelector('[data-info="sync"]'), r);
        tr.querySelector('[data-info="next"]').textContent = infoNext(r);
        refreshSummary();
    }

    // ---- editing ----

    table.addEventListener('click', e => {
        const td = e.target.closest('td.cell');
        if (!td) return;
        const pk = td.dataset.pk, key = td.dataset.key;
        if (e.target.closest('.hist')) { openHistory(pk, key); return; }
        const col = columns.find(c => c.key === key);
        if (col.readonly || (editing && editing.td === td)) return;
        startEdit(td, rows.get(pk), col);
    });

    // Keyboard: Tab papunta sa cell, Enter o F2 para mag-edit, Enter ulit para i-save, Escape para hindi ituloy.
    table.addEventListener('keydown', e => {
        const td = e.target;
        if (!td.matches || !td.matches('td.cell') || (e.key !== 'Enter' && e.key !== 'F2')) return;
        e.preventDefault();
        const col = columns.find(c => c.key === td.dataset.key);
        if (!col.readonly) startEdit(td, rows.get(td.dataset.pk), col);
    });

    function startEdit(td, row, col) {
        if (editing) {
            finishEdit(true);
            // Kung nabuo ulit ang table, ang bagong td ng parehong cell ang gagamitin.
            td = table.querySelector(`td.cell[data-pk="${CSS.escape(row.key)}"][data-key="${CSS.escape(col.key)}"]`) || td;
        }
        const cell = row.cells[col.key];
        const secret = col.secret || !!(cell && cell.secret);
        const original = secret ? '' : (cell ? cell.value : '');
        const input = el('input', {class: 'w-full border border-blue-500 rounded px-1 font-mono', autocomplete: 'off',
            placeholder: secret ? 'bagong value (blangko = walang palit)' : ''});
        input.value = original;
        const mark = el('span', {class: 'block text-xs'});
        put(td, input, mark);
        input.focus();
        editing = {pk: row.key, key: col.key, td, input, mark, original, secret, timer: null};

        input.addEventListener('input', () => {
            clearTimeout(editing.timer);
            // Ang secret ay sine-save lang sa Enter o pag-alis sa cell, para hindi masave ang kalahating token.
            if (!secret) editing.timer = setTimeout(() => save(editing), PAUSE_MS);
        });
        input.addEventListener('keydown', ev => {
            if (ev.key === 'Enter') { ev.preventDefault(); finishEdit(true); refocus(row.key, col.key); }
            if (ev.key === 'Escape') { ev.preventDefault(); finishEdit(false); refocus(row.key, col.key); }
        });
        input.addEventListener('blur', () => { if (editing && editing.input === input) finishEdit(true); });
    }

    // Bumabalik ang focus sa cell, kahit nabuo ulit ang table.
    function refocus(pk, key) {
        table.querySelector(`td.cell[data-pk="${CSS.escape(pk)}"][data-key="${CSS.escape(key)}"]`)?.focus();
    }

    function finishEdit(doSave) {
        const ed = editing;
        if (!ed) return;
        clearTimeout(ed.timer);
        if ('server' in ed) {
            const row = rows.get(ed.pk);
            if (ed.server) row.cells[ed.key] = ed.server; else delete row.cells[ed.key];
        }
        editing = null;
        if (doSave) save(ed);
        if (needsRender) render(); else repaint(ed.pk, ed.key);
    }

    async function save(ed) {
        const value = ed.input.value.trim();
        if (value === ed.original.trim() && !(ed.secret && value !== '')) return;
        if (ed.secret && value === '') return;
        ed.original = value;

        const id = cellId(ed.pk, ed.key);
        const row = rows.get(ed.pk), col = columns.find(c => c.key === ed.key);
        marks.set(id, {text: 'Saving…', cls: 'text-gray-400'});
        repaint(ed.pk, ed.key);

        try {
            const res = await fetch(url.cellUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
                body: JSON.stringify({page_name: row.name, provider: col.provider, key: col.name, value, is_secret: ed.secret}),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                marks.set(id, {text: '!', cls: 'text-red-600', title: data.error || data.message || ('Hindi nai-save (' + res.status + ')')});
            } else {
                if (data.cell) row.cells[ed.key] = data.cell; else delete row.cells[ed.key];
                delete ed.server; // mas bago ang naisave kaysa sa itinabing value mula sa poll
                if (ed.key === 'botcake.page_id' || ed.key === 'botcake.token') {
                    row.info.botcake = !!(row.cells['botcake.page_id'] && row.cells['botcake.token']);
                    refreshActions(ed.pk);
                }
                marks.set(id, {text: data.status === 'unchanged' ? '✓' : 'Saved', cls: 'text-green-600'});
                setTimeout(() => { if (marks.get(id)?.cls === 'text-green-600') { marks.delete(id); repaint(ed.pk, ed.key); } }, 2500);
            }
        } catch (err) {
            marks.set(id, {text: '!', cls: 'text-red-600', title: 'Walang koneksyon: hindi nai-save.'});
        }
        if (needsRender && !editing) render(); else repaint(ed.pk, ed.key);
    }

    // ---- polling: ang binago ng iba, maliban sa cell na ine-edit ----

    async function poll() {
        if (document.hidden) return;
        try {
            const res = await fetch(url.gridUrl + '?since=' + encodeURIComponent(since), {headers: {'Accept': 'application/json'}});
            if (!res.ok) return;
            const data = await res.json();
            let structure = false;
            for (const c of data.cells) {
                structure = ensureRow(c.page, c.name) || structure;
                if (c.cell) structure = ensureColumn(c.key, c.cell.secret) || structure;
                const row = rows.get(c.page);
                if (editing && editing.pk === c.page && editing.key === c.key) {
                    // Itatabi; ilalapat pagkatapos ng edit kung walang bagong value na nai-save.
                    editing.server = c.cell;
                    continue;
                }
                if (c.cell) row.cells[c.key] = c.cell; else delete row.cells[c.key];
                row.info.botcake = !!(row.cells['botcake.page_id'] && row.cells['botcake.token']);
                if (!structure) { repaint(c.page, c.key); refreshActions(c.page); }
            }
            for (const [pk, info] of Object.entries(data.info || {})) {
                const row = rows.get(pk);
                if (!row) continue;
                Object.assign(row.info, info);
                refreshInfo(pk);
            }
            since = data.now;
            document.getElementById('sync-lock').classList.toggle('hidden', !data.sync_lock);
            document.getElementById('sync-lock-since').textContent = data.sync_lock || '';
            showSyncAll(data.sync_all);
            if (structure) render();
        } catch (err) { /* susubukan ulit sa susunod na poll */ }
    }
    setInterval(poll, POLL_MS);

    // ---- history ----

    async function openHistory(pk, key) {
        const row = rows.get(pk), col = columns.find(c => c.key === key);
        const panel = document.getElementById('history');
        document.getElementById('history-title').textContent = row.name + ' · ' + col.label;
        const cur = row.cells[key];
        const current = document.getElementById('history-current');
        put(current,
            el('div', {class: 'text-xs text-gray-500', text: 'Ngayon'}),
            el('div', {class: 'font-mono break-all', text: cur ? cur.value : '(walang laman)'}),
            cur ? el('div', {class: 'text-xs text-gray-500', text: cur.by + ' · ' + cur.at}) : null,
            cur ? el('button', {class: 'mt-1 text-xs text-red-600 hover:underline', type: 'button', id: 'history-clear', text: 'Burahin ang value'}) : null,
        );
        const list = document.getElementById('history-list');
        list.replaceChildren(el('li', {class: 'text-gray-400', text: 'Kinukuha…'}));
        panel.classList.remove('hidden');

        const clear = document.getElementById('history-clear');
        if (clear) clear.addEventListener('click', async () => {
            if (!confirm('Burahin ang ' + col.key + ' ng ' + row.name + '?')) return;
            const res = await fetch(url.cellUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
                body: JSON.stringify({page_name: row.name, provider: col.provider, key: col.name, clear: true}),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) { notice(data.error || 'Hindi nabura.', 'text-red-600'); return; }
            delete row.cells[key];
            render();
            openHistory(pk, key);
        });

        const q = new URLSearchParams({page_name: row.name, provider: col.provider, key: col.name});
        const res = await fetch(url.historyUrl + '?' + q, {headers: {'Accept': 'application/json'}});
        const data = await res.json().catch(() => ({history: []}));
        list.replaceChildren(...(data.history || []).map(h => el('li', {class: 'border-b pb-1'}, [
            el('span', {class: 'font-mono break-all', text: h.value}),
            el('div', {class: 'text-gray-500', text: h.by + ' · ' + h.at + ' → ' + (h.replaced_at || '')}),
        ])));
        if (!list.children.length) list.append(el('li', {class: 'text-gray-400', text: 'Walang dating value.'}));
    }
    document.getElementById('history-close').addEventListener('click', () => document.getElementById('history').classList.add('hidden'));

    // ---- Test lahat ng token: isang page kada request, isa-isa, sa ayos ng grid ----

    const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
    const BETWEEN_MS = 500, RATE_WAIT_MS = 5000;

    // Isang page. Ang wait = ilang ms bago subukan ulit kapag rate limit (429 ng Likha o "rate_limited" ng Botcake).
    async function testOne(r) {
        try {
            const res = await fetch(url.testTokenOneUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
                body: JSON.stringify({page_name: r.name}),
            });
            const data = await res.json().catch(() => ({}));
            if (res.status === 429) {
                const after = parseFloat(res.headers.get('Retry-After'));
                return {data: {status: 'error', error: 'Rate limit (HTTP 429)'}, wait: after > 0 ? after * 1000 : RATE_WAIT_MS};
            }
            if (!res.ok) return {data: {status: 'error', error: data.error || data.message || ('HTTP ' + res.status)}};
            return {data, wait: data.reason === 'rate_limited' ? RATE_WAIT_MS : 0};
        } catch (err) {
            return {data: {status: 'error', error: 'Walang koneksyon.'}};
        }
    }

    document.getElementById('test-all').addEventListener('click', async e => {
        const btn = e.currentTarget, out = document.getElementById('test-all-status');
        const all = [...rows.values()].sort(gridOrder);
        const targets = all.filter(r => r.info.botcake);
        const sum = {ok: 0, invalid: 0, error: 0, skipped: all.length - targets.length};
        btn.disabled = true;

        for (let i = 0; i < targets.length; i++) {
            const r = targets[i], id = cellId(r.key, 'botcake.token_status');
            if (i > 0) await sleep(BETWEEN_MS);
            out.textContent = 'Test token: ' + (i + 1) + ' / ' + targets.length + ' · ' + r.name;
            marks.set(id, {text: 'Testing…', cls: 'text-gray-400'});
            repaint(r.key, 'botcake.token_status');
            let {data, wait} = await testOne(r);
            if (wait) {
                // Isang ulit lang ang parehong page, pagkatapos maghintay; kapag rate limit pa rin, error.
                out.textContent = 'Test token: ' + (i + 1) + ' / ' + targets.length + ' · ' + r.name + ' (rate limit, maghihintay ' + Math.ceil(wait / 1000) + ' s)';
                await sleep(wait);
                ({data} = await testOne(r));
            }
            const status = ['ok', 'invalid', 'skipped'].includes(data.status) ? data.status : 'error';
            sum[status]++;
            if (data.cell) r.cells['botcake.token_status'] = data.cell;
            if (status === 'error') marks.set(id, {text: '!', cls: 'text-red-600', title: data.error || 'Hindi nasubukan.'});
            else marks.set(id, {text: status, cls: status === 'ok' ? 'text-green-600' : 'text-red-600'});
            repaint(r.key, 'botcake.token_status');
        }

        out.textContent = 'Test token tapos: ' + sum.ok + ' ok, ' + sum.invalid + ' invalid, ' + sum.error + ' error, '
            + sum.skipped + ' walang Botcake ID o token (nilaktawan).';
        btn.disabled = false;
    });

    // ---- Sync lahat: sa queue, isa-isang page; ang progreso ay mula sa poll ----

    const fmt = v => Number(v || 0).toLocaleString('en-US');
    const duration = s => s >= 3600 ? Math.floor(s / 3600) + ' h ' + Math.floor((s % 3600) / 60) + ' min' : Math.floor(s / 60) + ' min';
    const STATUS = {running: 'tumatakbo', paused: 'naka-pause', stopped: 'itinigil', done: 'tapos'};

    function showSyncAll(p) {
        const active = !!(p && (p.status === 'running' || p.status === 'paused'));
        document.getElementById('sync-all').disabled = active;
        document.getElementById('sync-all-pause').classList.toggle('hidden', !(p && p.status === 'running'));
        document.getElementById('sync-all-resume').classList.toggle('hidden', !(p && p.status === 'paused'));
        document.getElementById('sync-all-stop').classList.toggle('hidden', !active);
        document.getElementById('sync-all-panel').classList.toggle('hidden', !p);
        document.getElementById('sync-all-status').textContent = p && active ? 'Sync lahat: ' + p.done + ' / ' + p.total : '';
        if (!p) return;

        // hal. "Sync lahat · tumatakbo · 7 / 40 page · ngayon: Page X (window, 120 batch) · 1 failed · +9,985 bago · ..."
        const current = p.current && active
            ? 'ngayon: ' + p.current + (p.current_kind ? ' (' + p.current_kind + (p.current_batches != null ? ', ' + fmt(p.current_batches) + ' batch' : '') + ')' : '')
                + (p.waiting ? ' (naghihintay matapos ang ibang sync)' : '') + (p.status === 'paused' ? ' (tatapusin muna)' : '')
            : null;
        document.getElementById('sync-all-line').textContent = ['Sync lahat', STATUS[p.status] || p.status, p.done + ' / ' + p.total + ' page',
            current, p.failed ? p.failed + ' failed' : null, p.skipped ? p.skipped + ' laktaw (sariwa)' : null,
            p.remaining && active ? p.remaining + ' natitira' : null,
            '+' + fmt(p.totals.new) + ' bago', fmt(p.totals.updated) + ' na-update', fmt(p.totals.matched) + ' na-match', fmt(p.totals.ad_filled) + ' ad id',
            duration(p.seconds), 'sinimulan' + (p.started_by ? ' ni ' + p.started_by : '') + ' ' + (p.started_at || ''),
            p.finished_at ? 'natapos ' + p.finished_at : null].filter(Boolean).join(' · ');

        put(document.getElementById('sync-all-history'), ...(p.history || []).map(h => el('tr', {}, [
            h.started_at, h.started_by || '', STATUS[h.status] || h.status, h.done + ' / ' + h.total, String(h.failed), String(h.skipped),
            fmt(h.totals.new), fmt(h.totals.matched), fmt(h.totals.ad_filled), duration(h.seconds),
        ].map(v => el('td', {class: 'pr-3', text: v || ''})))));
    }

    for (const b of document.querySelectorAll('.sync-all-control')) {
        b.addEventListener('click', async () => {
            if (b.dataset.action === 'stop' && !confirm('Itigil ang Sync lahat? Tatapusin muna ang page na tumatakbo.')) return;
            b.disabled = true;
            try {
                const res = await fetch(url.syncAllUrl + '/' + b.dataset.action, {method: 'POST', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}});
                const data = await res.json().catch(() => ({}));
                if (!res.ok) notice(data.message || 'Hindi nagawa.', 'text-red-600'); else showSyncAll(data.sync_all);
            } catch (err) {
                notice('Walang koneksyon.', 'text-red-600');
            }
            b.disabled = false;
        });
    }

    document.getElementById('sync-all').addEventListener('click', async e => {
        const btn = e.currentTarget;
        if (!confirm('I-sync ang lahat ng page na may Botcake ID at token, isa-isa sa queue, ayon sa plano: ' + document.getElementById('plan-summary').textContent + '?')) return;
        btn.disabled = true;
        try {
            const res = await fetch(url.syncAllUrl, {method: 'POST', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}});
            const data = await res.json().catch(() => ({}));
            if (!res.ok) { notice(data.message || 'Hindi nasimulan ang Sync lahat.', 'text-red-600'); btn.disabled = false; return; }
            showSyncAll(data.sync_all);
            if (data.status === 'running') notice('May tumatakbo o naka-pause nang Sync lahat; walang bagong sinimulan (gamitin ang Resume o Stop).', 'text-amber-700');
            if (data.status === 'not_ready') { notice('Hindi pa handa ang Sync lahat (inaayos pa ang database pagkatapos ng update). Subukan ulit mamaya.', 'text-amber-700'); btn.disabled = false; }
        } catch (err) {
            notice('Walang koneksyon: hindi nasimulan ang Sync lahat.', 'text-red-600');
            btn.disabled = false;
        }
    });

    // ---- + Page, + Column, filter ----

    document.getElementById('add-page').addEventListener('submit', async e => {
        e.preventDefault();
        const input = e.target.page_name;
        const res = await fetch(url.pageCheckUrl + '?' + new URLSearchParams({page_name: input.value}), {headers: {'Accept': 'application/json'}});
        const data = await res.json().catch(() => ({}));
        if (!res.ok) { notice(data.error || 'Hindi naidagdag.', 'text-red-600'); return; }
        const pk = data.key;
        const added = ensureRow(pk, data.name);
        input.value = '';
        notice(
            (added ? 'Naidagdag ang row na "' + data.name + '" (mase-save kapag may nilagay na value).' : 'Nasa grid na ang "' + data.name + '".')
            + (data.in_orders ? '' : ' Babala: walang order na may PAGE na ganito — tama ba ang baybay?'),
            data.in_orders ? 'text-green-700' : 'text-amber-700'
        );
        render();
        table.querySelector(`tr[data-pk="${CSS.escape(pk)}"]`)?.scrollIntoView({block: 'center'});
    });

    document.getElementById('add-column').addEventListener('submit', e => {
        e.preventDefault();
        const f = e.target;
        const provider = f.provider.value.trim().toLowerCase(), key = f.key.value.trim().toLowerCase();
        if (!/^[a-z0-9_]{1,50}$/.test(provider)) { notice('Provider: maliliit na letra, numero at _ lang.', 'text-red-600'); return; }
        if (!/^[a-z0-9_]+(\.[a-z0-9_]+)*$/.test(key) || key.length > 100) { notice('Key: maliliit na letra, numero, _ at . lang (hal. flow.reminder).', 'text-red-600'); return; }
        const added = ensureColumn(provider + '.' + key, f.is_secret.checked);
        notice(added ? 'Naidagdag ang column ' + provider + '.' + key + ' (mase-save kapag may nilagay na value).' : 'Nasa grid na ang column na iyan.', 'text-green-700');
        f.key.value = '';
        f.is_secret.checked = false;
        render();
    });

    document.getElementById('filter').addEventListener('input', () => render());

    showSyncAll(initial.sync_all);
    render();
})();
</script>
</body>
</html>
