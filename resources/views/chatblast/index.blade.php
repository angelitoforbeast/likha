@extends('layouts.chatblast')

{{-- Iisang page para sa "To send" ($view = send: ang ipapadala pa, anuman ang petsa) at "History" ($view = history: ang
     naproseso na, may From/To para sa pagtingin lang). --}}
@section('title', $view === 'send' ? 'Chatblast · To send' : 'Chatblast · History')
@section('heading', $view === 'send' ? 'To send' : 'History')

@push('styles')
    <style>
        #cb-table thead th { position: sticky; top: 0; z-index: 2; }
        #cb-table tbody tr { cursor: pointer; }
        #cb-table tbody tr.selected-row { background: #eff6ff; }
        .chip.active { outline: 2px solid #1d4ed8; }
        pre.cb { white-space: pre-wrap; word-break: break-word; }
    </style>
@endpush

@section('content')
<div id="cb"
     data-view="{{ $view }}"
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

    {{-- Mga filter (pagtingin lang; ang "Send all ready" ay page at sequence lang ang sinusunod) --}}
    <form id="filters" class="flex flex-wrap items-end gap-3 mb-3 text-sm">
        @if($view === 'history')
        <label class="flex flex-col text-xs text-gray-600">From<input type="date" name="from" value="{{ $today }}" class="border rounded px-2 py-1 text-sm text-gray-900"></label>
        <label class="flex flex-col text-xs text-gray-600">To<input type="date" name="to" value="{{ $today }}" class="border rounded px-2 py-1 text-sm text-gray-900"></label>
        @endif
        <label class="flex flex-col text-xs text-gray-600">Page
            <select name="page" class="border rounded px-2 py-1 text-sm text-gray-900">
                <option value="">All</option>
                @foreach($pages as $p)<option value="{{ $p }}">{{ $p }}</option>@endforeach
            </select>
        </label>
        <label class="flex flex-col text-xs text-gray-600">Sequence
            <select name="kind" class="border rounded px-2 py-1 text-sm text-gray-900">
                <option value="">All</option>
                @foreach($campaigns as $c)<option value="{{ $c->kind }}">{{ $c->name }}{{ $c->active ? '' : ' (off)' }}</option>@endforeach
            </select>
        </label>
        <label class="flex flex-col text-xs text-gray-600">Search<input name="q" placeholder="name or waybill" class="border rounded px-2 py-1 text-sm text-gray-900 w-56"></label>
        {{-- History: ang naipadala ang default na chip --}}
        <input type="hidden" name="status" value="{{ $view === 'history' ? 'sent' : '' }}">
        <button class="bg-gray-200 hover:bg-gray-300 px-3 py-1 rounded">Show</button>
    </form>

    {{-- Bilang kada status (pindutin para salain) at ang lagay ng pagpapadala --}}
    <div class="flex flex-wrap items-center gap-2 mb-2 text-xs">
        <div id="chips" class="flex flex-wrap gap-2" aria-label="Count per status"></div>
    </div>
    @if($view === 'send')
    {{-- Ang ready kada sequence at kada page --}}
    <div id="by-kind" class="text-xs text-gray-700 mb-1"></div>
    <div id="by-page" class="text-xs text-gray-700 mb-2"></div>
    @endif
    <div class="flex flex-wrap items-center gap-3 mb-3 text-sm">
        <span id="state" class="text-xs text-gray-700" aria-live="polite"></span>
        <span id="test-psid-owner" class="text-xs text-gray-700"></span>
        @if($view === 'send')
        <button id="pause" type="button" class="hidden bg-amber-500 hover:bg-amber-600 text-white px-3 py-1 rounded">Pause</button>
        <button id="resume" type="button" class="hidden bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded">Resume</button>
        @endif
    </div>

    {{-- Mga aksyon: ang pagpapadala ay sa To send; ang Retry failed ay sa History --}}
    <div class="flex flex-wrap items-center gap-2 mb-3 text-sm">
        @if($view === 'send')
        {{-- Kusang nagre-refresh ang listahan kada oras (:15); ang button ay para sa ngayon na --}}
        <button id="build" type="button" class="border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-xs px-2 py-1 rounded disabled:opacity-50">Refresh now</button>
        <span id="refreshed" class="text-xs text-gray-500"></span>
        <button id="send-selected" type="button" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded disabled:opacity-50" disabled>Send selected (0)</button>
        <button id="send-filtered" type="button" class="bg-blue-700 hover:bg-blue-800 text-white px-3 py-1 rounded">Send all ready</button>
        @else
        <button id="retry" type="button" class="hidden bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded">Retry failed</button>
        @endif
        <span id="notice" class="text-xs" aria-live="polite"></span>
    </div>

    <details class="mb-3 bg-white rounded shadow px-4 py-2 text-sm">
        <summary class="cursor-pointer font-medium">Test PSID</summary>
        <div class="grid lg:grid-cols-3 gap-4 mt-2">
            <form id="test-psid-form">
                <p class="text-xs text-gray-600 mb-1">The page's Test PSID (your own account among the page's Botcake customers): every "Send test" goes here.</p>
                <select name="page" class="border rounded px-2 py-1 text-sm mb-1">
                    @foreach($pages as $p)<option value="{{ $p }}">{{ $p }}</option>@endforeach
                </select>
                <input name="q" placeholder="search a name" class="border rounded px-2 py-1 text-sm">
                <button class="bg-gray-200 hover:bg-gray-300 px-3 py-1 rounded">Search</button>
                <div id="test-psid-current" class="text-xs text-gray-600 mt-1"></div>
                <ul id="test-psid-results" class="text-sm mt-1 space-y-1"></ul>
            </form>
        </div>
    </details>

    <div class="bg-white rounded shadow overflow-auto max-h-[70vh]">
        <table id="cb-table" class="text-sm border-collapse min-w-full">
            <thead>
            <tr class="bg-gray-100 text-left text-xs text-gray-600">
                <th class="bg-gray-100 px-2 py-2 border-b"><input type="checkbox" id="check-all" aria-label="Select all"></th>
                @foreach(['Time', 'Page', 'Customer', 'Waybill', 'J&T', 'PSID', 'Message', 'Status', 'Tries', 'HTTP', 'Sent'] as $h)
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
    <aside id="panel" class="hidden fixed right-0 top-0 h-full w-[34rem] max-w-full bg-white shadow-xl border-l p-4 overflow-auto text-sm z-50" aria-label="Message details">
        <div class="flex justify-between items-center mb-2">
            <div id="panel-title" class="font-semibold"></div>
            <button id="panel-close" type="button" class="text-gray-500 hover:text-gray-800 px-2" aria-label="Close">✕</button>
        </div>
        <div id="panel-body"></div>
    </aside>
