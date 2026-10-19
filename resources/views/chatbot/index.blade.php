@extends('layouts.chatblast')

@section('title', 'Chatbot · Pages')
@section('heading', 'Chatbot')

@section('description')
    One row per page with a Botcake ID and token: switch the bot On or Off and set the values its replies will use. Values save as you type.
@endsection

@push('styles')
<style>
    /* Ang table lang ang nag-i-scroll (pataas at patagilid sa loob ng kahon); ang page ay hindi kailanman patagilid. */
    #cb-box { max-height: calc(100vh - 15rem); min-height: 18rem; }
    #cb-table th, #cb-table td { background: #fff; }
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
<div id="cbot"
     data-data-url="{{ route('chatbot.data') }}"
     data-cell-url="{{ route('chatbot.cell') }}"
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
        <span id="count" class="text-xs text-slate-500"></span>
    </div>
    <p id="notice" class="mb-2 min-h-[1rem] text-xs" aria-live="polite"></p>

    <div id="cb-box" class="cb-card overflow-auto">
        <table id="cb-table" class="text-sm" style="border-collapse: separate; border-spacing: 0;"></table>
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

<script>
(function () {
    const box = document.getElementById('cbot'), url = box.dataset, $ = id => document.getElementById(id);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const PAUSE_MS = 800, table = $('cb-table');
    let columns = [], rows = [], flows = [], editing = null;
    const marks = new Map(), seqs = new Map(), errors = new Map();
    let openRow = null; // ang page ng row na bukas (isa lang)

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
            el('th', {class: 'stick-1 w-32 min-w-[8rem] sm:w-48 sm:min-w-[12rem] border-b border-slate-200 px-3 py-2 text-left text-xs font-medium uppercase text-slate-500', text: 'Page'}),
            el('th', {class: 'stick-2 min-w-[6rem] w-24 border-b border-slate-200 px-3 py-2 text-left text-xs font-medium uppercase text-slate-500', text: 'Active'}),
            el('th', {class: 'stick-3 min-w-[10rem] w-40 border-b border-slate-200 px-3 py-2 text-left text-xs font-medium uppercase text-slate-500', text: 'Flow'}),
            el('th', {class: 'min-w-[5rem] border-b border-slate-200 px-3 py-2 text-left text-xs font-medium uppercase text-slate-500', text: 'Chats today'}),
            el('th', {class: 'min-w-[8rem] border-b border-slate-200 px-3 py-2 text-left text-xs font-medium uppercase text-slate-500', text: 'Last chat'}),
            ...columns.filter(c => c.key !== 'flow').map(c => el('th', {class: 'min-w-[12rem] border-b border-slate-200 px-3 py-2 text-left text-xs font-medium text-slate-500', text: c.label})),
        ]);
        const list = visible();
        const body = el('tbody', {}, list.map(r => el('tr', {'data-page': r.page, class: r.page === openRow ? 'cb-open' : null}, [
            el('td', {class: 'stick-1 border-b border-slate-100 px-3 py-2 align-top font-medium break-words'}, [r.page, el('div', {class: 'font-mono text-xs text-slate-400', text: r.page_id || ''})]),
            activeCell(r),
            cell(r, columns.find(c => c.key === 'flow'), 'stick-3'),
            el('td', {class: 'border-b border-slate-100 px-3 py-2 align-top', text: String(r.today)}),
            el('td', {class: 'border-b border-slate-100 px-3 py-2 align-top text-xs text-slate-500', text: r.last_chat || '—'}),
            ...columns.filter(c => c.key !== 'flow').map(c => cell(r, c)),
        ])));
        table.replaceChildren(el('thead', {}, head), body);
        $('count').textContent = list.length + ' of ' + rows.length + ' page' + (rows.length === 1 ? '' : 's');
        if (!rows.length) table.append(el('tbody', {}, el('tr', {}, el('td', {class: 'px-3 py-3 text-slate-500', colspan: '5', text: 'No page has a Botcake ID and token yet.'}))));
        requestAnimationFrame(markClamped);
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
        const value = r.cells[c.key] ?? '';
        const k = id(r.page, c.key), m = marks.get(k);
        // FLOW: dropdown ng mga flow (/chatbot); ang luma na text na wala sa listahan ay ipinapakita pa rin.
        if (c.kind === 'flow') {
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
        const td = el('td', {class: extra + ' cb-cell border-b border-slate-100 px-3 py-2 align-top', tabindex: '0', 'data-key': c.key});
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
        td.addEventListener('click', ev => { if (!ev.target.closest('input')) toggleRow(r.page); });
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
        modal('col-box', false); modal('page-box', false);
    });
    $('f-q').addEventListener('input', render);
    $('f-state').addEventListener('change', render);
    load();
})();
</script>
@endsection
