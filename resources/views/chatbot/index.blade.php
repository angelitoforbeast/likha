@extends('layouts.chatblast')

@section('title', 'Chatbot · Page settings')
@section('heading', 'Chatbot')

@section('description')
    One row per page with a Botcake ID and token: switch the bot On or Off and set the values its replies will use. Values save as you type; pasted values save with one button.
@endsection

@push('styles')
<style>
    /* Ang table lang ang nag-i-scroll (pataas at patagilid sa loob ng kahon, .cb-scroll ng layout: ang natitirang taas ng
       screen, kaya laging kita ang horizontal bar); ang page ay hindi kailanman patagilid. */
    #cb-table th, #cb-table td { background: #fff; }
    /* Paste: ang napiling mga cell (asul) at ang hindi pa naka-save (dilaw, ang dating value sa hover). */
    #cb-table td.cb-sel { background: #eff6ff; box-shadow: inset 0 0 0 1px #93c5fd; }
    #cb-table td.cb-pending { background: #fef3c7; }
    #cb-table td.cb-pending.cb-sel { background: #fde68a; }
    #cb-table:focus-within td.cb-cell:focus { outline: 2px solid #3b82f6; outline-offset: -2px; }
    /* Edit ng isang page: ang binago (dilaw, may "Was: …") at ang hindi link sa image field (pula, hindi sine-save). */
    #pe .pe-in { resize: none; overflow-y: auto; max-height: 20rem; }
    #pe .pe-changed .pe-in { background: #fef3c7; border-color: #f59e0b; }
    #pe .pe-flagged .pe-in { background: #fef2f2; border-color: #ef4444; }
    /* Text cell: buo ang line break, 3 linya kapag sarado (may fade kapag may kasunod pa). Ang click sa cell ay nagbubukas ng
       buong row: bawat cell hanggang 15rem (mga 240 px), may sariling scroll. Ang lapis lang ang nagbubukas ng editor. */
    #cb-table .cb-txt { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 3; overflow: hidden; max-width: 14rem; white-space: pre-line; overflow-wrap: anywhere; }
    #cb-table .cb-txt.cb-clamped { -webkit-mask-image: linear-gradient(to bottom, #000 55%, transparent); mask-image: linear-gradient(to bottom, #000 55%, transparent); }
    #cb-table tr.cb-open .cb-txt { display: block; -webkit-line-clamp: unset; max-height: 15rem; overflow-y: auto; -webkit-mask-image: none; mask-image: none; }
    #cb-table td.cb-cell { cursor: pointer; }
    #cb-table .cb-edit { opacity: 0; }
    #cb-table td.cb-cell:hover .cb-edit, #cb-table td.cb-cell:focus-within .cb-edit, #cb-table .cb-edit:focus { opacity: 1; }
    @media (hover: none), (max-width: 639px) { #cb-table .cb-edit { opacity: 1; } }
    #cb-table thead th { position: sticky; top: 0; z-index: 20; }
    #cb-table .stick-1 { position: sticky; left: 0; z-index: 10; }
    #cb-table thead .stick-1, #cb-table thead .stick-2, #cb-table thead .stick-3 { z-index: 30; }
    @media (min-width: 640px) {
        #cb-table .stick-2 { position: sticky; left: 12rem; z-index: 10; }
        #cb-table .stick-3 { position: sticky; left: 18rem; z-index: 10; box-shadow: 1px 0 0 #e2e8f0; }
    }
</style>
@endpush

@section('content')
@include('chatbot.partials.switch', ['repliesOn' => $repliesOn])
<div id="cbot" class="cb-page"
     data-data-url="{{ route('chatbot.data') }}"
     data-cell-url="{{ route('chatbot.cell') }}"
     data-cells-url="{{ route('chatbot.cells') }}"
     data-column-url="{{ route('chatbot.column') }}"
     @if($isCeo) data-active-url="{{ route('chatbot.active') }}" data-pages-url="{{ route('chatbot.pages') }}" @endif>

    <div class="cb-filters mb-3 flex flex-wrap items-end gap-3 text-sm">
        <label class="flex flex-col text-xs">Search
            <input id="f-q" type="search" placeholder="Page name" class="border px-2 py-1 text-sm text-gray-900">
        </label>
        <label class="flex flex-col text-xs">Bot
            <select id="f-state" class="border px-2 py-1 text-sm text-gray-900">
                <option value="">All</option><option value="on">On</option><option value="off">Off</option>
            </select>
        </label>
        <button id="add-column" type="button" class="cb-btn">+ Column</button>
        @if($isCeo)<button id="add-page" type="button" class="cb-btn cb-btn-primary">+ Page</button>@endif
        {{-- Paste: ang Edit ng bawat page (may paste box), o Ctrl/Cmd+V sa napiling cell ng grid. --}}
        <label class="flex items-center gap-1 text-xs"><input id="f-blanks" type="checkbox"> Paste blanks as empty</label>
        <span id="count" class="text-xs text-slate-500"></span>
    </div>
    <p id="notice" class="mb-2 min-h-[1rem] text-xs" aria-live="polite"></p>

    <div id="cb-box" class="cb-card cb-scroll">
        <table id="cb-table" class="text-sm" style="border-collapse: separate; border-spacing: 0;"></table>
    </div>

    {{-- Ang bar ng paste: mga hindi pa naka-save (Save / Cancel), o pagkatapos ng Save, ang Undo. --}}
    <div id="bar" class="mt-2 hidden flex-wrap items-center gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm" role="status" aria-live="polite">
        <span id="bar-text" class="font-medium"></span>
        <button id="bar-save" type="button" class="cb-btn cb-btn-primary cb-btn-sm">Save</button>
        <button id="bar-cancel" type="button" class="cb-btn cb-btn-sm">Cancel</button>
        <button id="bar-undo" type="button" class="cb-btn cb-btn-sm">Undo</button>
    </div>

    {{-- Edit ng isang page: lahat ng botfield nito, pababa at nag-i-scroll. Ang paste box sa itaas ay pumupuno sa mga field
         mula sa isang row ng Google Sheets; isang Save para sa lahat ng binago. Sa phone, buong screen. --}}
    <div id="pe" class="fixed inset-0 z-50 hidden bg-slate-900/30 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="pe-title">
        <div class="mx-auto flex h-full max-h-full w-full max-w-2xl flex-col bg-white text-sm shadow-lg sm:rounded-xl">
            <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
                <div id="pe-title" class="min-w-0 break-words font-semibold"><span id="pe-name"></span> <span id="pe-id" class="font-mono text-xs font-normal text-slate-400"></span></div>
                <button id="pe-close" type="button" class="px-2 text-slate-500 hover:text-slate-800" aria-label="Close">✕</button>
            </div>
            <div class="border-b border-slate-200 px-4 py-2">
                <label for="pe-paste" class="text-xs text-slate-600">Paste a row copied from Google Sheets (with or without the header line)</label>
                <textarea id="pe-paste" rows="2" class="mt-1 w-full rounded-md border border-slate-300 p-2 font-mono text-xs" placeholder="Ctrl/Cmd+V here"></textarea>
                <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-600">
                    <label id="pe-start-box" class="flex items-center gap-1">Starting at field <select id="pe-start" class="max-w-[12rem] rounded border border-slate-300 px-1 py-0.5 text-xs text-gray-900"></select></label>
                    <label class="flex items-center gap-1"><input id="pe-blanks" type="checkbox"> Paste blanks as empty</label>
                </div>
                <ul id="pe-notes" class="mt-1 space-y-0.5 text-xs text-slate-600" aria-live="polite"></ul>
            </div>
            <div id="pe-fields" class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto px-4"></div>
            <div class="border-t border-slate-200 px-4 py-3">
                <p id="pe-error" class="mb-1 text-xs text-red-600 empty:hidden" aria-live="polite"></p>
                <div class="flex flex-wrap gap-2"><button id="pe-save" type="button" class="cb-btn cb-btn-primary" disabled>Save</button><button id="pe-cancel" type="button" class="cb-btn">Cancel</button></div>
            </div>
        </div>
    </div>

    {{-- Mahabang text: modal sa gitna. Sine-save habang nagta-type at sa pagsara. --}}
    <div id="long" class="fixed inset-0 z-50 hidden overflow-y-auto bg-slate-900/30 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="long-title">
        <div class="mx-auto w-full max-w-2xl rounded-xl bg-white p-5 text-sm shadow-lg">
            <div class="mb-3 flex items-center justify-between gap-3">
                <div id="long-title" class="font-semibold break-words"></div>
                <button id="long-close" type="button" class="px-2 text-slate-500 hover:text-slate-800" aria-label="Close">✕</button>
            </div>
            <textarea id="long-text" rows="12" class="w-full rounded-md border border-slate-300 p-2 font-mono text-sm"></textarea>
            <p id="long-mark" class="mt-1 min-h-[1rem] text-xs" aria-live="polite"></p>
        </div>
    </div>

    {{-- "+ Column" --}}
    <div id="col-box" class="fixed inset-0 z-50 hidden overflow-y-auto bg-slate-900/30 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="col-title">
        <form id="col-form" class="mx-auto w-full max-w-md rounded-xl bg-white p-5 text-sm shadow-lg">
            <div id="col-title" class="mb-3 font-semibold">New botfield column</div>
            <label class="flex flex-col text-xs text-slate-600">Column name
                <input name="name" maxlength="100" required placeholder="e.g. Promo Text" class="mt-1 rounded-md border border-slate-300 px-2 py-1 text-sm text-gray-900">
            </label>
            <p class="mt-2 text-xs text-slate-500">It appears for every page.</p>
            <p id="col-error" class="mt-1 min-h-[1rem] text-xs text-red-600"></p>
            <div class="mt-2 flex gap-2"><button class="cb-btn cb-btn-primary">Add column</button><button type="button" data-close class="cb-btn">Cancel</button></div>
        </form>
    </div>

    @if($isCeo)
    {{-- "+ Page": bago, o isang page na nasa page settings na. Ang token ay hindi kailanman ipinapakita. --}}
    <div id="page-box" class="fixed inset-0 z-50 hidden overflow-y-auto bg-slate-900/30 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="page-title">
        <form id="page-form" class="mx-auto w-full max-w-md rounded-xl bg-white p-5 text-sm shadow-lg" autocomplete="off">
            <div id="page-title" class="mb-3 font-semibold">Add a page</div>
            <label class="flex flex-col text-xs text-slate-600">Page name, exactly as PAGE in orders, or pick one from page settings
                <input name="page_name" list="other-pages" maxlength="255" required class="mt-1 rounded-md border border-slate-300 px-2 py-1 text-sm text-gray-900">
            </label>
            <datalist id="other-pages">@foreach($otherPages as $p)<option value="{{ $p }}"></option>@endforeach</datalist>
            <label class="mt-2 flex flex-col text-xs text-slate-600">Botcake ID (leave blank if page settings has it)
                <input name="page_id" inputmode="numeric" maxlength="30" class="mt-1 rounded-md border border-slate-300 px-2 py-1 font-mono text-sm text-gray-900">
            </label>
            <label class="mt-2 flex flex-col text-xs text-slate-600">Botcake token (leave blank if page settings has it)
                <input name="token" type="password" maxlength="1000" class="mt-1 rounded-md border border-slate-300 px-2 py-1 font-mono text-sm text-gray-900">
            </label>
            <p class="mt-2 text-xs text-slate-500">Saved to page settings, then the token is tested and the bot is set up and switched On. This replaces the page's Default Reply in Botcake. If Botcake fails, the page is saved and stays Off.</p>
            <p id="page-error" class="mt-1 min-h-[1rem] text-xs text-red-600"></p>
            <div class="mt-2 flex gap-2"><button class="cb-btn cb-btn-primary">Add page</button><button type="button" data-close class="cb-btn">Cancel</button></div>
        </form>
    </div>
    @endif
</div>

<script src="{{ asset('js/chatbot-paste.js') }}?v={{ substr(md5_file(public_path('js/chatbot-paste.js')), 0, 10) }}"></script>
<script>
(function () {
    const box = document.getElementById('cbot'), url = box.dataset, $ = id => document.getElementById(id);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const PAUSE_MS = 800, table = $('cb-table');
    let columns = [], rows = [], flows = [], editing = null;
    const marks = new Map(), seqs = new Map(), errors = new Map();
    let openRow = null; // ang page ng row na bukas (isa lang)
    // Paste: ang hindi pa naka-save (id => {page, key, value, old}), ang napiling mga cell (dalawang sulok, {page, key}),
    // at ang dating value ng huling Save (para sa Undo).
    const pending = new Map();
    let sel = null, lastUndo = null;
    const botCols = () => columns.filter(c => c.key !== 'flow');

    function el(tag, attrs = {}, children = []) {
        const e = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs)) {
            if (v == null) continue;
            if (k === 'class') e.className = v; else if (k === 'text') e.textContent = v; else e.setAttribute(k, v);
        }
        for (const c of [].concat(children)) if (c != null) e.append(c);
        return e;
    }
    const post = (u, body) => fetch(u, {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}, body: JSON.stringify(body)})
        .then(async r => ({ok: r.ok, status: r.status, data: await r.json().catch(() => ({}))}));
    function notice(text, cls = 'text-slate-600') { $('notice').className = 'mb-2 min-h-[1rem] text-xs ' + cls; $('notice').textContent = text; }
    const id = (page, key) => page + '\u0000' + key;
    const isUrl = v => /^https?:\/\//i.test(v || '');

    async function load() {
        const res = await fetch(url.dataUrl, {headers: {'Accept': 'application/json'}});
        if (!res.ok) { notice('Could not load the pages (' + res.status + ').', 'text-red-600'); return; }
        const data = await res.json();
        columns = data.columns; rows = data.rows; flows = data.flows || [];
        render();
    }

    function visible() {
        const q = $('f-q').value.trim().toLowerCase(), st = $('f-state').value;
        return rows.filter(r => (!q || r.page.toLowerCase().includes(q)) && (!st || (st === 'on') === r.active));
    }

    function render() {
        const head = el('tr', {}, [
            el('th', {class: 'stick-1 w-32 min-w-[8rem] sm:w-48 sm:min-w-[12rem] border-b border-slate-200 px-3 py-2 text-left text-xs font-medium uppercase text-slate-500'}, 'Page'),
            el('th', {class: 'stick-2 min-w-[6rem] w-24 border-b border-slate-200 px-3 py-2 text-left text-xs font-medium uppercase text-slate-500', text: 'Active'}),
            el('th', {class: 'stick-3 min-w-[10rem] w-40 border-b border-slate-200 px-3 py-2 text-left text-xs font-medium uppercase text-slate-500', text: 'Flow'}),
            el('th', {class: 'min-w-[5rem] border-b border-slate-200 px-3 py-2 text-left text-xs font-medium uppercase text-slate-500', text: 'Chats today'}),
            el('th', {class: 'min-w-[8rem] border-b border-slate-200 px-3 py-2 text-left text-xs font-medium uppercase text-slate-500', text: 'Last chat'}),
            ...columns.filter(c => c.key !== 'flow').map(c => el('th', {class: 'min-w-[12rem] border-b border-slate-200 px-3 py-2 text-left text-xs font-medium text-slate-500', text: c.label})),
        ]);
        const list = visible();
        const body = el('tbody', {}, list.map(r => el('tr', {'data-page': r.page, class: r.page === openRow ? 'cb-open' : null}, [
            el('td', {class: 'stick-1 border-b border-slate-100 px-3 py-2 align-top font-medium break-words'}, [r.page, el('div', {class: 'font-mono text-xs text-slate-400', text: r.page_id || ''}), editButton(r)]),
            activeCell(r),
            cell(r, columns.find(c => c.key === 'flow'), 'stick-3'),
            el('td', {class: 'border-b border-slate-100 px-3 py-2 align-top', text: String(r.today)}),
            el('td', {class: 'border-b border-slate-100 px-3 py-2 align-top text-xs text-slate-500', text: r.last_chat || '—'}),
            ...columns.filter(c => c.key !== 'flow').map(c => cell(r, c)),
        ])));
        table.replaceChildren(el('thead', {}, head), body);
        $('count').textContent = list.length + ' of ' + rows.length + ' page' + (rows.length === 1 ? '' : 's');
        if (!rows.length) table.append(el('tbody', {}, el('tr', {}, el('td', {class: 'px-3 py-3 text-slate-500', colspan: '5', text: 'No page has a Botcake ID and token yet.'}))));
        paintSel();
        paintBar();
        requestAnimationFrame(markClamped);
    }

    // Edit: binubuksan ang lahat ng botfield ng page (sa phone, lapis lang; ang pangalan ay nasa aria-label).
    function editButton(r) {
        const b = el('button', {type: 'button', class: 'mt-1 inline-flex items-center gap-1 rounded border border-slate-300 px-1.5 py-0.5 text-xs font-normal text-slate-600 hover:bg-slate-50 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500',
            'aria-label': 'Edit ' + r.page, title: 'Edit ' + r.page}, [el('span', {'aria-hidden': 'true', text: '✎'}), el('span', {class: 'hidden sm:inline', text: 'Edit'})]);
        b.addEventListener('click', () => openPe(r));
        return b;
    }

    // Ang fade: sa text na lampas sa 3 linya ng row na sarado.
    function markClamped() {
        table.querySelectorAll('tbody tr:not(.cb-open) .cb-txt').forEach(t => { t.scrollTop = 0; t.classList.toggle('cb-clamped', t.scrollHeight > t.clientHeight + 1); });
    }

    /** Buksan ang buong row (isara ang dati), o isara kapag ito na ang bukas. */
    function toggleRow(page) {
        openRow = openRow === page ? null : page;
        table.querySelectorAll('tbody tr').forEach(tr => tr.classList.toggle('cb-open', tr.dataset.page === openRow));
        requestAnimationFrame(markClamped);
    }

    function activeCell(r) {
        const td = el('td', {class: 'stick-2 border-b border-slate-100 px-3 py-2 align-top'});
        const b = el('button', {type: 'button', role: 'switch', class: 'inline-flex items-center gap-2 rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed', title: r.active ? 'Switch the bot off' : 'Switch the bot on', 'aria-label': (r.active ? 'Switch off ' : 'Switch on ') + r.page}, [
            el('span', {class: 'relative h-[22px] w-10 shrink-0 rounded-full transition-colors duration-200'}, el('span', {class: 'absolute left-[2px] top-[2px] h-[18px] w-[18px] rounded-full bg-white shadow transition-transform duration-200'})),
            el('span', {class: 'w-6 text-left text-xs font-medium'}),
        ]);
        paintSwitch(b, r.active);
        if (url.activeUrl) b.addEventListener('click', () => toggle(r, b)); else b.disabled = true; // CEO lang ang makakapag-switch
        td.append(b);
        const err = errors.get(r.page);
        if (err) td.append(el('div', {class: 'mt-1 max-w-[12rem] text-xs text-red-600 break-words', text: err}));
        return td;
    }

    function paintSwitch(b, on) {
        const [track, label] = b.children;
        b.setAttribute('aria-checked', on ? 'true' : 'false');
        track.classList.toggle('bg-green-500', on); track.classList.toggle('bg-slate-300', !on);
        track.firstChild.classList.toggle('translate-x-[18px]', on);
        label.textContent = on ? 'On' : 'Off';
        label.classList.toggle('text-green-700', on); label.classList.toggle('text-slate-600', !on);
    }

    async function toggle(r, b) {
        const on = !r.active;
        if (!confirm((on ? 'Switch the bot On for ' : 'Switch the bot Off for ') + r.page + '? This changes the live Botcake page.')) return;
        // Lilipat agad ang knob habang naghihintay; ang render() pagkatapos ay sagot ng server (babalik kapag pumalya ang Botcake).
        b.disabled = true; b.setAttribute('aria-busy', 'true'); b.classList.add('opacity-50'); paintSwitch(b, on);
        try {
            const {ok, data} = await post(url.activeUrl, {page_name: r.page, on});
            if (!ok) errors.set(r.page, data.error || data.message || 'Not changed.');
            else { r.active = data.active; if (data.error) errors.set(r.page, data.error); else errors.delete(r.page); }
        } catch (e) { errors.set(r.page, 'No connection: not changed.'); }
        render();
    }

    function cell(r, c, extra = '') {
        const k = id(r.page, c.key), m = marks.get(k);
        // FLOW: dropdown ng mga flow (/chatbot); ang luma na text na wala sa listahan ay ipinapakita pa rin.
        if (c.kind === 'flow') {
            const value = r.cells[c.key] ?? '';
            const td = el('td', {class: extra + ' border-b border-slate-100 px-3 py-2 align-top', 'data-key': c.key});
            // Ang lumang FLOW text na hindi nakatali sa flow ay "Not linked", para ang pagpili ng flow ay totoong pagbabago.
            const UNLINKED = '\u0000unlinked';
            const sel = el('select', {class: 'w-full max-w-[9rem] rounded border border-slate-300 px-1 py-0.5 text-sm', 'aria-label': 'Flow for ' + r.page}, [
                el('option', {value: '', text: '— none —'}),
                r.flow_text ? el('option', {value: UNLINKED, text: 'Not linked: ' + r.flow_text}) : null,
                ...flows.map(n => el('option', {value: n, text: n})),
            ]);
            sel.value = value || (r.flow_text ? UNLINKED : '');
            if (!value && r.flow_text) sel.classList.add('border-amber-500', 'text-amber-800');
            sel.addEventListener('change', () => { if (sel.value === UNLINKED) return; r.flow_text = null; save(r, c, sel.value); });
            td.append(sel);
            if (m) td.append(el('span', {class: 'block text-xs ' + m.cls, text: m.text, title: m.title || null}));
            return td;
        }
        const p = pending.get(k);
        const td = el('td', {class: extra + ' cb-cell border-b border-slate-100 px-3 py-2 align-top' + (p ? ' cb-pending' : ''), tabindex: '0', 'data-key': c.key,
            title: p ? 'Not saved yet. Was: ' + (p.old === '' ? '(empty)' : (p.old.length > 300 ? p.old.slice(0, 300) + '…' : p.old)) : null});
        const value = p ? p.value : (r.cells[c.key] ?? '');
        let show;
        if (c.kind === 'image' && isUrl(value)) {
            show = el('span', {class: 'flex items-center gap-2'}, [el('img', {src: value, alt: '', loading: 'lazy', referrerpolicy: 'no-referrer', class: 'h-8 w-8 rounded object-cover'}), el('span', {class: 'max-w-[9rem] truncate text-xs', text: value})]);
        } else if (value) {
            show = el('span', {class: 'cb-txt', text: String(value)});
        } else {
            show = el('span', {class: 'block max-w-[14rem] truncate text-slate-300', text: c.kind === 'image' ? '+ link' : '+ set'});
        }
        const edit = el('button', {type: 'button', class: 'cb-edit shrink-0 rounded px-1 text-slate-500 hover:bg-slate-100 hover:text-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500', 'aria-label': 'Edit ' + c.label, title: 'Edit ' + c.label, text: '✎'});
        td.append(el('div', {class: 'flex items-start gap-1'}, [el('div', {class: 'min-w-0 flex-1'}, show), edit]));
        if (m) td.append(el('span', {class: 'block text-xs ' + m.cls, text: m.text, title: m.title || null}));
        // Ang lapis lang ang nagbubukas ng editor (ang text sa textarea ng modal, dahil inaalis ng input ang line break; ang
        // image ay sa URL editor). Ang click (o Enter / Space) sa cell ay nagbubukas o nagsasara ng row.
        const open = () => c.kind === 'image' ? startEdit(td, r, c) : openLong(r, c);
        edit.addEventListener('click', ev => { ev.stopPropagation(); open(); });
        edit.addEventListener('keydown', ev => ev.stopPropagation());
        td.addEventListener('click', ev => {
            if (ev.target.closest('input')) return;
            if (ev.shiftKey && sel) { sel.b = {page: r.page, key: c.key}; paintSel(); return; }
            sel = {a: {page: r.page, key: c.key}, b: {page: r.page, key: c.key}};
            paintSel();
            toggleRow(r.page);
        });
        td.addEventListener('keydown', ev => { if (ev.target === td && (ev.key === 'Enter' || ev.key === ' ')) { ev.preventDefault(); toggleRow(r.page); } });
        return td;
    }

    function startEdit(td, r, c) {
        if (editing) finish(true);
        const input = el('input', {class: 'w-full min-w-[10rem] rounded border border-blue-500 px-1', autocomplete: 'off', placeholder: c.kind === 'image' ? 'https://…' : ''});
        input.value = r.cells[c.key] ?? '';
        td.replaceChildren(input);
        input.focus();
        editing = {r, c, input, original: input.value, timer: null};
        input.addEventListener('input', () => { clearTimeout(editing.timer); editing.timer = setTimeout(() => save(r, c, input.value), PAUSE_MS); });
        input.addEventListener('keydown', ev => {
            if (ev.key === 'Enter') { ev.preventDefault(); finish(true); }
            if (ev.key === 'Escape') { ev.preventDefault(); input.value = editing.original; finish(false); }
        });
        input.addEventListener('blur', () => { if (editing && editing.input === input) finish(true); });
    }

    function finish(doSave) {
        const ed = editing;
        if (!ed) return;
        clearTimeout(ed.timer);
        editing = null;
        if (doSave) save(ed.r, ed.c, ed.input.value); else render();
    }

    async function save(r, c, value) {
        value = value.trim();
        if (pending.size && c.kind !== 'flow') {
            // Gaya ng paste: ang hindi link sa column ng larawan ay hindi isinasama (tatanggihan ng server ang buong Save).
            if (value !== '' && c.kind === 'image' && !ChatbotPaste.isImageLink(value)) notice(r.page + ' · ' + c.label + ': use an image link that starts with http:// or https://.', 'text-red-600');
            else addPending(r, c.key, value);
            if (!editing) render();
            return;
        }
        if (value === String(r.cells[c.key] ?? '')) { if (!editing) render(); return; }
        const k = id(r.page, c.key), seq = (seqs.get(k) || 0) + 1;
        seqs.set(k, seq);
        marks.set(k, {text: 'Saving…', cls: 'text-slate-400'});
        if (!editing) render();
        try {
            const {ok, status, data} = await post(url.cellUrl, {page_name: r.page, key: c.key, value});
            if (seqs.get(k) !== seq) return; // mas bago ang ibang save ng parehong cell
            if (!ok) marks.set(k, {text: '!', cls: 'text-red-600', title: data.error || data.message || ('Not saved (' + status + ')')});
            else {
                if (data.value == null) delete r.cells[c.key]; else r.cells[c.key] = data.value;
                marks.set(k, {text: data.status === 'unchanged' ? '✓' : 'Saved', cls: 'text-green-600'});
                setTimeout(() => { if (marks.get(k)?.cls === 'text-green-600') { marks.delete(k); if (!editing) render(); } }, 2500);
            }
            if (!ok && !editing) notice(r.page + ' · ' + c.label + ': ' + (data.error || 'not saved'), 'text-red-600');
        } catch (e) {
            if (seqs.get(k) === seq) marks.set(k, {text: '!', cls: 'text-red-600', title: 'No connection: not saved.'});
        }
        if (!editing) render();
        if ($('long').dataset.key === k) $('long-mark').textContent = marks.get(k)?.text === '!' ? (marks.get(k).title || 'Not saved') : (marks.get(k)?.text || '');
    }

    // ---- mahabang text sa modal ----
    let longTimer = null, longRow = null, longCol = null;
    function openLong(r, c) {
        longRow = r; longCol = c;
        $('long-title').textContent = r.page + ' · ' + c.label;
        $('long-text').value = r.cells[c.key] ?? '';
        $('long').dataset.key = id(r.page, c.key);
        $('long-mark').textContent = '';
        $('long').classList.remove('hidden');
        $('long-text').focus();
    }
    function closeLong() {
        clearTimeout(longTimer);
        if (longRow) save(longRow, longCol, $('long-text').value);
        $('long').classList.add('hidden');
        delete $('long').dataset.key;
        longRow = null;
    }
    $('long-text').addEventListener('input', () => { clearTimeout(longTimer); longTimer = setTimeout(() => save(longRow, longCol, $('long-text').value), PAUSE_MS); });
    $('long-close').addEventListener('click', closeLong);
    $('long').addEventListener('click', e => { if (e.target === $('long')) closeLong(); });

    // ---- "+ Column" at "+ Page" ----
    function modal(boxId, show) {
        const m = $(boxId);
        if (!m) return;
        m.classList.toggle('hidden', !show);
        if (show) m.querySelector('input')?.focus();
    }
    document.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', () => { modal('col-box', false); modal('page-box', false); }));
    $('add-column').addEventListener('click', () => { $('col-error').textContent = ''; $('col-form').reset(); modal('col-box', true); });
    $('col-form').addEventListener('submit', async ev => {
        ev.preventDefault();
        const {ok, data} = await post(url.columnUrl, {name: new FormData(ev.target).get('name')});
        if (!ok) { $('col-error').textContent = data.error || data.message || 'Not added.'; return; }
        modal('col-box', false);
        notice('Column "' + data.label + '" is ready for every page.', 'text-green-700');
        await load();
    });
    if ($('add-page')) {
        $('add-page').addEventListener('click', () => { $('page-error').textContent = ''; $('page-form').reset(); modal('page-box', true); });
        $('page-form').addEventListener('submit', async ev => {
            ev.preventDefault();
            const f = new FormData(ev.target);
            const btn = ev.target.querySelector('button.cb-btn-primary');
            btn.disabled = true;
            try {
                const {ok, data} = await post(url.pagesUrl, {page_name: f.get('page_name'), page_id: f.get('page_id'), token: f.get('token')});
                if (!ok) { $('page-error').textContent = data.error || data.message || 'Not added.'; return; }
                modal('page-box', false);
                notice(data.page + ' added · token test: ' + data.token_test + ' · ' + (data.active ? 'On' : 'Off'), data.active ? 'text-green-700' : 'text-amber-700');
                if (data.error) errors.set(data.page, data.error); // sa row ng page, gaya ng error ng switch
                await load();
            } finally { btn.disabled = false; }
        });
    }

    document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        if (!$('long').classList.contains('hidden')) closeLong();
        if (!$('pe').classList.contains('hidden')) closePe();
        modal('col-box', false); modal('page-box', false);
    });
    // ---- paste: hindi pa naka-save, Save, Cancel, Undo ----
    function addPending(r, key, value) {
        const old = String(r.cells[key] ?? ''), k = id(r.page, key);
        if (value === old) pending.delete(k); else pending.set(k, {page: r.page, key, value, old});
    }
    const pageCount = list => new Set(list.map(x => x.page_name ?? x.page)).size;
    const plural = (n, word) => n + ' ' + word + (n === 1 ? '' : 's');
    function paintBar() {
        const bar = $('bar'), n = pending.size, show = n > 0 || !!lastUndo;
        bar.classList.toggle('hidden', !show); bar.classList.toggle('flex', show);
        $('bar-save').classList.toggle('hidden', !n); $('bar-cancel').classList.toggle('hidden', !n);
        $('bar-undo').classList.toggle('hidden', !lastUndo);
        $('bar-text').textContent = n ? plural(n, 'cell') + ' changed on ' + plural(pageCount([...pending.values()]), 'page') + ' · not saved yet'
            : lastUndo ? 'Saved ' + plural(lastUndo.length, 'field') + ' on ' + plural(pageCount(lastUndo), 'page') + '.' : '';
    }
    /** Isang Save (o Undo): isang request, isang transaction. Ang sagot ng server, o {error}. */
    async function saveCells(changes) {
        try {
            const {ok, status, data} = await post(url.cellsUrl, {blanks_empty: true, changes});
            if (ok) return data;
            const first = (data.errors || []).slice(0, 3).map(e => e.page_name + ' · ' + (columns.find(c => c.key === e.key)?.label || e.key) + ': ' + e.error).join(' ');
            return {error: (data.error || data.message || ('Not saved (' + status + ').')) + (first ? ' ' + first : '')};
        } catch (e) {
            return {error: 'No connection: nothing was saved.'};
        }
    }
    async function savePending() {
        const btn = $('bar-save');
        btn.disabled = true;
        const res = await saveCells([...pending.values()].map(p => ({page_name: p.page, key: p.key, value: p.value})));
        btn.disabled = false;
        if (res.error) { notice(res.error, 'text-red-600'); return; }
        pending.clear();
        done(res);
    }
    function done(res) {
        lastUndo = res.undo.length ? res.undo : null;
        notice('Saved ' + plural(res.saved, 'field') + ' on ' + plural(res.pages, 'page') + (res.unchanged ? ' · ' + res.unchanged + ' already had that value' : '') + '.', 'text-green-700');
        load();
    }
    $('bar-save').addEventListener('click', savePending);
    $('bar-cancel').addEventListener('click', () => { pending.clear(); notice('Pasted changes discarded.'); render(); });
    $('bar-undo').addEventListener('click', async () => {
        const btn = $('bar-undo');
        btn.disabled = true;
        const res = await saveCells(lastUndo);
        btn.disabled = false;
        if (res.error) { notice('Undo failed: ' + res.error, 'text-red-600'); return; }
        lastUndo = null;
        notice('Undone: ' + plural(res.saved, 'field') + ' back to the previous value.', 'text-green-700');
        load();
    });
    window.addEventListener('beforeunload', e => { if (pending.size || peDirty()) { e.preventDefault(); e.returnValue = ''; } });

    // ---- pagpili, Copy at Paste sa grid ----
    /** Ang napiling rectangle sa ayos ng nakikita (mga index), o null. */
    function selBox() {
        if (!sel) return null;
        const list = visible(), cols = botCols();
        const ri = p => list.findIndex(r => r.page === p), ci = k => cols.findIndex(c => c.key === k);
        const r1 = ri(sel.a.page), r2 = ri(sel.b.page), c1 = ci(sel.a.key), c2 = ci(sel.b.key);
        if (r1 < 0 || r2 < 0 || c1 < 0 || c2 < 0) return null;
        return {list, cols, top: Math.min(r1, r2), bottom: Math.max(r1, r2), left: Math.min(c1, c2), right: Math.max(c1, c2)};
    }
    function paintSel() {
        const b = selBox(), keys = new Set();
        if (b) for (let i = b.top; i <= b.bottom; i++) for (let j = b.left; j <= b.right; j++) keys.add(id(b.list[i].page, b.cols[j].key));
        table.querySelectorAll('tbody td.cb-cell').forEach(td => td.classList.toggle('cb-sel', keys.has(id(td.parentElement.dataset.page, td.dataset.key))));
    }
    const typing = t => t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT');
    table.addEventListener('keydown', e => {
        if (!(e.ctrlKey || e.metaKey) || e.key.toLowerCase() !== 'a' || typing(e.target)) return;
        const list = visible(), cols = botCols();
        if (!list.length || !cols.length) return;
        e.preventDefault();
        sel = {a: {page: list[0].page, key: cols[0].key}, b: {page: list[list.length - 1].page, key: cols[cols.length - 1].key}};
        paintSel();
    });
    table.addEventListener('copy', e => {
        const b = selBox();
        if (!b || typing(e.target)) return;
        const out = [];
        for (let i = b.top; i <= b.bottom; i++) {
            const r = b.list[i], line = [];
            for (let j = b.left; j <= b.right; j++) { const p = pending.get(id(r.page, b.cols[j].key)); line.push(p ? p.value : String(r.cells[b.cols[j].key] ?? '')); }
            out.push(line);
        }
        e.clipboardData.setData('text/plain', ChatbotPaste.toTsv(out));
        e.preventDefault();
        notice('Copied ' + plural(out.length * out[0].length, 'cell') + '.');
    });
    // Mula sa kaliwang itaas ng napili, pakanan at pababa (ayon sa nakikita). Isang value sa napiling rectangle = puno lahat.
    table.addEventListener('paste', e => {
        const b = selBox();
        if (!b || typing(e.target)) return;
        e.preventDefault();
        let block = ChatbotPaste.parseTsv(e.clipboardData.getData('text/plain'));
        if (!block.length) return;
        if (block.length === 1 && block[0].length === 1) block = Array.from({length: b.bottom - b.top + 1}, () => Array(b.right - b.left + 1).fill(block[0][0]));
        let dropped = 0, flagged = 0, added = 0;
        block.forEach((line, i) => line.forEach((raw, j) => {
            const r = b.list[b.top + i], c = b.cols[b.left + j], value = raw.trim();
            if (!r || !c) { if (value !== '') dropped++; return; }
            if (value === '' && !$('f-blanks').checked) return;
            if (value !== '' && c.kind === 'image' && !ChatbotPaste.isImageLink(value)) { flagged++; return; }
            addPending(r, c.key, value); added++;
        }));
        const width = Math.max(...block.map(l => l.length));
        sel = {a: {page: b.list[b.top].page, key: b.cols[b.left].key},
            b: {page: b.list[Math.min(b.top + block.length, b.list.length) - 1].page, key: b.cols[Math.min(b.left + width, b.cols.length) - 1].key}};
        const notes = [plural(added, 'cell') + ' pasted, not saved yet'];
        if (flagged) notes.push(plural(flagged, 'value') + ' in image columns are not links and were left out');
        if (dropped) notes.push(plural(dropped, 'value') + ' past the last column or row were dropped');
        notice(notes.join(' · ') + '.', flagged || dropped ? 'text-amber-700' : 'text-slate-600');
        render();
        table.querySelector('td.cb-sel')?.focus({preventScroll: true});
    });

    // ---- Edit ng isang page (modal) ----
    // pe: ang page na bukas, ang mga value nito nang buksan (orig), at ang value ng mga field bago ang huling paste
    // (beforePaste), para ang bagong paste o ibang "Starting at field" ay pumalit sa dati at hindi magpatong.
    let pe = null;
    const peInput = key => $('pe-fields').querySelector('[data-key="' + CSS.escape(key) + '"] .pe-in');
    const grow = t => { if (t.tagName === 'TEXTAREA') { t.style.height = 'auto'; t.style.height = t.scrollHeight + 2 + 'px'; } };
    /** Bawat field: ang value ngayon, at kung binago (changed) o hindi link sa image field (flagged, hindi sine-save). */
    function peState() {
        return botCols().map(c => {
            // Ikinukumpara sa trimmed na dating value: ang lumang value na may espasyo sa dulo ay hindi "binago" pagbukas.
            const value = peInput(c.key).value.trim(), old = pe.orig[c.key], same = value === old.trim();
            const flagged = value !== '' && !same && c.kind === 'image' && !ChatbotPaste.isImageLink(value);
            return {c, value, old, changed: !same && !flagged, flagged};
        });
    }
    const peDirty = () => !!pe && peState().some(x => x.changed || x.flagged);
    function paintPe() {
        const list = peState(), n = list.filter(x => x.changed).length;
        for (const x of list) {
            const row = peInput(x.c.key).closest('.pe-field');
            row.classList.toggle('pe-changed', x.changed); row.classList.toggle('pe-flagged', x.flagged);
            const was = row.querySelector('.pe-was');
            was.textContent = x.flagged ? 'Not a link (http:// or https://): will not be saved.' : x.changed ? 'Was: ' + (x.old === '' ? '(empty)' : x.old) : '';
            was.className = 'pe-was whitespace-pre-line break-words text-xs ' + (x.flagged ? 'text-red-600' : 'text-slate-500');
            const img = row.querySelector('img');
            if (img) { const ok = ChatbotPaste.isImageLink(x.value); img.classList.toggle('invisible', !ok); if (ok && img.getAttribute('src') !== x.value) img.src = x.value; }
        }
        $('pe-save').disabled = !n;
        $('pe-save').textContent = n ? 'Save ' + plural(n, 'change') : 'Save';
    }
    function openPe(r) {
        pe = {r, orig: {}, beforePaste: {}};
        $('pe-name').textContent = r.page;
        $('pe-id').textContent = r.page_id || '';
        const start = $('pe-start').value;
        $('pe-start').replaceChildren(...botCols().map(c => el('option', {value: c.key, text: c.label})));
        if (start && botCols().some(c => c.key === start)) $('pe-start').value = start;
        $('pe-blanks').checked = $('f-blanks').checked;
        $('pe-paste').value = '';
        $('pe-notes').replaceChildren();
        $('pe-error').textContent = '';
        $('pe-start-box').classList.remove('hidden');
        $('pe-fields').replaceChildren(...botCols().map(c => {
            const v = pe.orig[c.key] = String(r.cells[c.key] ?? ''), fid = 'pe-f-' + c.key;
            // Ang image ay link (isang linya, may maliit na preview); ang iba ay textarea na lumalaki, buo ang line break.
            const input = c.kind === 'image'
                ? el('input', {id: fid, type: 'url', class: 'pe-in w-full rounded-md border border-slate-300 px-2 py-1 text-sm', placeholder: 'https://…', autocomplete: 'off'})
                : el('textarea', {id: fid, rows: '1', class: 'pe-in w-full rounded-md border border-slate-300 px-2 py-1 text-sm'});
            input.value = v;
            input.addEventListener('input', () => { grow(input); paintPe(); });
            const box = c.kind === 'image'
                ? el('div', {class: 'flex items-center gap-2'}, [el('img', {alt: '', loading: 'lazy', referrerpolicy: 'no-referrer', class: 'invisible h-10 w-10 shrink-0 rounded border border-slate-200 object-cover'}), input])
                : input;
            return el('div', {class: 'pe-field py-2', 'data-key': c.key}, [el('label', {for: fid, class: 'mb-1 block text-xs font-medium text-slate-600', text: c.label}), box, el('div', {class: 'pe-was'})]);
        }));
        $('pe').classList.remove('hidden');
        $('pe-fields').scrollTop = 0;
        $('pe-fields').querySelectorAll('textarea').forEach(grow);
        paintPe();
        $('pe-paste').focus();
    }
    function closePe(force = false) {
        if (!pe) return;
        if (!force && peDirty() && !confirm('Discard the changes to ' + pe.r.page + '?')) return;
        $('pe').classList.add('hidden');
        pe = null;
    }
    /** Ang paste box: isang row (ang una lang), ayon sa header kung mayroon, kung wala ay ayon sa ayos mula sa napiling field. */
    function pePaste() {
        for (const [key, v] of Object.entries(pe.beforePaste)) peInput(key).value = v;
        pe.beforePaste = {};
        const text = $('pe-paste').value, notes = [];
        const current = Object.fromEntries(botCols().map(c => [c.key, peInput(c.key).value]));
        const plan = ChatbotPaste.plan({text, columns: botCols(), targets: [{page: pe.r.page, cells: pe.orig}], startKey: $('pe-start').value, blanksEmpty: $('pe-blanks').checked, firstRowOnly: true});
        $('pe-start-box').classList.toggle('hidden', plan.byName);
        if (text.trim() && plan.error) notes.push(plan.error);
        if (plan.byName) notes.push('Mapped by the header names in the first line.');
        if (plan.ignored.length) notes.push('Not a field, ignored: ' + plan.ignored.join(', ') + '.');
        if (plan.reserved) notes.push('ACTIVE and FLOW are not in this window: they were not changed.');
        if (plan.dropped) notes.push(plural(plan.dropped, 'value') + ' past the last field were dropped.');
        if (plan.extraRows) notes.push('Only the first row was used (' + plural(plan.extraRows, 'more row') + ' ignored).');
        let filled = 0;
        for (const x of plan.changes) {
            if (x.status === 'blank') continue; // blangko: naiiwan ang value
            pe.beforePaste[x.key] = current[x.key];
            peInput(x.key).value = x.value;
            if (x.status !== 'unchanged') filled++;
        }
        if (plan.changes.length) notes.unshift(plural(filled, 'field') + ' filled from the paste, not saved yet.');
        $('pe-notes').replaceChildren(...notes.map(n => el('li', {text: n})));
        $('pe-fields').querySelectorAll('textarea').forEach(grow);
        paintPe();
    }
    ['pe-paste', 'pe-start', 'pe-blanks'].forEach(i => $(i).addEventListener(i === 'pe-paste' ? 'input' : 'change', pePaste));
    $('pe-blanks').addEventListener('change', () => { $('f-blanks').checked = $('pe-blanks').checked; });
    $('pe-close').addEventListener('click', () => closePe());
    $('pe-cancel').addEventListener('click', () => closePe());
    $('pe').addEventListener('click', e => { if (e.target === $('pe')) closePe(); });
    $('pe-save').addEventListener('click', async () => {
        const changes = peState().filter(x => x.changed).map(x => ({page_name: pe.r.page, key: x.c.key, value: x.value}));
        if (!changes.length) return;
        $('pe-save').disabled = true;
        $('pe-error').textContent = '';
        const res = await saveCells(changes);
        if (res.error) { $('pe-error').textContent = res.error; $('pe-save').disabled = false; return; }
        closePe(true);
        done(res);
    });

    $('f-q').addEventListener('input', render);

    $('f-state').addEventListener('change', render);
    load();
})();
</script>
@endsection
