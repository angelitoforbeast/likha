@extends('layouts.chatblast')

@section('title', 'Page Settings')
@section('heading', 'Page Settings')
@section('description', 'The Botcake settings of every page: ID, token, flows, message field and template. Values save as you type.')

@section('actions')
    <button id="setup-help" type="button" class="text-blue-700 hover:underline">How to set up a new page</button>
    <a href="{{ route('page-settings.psid-coverage') }}" class="text-blue-700 hover:underline">PSID coverage (orders without a PSID)</a>
@endsection

@push('styles')
    <style>
        /* Ang grid lang ang nag-i-scroll; nakadikit ang header row (puti, isang linya mula sa layout). Magaan na hati ng row. */
        #grid-table thead tr, #grid-table thead th { background: #fff; }
        #grid-table thead th { border-bottom: 0; color: #64748b; }
        #grid-table th, #grid-table td { border-right-color: transparent; }
        #grid-table tbody td { border-bottom-color: #f1f5f9; }
        #grid-table .cell { cursor: text; }
        #grid-table .cell.empty:hover { outline: 1px dashed #94a3b8; }
        #grid-table .cell.readonly { cursor: default; }
        #grid-table .cell:hover .hist { visibility: visible; }
        #grid-table .hist { visibility: hidden; }
    </style>
@endpush

