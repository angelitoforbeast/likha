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
    /* History at Changes: ang value ay 3 linya kapag sarado ("Show all" para sa buo). */
    .cb-fold { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 3; overflow: hidden; white-space: pre-line; overflow-wrap: anywhere; }
    .cb-fold.cb-unfold { display: block; -webkit-line-clamp: unset; }
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
     data-check-url="{{ route('chatbot.check') }}"
     data-preview-url="{{ route('chatbot.preview') }}"
     data-history-url="{{ route('chatbot.history') }}"
     data-restore-url="{{ route('chatbot.restore') }}"
     data-changes-url="{{ route('chatbot.changes') }}"
     data-provider-url="{{ route('chatbot.provider') }}"
     data-forbeast-token-url="{{ route('chatbot.forbeast-token') }}"
     data-needs-token="{{ \App\Services\Chatbot\ForbeastPageSetup::NEEDS_TOKEN }}"
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
        <button id="changes-open" type="button" class="cb-btn">Changes</button>
        @if($isCeo)<button id="add-page" type="button" class="cb-btn cb-btn-primary">+ Page</button>@endif
        {{-- Paste: ang Edit ng bawat page (may paste box), o Ctrl/Cmd+V sa napiling cell ng grid. --}}
        <label class="flex items-center gap-1 text-xs"><input id="f-blanks" type="checkbox"> Paste blanks as empty</label>
        <span id="count" class="text-xs text-slate-500"></span>
        {{-- Bulk "Replies via": ang mga page na may check (o lahat ng nakikita) sa isang kilos. --}}
        <span class="flex items-end gap-1 text-xs">
            <label class="flex flex-col">Replies via
                <select id="bulk-provider" class="border px-2 py-1 text-sm text-gray-900"><option value="botcake">Botcake</option><option value="gateway">Forbeast</option></select>
            </label>
            <button id="bulk-apply" type="button" class="cb-btn" disabled>Apply to selected</button>
        </span>
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
                <button id="pe-preview" type="button" class="cb-btn cb-btn-sm ml-auto shrink-0">Preview</button>
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

    {{-- Preview, History ng isang field, at Changes: mga modal na nasa ibabaw ng Edit (z-index 60). Basa lang, maliban sa
         Restore. Sa phone, buong screen. --}}
    <div id="pv" class="fixed inset-0 hidden bg-slate-900/30 sm:p-8" style="z-index: 60" role="dialog" aria-modal="true" aria-labelledby="pv-title">
        <div class="mx-auto flex h-full max-h-full w-full max-w-lg flex-col bg-white text-sm shadow-lg sm:rounded-xl">
            <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
                <div class="min-w-0"><div id="pv-title" class="break-words font-semibold"></div><div id="pv-sub" class="text-xs text-slate-500"></div></div>
                <button type="button" data-close-dialog class="px-2 text-slate-500 hover:text-slate-800" aria-label="Close">✕</button>
            </div>
            <div id="pv-list" class="min-h-0 flex-1 space-y-4 overflow-y-auto bg-slate-50 px-4 py-3"></div>
        </div>
    </div>
    <div id="hist" class="fixed inset-0 hidden bg-slate-900/30 sm:p-8" style="z-index: 60" role="dialog" aria-modal="true" aria-labelledby="hist-title">
        <div class="mx-auto flex h-full max-h-full w-full max-w-lg flex-col bg-white text-sm shadow-lg sm:rounded-xl">
            <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
                <div class="min-w-0"><div id="hist-title" class="break-words font-semibold"></div><div id="hist-sub" class="break-words text-xs text-slate-500"></div></div>
                <button type="button" data-close-dialog class="px-2 text-slate-500 hover:text-slate-800" aria-label="Close">✕</button>
            </div>
            <p id="hist-note" class="px-4 pt-2 text-xs empty:hidden" aria-live="polite"></p>
            <ol id="hist-list" class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto px-4"></ol>
        </div>
    </div>
    <div id="chg" class="fixed inset-0 hidden bg-slate-900/30 sm:p-8" style="z-index: 60" role="dialog" aria-modal="true" aria-labelledby="chg-title">
        <div class="mx-auto flex h-full max-h-full w-full max-w-4xl flex-col bg-white text-sm shadow-lg sm:rounded-xl">
            <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
                <div id="chg-title" class="font-semibold">Changes on chatbot pages</div>
                <button type="button" data-close-dialog class="px-2 text-slate-500 hover:text-slate-800" aria-label="Close">✕</button>
            </div>
            <form id="chg-filters" class="grid grid-cols-2 gap-2 border-b border-slate-200 px-4 py-2 text-xs sm:grid-cols-5">
                <label class="flex min-w-0 flex-col">Page<select name="page" class="mt-0.5 w-full rounded border border-slate-300 px-1 py-1 text-sm text-gray-900"><option value="">All pages</option></select></label>
                <label class="flex min-w-0 flex-col">User<select name="user" class="mt-0.5 w-full rounded border border-slate-300 px-1 py-1 text-sm text-gray-900"><option value="">Everyone</option></select></label>
                <label class="flex min-w-0 flex-col">Field<select name="field" class="mt-0.5 w-full rounded border border-slate-300 px-1 py-1 text-sm text-gray-900"><option value="">All fields</option></select></label>
                <label class="flex min-w-0 flex-col">From<input name="from" type="date" class="mt-0.5 w-full rounded border border-slate-300 px-1 py-1 text-sm text-gray-900"></label>
                <label class="flex min-w-0 flex-col">To<input name="to" type="date" class="mt-0.5 w-full rounded border border-slate-300 px-1 py-1 text-sm text-gray-900"></label>
            </form>
            <ol id="chg-list" class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto px-4" aria-live="polite"></ol>
            <div class="flex flex-wrap items-center gap-2 border-t border-slate-200 px-4 py-2 text-xs">
                <button id="chg-prev" type="button" class="cb-btn cb-btn-sm">Newer</button>
                <span id="chg-count" class="text-slate-600"></span>
                <button id="chg-next" type="button" class="cb-btn cb-btn-sm">Older</button>
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
    let columns = [], rows = [], flows = [], editing = null, gatewayReady = false;
    const NEEDS_TOKEN = url.needsToken; // ang dahilan kapag hindi mapipili ang Forbeast
    const picked = new Set(); // mga page na may check (bulk "Replies via")
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
        columns = data.columns; rows = data.rows; flows = data.flows || []; gatewayReady = !!data.gateway_ready;
        // Ang naka-cache na Check ay kasama sa data; ang wala pa ay kinukuha isa-isa pagkatapos ng render.
        checks.clear();
        for (const r of rows) if (r.check) checks.set(r.page, r.check);
        render();
        queueChecks(rows.filter(r => !checks.has(r.page)).map(r => r.page));
    }

    function visible() {
        const q = $('f-q').value.trim().toLowerCase(), st = $('f-state').value;
        return rows.filter(r => (!q || r.page.toLowerCase().includes(q)) && (!st || (st === 'on') === r.active));
    }

    function render() {
        const head = el('tr', {}, [
            el('th', {class: 'stick-1 w-32 min-w-[8rem] sm:w-48 sm:min-w-[12rem] border-b border-slate-200 px-3 py-2 text-left text-xs font-medium uppercase text-slate-500'}, [el('input', {type: 'checkbox', id: 'sel-all', class: 'mr-1 align-middle', 'aria-label': 'Select every page in the list', title: 'Select all in the list'}), 'Page']),
            el('th', {class: 'stick-2 min-w-[6rem] w-24 border-b border-slate-200 px-3 py-2 text-left text-xs font-medium uppercase text-slate-500', text: 'Active'}),
            el('th', {class: 'stick-3 min-w-[10rem] w-40 border-b border-slate-200 px-3 py-2 text-left text-xs font-medium uppercase text-slate-500', text: 'Flow'}),
            el('th', {class: 'min-w-[12rem] border-b border-slate-200 px-3 py-2 text-left text-xs font-medium uppercase text-slate-500', text: 'Check', title: 'Values the flow uses, image links, Seq pairs and unknown variables'}),
            el('th', {class: 'min-w-[5rem] border-b border-slate-200 px-3 py-2 text-left text-xs font-medium uppercase text-slate-500', text: 'Chats today'}),
            el('th', {class: 'min-w-[8rem] border-b border-slate-200 px-3 py-2 text-left text-xs font-medium uppercase text-slate-500', text: 'Last chat'}),
            ...columns.filter(c => c.key !== 'flow').map(c => el('th', {class: 'min-w-[12rem] border-b border-slate-200 px-3 py-2 text-left text-xs font-medium text-slate-500', text: c.label})),
        ]);
        const list = visible();
        const body = el('tbody', {}, list.map(r => el('tr', {'data-page': r.page, class: r.page === openRow ? 'cb-open' : null}, [
            el('td', {class: 'stick-1 border-b border-slate-100 px-3 py-2 align-top font-medium break-words'}, [pickBox(r), r.page, el('div', {class: 'font-mono text-xs text-slate-400', text: r.page_id || ''}),
                el('div', {class: 'flex flex-wrap gap-1'}, [editButton(r), previewButton(r)])]),
            activeCell(r),
            cell(r, columns.find(c => c.key === 'flow'), 'stick-3'),
            checkCell(r),
            el('td', {class: 'border-b border-slate-100 px-3 py-2 align-top', text: String(r.today)}),
            el('td', {class: 'border-b border-slate-100 px-3 py-2 align-top text-xs text-slate-500', text: r.last_chat || '—'}),
            ...columns.filter(c => c.key !== 'flow').map(c => cell(r, c)),
        ])));
        table.replaceChildren(el('thead', {}, head), body);
        $('count').textContent = list.length + ' of ' + rows.length + ' page' + (rows.length === 1 ? '' : 's');
        if (!rows.length) table.append(el('tbody', {}, el('tr', {}, el('td', {class: 'px-3 py-3 text-slate-500', colspan: '5', text: 'No page has a Botcake ID and token yet.'}))));
        paintSel();
        paintBar();
        paintPicked();
        $('sel-all').addEventListener('change', ev => { for (const r of visible()) { if (ev.target.checked) picked.add(r.page); else picked.delete(r.page); } render(); });
        requestAnimationFrame(markClamped);
    }

    // Edit: binubuksan ang lahat ng botfield ng page (sa phone, lapis lang; ang pangalan ay nasa aria-label).
    function editButton(r) {
        const b = el('button', {type: 'button', class: 'mt-1 inline-flex items-center gap-1 rounded border border-slate-300 px-1.5 py-0.5 text-xs font-normal text-slate-600 hover:bg-slate-50 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500',
            'aria-label': 'Edit ' + r.page, title: 'Edit ' + r.page}, [el('span', {'aria-hidden': 'true', text: '✎'}), el('span', {class: 'hidden sm:inline', text: 'Edit'})]);
        b.addEventListener('click', () => openPe(r));
        return b;
    }

    function previewButton(r) {
        const b = el('button', {type: 'button', class: 'mt-1 inline-flex items-center gap-1 rounded border border-slate-300 px-1.5 py-0.5 text-xs font-normal text-slate-600 hover:bg-slate-50 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500',
            'aria-label': 'Preview ' + r.page, title: 'Preview what a customer sees'}, [el('span', {'aria-hidden': 'true', text: '👁'}), el('span', {class: 'hidden sm:inline', text: 'Preview'})]);
        b.addEventListener('click', () => openPreview(r.page));
        return b;
    }

    // ---- Check column ----
    // Ang mga icon na gamit sa Likha (gaya ng Check sa page settings): bilog na may check, bilog na may X, o tatsulok na
    // babala (amber: ang content check na hindi sigurado).
    const ICON_PATHS = {ok: '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>', x: '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
        warn: '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/>'};
    const ICON_COLORS = {ok: 'text-green-600', x: 'text-red-600', warn: 'text-amber-600'};
    function icon(kind, label) {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        for (const [k, v] of Object.entries({viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': '2', 'stroke-linecap': 'round', 'stroke-linejoin': 'round',
            class: 'h-4 w-4 shrink-0 ' + ICON_COLORS[kind], role: 'img', 'aria-label': label})) svg.setAttribute(k, v);
        svg.innerHTML = ICON_PATHS[kind]; // nakapirming SVG, walang data mula sa labas
        return svg;
    }
    const checks = new Map(), checking = new Set();
    function checkCell(r) {
        const td = el('td', {class: 'border-b border-slate-100 px-3 py-2 align-top text-xs', 'data-check': r.page});
        fillCheck(td, r.page);
        return td;
    }
    function fillCheck(td, page) {
        const c = checks.get(page), out = [];
        if (checking.has(page) || !c) out.push(el('span', {class: 'text-slate-400', text: c?.error || 'Checking…'}));
        else if (c.status === 'none') out.push(el('span', {class: 'text-slate-400', text: '—', title: 'No flow picked'}));
        else if (c.status === 'ok' && !c.warnings?.length) out.push(el('div', {class: 'flex items-start gap-1'}, [icon('ok', 'Check passed'), el('span', {class: 'text-green-700', text: 'All values pass'})]));
        else if (c.status !== 'ok') out.push(el('div', {class: 'flex items-start gap-1'}, [icon('x', 'Check failed'), el('ul', {class: 'min-w-0 space-y-0.5 text-red-700'}, c.reasons.map(t => el('li', {class: 'break-words', text: t})))]));
        // Ang content check na hindi sigurado: babala (amber), hindi X.
        if (c?.warnings?.length) out.push(el('div', {class: 'flex items-start gap-1' + (c.status === 'ok' ? '' : ' mt-1')}, [icon('warn', 'Check warning'), el('ul', {class: 'min-w-0 space-y-0.5 text-amber-700'}, c.warnings.map(t => el('li', {class: 'break-words', text: t})))]));
        if (c?.unchecked) out.push(el('div', {class: 'text-slate-500', text: c.unchecked + ' image link' + (c.unchecked === 1 ? '' : 's') + ' not checked yet'}));
        if (c?.content?.unchecked) out.push(el('div', {class: 'text-slate-500', text: c.content.unchecked + ' content check' + (c.content.unchecked === 1 ? '' : 's') + ' not done yet'}));
        // Ang item na pinagkukumparahan at kung saan galing (orders, ads o item settings).
        const exp = c?.content?.expected;
        if (exp) out.push(el('div', {class: 'text-slate-500 break-words', text: 'Item: ' + exp.item + (exp.price !== null ? ' ₱' + exp.price : '') + ' (' + ({orders: 'orders, 3 days', ads: 'ads', item_settings: 'item settings'}[exp.source] || exp.source) + ')'}));
        if (c && !checking.has(page) && c.status) {
            const again = el('button', {type: 'button', class: 'text-blue-700 underline hover:text-blue-900', text: 'Check again', 'aria-label': 'Check ' + page + ' again'});
            again.addEventListener('click', ev => { ev.stopPropagation(); runCheck(page, true); });
            out.push(el('div', {class: 'mt-1 flex flex-wrap items-center gap-x-2 text-slate-400'}, [c.checked_at ? el('span', {text: c.checked_at}) : null, again]));
        }
        td.replaceChildren(...out);
    }
    function paintCheck(page) {
        table.querySelectorAll('td[data-check]').forEach(td => { if (td.dataset.check === page) fillCheck(td, page); });
    }
    async function runCheck(page, again = false) {
        checking.add(page); paintCheck(page);
        try {
            const res = again ? await post(url.checkUrl, {page_name: page})
                : await fetch(url.checkUrl + '?page_name=' + encodeURIComponent(page), {headers: {'Accept': 'application/json'}}).then(async r => ({ok: r.ok, status: r.status, data: await r.json().catch(() => ({}))}));
            checks.set(page, res.ok ? res.data : {error: 'Could not check (' + res.status + ')'});
        } catch (e) {
            checks.set(page, {error: 'Could not check: no connection'});
        }
        checking.delete(page); paintCheck(page);
    }
    // Tatlo nang sabay: ang Check ng page na may larawan ay maaaring ilang segundo (HEAD ng bawat link).
    function queueChecks(pages) {
        const todo = [...pages];
        const next = () => { const p = todo.shift(); return p === undefined ? null : runCheck(p).then(next); };
        for (let i = 0; i < 3; i++) next();
    }

    // ---- Preview, History, Changes (mga modal sa ibabaw) ----
    function dialog(boxId, show) {
        $(boxId).classList.toggle('hidden', !show);
        if (show) $(boxId).querySelector('[data-close-dialog]').focus();
    }
    document.querySelectorAll('[data-close-dialog]').forEach(b => b.addEventListener('click', () => dialog(b.closest('[role="dialog"]').id, false)));
    ['pv', 'hist', 'chg'].forEach(d => $(d).addEventListener('click', e => { if (e.target === $(d)) dialog(d, false); }));
    const getJson = u => fetch(u, {headers: {'Accept': 'application/json'}}).then(async r => ({ok: r.ok, status: r.status, data: await r.json().catch(() => ({}))}));
    /** Ang value na 3 linya kapag sarado, may "Show all" kapag may kasunod pa. */
    function folded(text, cls = '') {
        const box = el('div', {class: 'cb-fold ' + cls, text: text === '' ? '(empty)' : text});
        const wrap = el('div', {class: 'min-w-0'}, box);
        requestAnimationFrame(() => {
            if (box.scrollHeight <= box.clientHeight + 1) return;
            const more = el('button', {type: 'button', class: 'text-xs text-blue-700 underline', text: 'Show all'});
            more.addEventListener('click', () => { const open = box.classList.toggle('cb-unfold'); more.textContent = open ? 'Show less' : 'Show all'; });
            wrap.append(more);
        });
        return wrap;
    }

    async function openPreview(page) {
        $('pv-title').textContent = 'Preview · ' + page;
        $('pv-sub').textContent = 'Loading…';
        $('pv-list').replaceChildren();
        dialog('pv', true);
        const {ok, status, data} = await getJson(url.previewUrl + '?page_name=' + encodeURIComponent(page));
        if (!ok) { $('pv-sub').textContent = data.error || 'Could not load (' + status + ').'; return; }
        $('pv-sub').textContent = 'As the customer "' + data.sample + '" would see it. Read-only.';
        if (!data.items.length) { $('pv-list').append(el('p', {class: 'text-slate-500', text: 'No main texts or Seq values yet.'})); return; }
        $('pv-list').replaceChildren(...data.items.map(it => el('section', {}, [
            el('div', {class: 'mb-1 text-xs font-medium text-slate-500', text: it.label}),
            it.image ? el('img', {src: it.image, alt: it.label, loading: 'lazy', referrerpolicy: 'no-referrer', class: 'mb-1 max-h-48 max-w-[80%] rounded-2xl border border-slate-200 object-contain'}) : null,
            it.text !== null ? el('div', {class: 'max-w-[85%] whitespace-pre-line break-words rounded-2xl bg-white px-3 py-2 shadow-sm ring-1 ring-slate-200', text: it.text}) : null,
        ])));
    }
    $('pe-preview').addEventListener('click', () => pe && openPreview(pe.r.page));

    let hist = null; // {page, key, label}
    async function openHistory(page, c) {
        hist = {page, key: c.key, label: c.label};
        $('hist-title').textContent = 'History · ' + c.label;
        $('hist-sub').textContent = page;
        $('hist-note').textContent = '';
        dialog('hist', true);
        await loadHistory();
    }
    async function loadHistory() {
        $('hist-list').replaceChildren(el('li', {class: 'py-2 text-slate-500', text: 'Loading…'}));
        const {ok, status, data} = await getJson(url.historyUrl + '?page_name=' + encodeURIComponent(hist.page) + '&key=' + encodeURIComponent(hist.key));
        if (!ok) { $('hist-list').replaceChildren(el('li', {class: 'py-2 text-red-600', text: data.error || 'Could not load (' + status + ').'})); return; }
        if (!data.versions.length) { $('hist-list').replaceChildren(el('li', {class: 'py-2 text-slate-500', text: 'No versions yet.'})); return; }
        $('hist-list').replaceChildren(...data.versions.map(v => {
            const restore = v.restorable ? el('button', {type: 'button', class: 'cb-btn cb-btn-sm', text: 'Restore', 'aria-label': 'Restore the version of ' + v.at}) : null;
            restore?.addEventListener('click', () => restoreVersion(v, restore));
            return el('li', {class: 'py-2'}, [
                el('div', {class: 'mb-1 flex flex-wrap items-center gap-x-2 text-xs text-slate-500'}, [
                    el('span', {class: 'rounded px-1.5 py-0.5 font-medium ' + (v.current ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-600'), text: v.current ? 'Current' : 'Old'}),
                    el('span', {text: v.by}), el('span', {text: v.at || ''}), restore ? el('span', {class: 'ml-auto'}, restore) : null,
                ]),
                folded(v.value, v.secret ? 'italic text-slate-400' : ''),
            ]);
        }));
    }
    async function restoreVersion(v, btn) {
        if (!confirm('Restore this ' + hist.label + ' value for ' + hist.page + '? The current value stays in the history.')) return;
        btn.disabled = true;
        const {ok, status, data} = await post(url.restoreUrl, {id: v.id});
        if (!ok) { btn.disabled = false; $('hist-note').className = 'px-4 pt-2 text-xs text-red-600'; $('hist-note').textContent = data.error || 'Not restored (' + status + ').'; return; }
        $('hist-note').className = 'px-4 pt-2 text-xs text-green-700';
        $('hist-note').textContent = data.status === 'unchanged' ? 'That is already the current value.' : 'Restored. It is now the current value.';
        // Ang bukas na Edit ng page: ang field na ito ay ang naibalik na ngayon (hindi "binago").
        const r = rows.find(x => x.page === hist.page);
        if (r) r.cells[data.key] = data.value;
        if (pe && pe.r.page === hist.page && peInput(data.key)) { pe.orig[data.key] = data.value; peInput(data.key).value = data.value; grow(peInput(data.key)); paintPe(); }
        render();
        await loadHistory();
    }

    let chgPage = 1, chgLists = false;
    async function loadChanges() {
        const f = new FormData($('chg-filters')), q = new URLSearchParams();
        for (const [k, v] of f.entries()) if (v) q.set(k, v);
        q.set('p', chgPage);
        $('chg-list').replaceChildren(el('li', {class: 'py-2 text-slate-500', text: 'Loading…'}));
        const {ok, status, data} = await getJson(url.changesUrl + '?' + q);
        if (!ok) { $('chg-list').replaceChildren(el('li', {class: 'py-2 text-red-600', text: data.error || 'Could not load (' + status + ').'})); return; }
        if (!chgLists) {
            const fill = (name, items) => $('chg-filters').elements[name].append(...items.map(([v, t]) => el('option', {value: v, text: t})));
            fill('page', data.pages.map(p => [p, p]));
            fill('user', data.users.map(u => [u.id, u.name]));
            fill('field', data.fields.map(x => [x.key, x.label]));
            chgLists = true;
        }
        chgPage = data.page;
        $('chg-count').textContent = data.total ? 'Page ' + data.page + ' of ' + data.last_page + ' · ' + plural(data.total, 'change') : 'No changes match.';
        $('chg-prev').disabled = data.page <= 1;
        $('chg-next').disabled = data.page >= data.last_page;
        $('chg-list').replaceChildren(...data.entries.map(e => el('li', {class: 'py-2'}, [
            el('div', {class: 'mb-1 flex flex-wrap gap-x-2 text-xs text-slate-500'}, [el('span', {text: e.at || ''}), el('span', {text: e.by}), el('span', {class: 'font-medium text-slate-800', text: e.page}), el('span', {class: 'font-medium text-slate-700', text: e.field})]),
            el('div', {class: 'grid gap-1 sm:grid-cols-[1fr_auto_1fr] sm:gap-2'}, [
                e.old === null ? el('div', {class: 'text-xs italic text-slate-400', text: '(new)'}) : folded(e.old, 'text-slate-500 line-through decoration-slate-300'),
                el('span', {class: 'text-slate-400', 'aria-label': 'changed to', text: '→'}),
                folded(e.new, e.new === '(hidden)' ? 'italic text-slate-400' : 'text-slate-900'),
            ]),
        ])));
        $('chg-list').scrollTop = 0;
    }
    $('changes-open').addEventListener('click', () => { dialog('chg', true); chgPage = 1; loadChanges(); });
    $('chg-filters').addEventListener('change', () => { chgPage = 1; loadChanges(); });
    $('chg-filters').addEventListener('submit', e => e.preventDefault());
    $('chg-prev').addEventListener('click', () => { chgPage--; loadChanges(); });
    $('chg-next').addEventListener('click', () => { chgPage++; loadChanges(); });

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
        td.append(b, providerSelect(r));
        if (r.auto_switched) td.append(autoSwitchNote(r.auto_switched));
        td.append(forbeastBox(r));
        const err = errors.get(r.page);
        if (err) td.append(el('div', {class: 'mt-1 max-w-[12rem] text-xs text-red-600 break-words', text: err}));
        return td;
    }

    // Ang Forbeast token ng page (gaya ng Botcake token): "Forbeast connected" o ang status, saka Add/New token (i-paste),
    // Test at Clear. Huling 4 lang ng token ang galing sa server.
    function forbeastBox(r) {
        const f = r.forbeast || {}, box = el('div', {class: 'mt-1 max-w-[12rem] text-xs'});
        const bad = f.status && !f.status.startsWith('ok');
        box.append(el('div', {class: 'break-words ' + (f.connected ? 'text-green-700' : (bad ? 'text-red-600' : 'text-slate-500')),
            text: (f.connected ? 'Forbeast connected' : (f.status || (f.token ? 'Forbeast: not tested' : 'No Forbeast token'))) + (f.token ? ' (' + f.token + ')' : ''), title: f.status || ''}));
        const btn = (text, label, fn) => {
            const b = el('button', {type: 'button', class: 'mr-1 mt-0.5 rounded border border-slate-300 px-1 text-[11px] text-slate-600 hover:bg-slate-50', text, 'aria-label': label + ', ' + r.page});
            b.addEventListener('click', () => fn(b));
            return b;
        };
        box.append(btn(f.token ? 'New token' : 'Add token', 'Forbeast token', b => {
            const token = (prompt('Paste the Forbeast token of ' + r.page + ' (starts with fbp_). It is tested and the page is connected right away.') || '').trim();
            if (token) forbeastSave(r, b, {token});
        }));
        if (f.token) {
            box.append(btn('Test', 'Test the Forbeast token', b => forbeastSave(r, b, {})));
            box.append(btn('Clear', 'Clear the Forbeast token', b => { if (confirm('Remove the Forbeast token of ' + r.page + ' and disconnect the page from Forbeast?')) forbeastSave(r, b, {clear: true}); }));
        }
        return box;
    }
    async function forbeastSave(r, b, body) {
        b.disabled = true; b.textContent = '…';
        try {
            const {ok, data} = await post(url.forbeastTokenUrl, Object.assign({page_name: r.page}, body));
            if (!ok) errors.set(r.page, data.error || data.message || 'Not changed.');
            else if (data.forbeast?.error) errors.set(r.page, data.forbeast.error);
            else errors.delete(r.page);
        } catch (e) { errors.set(r.page, 'No connection: not changed.'); }
        load(); // ang bagong token, status at "Replies via" ng row mula sa server
    }

    function pickBox(r) {
        const c = el('input', {type: 'checkbox', class: 'mr-1 align-middle', 'aria-label': 'Select ' + r.page});
        c.checked = picked.has(r.page);
        c.addEventListener('change', () => { if (c.checked) picked.add(r.page); else picked.delete(r.page); paintPicked(); });
        return c;
    }
    function paintPicked() {
        const list = visible();
        const all = $('sel-all'), n = list.filter(r => picked.has(r.page)).length;
        if (all) { all.checked = list.length > 0 && n === list.length; all.indeterminate = n > 0 && n < list.length; }
        const sel = [...picked].filter(p => rows.some(r => r.page === p));
        $('bulk-apply').disabled = !sel.length || !url.providerUrl;
        $('bulk-apply').textContent = sel.length ? 'Apply to ' + sel.length + ' selected' : 'Apply to selected';
    }
    $('bulk-apply').addEventListener('click', async () => {
        const names = [...picked].filter(p => rows.some(r => r.page === p)), want = $('bulk-provider').value;
        if (!names.length) return;
        const notReady = want === 'gateway' ? names.filter(p => !rows.find(r => r.page === p)?.forbeast?.ready) : [];
        if (notReady.length) { notice(NEEDS_TOKEN + ': ' + notReady.join(', '), 'text-red-600'); return; }
        const label = want === 'gateway' ? 'Forbeast' : 'Botcake';
        if (!confirm('Send and receive the chats of these ' + names.length + ' page' + (names.length === 1 ? '' : 's') + ' through ' + label + '?\n\n' + names.join('\n'))) return;
        $('bulk-apply').disabled = true;
        try {
            const {ok, data} = await post(url.providerUrl, {page_names: names, provider: want});
            if (ok) { rows.forEach(r => { if (names.includes(r.page)) { r.provider = data.provider; r.auto_switched = null; } }); picked.clear(); notice(names.length + ' page' + (names.length === 1 ? '' : 's') + ' now reply via ' + label + '.', 'text-green-700'); }
            else notice(data.error || data.message || 'Not changed.', 'text-red-600');
        } catch (e) { notice('No connection: not changed.', 'text-red-600'); }
        render();
    });

    // Kusang lumipat sa Botcake dahil pumalya ang Forbeast: ang oras, at ang dahilan sa loob (keyboard din). Nawawala kapag
    // may nagpalit nang mano-mano.
    function autoSwitchNote(a) {
        return el('details', {class: 'mt-1 max-w-[12rem] text-[11px] text-amber-700'}, [
            el('summary', {class: 'cursor-pointer', text: 'Auto-switched ' + a.at, title: a.reason}),
            el('div', {class: 'break-words text-slate-600', text: a.reason}),
        ]);
    }

    // Kung saan dumadaan ang chat at sagot ng page: Botcake o ang Forbeast (hindi mapipili kapag hindi pa naka-set up, o
    // walang gumaganang Forbeast token ang page at walang global key).
    function providerSelect(r) {
        const ready = gatewayReady && !!r.forbeast?.ready;
        const sel = el('select', {class: 'mt-1 block w-full max-w-[9rem] rounded border border-slate-300 px-1 py-0.5 text-xs', 'aria-label': 'Replies via, ' + r.page, title: ready ? 'Replies via' : NEEDS_TOKEN}, [
            el('option', {value: 'botcake', text: 'Botcake'}),
            el('option', {value: 'gateway', text: 'Forbeast' + (!gatewayReady ? ' (not set up)' : (ready ? '' : ' (add the token first)')), disabled: ready ? null : ''}),
        ]);
        sel.value = r.provider || 'botcake';
        sel.addEventListener('change', async () => {
            const want = sel.value;
            if (!confirm('Send and receive ' + r.page + "'s chats through " + (want === 'gateway' ? 'Forbeast' : 'Botcake') + '?')) { sel.value = r.provider; return; }
            sel.disabled = true;
            try {
                const {ok, data} = await post(url.providerUrl, {page_name: r.page, provider: want});
                if (ok) { r.provider = data.provider; r.auto_switched = null; errors.delete(r.page); } else errors.set(r.page, data.error || data.message || 'Not changed.');
            } catch (e) { errors.set(r.page, 'No connection: not changed.'); }
            render();
        });
        return sel;
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
            // Ang pagpalit ng flow ay nagbabago ng sagot ng bot sa page: kumpirmahin muna; kapag Cancel, ibalik ang dati.
            let before = sel.value;
            sel.addEventListener('change', () => {
                if (sel.value === UNLINKED) return;
                const from = before === '' ? '— none —' : (before === UNLINKED ? 'Not linked: ' + r.flow_text : before);
                const to = sel.value === '' ? '— none —' : sel.value;
                if (!confirm('Change the flow of ' + r.page + '?\n\nFrom: ' + from + '\nTo: ' + to)) { sel.value = before; return; }
                before = sel.value;
                r.flow_text = null; save(r, c, sel.value);
            });
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
        // Ang modal sa ibabaw (Preview, History, Changes) muna; ang Edit sa ilalim ay naiiwang bukas.
        const top = ['hist', 'pv', 'chg'].find(d => !$(d).classList.contains('hidden'));
        if (top) { dialog(top, false); return; }
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
            const history = el('button', {type: 'button', class: 'text-xs text-blue-700 underline hover:text-blue-900', text: 'History', 'aria-label': 'History of ' + c.label});
            history.addEventListener('click', () => openHistory(r.page, c));
            return el('div', {class: 'pe-field py-2', 'data-key': c.key}, [
                el('div', {class: 'mb-1 flex items-center justify-between gap-2'}, [el('label', {for: fid, class: 'text-xs font-medium text-slate-600', text: c.label}), history]),
                box, el('div', {class: 'pe-was'})]);
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