</div>

<script>
(function () {
    const box = document.getElementById('cb'), url = box.dataset;
    // "send" (To send) o "history"; ang mga button na wala sa view ay wala sa page, kaya null-safe ang $id.
    const VIEW = box.dataset.view, $id = id => document.getElementById(id);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const LABEL = {ready: 'ready', no_psid: 'no PSID', no_config: 'no config', queued: 'queued', sending: 'sending', sent: 'sent',
        failed: 'failed', field_mismatch: 'field mismatch', skipped: 'skipped', unknown: 'Sent (unconfirmed)'};
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
    let countsAt = 0, counts = {}, total = 0, sendable = 0;

    async function load(light = false) {
        const params = {...filters(), p: page, view: VIEW};
        if (light && Date.now() - countsAt < 30000) params.light = '1';
        const res = await fetch(url.listUrl + '?' + new URLSearchParams(params), {headers: {'Accept': 'application/json'}});
        if (!res.ok) { notice('Could not load the list (HTTP ' + res.status + ').', 'text-red-600'); schedule(); return; } // tuloy ang poll
        last = await res.json();
        if (last.counts) {
            counts = last.counts; total = last.total; sendable = last.sendable || 0; countsAt = Date.now();
            renderBreakdown('by-kind', 'Ready per sequence: ', last.by_kind, k => CAMPAIGNS[k] || k);
            renderBreakdown('by-page', 'Ready per page: ', last.by_page, p => p);
        }
        last.counts = counts;
        renderChips(counts);
        renderState(last.state);
        renderTestPsid(last.test_psid);
        renderRows(last.rows);
        const pages = Math.max(1, Math.ceil(total / last.per_page));
        document.getElementById('pager').textContent = 'Page ' + last.page + ' / ' + pages + ' · ' + fmt(total) + ' rows';
        document.getElementById('prev').disabled = last.page <= 1;
        document.getElementById('next').disabled = last.page >= pages;
        schedule();
    }

    // Ang ready kada sequence o kada page (To send lang), hal. "Ready per page: Page Uno 12 · Page Dos 3".
    function renderBreakdown(id, label, map, name) {
        const box = $id(id);
        if (!box) return;
        const parts = Object.entries(map || {}).map(([k, n]) => name(k) + ' ' + fmt(n));
        box.textContent = parts.length ? label + parts.join(' · ') : '';
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
        if (VIEW === 'history') {
            // History: walang "all"; ang Sent (kasama ang unconfirmed) ang default. Ang ibang chip ay isang lagay lang;
            // ang pindot ulit sa napiling chip ay bumabalik sa Sent. Ang Retry failed ay nasa Failed lang.
            const sum = list => list.reduce((a, s) => a + (counts[s] || 0), 0);
            const HISTORY_CHIPS = [['sent', 'Sent', ['sent', 'unknown']], ['failed', 'Failed', ['failed', 'field_mismatch']],
                ['skipped', 'Skipped', ['skipped']], ['no_psid', 'No PSID', ['no_psid']], ['no_config', 'No config', ['no_config']]];
            document.getElementById('chips').replaceChildren(...HISTORY_CHIPS.map(([status, label, list]) => {
                const b = el('button', {type: 'button', class: 'chip px-2 py-1 rounded ' + COLOR[status] + (current === status ? ' active' : ''), 'data-status': status},
                    [label + ' ', el('b', {text: fmt(sum(list))})]);
                b.addEventListener('click', () => { document.querySelector('#filters [name=status]').value = current === status ? 'sent' : status; page = 1; load(); });
                return b;
            }));
            $id('retry')?.classList.toggle('hidden', current !== 'failed');
            return;
        }
        document.getElementById('chips').replaceChildren(chip('', 'all', total),...ORDER.filter(s => counts[s]).map(s => chip(s, LABEL[s], counts[s])));
    }

    function renderState(s) {
        document.getElementById('state').textContent = 'Sending: ' + (s.paused ? 'paused' : 'running') + ' · in queue: ' + fmt(s.queued)
            + ' · sent in the last hour: ' + fmt(s.sent);
        $id('pause')?.classList.toggle('hidden', s.paused);
        $id('resume')?.classList.toggle('hidden', !s.paused);
        renderBuild(s.build);
        // Kailan huling na-refresh ang listahan, at ang susunod na kusang refresh (kada oras sa :15).
        const r = $id('refreshed');
        if (r && s.refresh) r.textContent = 'Updated ' + (s.refresh.updated || '—') + ' · next refresh ' + s.refresh.next;
    }

    // "Refresh now" ay tumatakbo sa queue: ipinapakita ang lagay habang naghihintay o tumatakbo, at ang resulta kapag tapos
    // (para sa pindot sa tab na ito). Habang tumatakbo, naka-disable ang button (kahit ibang tab o user ang pumindot).
    // Ang progress ay isinusulat lang kapag walang ibang mensahe (o progress na rin ang nakasulat), para hindi matakpan
    // ang "N queued" o ang error ng ibang button; ang naiwang progress ay binubura kapag tapos na.
    let watchBuild = false;
    const PROGRESS = 'Fetching new messages in the background';
    const buildRunning = b => !!b && (b.status === 'queued' || b.status === 'running');
    function renderBuild(b) {
        if ($id('build')) $id('build').disabled = buildRunning(b);
        const shown = document.getElementById('notice').textContent;
        if (buildRunning(b)) {
            if (watchBuild || shown === '' || shown.startsWith(PROGRESS)) {
                notice(PROGRESS + ': ' + (b.status === 'queued' ? 'waiting for the worker' : 'running')
                    + ' · ' + fmt(b.seconds) + ' s. The list updates when it is done.');
            }
            return;
        }
        if (!watchBuild && shown.startsWith(PROGRESS)) notice('');
        if (!watchBuild || !b) return;
        watchBuild = false;
        if (b.status === 'done') {
            const r = b.result;
            notice(fmt(r.added) + ' new (' + fmt(r.ready) + ' ready, ' + fmt(r.no_psid) + ' no PSID, ' + fmt(r.no_config) + ' no config); ' + fmt(r.updated) + ' updated; J&T: '
                + fmt(r.tracked) + ' tracked' + (r.rider_pending ? ', ' + fmt(r.rider_pending) + ' rider pending (next fetch or before sending)' : '') + '.', 'text-green-700');
            countsAt = 0;
            load();
        } else {
            notice(b.error || 'The fetch stopped before it finished; press Refresh now again.', 'text-red-600');
        }
    }

    function renderRows(rows) {
        const body = rows.map(r => {
            const check = el('input', {type: 'checkbox', 'aria-label': 'Select ' + r.waybill});
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
            : [el('tr', {}, el('td', {colspan: '12', class: 'px-3 py-4 text-gray-500', text: VIEW === 'send' ? 'Nothing to send in this filter.'
                : (document.querySelector('#filters [name=status]').value === 'sent' ? 'Nothing sent in this range yet.' : 'No processed messages in this filter.')}))]));
        document.getElementById('check-all').checked = rows.length > 0 && rows.every(r => selected.has(r.id));
    }

    function refreshSelected() {
        const b = $id('send-selected');
        if (!b) return; // History: walang Send selected
        b.textContent = 'Send selected (' + selected.size + ')';
        b.disabled = selected.size === 0;
    }

    // Habang may naka-queue o ipinapadala: kunin ulit kada ilang segundo.
    function schedule() {
        clearTimeout(timer);
        const active = last && (last.state.queued > 0 || buildRunning(last.state.build) || last.rows.some(r => r.status === 'queued' || r.status === 'sending'));
        if (active && !document.hidden) timer = setTimeout(() => { load(true); if (openId) openPanel(openId, true); }, 4000);
    }

    // Kanino ang Test PSID ng page na naka-filter: para makita bago mag-"Send test" na hindi kapangalan ang tatanggap.
    function renderTestPsid(t) {
        const page = filters().page;
        document.getElementById('test-psid-owner').textContent = !page ? ''
            : (t ? 'Test PSID of ' + page + ': ' + t.name + ' (…' + t.psid_last4 + ')' : 'No Test PSID for ' + page);
    }
    document.addEventListener('visibilitychange', schedule);

    // ---- side panel ----

    async function openPanel(id, quiet = false) {
        openId = id;
        const res = await fetch(url.showUrl + '/' + id, {headers: {'Accept': 'application/json'}});
        if (!res.ok) { if (!quiet) notice('Could not load the details.', 'text-red-600'); return; }
        const m = await res.json();
        document.getElementById('panel-title').replaceChildren(m.waybill + ' · ', badge(m.status), m.is_test ? ' (test)' : '');
        const kv = (k, v) => el('div', {class: 'flex gap-2'}, [el('span', {class: 'text-gray-500 w-28 shrink-0', text: k}), el('span', {class: 'break-all', text: v == null || v === '' ? '—' : String(v)})]);
        const steps = (m.steps || []).map(s => el('div', {class: 'border rounded p-2 mb-2'}, [
            el('div', {class: 'font-medium', text: s.step + ' · ' + s.method + ' ' + s.path}),
            el('div', {class: 'text-xs text-gray-600', text: 'HTTP ' + s.http + ' · ' + s.attempts + ' tries · ' + s.at}),
            el('div', {class: 'text-xs text-gray-500 mt-1', text: 'Request (no token):'}),
            el('pre', {class: 'cb text-xs bg-gray-50 p-1 rounded', text: JSON.stringify(s.request, null, 1)}),
            el('div', {class: 'text-xs text-gray-500 mt-1', text: 'Response:'}),
            el('pre', {class: 'cb text-xs bg-gray-50 p-1 rounded', text: s.response}),
        ]));
        const actions = el('div', {class: 'flex gap-2 my-2'});
        if (['ready', 'failed', 'field_mismatch', 'skipped'].includes(m.status) && m.psid) {
            const send = el('button', {type: 'button', class: 'bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded', text: 'Send'});
            send.addEventListener('click', () => sendIds([m.id], 'Send this message to the customer?'));
            actions.append(send);
        }
        if (!m.is_test) {
            const test = el('button', {type: 'button', class: 'bg-gray-200 hover:bg-gray-300 px-3 py-1 rounded', text: 'Send test'});
            test.addEventListener('click', async () => {
                try { await post(url.testUrl, {id: m.id}); notice('The test is queued to the page\'s Test PSID.', 'text-green-700'); load(); }
                catch (e) { notice(e.message, 'text-red-600'); }
            });
            actions.append(test);
        }
        document.getElementById('panel-body').replaceChildren(
            m.reason ? el('div', {class: 'mb-2 px-2 py-1 rounded bg-amber-50 text-amber-900', text: m.reason}) : '',
            actions,
            // Ang nakita ng customer sa Messenger (template + text), parang chat bubble; mula sa nakatala lang.
            el('div', {class: 'text-xs text-gray-500', text: m.status === 'sent' ? 'What the customer saw:' : 'What the customer will see:'}),
            el('div', {class: 'mb-1 flex'}, el('pre', {class: 'cb max-w-[90%] rounded-2xl rounded-bl-sm bg-gray-100 px-3 py-2 text-sm text-gray-900 font-sans', text: m.customer_saw.text})),
            m.customer_saw.note ? el('div', {class: 'mb-1 text-xs text-amber-800', text: m.customer_saw.note}) : '',
            m.botcake_url ? el('a', {href: m.botcake_url, target: '_blank', rel: 'noopener noreferrer', class: 'mb-2 inline-block text-xs text-blue-700 hover:underline', text: 'Open in Botcake ↗'}) : '',
            el('div', {class: 'text-xs text-gray-500', text: 'Full text:'}),
            el('pre', {class: 'cb bg-gray-50 border rounded p-2 mb-2', text: m.text || ''}),
            kv('Page', m.page), kv('Customer', m.customer), kv('PSID', m.psid), kv('Sequence', CAMPAIGNS[m.kind] || m.kind), kv('Step', m.step), kv('J&T at build', m.jnt_status), kv('J&T now', m.jnt_now),
            kv('Tries', m.attempts), kv('Last HTTP', m.last_http), kv('Created', m.created_at + ' · ' + m.created_by),
            kv('Queued', m.queued_at), kv('Sent', m.sent_at ? m.sent_at + ' · ' + (m.sent_by || '') : null),
            kv('Test of', m.test_of), kv('Order id', m.macro_output_id),
            el('div', {class: 'font-medium mt-3 mb-1', text: 'Send steps'}),
            ...(steps.length ? steps : [el('div', {class: 'text-gray-500', text: 'No send steps yet (not sent yet).'})]),
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
            notice(fmt(d.queued) + ' queued.', 'text-green-700');
            ids.forEach(i => selected.delete(i));
            refreshSelected();
            load();
            if (openId) openPanel(openId, true);
        } catch (e) { notice(e.message, 'text-red-600'); }
    }

    $id('send-selected')?.addEventListener('click', () => sendIds([...selected], 'Send to ' + selected.size + ' customer(s)?'));

    $id('send-filtered')?.addEventListener('click', async () => {
        // Ang lahat ng ready ng napiling page at sequence, anuman ang petsa (ang hanap ay pagtingin lang).
        const f = filters(), scope = {page: f.page || '', kind: f.kind || ''};
        const where = (scope.page || 'all pages') + ', ' + (scope.kind ? (CAMPAIGNS[scope.kind] || scope.kind) : 'all sequences');
        if (!confirm('Send ' + fmt(sendable) + ' ready messages (' + where + ')?')) return;
        try { const d = await post(url.sendFilteredUrl, scope); notice(fmt(d.queued) + ' queued.', 'text-green-700'); load(); }
        catch (e) { notice(e.message, 'text-red-600'); }
    });

    $id('retry')?.addEventListener('click', async () => {
        if (!confirm('Send the failed and field mismatch messages in this filter again?')) return;
        try { const d = await post(url.retryUrl, filters()); notice(fmt(d.queued) + ' queued again.', 'text-green-700'); load(); }
        catch (e) { notice(e.message, 'text-red-600'); }
    });

    $id('build')?.addEventListener('click', async e => {
        const btn = e.currentTarget;
        btn.disabled = true;
        try {
            watchBuild = true;
            const run = (await post(url.buildUrl, {kind: filters().kind})).run;
            renderBuild(run);
            if (buildRunning(run)) load(true); // sinisimulan ang poll hanggang matapos
        } catch (err) { watchBuild = false; notice(err.message, 'text-red-600'); btn.disabled = false; }
    });

    for (const [id, u] of [['pause', url.pauseUrl], ['resume', url.resumeUrl]]) {
        $id(id)?.addEventListener('click', async () => {
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
        document.getElementById('test-psid-current').textContent = d.current ? 'Current Test PSID: …' + d.current : 'The page has no Test PSID yet.';
        document.getElementById('test-psid-results').replaceChildren(...(d.customers || []).map(c => {
            const b = el('button', {type: 'button', class: 'text-blue-700 hover:underline', text: c.name + ' (…' + c.psid_last4 + ')'});
            b.addEventListener('click', async () => {
                if (!confirm('Make "' + c.name + '" (PSID …' + c.psid_last4 + ') the Test PSID of ' + pf.page.value + '? Every "Send test" of the page goes there.')) return;
                try { const s = await post(url.testPsidUrl, {page: pf.page.value, customer_id: c.id}); document.getElementById('test-psid-current').textContent = 'Saved: ' + s.name + ' (…' + s.psid_last4 + ')'; load(); }
                catch (err) { notice(err.message, 'text-red-600'); }
            });
            return el('li', {}, b);
        }));
        if (!(d.customers || []).length) document.getElementById('test-psid-results').append(el('li', {class: 'text-gray-500', text: 'No match (type at least 2 letters of the start of the name).'}));
    });

    load();
})();
</script>
@endsection