@section('content')
<div class="cb-page">

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

    {{-- Ang Setup box: iisang pangalan ng flow, custom field at template para sa lahat ng page. Ang setup check ang nagsusulat
         ng flow ID, field ID at template ng bawat page (Test token, Check all, at gabi-gabi). --}}
    <section id="setup-box" class="cb-card mb-3 p-3 text-sm" aria-labelledby="setup-title"
             data-url="{{ route('page-settings.setup') }}" data-check-url="{{ route('page-settings.setup.check-all') }}" data-progress-url="{{ route('page-settings.setup.progress') }}">
        <div class="mb-2 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            <h2 id="setup-title" class="font-semibold">Setup for every page</h2>
            <span class="text-xs text-gray-500">Each page's flow ID, message field ID and template are set from these exact names. Checked every night at 21:30.</span>
        </div>
        <form id="setup-form" class="flex flex-wrap items-end gap-3">
            <label class="flex w-full flex-col gap-0.5 text-xs text-gray-600 sm:w-64">Flow name
                <input name="flow" required maxlength="255" value="{{ $grid['setup']['names']['flow'] }}" class="w-full rounded border px-2 py-1 text-sm text-gray-900">
            </label>
            <label class="flex w-full flex-col gap-0.5 text-xs text-gray-600 sm:w-40">Custom field name
                <input name="field" required maxlength="255" value="{{ $grid['setup']['names']['field'] }}" class="w-full rounded border px-2 py-1 text-sm text-gray-900">
            </label>
            <label class="flex w-full flex-col gap-0.5 text-xs text-gray-600 sm:w-40">Template name
                <input name="template" required maxlength="255" value="{{ $grid['setup']['names']['template'] }}" class="w-full rounded border px-2 py-1 text-sm text-gray-900">
            </label>
            <button class="cb-btn cb-btn-primary">Save and check all</button>
            <button type="button" id="setup-check-all" class="cb-btn">Check all</button>
            <span id="setup-status" class="min-w-0 text-xs text-gray-600" aria-live="polite"></span>
        </form>
    </section>

    <div class="cb-filters flex flex-wrap items-end gap-4 mb-3 text-sm">
        <input id="filter" placeholder="Search pages" class="border rounded px-2 py-1">

        <form id="add-page" class="flex items-center gap-1">
            <input name="page_name" placeholder="Page name (as PAGE in orders)" class="border rounded px-2 py-1 w-72" required>
            <button class="cb-btn">+ Page</button>
        </form>

        <form id="add-column" class="flex items-center gap-1">
            <input name="provider" value="botcake" placeholder="provider" class="border rounded px-2 py-1 w-24" required>
            <input name="key" placeholder="key (e.g. flow.reminder)" class="border rounded px-2 py-1 w-44" required>
            <label class="flex items-center gap-1"><input type="checkbox" name="is_secret" value="1"> secret</label>
            <button class="cb-btn">+ Column</button>
        </form>

        <span id="notice" class="min-w-0 flex-1 basis-0 truncate text-xs"></span>
    </div>

    <div class="flex flex-wrap items-center gap-3 mb-3 text-sm">
        <button id="test-all" type="button" class="cb-btn">Test all tokens</button>
        <span id="test-all-status" class="text-xs text-gray-600" aria-live="polite"></span>
        <button id="sync-all" type="button" class="cb-btn cb-btn-primary">Sync all</button>
        <button id="sync-all-pause" type="button" data-action="pause" class="sync-all-control cb-btn hidden">Pause</button>
        <button id="sync-all-resume" type="button" data-action="resume" class="sync-all-control cb-btn hidden">Resume</button>
        <button id="sync-all-stop" type="button" data-action="stop" class="sync-all-control cb-btn hidden">Stop</button>
        <span id="sync-all-status" class="text-xs text-gray-600" aria-live="polite"></span>
    </div>

    {{-- Ang kasalukuyan o huling Sync lahat run, at ang huling 10 run. Ina-update ng poll. --}}
    <div id="sync-all-panel" class="hidden mb-3 px-4 py-2 rounded bg-blue-50 text-blue-950 text-xs">
        <div id="sync-all-line" class="mb-1"></div>
        <details>
            <summary class="cursor-pointer text-blue-800">Last 10 Sync all runs</summary>
            <table class="mt-1 border-collapse">
                <thead><tr class="text-left text-gray-600">
                    <th class="pr-3">Started</th><th class="pr-3">By</th><th class="pr-3">Status</th><th class="pr-3">Page</th>
                    <th class="pr-3">Failed</th><th class="pr-3">Skipped</th><th class="pr-3">New</th><th class="pr-3">Matched</th>
                    <th class="pr-3">Ad id</th><th class="pr-3">Duration</th>
                </tr></thead>
                <tbody id="sync-all-history"></tbody>
            </table>
        </details>
    </div>

    {{-- Ang plano ng Sync lahat, mula sa "Susunod na sync" ng bawat page. --}}
    <p class="mb-3 text-xs text-gray-700">Sync all plan: <span id="plan-summary">{{ $grid['plan_summary'] ?: 'none' }}</span></p>

    {{-- Ang lock ng Botcake sync: habang hawak, "Skipped" ang ibang sync (schedule, button, command). --}}
    <div id="sync-lock" class="mb-3 px-4 py-2 rounded bg-amber-100 text-amber-900 text-sm {{ $grid['sync_lock'] ? '' : 'hidden' }}">
        A Botcake sync has been running since <span id="sync-lock-since">{{ $grid['sync_lock'] }}</span>. Other syncs skip until it ends (or for up to 2 hours if it was cut off).
    </div>

    <p class="mb-2 text-xs text-gray-500">
        Click a value and type: it saves by itself (Enter, leaving the field, or a few seconds without typing). Blank = delete the setting.
        Token / secret: blank = no change; the value is never shown (last 4 only). Every few seconds, changes made by others are loaded.
        Botcake, flow and field IDs: digits only.
        The Chatblast flow (the one every sequence sends through) shows its ID, its name in Botcake and the template content, where [message for the customer] is each customer's message. The flow ID, the message field ID and the template are set by the check from the names in "Setup for every page". "+ set": no value yet, click to set it.
        ▸ next to a page: the full flow names, the full template, the sync details, the other keys and the history.
    </p>

    {{-- Buod ng grid: bilang mula sa grid na nasa browser na (walang bagong query); bawat chip ay filter. --}}
    <div id="summary-bar" class="flex flex-wrap items-center gap-2 mb-2 text-xs"></div>

    <div id="grid"
         class="cb-card cb-scroll"
         data-grid-url="{{ route('page-settings.grid') }}"
         data-cell-url="{{ route('page-settings.cell') }}"
         data-history-url="{{ route('page-settings.history') }}"
         data-page-check-url="{{ route('page-settings.page-check') }}"
         data-test-token-url="{{ route('page-settings.test-token') }}"
         data-test-token-one-url="{{ route('page-settings.test-token-one') }}"
         data-sync-url="{{ route('page-settings.sync') }}"
         data-send-test-url="{{ route('page-settings.send-test') }}"
         @if ($isCeo ?? false) data-mark-tested-url="{{ route('page-settings.mark-tested') }}" @endif
         data-test-psid-picker-url="{{ route('chatblast.index') }}#test-psid"
         data-sync-all-url="{{ route('page-settings.sync-all') }}"
         data-coverage-url="{{ route('page-settings.psid-coverage.orders') }}"
         data-customers-url="{{ route('page-settings.customers') }}">
        <table id="grid-table" class="text-sm border-collapse w-full table-fixed"></table>
    </div>

    {{-- Ang history ng isang value: modal sa gitna (hindi side panel na tumatakip sa grid). Isara: ✕, Escape, o pindot sa labas. --}}
    <div id="history" class="fixed inset-0 z-50 hidden overflow-y-auto bg-slate-900/30 p-4 sm:p-8" role="dialog" aria-modal="true">
        <div class="mx-auto w-full max-w-lg rounded-xl bg-white p-5 text-sm shadow-lg">
            <div class="mb-3 flex items-center justify-between">
                <div id="history-title" class="break-all font-semibold"></div>
                <button id="history-close" class="px-2 text-slate-500 hover:text-slate-800" aria-label="Close">✕</button>
            </div>
            <div id="history-current" class="mb-3"></div>
            <ul id="history-list" class="space-y-1 text-xs text-gray-700"></ul>
        </div>
    </div>

    {{-- Ang gabay sa bagong page: modal sa gitna, hindi sticky. Isara: ✕, Escape, o pindot sa labas. --}}
    <div id="setup-guide" class="fixed inset-0 z-50 hidden overflow-y-auto bg-slate-900/30 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="setup-guide-title">
        <div class="mx-auto w-full max-w-lg rounded-xl bg-white p-5 text-sm shadow-lg">
            <div class="mb-3 flex items-center justify-between">
                <div id="setup-guide-title" class="font-semibold">How to set up a new page</div>
                <button id="setup-guide-close" type="button" class="px-2 text-slate-500 hover:text-slate-800" aria-label="Close">✕</button>
            </div>
            <ol class="list-decimal space-y-2 pl-5 text-slate-700">
                <li><b>Botcake ID and token.</b> Add the page with + Page, then type its Botcake ID and its token (Botcake: page settings, API).</li>
                <li><b>The automatic check.</b> When both are saved, the token is tested and the flow, the custom field and the template named in "Setup for every page" are found and set. The Check column shows a check, or an X with the reason.</li>
                <li><b>Copy the flow and field in Botcake if missing.</b> An X says what is missing: copy the flow from another page, or create the custom field, then press Test.</li>
                <li><b>Ready.</b> When the check sets the flow and field and passes, the row turns green without a test send: the page's messages are now in To send and auto. After the first sends, the check also confirms in Botcake that they were delivered.</li>
                <li><b>Send test to the Test PSID (optional).</b> Needed only when a person changes the flow or field ID by hand: set the page's Test PSID (your own account), then press Send test and check Messenger.</li>
            </ol>
        </div>
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
    const ORDERS_LABEL = @json('Orders (' . $grid['order_days'] . ' days)');

    let columns = initial.columns;
    const SETUP = initial.setup.names; // ang mga pangalan ng Setup box (para sa mga gabay)
    const rows = new Map(initial.rows.map(r => [r.key, r]));
    let since = initial.now;
    let editing = null;      // {pk, key, td, input, original, timer}
    let needsRender = false;
    const marks = new Map(); // "pk|key" → {text, cls, title}
    const saveSeq = new Map(); // "pk|key" → bilang ng huling save: ang sagot ng mas lumang save ay hindi na inilalapat
    const open = new Set(); // mga page na bukas ang detalye: nasa labas ng render(), kaya hindi nagsasara sa poll o filter

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

    // Isang linya lang (ang buo ay nasa tooltip): nasa nakapirming header ito, kaya ang mahabang mensahe ay hindi dapat
    // magdagdag ng linya na nagtutulak pababa sa grid.
    function notice(text, cls = 'text-gray-600') {
        const n = document.getElementById('notice');
        n.className = 'min-w-0 flex-1 basis-0 truncate text-xs ' + cls;
        n.textContent = text;
        n.title = text;
    }

    // Pinakamaraming order muna; ang walang order sa window ay nasa ibaba, ayon sa pangalan.
    const gridOrder = (a, b) => ((b.info.orders || 0) - (a.info.orders || 0)) || a.name.localeCompare(b.name, undefined, {numeric: true, sensitivity: 'base'});

    function sortedRows() {
        const q = document.getElementById('filter').value.trim().toLowerCase();
        return [...rows.values()]
            .filter(r => (!q || r.name.toLowerCase().includes(q)) && chipFilter(r))
            .sort(gridOrder);
    }

    // ---- kalagayan ng row: ang summary bar at ang kulay sa gilid ng Page cell ----

    // Kulang ang setup ng page na may order kapag wala ang isa sa mga ito.
    const REQUIRED = ['botcake.page_id', 'botcake.token', 'botcake.flow.shipping', 'botcake.field.message', 'botcake.template.shipping'];
    const FLOW_NAME = /^botcake\.flow\.[a-z0-9_]+\.name$/;

    // "Nasubukan": may test (botcake.test_ok) o totoong padala na hindi mas luma sa huling pagbabago ng setup, gaya ng
    // ChatblastReady sa server. Ang mga oras ay buong timestamp (ts) ng cell at info.last_send.
    const CONFIG_KEYS = ['botcake.page_id', 'botcake.token', 'botcake.flow.shipping', 'botcake.field.message'];
    const TEST_OK_KEY = 'botcake.test_ok';
    const CHECK_KEY = 'botcake.setup.check', DELIVERY_KEY = 'botcake.setup.delivery';
    const json = c => { try { const v = JSON.parse(c?.value || ''); return v && typeof v === 'object' ? v : null; } catch (e) { return null; } };
    const checkOf = r => json(r.cells[CHECK_KEY]), deliveryOf = r => json(r.cells[DELIVERY_KEY]);
    // Gaya ng ChatblastReady sa server: hindi Ready ang X ng check o ang "none delivered"; kung wala, Ready kapag may test o
    // totoong padala pagkatapos ng setup, o kapag ang check ang sumulat ng flow at field at pumasa pagkatapos ng setup.
    // Pagkatapos ng pumasang check: ang flow o field na iba sa nahanap nito (hal. pinili ng tao) ay humaharang, kahit may test.
    function drift(r) {
        const c = checkOf(r);
        if (!c || c.ok !== true) return null;
        for (const [key, what, kind] of [['botcake.flow.shipping', 'flow', 'flow'], [FIELD_KEY, 'field', 'custom field']]) {
            const found = String(c[what]?.id || '');
            if (found !== '' && String(r.cells[key]?.value || '') !== found) return 'The ' + what + ' ID is not the ' + kind + ' named ' + ((c.names || {})[what] || '') + ' (press Test to set it)';
        }
        return null;
    }
    const notDelivered = r => { const d = deliveryOf(r); return !!(d && d.status === 'not_delivered' && String(d.flow_id || '') === String(r.cells['botcake.flow.shipping']?.value || '')); };
    function tested(r) {
        const c = checkOf(r);
        if (c && c.ok === false) return false;
        if (drift(r) || notDelivered(r)) return false;
        const changed = CONFIG_KEYS.map(k => r.cells[k]?.ts || '').sort().pop();
        if (!changed) return false;
        const test = r.cells[TEST_OK_KEY]?.ts || '';
        if ((test !== '' && test >= changed) || (!!r.info.last_send && r.info.last_send >= changed)) return true;
        return byCheck(r, changed);
    }
    // Ready dahil ang check ang sumulat ng flow at field (system) at pumasa pagkatapos ng huling pagbabago: walang Send test.
    function byCheck(r, changed = CONFIG_KEYS.map(k => r.cells[k]?.ts || '').sort().pop()) {
        const c = checkOf(r), system = k => r.cells[k] && r.cells[k].by === 'system';
        return !!(changed && c && c.ok === true && String(c.at || '') >= changed && system('botcake.flow.shipping') && system(FIELD_KEY));
    }
    // Kumpleto para sa pagpapadala: ang 4 na key ng server (Chatblast::REQUIRED), hindi kasama ang template.
    const complete = r => CONFIG_KEYS.every(k => r.cells[k]);

    function state(r) {
        const token = String(r.cells['botcake.token_status']?.value || '');
        return {
            invalid: token.startsWith('invalid'),
            ok: token.startsWith('ok') && tested(r),
            failed: !!(r.info.last_sync && r.info.last_sync.failed),
            incomplete: r.info.orders > 0 && REQUIRED.some(k => !r.cells[k]),
            // Kumpleto pero wala pang kumpirmadong padala: hindi pa ipinapadala ng chatblast.
            needsTest: complete(r) && !tested(r),
            // Pulang linya ng flow na may ID: wala sa Botcake o bura na.
            flows: Object.entries(r.cells).some(([k, c]) => FLOW_NAME.test(k) && c && c.tone === 'red' && r.cells[k.slice(0, -'.name'.length)]),
            // X ng setup check (flow, field o template) o ng delivered check: hindi nagpapadala.
            checkX: (checkOf(r)?.ok === false) || notDelivered(r) || !!drift(r),
            // Hindi APPROVED ang template ng shipping flow: bigo ang pagpapadala.
            template: !!(r.cells[SHIPPING_KEY] && r.cells[TEMPLATE_KEY] && r.cells[TEMPLATE_KEY + '.text'] && r.cells[TEMPLATE_KEY + '.text'].tone === 'red'),
        };
    }

    const CHIPS = [
        ['all', 'Pages', () => true],
        ['ready', 'Ready', r => borderOf(r) === 'border-l-green-500'],
        ['orders', 'With orders', r => r.info.orders > 0],
        ['invalid', 'Token invalid', (r, st) => st.invalid],
        ['incomplete', 'Setup incomplete', (r, st) => st.incomplete],
        ['needs_test', 'Needs test', (r, st) => st.needsTest],
        ['flows', 'Flow problems', (r, st) => st.flows || st.template],
        ['check', 'Check X', (r, st) => st.checkX],
        ['failed', 'Last sync failed', (r, st) => st.failed],
    ];
    const LEGEND = [['bg-green-500', 'Ready'], ['bg-amber-400', 'Setup incomplete or needs a test send'], ['bg-red-500', 'Token invalid, last sync failed, template not approved or an X in Check']];
    let chip = null; // ang naka-pindot na chip; null = lahat

    function chipFilter(r) {
        const c = CHIPS.find(([key]) => key === chip);
        return !c || c[2](r, state(r));
    }

    // Ang mga chip ay binubuo nang isang beses at ina-update lang, para hindi mawala ang keyboard focus sa poll o pindot.
    function summaryBar() {
        const bar = document.getElementById('summary-bar'), all = [...rows.values()];
        const st = new Map(all.map(r => [r, state(r)]));
        if (!bar.children.length) {
            put(bar, ...CHIPS.map(([key]) => el('button', {type: 'button', 'data-chip': key})),
                el('span', {class: 'ml-2 flex items-center gap-3 text-gray-500'}, LEGEND.map(([color, label]) =>
                    el('span', {class: 'flex items-center gap-1'}, [el('span', {class: 'inline-block w-1 h-3 ' + color}), label]))));
        }
        for (const [key, label, test] of CHIPS) {
            const b = bar.querySelector(`[data-chip="${key}"]`), on = (chip ?? 'all') === key;
            b.setAttribute('aria-pressed', String(on));
            b.className = 'rounded-full border px-2 py-0.5 ' + (on ? 'bg-blue-600 border-blue-600 text-white' : 'bg-white hover:bg-gray-100 text-gray-700');
            b.title = key === 'all' ? 'Show every page' : on ? 'Click again to show every page' : 'Show only these pages';
            b.textContent = label + ' ' + all.filter(r => test(r, st.get(r))).length;
        }
    }

    document.getElementById('summary-bar').addEventListener('click', e => {
        const b = e.target.closest('[data-chip]');
        if (!b) return;
        const key = b.dataset.chip === 'all' ? null : b.dataset.chip;
        chip = chip === key ? null : key;
        render();
    });

    function borderOf(r) {
        const st = state(r);
        return !r.info.orders ? 'border-l-transparent' : st.invalid || st.failed || st.template || st.checkX ? 'border-l-red-500' : st.incomplete || st.needsTest ? 'border-l-amber-400' : st.ok ? 'border-l-green-500' : 'border-l-transparent';
    }

    // Pagkatapos magbago ang cell o info ng isang page: ang kulay ng row at ang mga bilang lang, hindi ang buong table.
    function paintState(pk) {
        const td = table.querySelector(`tr[data-pk="${CSS.escape(pk)}"] td.sticky-col`), r = rows.get(pk);
        if (td && r) td.className = td.className.replace(/\bborder-l-(?:red-500|amber-400|green-500|transparent)\b/, borderOf(r));
        summaryBar();
    }

    function ensureColumn(key, secret) {
        if (columns.some(c => c.key === key)) return false;
        const dot = key.indexOf('.');
        columns.push({key, provider: key.slice(0, dot), name: key.slice(dot + 1), label: key, secret: !!secret, readonly: false, group: 'other'});
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

    // Ang mga cell ng bawat row, para walang horizontal scroll: ang mga column ay pinagsama ayon sa group (mula sa server).
    // Ang "Chatblast flow" ay ang shipping flow lang: dito dumadaan ang bawat sequence. Ang ibang flow slot ay nasa ▸.
    const GROUPS = [['botcake', 'Botcake'], ['flows', 'Chatblast flow']];
    const WIDTHS = ['12%', '13%', '19%', '24%', '7%', '15%', '10%']; // Page, Check, Botcake, Flows, Orders / Customers, Sync, actions
    // Maikling label sa loob ng cell; ang buong label at key ay nasa hover.
    // Ayos ng mga linya sa Botcake cell: ID, token, status, saka ang message field at test PSID.
    const LINE_ORDER = ['botcake.page_id', 'botcake.token', 'botcake.token_status', 'botcake.field.message', 'botcake.test_psid', 'botcake.test_ok'];
    const inGroup = group => columns.filter(c => c.group === group)
        .sort((a, b) => (LINE_ORDER.indexOf(a.key) + 1 || 99) - (LINE_ORDER.indexOf(b.key) + 1 || 99));
    const SHORT = {'botcake.page_id': 'ID', 'botcake.token': 'Token', 'botcake.token_status': 'Status', 'botcake.field.message': 'Message field',
        'botcake.test_psid': 'Test PSID', 'botcake.test_ok': 'Test send', 'botcake.flow.shipping': 'Flow ID', 'botcake.flow.on_delivery': 'Delivering',
        'botcake.flow.in_transit': 'In transit', 'botcake.flow.broadcast': 'Broadcast', 'botcake.template.shipping': 'Template'};

    function render() {
        if (editing) { needsRender = true; return; }
        needsRender = false;

        const th = 'bg-gray-100 px-2 py-2 border-b border-r';
        const head = el('tr', {class: 'bg-gray-100 text-left text-xs text-gray-600'}, [
            el('th', {class: 'sticky-col ' + th, text: 'Page'}),
            el('th', {class: th, text: 'Check', title: 'The setup check: flow, custom field and template by the names in "Setup for every page", and delivery'}),
            ...GROUPS.map(([, label]) => el('th', {class: th, text: label})),
            el('th', {class: th, text: 'Orders / Customers', title: ORDERS_LABEL + ' / Customers'}),
            el('th', {class: th, text: 'Sync', title: 'Last sync / Next sync'}),
            el('th', {class: 'bg-gray-100 px-2 py-2 border-b', text: ''}),
        ]);

        // Walang order sa window: naka-gray ang buong row (maaari pa ring i-edit; hindi nawawala ang settings).
        const body = sortedRows().flatMap(r => {
            const tr = el('tr', {class: 'hover:bg-blue-50/40' + (r.info.orders ? '' : ' text-gray-400'), 'data-pk': r.key}, [
                pageTd(r),
                el('td', {class: 'px-2 py-1 border-b border-r align-top text-xs', 'data-info': 'check'}, checkCell(r)),
                ...GROUPS.map(([group]) => el('td', {class: 'px-2 py-1 border-b border-r align-top'},
                    group === 'flows' ? inGroup(group).filter(c => c.key === SHIPPING_KEY).map(c => flowBlock(r, c)) : inGroup(group).map(c => line(r, c)))),
                el('td', {class: 'px-2 py-1 border-b border-r align-top text-right text-xs', 'data-info': 'oc', title: ORDERS_LABEL + ' / Customers'}, infoOc(r)),
                paintSync(el('td', {class: 'px-2 py-1 border-b border-r align-top text-xs', 'data-info': 'sync'}), r),
                el('td', {class: 'px-2 py-1 border-b align-top', 'data-info': 'actions'}, actions(r)),
            ]);
            return open.has(r.key) ? [tr, detailTr(r)] : [tr];
        });

        table.replaceChildren(el('colgroup', {}, WIDTHS.map(w => el('col', {style: 'width: ' + w}))), el('thead', {}, head), el('tbody', {}, body.length ? body
            : el('tr', {}, el('td', {class: 'px-3 py-3 text-gray-500', colspan: String(WIDTHS.length), text: rows.size ? 'No pages match the filter.' : 'No pages yet. Use + Page.'}))));
        summaryBar();
    }

    // Ang mga icon na gamit sa Likha (gaya ng nav ng chatblast): bilog na may check, o bilog na may X.
    const ICON_PATHS = {ok: '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>', x: '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>'};
    function icon(kind, label) {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        for (const [k, v] of Object.entries({viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': '2', 'stroke-linecap': 'round', 'stroke-linejoin': 'round',
            class: 'h-4 w-4 shrink-0 ' + (kind === 'ok' ? 'text-green-600' : 'text-red-600'), role: 'img', 'aria-label': label})) svg.setAttribute(k, v);
        svg.innerHTML = ICON_PATHS[kind]; // nakapirming SVG, walang data mula sa labas
        return svg;
    }
    const iconLine = (kind, label, text, cls) => el('div', {class: 'flex items-start gap-1'}, [icon(kind, label), el('span', {class: 'min-w-0 break-words ' + cls, text})]);

    // Ang Check column: check o X (ang dahilan sa ilalim), ang delivered check, kailan nasuri, at ang grey na "Check failed".
    function checkCell(r) {
        const c = checkOf(r), d = deliveryOf(r), cell = r.cells[CHECK_KEY], out = [];
        if (!c) return [el('span', {class: 'text-gray-400', text: r.info.botcake ? 'No check yet' : '—'})];
        const moved = drift(r);
        if (c.ok === true && moved) out.push(iconLine('x', 'Not ready', moved, 'text-red-700'));
        else if (c.ok === true) out.push(iconLine('ok', 'Check passed', 'Flow, field and template', 'text-green-700'));
        else if (c.ok === false) out.push(icon('x', 'Check failed: ' + (c.x || []).join('; ')), ...(c.x || []).map(t => el('div', {class: 'break-words text-red-700', text: t})));
        if (d && c.ok === true) {
            if (d.status === 'delivered') out.push(iconLine('ok', 'Delivered', 'Delivered (Botcake)', 'text-green-700'));
            else if (notDelivered(r)) out.push(iconLine('x', 'Not delivered', 'Sent ' + (d.sent || 0) + ', none delivered (Botcake)', 'text-red-700'));
            else out.push(el('div', {class: 'text-gray-500', text: 'Delivery: waiting for a send'}));
        }
        if (c.error) out.push(el('div', {class: 'break-words text-gray-500', text: 'Check failed: ' + c.error}));
        if (cell) out.push(el('div', {class: 'text-gray-400', text: 'Checked ' + cell.at}));
        return out;
    }

    function pageTd(r) {
        const isOpen = open.has(r.key);
        return el('td', {class: 'sticky-col ' + (r.info.orders ? 'bg-white' : 'bg-gray-50') + ' px-2 py-1 border-b border-r border-l-4 ' + borderOf(r) + ' align-top font-medium break-words'}, [
            el('button', {type: 'button', class: 'toggle mr-1 text-gray-500 hover:text-gray-900', 'data-pk': r.key, 'aria-expanded': String(isOpen),
                title: isOpen ? 'Hide details' : 'Show details', text: isOpen ? '▾' : '▸'}),
            r.name,
        ]);
    }

    // Isang linya sa loob ng cell: maliit na grey na label, saka ang value na nae-edit sa lugar (ang .cell). Ang flow at
    // ang message field ay may dropdown, gabay at babala sa ilalim (pick-lines, katabi ng .cell para hindi mapalitan ng input).
    function line(row, col, labelWidth = 'w-20') {
        const main = el('div', {class: 'flex items-baseline gap-1 min-w-0'}, [
            el('span', {class: labelWidth + ' shrink-0 truncate text-[11px] text-gray-400', title: col.label + (col.readonly ? ' (system)' : '') + ' · ' + col.key,
                text: SHORT[col.key] || col.label}),
            cellEl(row, col),
        ]);
        if (!PICK_KEYS.includes(col.key)) return main;
        return el('div', {class: 'min-w-0'}, [main, el('div', {class: 'pick-lines', 'data-pk': row.key, 'data-key': col.key}, picks(row, col))]);
    }

    // ---- kusang setup: ang listahan mula sa Botcake (…options), ang dropdown sa pag-edit, ang gabay at ang babala ----

    const FIELD_KEY = 'botcake.field.message';
    const PICK_KEYS = ['botcake.flow.shipping', FIELD_KEY];
    const GUIDE = {
        'botcake.flow.shipping': 'No flow named "' + SETUP.flow + '" in Botcake. Copy it in Botcake from another page to this page, then press Test.',
        [FIELD_KEY]: 'No custom field named "' + SETUP.field + '" in Botcake. Create it in Botcake, then press Test.',
    };
    const NOT_CANDIDATE = {'botcake.flow.shipping': 'This flow is not "' + SETUP.flow + '" in Botcake', [FIELD_KEY]: 'This field is not "' + SETUP.field + '" in Botcake'};

    // Ang listahan ([{id, name, candidate, recommended}], nakaayos na) mula sa huling Test, o null kapag hindi pa nasusuri.
    // Ang listahang naitala bago nagkaroon ng candidate ay mga kandidato lang, kaya ang walang candidate ay kandidato.
    const isCandidate = o => o.candidate !== false;
    function optionsOf(row, key) {
        const c = row.cells[key + '.options'];
        if (!c) return null;
        try { const v = JSON.parse(c.value); return Array.isArray(v) ? v : null; } catch (e) { return null; }
    }

    // Sa ilalim ng ID (hindi nag-e-edit): ang gabay kapag walang kandidato, at ang babala kapag ang naka-save ay wala sa
    // listahan o hindi kandidato. Ang dropdown ay nasa pag-edit ng cell (startPick).
    function picks(row, col) {
        const opts = optionsOf(row, col.key);
        if (opts === null) return [];
        const value = String(row.cells[col.key]?.value || ''), item = opts.find(o => String(o.id) === value);
        const out = [];
        if (!opts.some(isCandidate) && value === '') out.push(el('div', {class: 'text-xs text-red-600', text: GUIDE[col.key]}));
        // Ang flow na wala sa Botcake ay may sariling pulang linya na ("Not in Botcake", mula sa pangalan nito).
        // Ang message field ay may sariling "· (not in Botcake list)" sa linya ng ID (fieldName).
        if (value !== '' && !item && col.key !== FIELD_KEY && !(row.cells[col.key + '.name']?.tone === 'red')) out.push(el('div', {class: 'text-xs text-red-600', text: '(not in Botcake list)'}));
        if (item && !isCandidate(item)) out.push(el('div', {class: 'text-xs text-red-600', text: NOT_CANDIDATE[col.key]}));
        return out;
    }

    // Ang pangalan ng message field sa tabi ng ID, mula sa naitalang listahan (walang tawag sa Botcake): amber kapag wala
    // sa listahan, grey na paalala kapag wala pang listahan.
    function fieldName(row, value) {
        const opts = optionsOf(row, FIELD_KEY);
        if (opts === null) return el('span', {class: 'ml-1 text-xs text-gray-400', text: 'press Test for the name'});
        const item = opts.find(o => String(o.id) === value);
        return item ? el('span', {class: 'break-words text-gray-600', text: ' · ' + item.name})
            : el('span', {class: 'text-amber-700', text: ' · (not in Botcake list)'});
    }

    // Ang pag-edit ng flow ID at message field: dropdown ng listahan mula sa Botcake (pangalan · ID, may "(recommended)");
    // ang huli ay "Type an ID…" (ang dating text input). Ang pinili ay sine-save gaya ng tinype na ID (save()).
    const TYPE_ID = '__type__';
    function startPick(td, row, col, original) {
        const opts = optionsOf(row, col.key);
        const option = (value, text, disabled = false) => { const o = el('option', {value, text}); o.disabled = disabled; return o; };
        const items = [];
        if (opts === null) {
            items.push(option('', 'No list yet: press Test', true));
            if (original !== '') items.push(option(original, original));
        } else {
            if (original === '') items.push(option('', 'Pick one…'));
            else if (!opts.some(o => String(o.id) === original)) items.push(option(original, original + ' (not in Botcake list)'));
            items.push(...opts.map(o => option(String(o.id), o.name + ' · ' + o.id + (o.recommended ? ' (recommended)' : ''))));
        }
        items.push(el('option', {value: TYPE_ID, text: 'Type an ID…'}));
        const select = el('select', {class: 'w-full rounded border border-blue-500 bg-white px-1 py-0.5 text-xs', 'aria-label': col.label}, items);
        select.value = original;
        const mark = el('span', {class: 'block text-xs'});
        put(td, select, mark);
        select.focus();
        editing = {pk: row.key, key: col.key, td, input: select, mark, original, secret: false, timer: null};

        select.addEventListener('change', () => {
            if (select.value !== TYPE_ID) { finishEdit(true); refocus(row.key, col.key); return; }
            finishEdit(false);
            startEdit(table.querySelector(`.cell[data-pk="${CSS.escape(row.key)}"][data-key="${CSS.escape(col.key)}"]`) || td, row, col, true);
        });
        select.addEventListener('keydown', ev => {
            if (ev.key === 'Enter') { ev.preventDefault(); finishEdit(true); refocus(row.key, col.key); }
            if (ev.key === 'Escape') { ev.preventDefault(); finishEdit(false); refocus(row.key, col.key); }
        });
        select.addEventListener('blur', () => { if (editing && editing.input === select) finishEdit(true); });
    }

    function cellEl(row, col) {
        const td = el('div', {class: 'cell flex-1 min-w-0 rounded px-1' + (col.readonly ? ' readonly bg-gray-50 text-gray-600' : ''),
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
        // Ang "Test send": kailan nasubukan, o amber na "Needs a test send" kapag kumpleto pero hindi pa nasubukan.
        if (col.key === TEST_OK_KEY) {
            const x = checkOf(row)?.ok === false || notDelivered(row) || !!drift(row);
            put(td, tested(row) ? el('span', {class: 'text-xs text-green-700', text: cell && !byCheck(row) ? 'Tested ' + cell.at : byCheck(row) ? 'Not needed: set by the check' : 'Tested by a real send'})
                : x ? el('span', {class: 'text-xs font-medium text-red-700', text: 'Not ready: see Check'})
                : complete(row) ? el('span', {class: 'text-xs font-medium text-amber-700', text: 'Needs a test send'})
                : el('span', {class: 'text-xs text-gray-400', text: '—'}));
            return;
        }
        // Walang laman at nae-edit: maliit na "+ set" para makitang mapipindot (dashed outline sa hover).
        const empty = !col.readonly && !(cell && cell.value !== '');
        td.classList.toggle('empty', empty);
        put(td,
            empty ? el('span', {class: 'text-xs text-gray-400', text: '+ set'}) : el('span', {class: 'font-mono break-all', text: cell ? cell.value : ''}),
            !empty && col.key === FIELD_KEY ? fieldName(row, String(cell.value)) : null,
            mark ? el('span', {class: 'ml-1 text-xs ' + mark.cls, title: mark.title || '', text: mark.text}) : null,
            mark && mark.title ? el('div', {class: 'text-xs text-red-600', text: mark.title}) : null,
            col.readonly ? null : el('button', {class: 'hist ml-1 text-xs text-gray-400 hover:text-gray-700', type: 'button', title: 'History', text: '⋯'}),
        );
    }

    // ---- pangalan ng flow at template: mula sa Botcake, isinusulat ng system, ipinapakita sa ilalim ng cell ----

    const FLOW_KEY = /^botcake\.flow\.[a-z0-9_]+$/;
    // Hindi sariling column: ang pangalan ng flow, ang body ng template, at ang mga kandidato ng dropdown (…options).
    const DETAIL_KEY = /^botcake\.flow\.[a-z0-9_]+\.name$|^botcake\.template\.shipping\.text$|^botcake\.(flow\.shipping|field\.message)\.options$/;
    const TEMPLATE_KEY = 'botcake.template.shipping', SHIPPING_KEY = 'botcake.flow.shipping';
    const TONE = {red: 'text-red-600', grey: 'text-gray-400'};

    // Isang block kada flow: ang ID (nae-edit), ang pangalan nito sa Botcake, at sa shipping flow ang buong content ng
    // template. Ang pangalan at content ay katabi ng .cell, hindi nasa loob, kaya nakikita pa rin habang ine-edit ang ID.
    function flowBlock(row, col) {
        return el('div', {class: 'mb-1.5 min-w-0'}, [
            line(row, col),
            el('div', {class: 'flow-lines', 'data-pk': row.key, 'data-key': col.key}, flowLines(row, col)),
        ]);
    }

    // Walang placeholder: ang hindi pa nasusuri o bigong pagsuri ay walang linya (ang dahilan ay nasa ▸); buong text, walang putol.
    const UNKNOWN = ['(no name)', '(no body)'];
    function flowLines(row, col) {
        const out = [], name = row.cells[col.key] ? row.cells[col.key + '.name'] : null;
        if (name && name.tone === 'red') out.push(el('div', {class: 'text-xs text-red-600', text: name.line}));
        else if (name && name.tone === '' && !UNKNOWN.includes(name.line)) out.push(el('div', {class: 'text-xs text-gray-600 break-words', text: name.line}));
        if (col.key === SHIPPING_KEY && row.cells[SHIPPING_KEY]) {
            const body = row.cells[TEMPLATE_KEY + '.text'];
            if (body && body.tone === '' && !UNKNOWN.includes(body.line)) out.push(...withToken(body.line));
            if (body && body.tone === 'red') out.push(el('div', {class: 'text-xs text-red-600', text: body.line}));
            // Walang template at bigo ang pagsuri ng templates (hal. walang sagot, kulang sa oras): ang dahilan, hindi "walang
            // approved". Ang "walang approved" ay ang pulang linya sa itaas, kapag nasuri talaga at walang napili.
            if (!row.cells[TEMPLATE_KEY] && body && body.tone === 'grey') out.push(el('div', {class: 'text-xs text-gray-500', text: 'Template check failed: ' + body.line.replace(/^Check failed: /, '')}));
        }
        return out;
    }

    // Ang content ng template: ang {{1}} (ang custom field na pinupuno ng chatblast) ay malinaw na token, may paalala.
    const SLOT = @json(\App\Services\BotcakeFlowNames::SLOT);
    const TOKEN = '[message for the customer]', TOKEN_HINT = 'Filled per customer, see To send / History';
    function withToken(text) {
        const parts = text.split(SLOT);
        const body = el('div', {class: 'text-xs text-gray-700 whitespace-pre-wrap break-words'}, parts.flatMap((p, i) => i === 0 ? [p]
            : [el('span', {class: 'rounded bg-violet-100 px-1 font-medium text-violet-800', text: TOKEN}), p]));
        return parts.length > 1 ? [body, el('div', {class: 'text-[11px] text-gray-400', text: TOKEN_HINT})] : [body];
    }

    // Ang mga cell na iginuguhit ulit kapag nagbago ang key (ang pangalan o body ay nasa cell ng flow o template).
    function paintedBy(key) {
        if (key === TEMPLATE_KEY || key === TEMPLATE_KEY + '.text') return [TEMPLATE_KEY, SHIPPING_KEY];
        if (key.endsWith('.options')) return [key.slice(0, -'.options'.length)];
        // Ang setup ay nagbabago ng "Test send" (kailangan ulit ng test); ang test_ok ay ang mismong linya.
        if (CONFIG_KEYS.includes(key)) return [DETAIL_KEY.test(key) ? key.slice(0, -'.name'.length) : key, TEST_OK_KEY];
        return [DETAIL_KEY.test(key) ? key.slice(0, -'.name'.length) : key];
    }

    // Ang mga cell na isinulat ng system pagkatapos ng save o Test token (null = binura).
    function applyCells(row, cells) {
        const keys = Object.keys(cells || {});
        for (const k of keys) { if (cells[k]) row.cells[k] = cells[k]; else delete row.cells[k]; }
        new Set(keys.flatMap(paintedBy)).forEach(k => repaint(row.key, k));
    }

    function repaint(pk, key) {
        if (editing && editing.pk === pk && editing.key === key) {
            // Habang nagta-type: ang mark lang ang ina-update, hindi ang input.
            const m = marks.get(cellId(pk, key));
            editing.mark.textContent = m ? (m.title || m.text) : '';
            editing.mark.className = 'block text-xs ' + (m ? m.cls : '');
            return;
        }
        const td = table.querySelector(`.cell[data-pk="${CSS.escape(pk)}"][data-key="${CSS.escape(key)}"]`);
        const row = rows.get(pk), col = columns.find(c => c.key === key);
        if (td && row && col) paint(td, row, col);
        const lines = table.querySelector(`.flow-lines[data-pk="${CSS.escape(pk)}"][data-key="${CSS.escape(key)}"]`);
        if (lines && row && col) put(lines, ...flowLines(row, col));
        const pickLines = table.querySelector(`.pick-lines[data-pk="${CSS.escape(pk)}"][data-key="${CSS.escape(key)}"]`);
        if (pickLines && row && col) put(pickLines, ...picks(row, col));
        refreshDetail(pk);
        paintState(pk);
    }

    function infoOc(r) { return String(r.info.orders || 0) + ' / ' + String(r.info.customers ?? 0); }

    function infoSync(r) {
        const s = r.info.last_sync;
        if (!s) return 'none yet';
        // hal. "Okt 17 → Okt 18 · by date · 2026-10-18 01:04 · date · +9,985 bago · 1,279 na-match"
        const n = v => Number(v || 0).toLocaleString('en-US');
        const counts = (s.new || s.matched) ? '+' + n(s.new) + ' new · ' + n(s.matched) + ' matched' : null;
        return [s.dates, s.kind, s.at, s.stop_reason || s.status, counts].filter(Boolean).join(' · ') + (s.error ? ' · ' + s.error : '')
            + (s.failed && r.info.next ? ' · next: ' + (r.info.next.from ? 'from ' + r.info.next.from : r.info.next.label) : '');
    }

    // Ang sync cell: "Okt 17 → Okt 18 · 01:04" at "susunod: mula Okt 17"; ang buong detalye ay nasa hover at sa detalye ng row.
    // Pula kapag failed ang huling run.
    function paintSync(td, r) {
        const s = r.info.last_sync, nx = r.info.next;
        const last = 'Last sync: ' + (s ? (s.dates ? s.dates + ' · ' + (s.at || '').slice(11) : (s.at || s.status)) : 'none yet');
        const next = nx ? 'next: ' + (nx.from ? 'from ' + nx.from : nx.label) : null;
        td.title = infoSync(r) + (nx ? '\nNext sync: ' + nx.label : '');
        put(td, el('div', {class: 'truncate', text: last}), next ? el('div', {class: 'truncate', text: next}) : null);
        td.classList.toggle('text-red-600', !!(s && s.failed));
        return td;
    }

    // ---- ang detalye ng row (▸): ang hindi kasya sa mga cell ----

    function detailTr(r) {
        const other = inGroup('other');
        return el('tr', {class: 'detail bg-gray-50', 'data-detail': r.key}, el('td', {class: 'px-4 py-2 border-b text-xs', colspan: String(WIDTHS.length)}, [
            el('div', {class: 'grid grid-cols-3 gap-4'}, [
                el('div', {}, [el('div', {'data-detail-flows': ''}, detailFlows(r)), ...otherFlows(r)]),
                el('div', {}, [heading('Shipping template'), ...columns.filter(c => c.group === 'template').map(c => line(r, c)),
                    el('div', {'data-detail-template': ''}, detailTemplate(r))]),
                el('div', {'data-detail-sync': ''}, detailSync(r)),
            ]),
            el('div', {class: 'mt-2'}, [
                el('div', {class: 'font-semibold text-gray-600', text: 'Other keys'}),
                other.length ? el('div', {class: 'grid grid-cols-2 gap-x-4'}, other.map(c => line(r, c, 'w-40')))
                    : el('div', {class: 'text-gray-400', text: 'None (use + Column).'}),
            ]),
            el('div', {class: 'mt-2 flex flex-wrap items-baseline gap-x-3'}, [
                el('span', {class: 'font-semibold text-gray-600', text: 'History:'}),
                ...columns.filter(c => !c.readonly).map(c => el('button', {class: 'hist-link text-blue-700 hover:underline', type: 'button', 'data-pk': r.key, 'data-key': c.key, title: c.key, text: c.label})),
            ]),
        ]));
    }

    const heading = text => el('div', {class: 'font-semibold text-gray-600', text});

    // Buong text, walang putol. Dito lang ang dahilan ng bigong pagsuri ("Check failed: …"); walang placeholder.
    // Ang Chatblast flow (shipping): ang ID, ang buong pangalan at ang dahilan ng bigong pagsuri (iginuguhit ulit).
    function detailFlows(r) {
        return [heading('Chatblast flow'), ...columns.filter(c => c.key === SHIPPING_KEY).map(c => {
            const id = r.cells[c.key], name = r.cells[c.key + '.name'];
            return el('div', {class: 'break-words'}, [
                el('span', {class: 'font-mono', text: id ? id.value : '—'}),
                id && name ? el('span', {class: TONE[name.tone] || 'text-gray-700', text: ' — ' + name.line}) : null,
            ]);
        })];
    }

    // Ang ibang flow slot na may laman sa page (hindi ginagamit ng chatblast), nae-edit pa rin; walang binubura. Nasa labas
    // ng [data-detail-flows] (gaya ng template line), kaya hindi nabubura ng refreshDetail ang input habang nag-e-edit.
    function otherFlows(r) {
        const others = columns.filter(c => FLOW_KEY.test(c.key) && c.key !== SHIPPING_KEY && r.cells[c.key]);
        return others.length ? [el('div', {class: 'mt-2'}, heading('Other flow IDs (not used by chatblast)')), ...others.map(c => line(r, c))] : [];
    }

    function detailTemplate(r) {
        const body = r.cells[TEMPLATE_KEY + '.text'];
        if (!body) return [];
        return body.tone === '' ? withToken(body.line) : [el('div', {class: 'whitespace-pre-wrap break-words ' + (TONE[body.tone] || 'text-gray-700'), text: body.line})];
    }

    function detailSync(r) {
        const s = r.info.last_sync;
        return [
            el('div', {class: s && s.failed ? 'text-red-600' : ''}, [heading('Sync'), ...[
                'Last sync: ' + (s ? [s.dates, s.kind, s.at, s.stop_reason || s.status].filter(Boolean).join(' · ') : 'none yet'),
                s ? '+' + fmt(s.new) + ' new · ' + fmt(s.updated) + ' updated · ' + fmt(s.matched) + ' matched · ' + fmt(s.ad_filled) + ' ad id' : null,
                s && s.error ? 'Error: ' + s.error : null,
                'Next sync: ' + (r.info.next ? r.info.next.label : '—'),
                ORDERS_LABEL + ': ' + fmt(r.info.orders) + ' · Customers: ' + fmt(r.info.customers),
            ].filter(Boolean).map(text => el('div', {class: 'break-words', text}))]),
        ];
    }

    // Ang mga bahaging hindi nae-edit lang ang binubuo ulit, para hindi mawala ang ine-edit na template o ibang key.
    function refreshDetail(pk) {
        const tr = table.querySelector(`tr[data-detail="${CSS.escape(pk)}"]`);
        const r = rows.get(pk);
        if (!tr || !r) return;
        put(tr.querySelector('[data-detail-flows]'), ...detailFlows(r));
        put(tr.querySelector('[data-detail-template]'), ...detailTemplate(r));
        put(tr.querySelector('[data-detail-sync]'), ...detailSync(r));
    }

    function toggle(pk) {
        if (open.has(pk)) open.delete(pk); else open.add(pk);
        render();
        table.querySelector(`.toggle[data-pk="${CSS.escape(pk)}"]`)?.focus();
    }

    // Kapareho ng BotcakeSyncAll::summary(): bilang kada uri ng susunod na sync.
    const SUMMARY = {window: 'window', continue: 'resume', by_date: 'by date', fresh: 'skipped (fresh)', missing: 'no ID/token', invalid: 'token invalid'};
    function refreshSummary() {
        const count = {};
        for (const r of rows.values()) if (r.info.next) count[r.info.next.kind] = (count[r.info.next.kind] || 0) + 1;
        const text = Object.entries(SUMMARY).filter(([k]) => count[k]).map(([k, label]) => count[k] + ' ' + label).join(', ');
        document.getElementById('plan-summary').textContent = text || 'none';
    }

    // Ang mga button ng row ay fetch (walang reload: hindi bumabalik sa itaas, nananatiling bukas ang ▸). Ang Cust. at
    // Orders ay link sa ibang page.
    function actions(r) {
        const out = [];
        const btn = (act, text, title) => el('button', {type: 'button', class: 'cb-btn cb-btn-sm', 'data-act': act, 'data-pk': r.key, title, text});
        if (r.info.botcake) {
            out.push(btn('test', 'Test', 'Test token, then read the flow, field and template from Botcake'));
            out.push(btn('sync', 'Sync', 'Sync now'));
        }
        if (complete(r)) {
            out.push(r.cells['botcake.test_psid'] ? btn('send-test', 'Send test', 'Send a test message to the Test PSID')
                : el('a', {class: 'text-xs text-amber-700 hover:underline', href: url.testPsidPickerUrl, title: 'Pick the Test PSID of the page on To send', text: 'Set a Test PSID first'}));
            // CEO lang (ang URL ay nasa page para sa CEO lang): ang page na sinuri na mismo ng may-ari.
            if (url.markTestedUrl && !tested(r)) out.push(btn('mark-tested', 'Mark as tested', 'Mark the page as tested without a test send'));
        }
        out.push(el('a', {class: 'text-blue-700 hover:underline text-xs', href: url.customersUrl + '?page=' + encodeURIComponent(r.name), title: 'Customers', text: 'Cust.'}));
        out.push(el('a', {class: 'text-blue-700 hover:underline text-xs', href: url.coverageUrl + '?page_name=' + encodeURIComponent(r.name), title: 'Orders without a PSID', text: 'Orders'}));
        return [el('div', {class: 'flex flex-wrap items-center gap-1'}, out)];
    }

    table.addEventListener('click', async e => {
        const b = e.target.closest('button[data-act]');
        const r = b && rows.get(b.dataset.pk);
        if (!r) return;
        b.disabled = true;
        try {
            if (b.dataset.act === 'test') {
                notice('Testing ' + r.name + '…');
                const {data} = await testOne(r);
                const status = applyTest(r, data);
                notice(r.name + ' · Token: ' + status + (data.error ? ' · ' + data.error : '') + (data.refresh_error ? ' · flow, field and template not checked: ' + data.refresh_error : ''),
                    status === 'ok' ? 'text-green-700' : 'text-red-600');
            } else if (b.dataset.act === 'sync') {
                const res = await fetch(url.syncUrl, {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}, body: JSON.stringify({page_name: r.name})});
                const data = await res.json().catch(() => ({}));
                notice(data.message || data.error || ('HTTP ' + res.status), res.ok ? 'text-green-700' : 'text-red-600');
            } else if (b.dataset.act === 'mark-tested') {
                if (confirm('Mark ' + r.name + ' as tested? Chatblast will send to this page without a test send.')) {
                    const res = await fetch(url.markTestedUrl, {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}, body: JSON.stringify({page_name: r.name})});
                    const data = await res.json().catch(() => ({}));
                    if (res.ok) { applyCells(r, {[TEST_OK_KEY]: data.cell}); refreshActions(r.key); }
                    notice(res.ok ? r.name + ': marked as tested.' : (data.error || ('HTTP ' + res.status)), res.ok ? 'text-green-700' : 'text-red-600');
                }
            } else if (b.dataset.act === 'send-test') {
                const res = await fetch(url.sendTestUrl, {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}, body: JSON.stringify({page_name: r.name})});
                const data = await res.json().catch(() => ({}));
                notice(res.ok ? r.name + ': test message ' + (data.status === 'sent' ? 'sent' : 'queued') + ' to the Test PSID. The row turns Ready when it is sent.'
                    : (data.error || ('HTTP ' + res.status)), res.ok ? 'text-green-700' : 'text-red-600');
            }
        } catch (err) {
            notice('No connection.', 'text-red-600');
        }
        b.disabled = false;
    });

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
        tr.querySelector('[data-info="oc"]').textContent = infoOc(r);
        paintSync(tr.querySelector('[data-info="sync"]'), r);
        refreshDetail(pk);
        paintState(pk);
        refreshSummary();
    }

    // ---- editing ----

    table.addEventListener('click', e => {
        const toggler = e.target.closest('.toggle');
        if (toggler) { toggle(toggler.dataset.pk); return; }
        const link = e.target.closest('.hist-link');
        if (link) { openHistory(link.dataset.pk, link.dataset.key); return; }
        const td = e.target.closest('.cell');
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
        if (!td.matches || !td.matches('.cell') || (e.key !== 'Enter' && e.key !== 'F2')) return;
        e.preventDefault();
        const col = columns.find(c => c.key === td.dataset.key);
        if (!col.readonly) startEdit(td, rows.get(td.dataset.pk), col);
    });

    // typing: ang text input kahit flow ID o message field ("Type an ID…").
    function startEdit(td, row, col, typing = false) {
        if (editing) {
            finishEdit(true);
            // Kung nabuo ulit ang table, ang bagong td ng parehong cell ang gagamitin.
            td = table.querySelector(`.cell[data-pk="${CSS.escape(row.key)}"][data-key="${CSS.escape(col.key)}"]`) || td;
        }
        const cell = row.cells[col.key];
        const secret = col.secret || !!(cell && cell.secret);
        const original = secret ? '' : (cell ? cell.value : '');
        if (PICK_KEYS.includes(col.key) && !secret && !typing) return startPick(td, row, col, original);
        const input = el('input', {class: 'w-full border border-blue-500 rounded px-1 font-mono', autocomplete: 'off',
            placeholder: secret ? 'new value (blank = no change)' : ''});
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
        table.querySelector(`.cell[data-pk="${CSS.escape(pk)}"][data-key="${CSS.escape(key)}"]`)?.focus();
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
        const seq = (saveSeq.get(id) || 0) + 1;
        saveSeq.set(id, seq);
        marks.set(id, {text: 'Saving…', cls: 'text-gray-400'});
        repaint(ed.pk, ed.key);

        try {
            const res = await fetch(url.cellUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
                body: JSON.stringify({page_name: row.name, provider: col.provider, key: col.name, value, is_secret: ed.secret}),
            });
            const data = await res.json().catch(() => ({}));
            // Huling dumating ang sagot ng mas lumang save (hal. mabagal na Botcake): ang mas bagong save ang masusunod.
            if (saveSeq.get(id) !== seq) return;
            if (!res.ok) {
                marks.set(id, {text: '!', cls: 'text-red-600', title: data.error || data.message || ('Not saved (' + res.status + ')')});
            } else {
                if (data.cell) row.cells[ed.key] = data.cell; else delete row.cells[ed.key];
                delete ed.server; // mas bago ang naisave kaysa sa itinabing value mula sa poll
                applyCells(row, data.cells);
                if (ed.key === 'botcake.page_id' || ed.key === 'botcake.token') {
                    row.info.botcake = !!(row.cells['botcake.page_id'] && row.cells['botcake.token']);
                }
                // Kumpleto na ang ID at token: sinubukan na ng server ang token at kinuha ang flow, field at template.
                if (data.token_test) {
                    notice(row.name + ' · Token: ' + data.token_test + (data.token_test === 'ok'
                        ? (data.refresh_error ? ' · flow, field and template not checked: ' + data.refresh_error : ' · flow, field and template checked')
                        : (data.refresh_error ? ' · ' + data.refresh_error : '')),
                        data.token_test === 'ok' ? 'text-green-700' : 'text-red-600');
                }
                refreshActions(ed.pk); // ang Send test ay lumalabas kapag kumpleto na ang setup
                marks.set(id, {text: data.status === 'unchanged' ? '✓' : 'Saved', cls: 'text-green-600'});
                setTimeout(() => { if (marks.get(id)?.cls === 'text-green-600') { marks.delete(id); repaint(ed.pk, ed.key); } }, 2500);
            }
        } catch (err) {
            if (saveSeq.get(id) !== seq) return;
            marks.set(id, {text: '!', cls: 'text-red-600', title: 'No connection: not saved.'});
        }
        if (needsRender && !editing) render(); else paintedBy(ed.key).forEach(k => repaint(ed.pk, k));
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
                if (DETAIL_KEY.test(c.key)) {
                    // Hindi sariling column: iginuguhit sa loob ng cell ng flow o template.
                    applyCells(rows.get(c.page), {[c.key]: c.cell});
                    continue;
                }
                if (c.cell) structure = ensureColumn(c.key, c.cell.secret) || structure;
                const row = rows.get(c.page);
                if (editing && editing.pk === c.page && editing.key === c.key) {
                    // Itatabi; ilalapat pagkatapos ng edit kung walang bagong value na nai-save.
                    editing.server = c.cell;
                    continue;
                }
                if (c.cell) row.cells[c.key] = c.cell; else delete row.cells[c.key];
                row.info.botcake = !!(row.cells['botcake.page_id'] && row.cells['botcake.token']);
                if (!structure) { paintedBy(c.key).forEach(k => repaint(c.page, k)); refreshActions(c.page); }
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
            if (structure || (chip && !editing)) render();
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
            el('div', {class: 'text-xs text-gray-500', text: 'Now'}),
            el('div', {class: 'font-mono break-all', text: cur ? cur.value : '(empty)'}),
            cur ? el('div', {class: 'text-xs text-gray-500', text: cur.by + ' · ' + cur.at}) : null,
            cur ? el('button', {class: 'mt-1 text-xs text-red-600 hover:underline', type: 'button', id: 'history-clear', text: 'Clear the value'}) : null,
        );
        const list = document.getElementById('history-list');
        list.replaceChildren(el('li', {class: 'text-gray-400', text: 'Loading…'}));
        panel.classList.remove('hidden');

        const clear = document.getElementById('history-clear');
        if (clear) clear.addEventListener('click', async () => {
            if (!confirm('Clear ' + col.key + ' of ' + row.name + '?')) return;
            const res = await fetch(url.cellUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
                body: JSON.stringify({page_name: row.name, provider: col.provider, key: col.name, clear: true}),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) { notice(data.error || 'Not cleared.', 'text-red-600'); return; }
            delete row.cells[key];
            applyCells(row, data.cells);
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
        if (!list.children.length) list.append(el('li', {class: 'text-gray-400', text: 'No earlier values.'}));
    }
    // Ang gabay sa bagong page (modal).
    const guide = document.getElementById('setup-guide');
    document.getElementById('setup-help').addEventListener('click', () => guide.classList.remove('hidden'));
    document.getElementById('setup-guide-close').addEventListener('click', () => guide.classList.add('hidden'));
    guide.addEventListener('click', e => { if (e.target === guide) guide.classList.add('hidden'); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') guide.classList.add('hidden'); });

    const closeHistory = () => document.getElementById('history').classList.add('hidden');
    document.getElementById('history-close').addEventListener('click', closeHistory);
    document.getElementById('history').addEventListener('click', e => { if (e.target.id === 'history') closeHistory(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !document.getElementById('history').classList.contains('hidden')) closeHistory(); });

    // ---- Test lahat ng token: isang page kada request, isa-isa, sa ayos ng grid ----

    const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
    const BETWEEN_MS = 500, RATE_WAIT_MS = 5000;

    // Isang page. Ang wait = ilang ms bago subukan ulit kapag rate limit (429 ng Likha o "rate_limited" ng Botcake).
    // Ang sagot ng isang Test (row o Test lahat): ang token status, at kapag ok, ang flow, field at template (cells).
    function applyTest(r, data) {
        const id = cellId(r.key, 'botcake.token_status');
        const status = ['ok', 'invalid', 'skipped'].includes(data.status) ? data.status : 'error';
        if (data.cell) r.cells['botcake.token_status'] = data.cell;
        if (status === 'error') marks.set(id, {text: '!', cls: 'text-red-600', title: data.error || 'Not tested.'});
        else marks.set(id, {text: status, cls: status === 'ok' ? 'text-green-600' : 'text-red-600'});
        repaint(r.key, 'botcake.token_status');
        applyCells(r, data.cells);
        if (data.refresh_error) {
            marks.set(id, {text: 'ok · flow, field and template not checked: ' + data.refresh_error, cls: 'text-gray-500'});
            repaint(r.key, 'botcake.token_status');
        }
        refreshActions(r.key);
        return status;
    }

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
            return {data: {status: 'error', error: 'No connection.'}};
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
                out.textContent = 'Test token: ' + (i + 1) + ' / ' + targets.length + ' · ' + r.name + ' (rate limit, waiting ' + Math.ceil(wait / 1000) + ' s)';
                await sleep(wait);
                ({data} = await testOne(r));
            }
            sum[applyTest(r, data)]++;
        }

        out.textContent = 'Test tokens done: ' + sum.ok + ' ok, ' + sum.invalid + ' invalid, ' + sum.error + ' error, '
            + sum.skipped + ' without a Botcake ID or token (skipped).';
        btn.disabled = false;
        if (chip) render(); // ang chip filter ay sumusunod sa bagong token status (hindi habang may ine-edit)
    });

    // ---- Sync lahat: sa queue, isa-isang page; ang progreso ay mula sa poll ----

    const fmt = v => Number(v || 0).toLocaleString('en-US');
    const duration = s => s >= 3600 ? Math.floor(s / 3600) + ' h ' + Math.floor((s % 3600) / 60) + ' min' : Math.floor(s / 60) + ' min';
    const STATUS = {running: 'running', paused: 'paused', stopped: 'stopped', done: 'done'};

    function showSyncAll(p) {
        const active = !!(p && (p.status === 'running' || p.status === 'paused'));
        document.getElementById('sync-all').disabled = active;
        document.getElementById('sync-all-pause').classList.toggle('hidden', !(p && p.status === 'running'));
        document.getElementById('sync-all-resume').classList.toggle('hidden', !(p && p.status === 'paused'));
        document.getElementById('sync-all-stop').classList.toggle('hidden', !active);
        document.getElementById('sync-all-panel').classList.toggle('hidden', !p);
        document.getElementById('sync-all-status').textContent = p && active ? 'Sync all: ' + p.done + ' / ' + p.total : '';
        if (!p) return;

        // hal. "Sync lahat · tumatakbo · 7 / 40 page · ngayon: Page X (window, 120 batch) · 1 failed · +9,985 bago · ..."
        const current = p.current && active
            ? 'now: ' + p.current + (p.current_kind ? ' (' + p.current_kind + (p.current_batches != null ? ', ' + fmt(p.current_batches) + ' batch' : '') + ')' : '')
                + (p.waiting ? ' (waiting for another sync to finish)' : '') + (p.status === 'paused' ? ' (finishing this page first)' : '')
            : null;
        document.getElementById('sync-all-line').textContent = ['Sync all', STATUS[p.status] || p.status, p.done + ' / ' + p.total + ' page',
            current, p.failed ? p.failed + ' failed' : null, p.skipped ? p.skipped + ' skipped (fresh)' : null,
            p.remaining && active ? p.remaining + ' left' : null,
            '+' + fmt(p.totals.new) + ' new', fmt(p.totals.updated) + ' updated', fmt(p.totals.matched) + ' matched', fmt(p.totals.ad_filled) + ' ad id',
            duration(p.seconds), 'started' + (p.started_by ? ' by ' + p.started_by : '') + ' ' + (p.started_at || ''),
            p.finished_at ? 'finished ' + p.finished_at : null].filter(Boolean).join(' · ');

        put(document.getElementById('sync-all-history'), ...(p.history || []).map(h => el('tr', {}, [
            h.started_at, h.started_by || '', STATUS[h.status] || h.status, h.done + ' / ' + h.total, String(h.failed), String(h.skipped),
            fmt(h.totals.new), fmt(h.totals.matched), fmt(h.totals.ad_filled), duration(h.seconds),
        ].map(v => el('td', {class: 'pr-3', text: v || ''})))));
    }

    for (const b of document.querySelectorAll('.sync-all-control')) {
        b.addEventListener('click', async () => {
            if (b.dataset.action === 'stop' && !confirm('Stop Sync all? The page that is running finishes first.')) return;
            b.disabled = true;
            try {
                const res = await fetch(url.syncAllUrl + '/' + b.dataset.action, {method: 'POST', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}});
                const data = await res.json().catch(() => ({}));
                if (!res.ok) notice(data.message || 'Not done.', 'text-red-600'); else showSyncAll(data.sync_all);
            } catch (err) {
                notice('No connection.', 'text-red-600');
            }
            b.disabled = false;
        });
    }

    document.getElementById('sync-all').addEventListener('click', async e => {
        const btn = e.currentTarget;
        if (!confirm('Sync every page with a Botcake ID and token, one at a time in the queue, per the plan: ' + document.getElementById('plan-summary').textContent + '?')) return;
        btn.disabled = true;
        try {
            const res = await fetch(url.syncAllUrl, {method: 'POST', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}});
            const data = await res.json().catch(() => ({}));
            if (!res.ok) { notice(data.message || 'Sync all did not start.', 'text-red-600'); btn.disabled = false; return; }
            showSyncAll(data.sync_all);
            if (data.status === 'running') notice('A Sync all run is already running or paused; nothing new started (use Resume or Stop).', 'text-amber-700');
            if (data.status === 'not_ready') { notice('Sync all is not ready yet (the database is still being updated). Try again later.', 'text-amber-700'); btn.disabled = false; }
        } catch (err) {
            notice('No connection: Sync all did not start.', 'text-red-600');
            btn.disabled = false;
        }
    });

    // ---- + Page, + Column, filter ----

    document.getElementById('add-page').addEventListener('submit', async e => {
        e.preventDefault();
        const input = e.target.page_name;
        const res = await fetch(url.pageCheckUrl + '?' + new URLSearchParams({page_name: input.value}), {headers: {'Accept': 'application/json'}});
        const data = await res.json().catch(() => ({}));
        if (!res.ok) { notice(data.error || 'Not added.', 'text-red-600'); return; }
        const pk = data.key;
        const added = ensureRow(pk, data.name);
        input.value = '';
        notice(
            (added ? 'Added the row "' + data.name + '" (it is saved once a value is entered).' : '"' + data.name + '" is already in the grid.')
            + (data.in_orders ? '' : ' Warning: no order has this PAGE. Is the spelling right?'),
            data.in_orders ? 'text-green-700' : 'text-amber-700'
        );
        render();
        table.querySelector(`tr[data-pk="${CSS.escape(pk)}"]`)?.scrollIntoView({block: 'center'});
    });

    document.getElementById('add-column').addEventListener('submit', e => {
        e.preventDefault();
        const f = e.target;
        const provider = f.provider.value.trim().toLowerCase(), key = f.key.value.trim().toLowerCase();
        if (!/^[a-z0-9_]{1,50}$/.test(provider)) { notice('Provider: lowercase letters, digits and _ only.', 'text-red-600'); return; }
        if (!/^[a-z0-9_]+(\.[a-z0-9_]+)*$/.test(key) || key.length > 100) { notice('Key: lowercase letters, digits, _ and . only (e.g. flow.reminder).', 'text-red-600'); return; }
        const added = ensureColumn(provider + '.' + key, f.is_secret.checked);
        notice(added ? 'Added the column ' + provider + '.' + key + ' (under ▸ of each page; it is saved once a value is entered).' : 'That column is already in the grid.', 'text-green-700');
        f.key.value = '';
        f.is_secret.checked = false;
        render();
    });

    document.getElementById('filter').addEventListener('input', () => render());

    showSyncAll(initial.sync_all);

    // ---- Setup box: i-save ang mga pangalan (saka Check all), Check all, at ang takbo ng Check all ----
    const setupBox = document.getElementById('setup-box'), setupStatus = document.getElementById('setup-status');
    let setupTimer = null;
    const hm = iso => iso ? new Date(iso).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'}) : '';
    function showSetup(p) {
        clearTimeout(setupTimer);
        if (!p) { setupStatus.textContent = ''; return; }
        if (p.status === 'queued' || p.status === 'running') {
            setupStatus.className = 'min-w-0 text-xs text-blue-800';
            setupStatus.textContent = p.status === 'queued' ? 'Check all is queued…' : 'Checking ' + p.done + ' of ' + p.total + ' pages…';
            setupTimer = setTimeout(async () => {
                const res = await fetch(setupBox.dataset.progressUrl, {headers: {'Accept': 'application/json'}});
                if (res.ok) showSetup((await res.json()).progress); // ang mga cell ng bawat page ay dumarating sa poll ng grid
            }, 4000);
            return;
        }
        setupStatus.className = 'min-w-0 text-xs ' + (p.status === 'done' ? 'text-gray-600' : 'text-red-600');
        setupStatus.textContent = p.status === 'done'
            ? 'Last Check all finished ' + hm(p.finished_at) + ': ' + p.total + ' pages, ' + p.x + ' with an X, ' + p.failed + ' could not be checked.'
            : 'Check all stopped ' + hm(p.finished_at) + ' after ' + p.done + ' of ' + p.total + ' pages.';
    }
    async function setupPost(url, body) {
        const res = await fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}, body: JSON.stringify(body)});
        const data = await res.json().catch(() => ({}));
        if (!res.ok) { setupStatus.className = 'min-w-0 text-xs text-red-600'; setupStatus.textContent = data.error || data.message || ('HTTP ' + res.status); return; }
        if (data.names) Object.assign(SETUP, data.names);
        showSetup(data.progress);
        if (data.queued === false) setupStatus.textContent = 'A Check all is already running. ' + setupStatus.textContent;
    }
    document.getElementById('setup-form').addEventListener('submit', ev => {
        ev.preventDefault();
        const f = new FormData(ev.target);
        setupPost(setupBox.dataset.url, {flow: f.get('flow'), field: f.get('field'), template: f.get('template')});
    });
    document.getElementById('setup-check-all').addEventListener('click', () => setupPost(setupBox.dataset.checkUrl, {}));
    showSetup(initial.setup.progress);
    render();
})();
</script>
@endsection
