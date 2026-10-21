@extends('layouts.chatblast')

@section('title', 'Chatbot · Inbox')
@section('heading', 'Inbox')

@section('description')
    Each customer's chats, the bot's replies and sheet rows in one thread, with the customer's fields beside it. Refreshes every 5 seconds.
@endsection

@push('styles')
<style>
    /* Tatlong column na sabay sa desktop; ang mga listahan lang sa loob ang nag-i-scroll. Ang grid ang pumupuno sa
       natitirang taas ng screen (.cb-page ng layout), kaya ang page ay hindi nag-i-scroll. */
    #ib-grid { min-height: 28rem; flex: 1 1 0; }
    #ib-grid > section { min-height: 0; }
    /* Sa phone: isang column lang, ayon sa data-view. */
    @media (max-width: 1023px) {
        #ib-grid[data-view="list"] > section:not(#ib-list),
        #ib-grid[data-view="thread"] > section:not(#ib-thread),
        #ib-grid[data-view="panel"] > section:not(#ib-panel) { display: none; }
    }
    @media (min-width: 1024px) { #ib .ib-back { display: none; } }
    .ib-bubble { max-width: min(36rem, 85%); }
    .ib-bubble .ib-time { display: none; }
    .ib-bubble:hover .ib-time, .ib-bubble.ib-show-time .ib-time, .ib-bubble:focus-within .ib-time { display: block; }
</style>
@endpush

@section('content')
@include('chatbot.partials.switch', ['repliesOn' => $repliesOn])

@if(!$pages)
    <p class="cb-card px-4 py-3 text-sm text-slate-600">No page has a Botcake ID yet. Add one in <a href="{{ url('/chatblast/page-settings') }}" class="text-blue-700 hover:underline">Page settings</a>.</p>
@else
<div id="ib" class="cb-page" data-list-url="{{ route('chatbot.inbox.conversations') }}" data-thread-url="{{ route('chatbot.inbox.thread') }}" data-panel-url="{{ route('chatbot.inbox.panel') }}"
     data-send-url="{{ route('chatbot.inbox.send') }}" data-pause-url="{{ route('chatbot.inbox.pause') }}" data-roles-url="{{ route('chatbot.inbox.roles') }}" data-sequence-url="{{ route('chatbot.inbox.sequence') }}"
     data-fields-url="{{ route('chatbot.customer-fields') }}" data-pages-url="{{ route('chatbot.pages.index') }}"
     data-can-send="{{ $canSend ? '1' : '0' }}" data-user="{{ auth()->user()?->name ?: 'You' }}">

    <div id="ib-grid" class="grid grid-cols-1 gap-3 lg:grid-cols-[18rem_minmax(0,1fr)_20rem]" data-view="list">
        {{-- Kaliwa: page, hanap, tabs at ang listahan ng usapan --}}
        <section id="ib-list" class="cb-card flex flex-col overflow-hidden" aria-label="Conversations">
            <div class="space-y-2 border-b border-slate-200 p-3">
                <div class="flex items-center gap-2">
                    <label class="sr-only" for="ib-page">Page</label>
                    <select id="ib-page" class="min-w-0 flex-1 rounded-md border border-slate-300 px-2 py-1 text-sm">
                        @foreach($pages as $name => $id)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                    @if($isCeo)
                        <button type="button" id="ib-gear" class="cb-btn cb-btn-sm" aria-haspopup="dialog" aria-label="Who can reply" title="Who can reply">⚙</button>
                    @endif
                </div>
                <div class="flex items-center gap-2">
                    <label class="sr-only" for="ib-q">Search</label>
                    <input id="ib-q" type="search" maxlength="100" placeholder="Name or PSID" class="min-w-0 flex-1 rounded-md border border-slate-300 px-2 py-1 text-sm">
                    <label class="sr-only" for="ib-window">Time</label>
                    <select id="ib-window" class="rounded-md border border-slate-300 px-1 py-1 text-sm">
                        <option value="24h">24h</option><option value="7d" selected>7d</option><option value="30d">30d</option>
                    </select>
                </div>
                <div class="flex gap-1 text-xs" role="tablist" aria-label="Who answers">
                    <button type="button" role="tab" data-tab="" class="ib-tab rounded-md px-2 py-1" aria-selected="true">All</button>
                    <button type="button" role="tab" data-tab="human" class="ib-tab rounded-md px-2 py-1" aria-selected="false">Human</button>
                    <button type="button" role="tab" data-tab="bot" class="ib-tab rounded-md px-2 py-1" aria-selected="false">Bot</button>
                </div>
            </div>
            <ul id="ib-rows" class="min-h-0 flex-1 divide-y divide-slate-100 overflow-y-auto"></ul>
            <p id="ib-list-note" class="border-t border-slate-100 px-3 py-1.5 text-xs text-slate-500" aria-live="polite"></p>
        </section>

        {{-- Gitna: ang usapan at ang reply box --}}
        <section id="ib-thread" class="cb-card flex flex-col overflow-hidden" aria-label="Conversation">
            <div class="flex items-center gap-2 border-b border-slate-200 px-3 py-2">
                <button type="button" class="ib-back cb-btn cb-btn-sm" data-go="list">← Chats</button>
                <div class="min-w-0 flex-1">
                    <div id="ib-who" class="truncate font-semibold">Pick a conversation</div>
                    <div id="ib-who-sub" class="truncate text-xs text-slate-500"></div>
                </div>
                <button type="button" class="ib-back cb-btn cb-btn-sm" data-go="panel" id="ib-to-panel" disabled>Customer →</button>
            </div>
            <div class="relative min-h-0 flex-1">
                <div id="ib-scroll" class="absolute inset-0 overflow-y-auto px-3 py-2" aria-live="polite">
                    <div class="py-1 text-center"><button type="button" id="ib-older" class="cb-btn cb-btn-sm hidden">Load older</button></div>
                    <ol id="ib-items" class="space-y-1.5"></ol>
                </div>
                <button type="button" id="ib-pill" class="absolute bottom-3 left-1/2 hidden -translate-x-1/2 rounded-full bg-blue-600 px-3 py-1 text-xs font-medium text-white shadow">New messages ↓</button>
            </div>
            <form id="ib-reply" class="hidden border-t border-slate-200 p-2" autocomplete="off">
                <p id="ib-window-note" class="mb-1 hidden rounded-md bg-amber-50 px-2 py-1 text-xs text-amber-900">Outside Messenger's 24-hour window: the customer must message first.</p>
                <label class="sr-only" for="ib-text">Message</label>
                <textarea id="ib-text" rows="2" maxlength="2000" placeholder="Type a reply (Enter sends, Shift+Enter for a new line)" class="w-full resize-y rounded-md border border-slate-300 px-2 py-1 text-sm"></textarea>
                <div class="mt-1 flex flex-wrap items-center gap-2">
                    <button type="button" id="ib-attach" class="cb-btn cb-btn-sm" aria-expanded="false" aria-controls="ib-image-row">Attach image URL</button>
                    <span id="ib-send-note" class="min-w-0 flex-1 text-xs" aria-live="polite"></span>
                    <button id="ib-send" class="cb-btn cb-btn-primary cb-btn-sm">Send</button>
                </div>
                <div id="ib-image-row" class="mt-1 hidden items-center gap-2">
                    <label class="sr-only" for="ib-image">Image URL</label>
                    <input id="ib-image" type="url" maxlength="2000" placeholder="https://…" class="min-w-0 flex-1 rounded-md border border-slate-300 px-2 py-1 text-sm">
                    <img id="ib-image-preview" alt="Preview" referrerpolicy="no-referrer" class="hidden h-10 w-10 rounded object-cover">
                </div>
            </form>
        </section>

        {{-- Kanan: ang customer --}}
        <section id="ib-panel" class="cb-card flex flex-col overflow-hidden" aria-label="Customer">
            <div class="flex items-center gap-2 border-b border-slate-200 px-3 py-2">
                <button type="button" class="ib-back cb-btn cb-btn-sm" data-go="thread">← Chat</button>
                <div class="font-semibold">Customer</div>
            </div>
            <div id="ib-panel-body" class="min-h-0 flex-1 overflow-y-auto px-3 py-2 text-sm"><p class="text-slate-500">Pick a conversation.</p></div>
            <label class="flex items-center gap-2 border-t border-slate-200 px-3 py-2 text-sm">
                <input type="checkbox" id="ib-events"> View executed actions
            </label>
        </section>
    </div>

    <div id="ib-modal" class="fixed inset-0 z-50 hidden overflow-y-auto bg-slate-900/30 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="ib-modal-title">
        <div class="mx-auto w-full max-w-2xl rounded-xl bg-white p-5 text-sm shadow-lg">
            <div class="mb-3 flex items-center justify-between gap-2"><div id="ib-modal-title" class="break-words font-semibold"></div>
                <button type="button" id="ib-modal-close" class="px-2 text-slate-500 hover:text-slate-800" aria-label="Close">✕</button></div>
            <div id="ib-modal-body"></div>
        </div>
    </div>

    @if($isCeo)
    <div id="ib-roles" class="fixed inset-0 z-50 hidden overflow-y-auto bg-slate-900/30 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="ib-roles-title">
        <form class="mx-auto w-full max-w-md rounded-xl bg-white p-5 text-sm shadow-lg">
            <div id="ib-roles-title" class="mb-1 font-semibold">Who can reply and pause the bot</div>
            <p class="mb-3 text-xs text-slate-500">The CEO always can. Others also need access to Chatblast.</p>
            <div class="max-h-72 space-y-1 overflow-y-auto">
                <label class="flex items-center gap-2 text-slate-500"><input type="checkbox" checked disabled> CEO</label>
                @foreach($allRoles as $r)
                    @continue($r === 'CEO')
                    <label class="flex items-center gap-2"><input type="checkbox" name="roles[]" value="{{ $r }}" @checked(in_array($r, $replyRoles, true))> {{ $r }}</label>
                @endforeach
            </div>
            <div class="mt-3 flex items-center gap-2">
                <button class="cb-btn cb-btn-primary cb-btn-sm">Save</button>
                <button type="button" data-close class="cb-btn cb-btn-sm">Cancel</button>
                <span id="ib-roles-note" class="text-xs" aria-live="polite"></span>
            </div>
        </form>
    </div>
    @endif
</div>

<script>
(function () {
    const root = document.getElementById('ib'), $ = id => document.getElementById(id), D = root.dataset;
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const POLL = 5000, PAGE_KEY = 'chatbot-inbox:page', EVENTS_KEY = 'chatbot-inbox:events';
    const S = {page: '', tab: '', psid: null, name: '', older: null, newer: null, seen: new Set(), panel: null, pausedAsked: false, busy: false, lastDay: null};

    function el(tag, attrs = {}, children = []) {
        const e = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs)) { if (v == null || v === false) continue; if (k === 'class') e.className = v; else if (k === 'text') e.textContent = v; else e.setAttribute(k, v === true ? '' : v); }
        for (const c of [].concat(children)) if (c != null) e.append(c);
        return e;
    }
    const qs = o => { const p = new URLSearchParams(); for (const [k, v] of Object.entries(o)) { if (v == null) continue; if (typeof v === 'object') { for (const [a, b] of Object.entries(v)) if (b != null) p.set(k + '[' + a + ']', b); } else p.set(k, v); } return p.toString(); };
    const getJson = async (url, params) => { const r = await fetch(url + '?' + qs(params), {headers: {'Accept': 'application/json'}}); if (!r.ok) throw r; return r.json(); };
    const postJson = async (url, body) => {
        const r = await fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}, body: JSON.stringify(body)});
        const data = await r.json().catch(() => ({}));
        return {ok: r.ok, status: r.status, data};
    };
    const errText = (res, fallback) => res.status === 429 ? 'Too many tries. Wait a minute.' : (res.data.error || res.data.message || fallback);

    // Oras: relative sa listahan, oras lang sa bubble, at mga araw (Today / Yesterday / petsa).
    const ymd = d => d.toLocaleDateString('en-CA', {timeZone: 'Asia/Manila'});
    function dayLabel(iso) {
        const d = new Date(iso), today = ymd(new Date()), yest = ymd(new Date(Date.now() - 864e5));
        return ymd(d) === today ? 'Today' : ymd(d) === yest ? 'Yesterday' : d.toLocaleDateString('en-US', {timeZone: 'Asia/Manila', month: 'short', day: 'numeric', year: 'numeric'});
    }
    const clock = iso => new Date(iso).toLocaleTimeString('en-US', {timeZone: 'Asia/Manila', hour: 'numeric', minute: '2-digit'});
    function ago(iso) {
        if (!iso) return '';
        const s = Math.max(0, (Date.now() - new Date(iso)) / 1000);
        if (s < 60) return 'now'; if (s < 3600) return Math.floor(s / 60) + 'm'; if (s < 86400) return Math.floor(s / 3600) + 'h';
        return Math.floor(s / 86400) + 'd';
    }

    // Sa phone: isang column lang.
    const show = v => { $('ib-grid').dataset.view = v; };
    root.querySelectorAll('[data-go]').forEach(b => b.addEventListener('click', () => show(b.dataset.go)));

    // ---- Listahan ----
    let listTimer = null;
    async function loadList() {
        if (!S.page) return;
        try {
            const data = await getJson(D.listUrl, {page_id: S.page, window: $('ib-window').value, tab: S.tab, q: $('ib-q').value.trim()});
            const rows = data.rows.map(r => {
                const li = el('li', {}, el('button', {type: 'button', class: 'flex w-full items-start gap-2 px-3 py-2 text-left hover:bg-slate-50' + (r.psid === S.psid ? ' bg-blue-50' : ''), 'aria-current': r.psid === S.psid ? 'true' : null}, [
                    el('span', {class: 'mt-1.5 h-2 w-2 shrink-0 rounded-full ' + (r.waiting ? 'bg-blue-600' : 'bg-transparent'), title: r.waiting ? 'Waiting for an answer' : null}, r.waiting ? el('span', {class: 'sr-only', text: 'Unanswered'}) : null),
                    el('span', {class: 'min-w-0 flex-1'}, [
                        el('span', {class: 'flex items-baseline gap-2'}, [el('span', {class: 'min-w-0 flex-1 truncate ' + (r.waiting ? 'font-semibold' : 'font-medium'), text: r.name}),
                            el('span', {class: 'shrink-0 text-xs text-slate-500', text: ago(r.at), title: r.at ? new Date(r.at).toLocaleString('en-US', {timeZone: 'Asia/Manila'}) : null})]),
                        el('span', {class: 'block truncate text-xs text-slate-500', text: r.preview}),
                    ]),
                    r.human ? el('span', {class: 'shrink-0 rounded bg-amber-100 px-1 text-[10px] text-amber-900', text: 'Human'}) : null,
                ]));
                li.firstChild.addEventListener('click', () => openThread(r.psid, r.name));
                return li;
            });
            $('ib-rows').replaceChildren(...rows);
            $('ib-list-note').textContent = rows.length ? rows.length + (rows.length >= 200 ? '+' : '') + ' conversations' : 'No conversations in this time.';
        } catch (e) { $('ib-list-note').textContent = 'Could not load the list (' + (e.status || 'network') + ').'; }
    }
    const reloadList = () => { clearTimeout(listTimer); listTimer = setTimeout(loadList, 250); };
    $('ib-q').addEventListener('input', reloadList);
    $('ib-window').addEventListener('change', loadList);
    root.querySelectorAll('.ib-tab').forEach(b => b.addEventListener('click', () => { S.tab = b.dataset.tab; paintTabs(); loadList(); }));
    function paintTabs() { root.querySelectorAll('.ib-tab').forEach(b => { const on = b.dataset.tab === S.tab; b.setAttribute('aria-selected', on ? 'true' : 'false'); b.className = 'ib-tab rounded-md px-2 py-1 ' + (on ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'); }); }

    // ---- Usapan ----
    function bubble(x) {
        const time = el('div', {class: 'ib-time mt-0.5 text-[10px] text-slate-500', text: clock(x.at)});
        if (x.side === 'system' || x.side === 'event') {
            const icon = x.side === 'system' ? '▤' : ({run_started: '▶', run_ended: '■', condition: '◇', field: '✎', ai: '✦', sheet: '▤', waiting: '⏳', skipped: '⤼', sequence: '↻'}[x.type] || '•');
            return el('li', {class: 'ib-bubble mx-auto flex flex-col items-center text-center', tabindex: '0'}, [
                el('span', {class: 'rounded-full px-2 py-0.5 text-xs ' + (x.side === 'system' && x.status === 'failed' ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-500'), text: icon + ' ' + x.text}),
                x.link ? el('a', {href: x.link, target: '_blank', rel: 'noopener noreferrer', class: 'mt-0.5 text-xs text-blue-700 hover:underline', text: 'Open row'}) : null, time]);
        }
        if (x.side === 'in') {
            let body;
            if (x.like) body = el('span', {class: 'text-4xl leading-none', role: 'img', 'aria-label': 'Like', text: '👍'});
            else if (x.image_url) body = imageLink(x.image_url);
            else body = el('div', {class: 'whitespace-pre-line break-words rounded-2xl rounded-bl-sm bg-slate-100 px-3 py-1.5 text-sm text-slate-900', text: x.text});
            return el('li', {class: 'ib-bubble mr-auto flex flex-col items-start', tabindex: '0'}, [body, time]);
        }
        const failed = x.status !== 'sent';
        const parts = (x.messages || []).map(m => m.type === 'image' ? imageLink(m.url)
            : el('div', {class: 'whitespace-pre-line break-words rounded-2xl rounded-br-sm px-3 py-1.5 text-sm ' + (failed ? 'bg-blue-100 text-slate-700' : 'bg-blue-600 text-white'), text: m.text}));
        return el('li', {class: 'ib-bubble ml-auto flex flex-col items-end', tabindex: '0'}, [
            el('div', {class: 'mb-0.5 text-[10px] text-slate-500', text: x.by}),
            el('div', {class: 'flex flex-col items-end gap-1'}, parts),
            failed ? el('div', {class: 'mt-0.5 text-xs ' + (x.status === 'failed' ? 'text-red-700' : 'text-slate-500'), text: (x.status === 'failed' ? 'Failed' : 'Skipped') + (x.error ? ': ' + x.error : '')}) : null,
            time]);
    }
    function imageLink(url) {
        return el('a', {href: url, target: '_blank', rel: 'noopener noreferrer', class: 'block'},
            el('img', {src: url, alt: 'Image', loading: 'lazy', referrerpolicy: 'no-referrer', class: 'max-h-48 max-w-[14rem] rounded-xl border border-slate-200 object-cover'}));
    }
    function daySep(iso) { return el('li', {class: 'py-1 text-center text-[11px] font-medium uppercase tracking-wide text-slate-400', 'data-day': ymd(new Date(iso)), text: dayLabel(iso)}); }

    // Ilagay ang mga item (sunod-sunod sa oras), may hiwalay na araw; walang doble.
    function render(items, where) {
        const nodes = []; let day = where === 'top' ? null : S.lastDay;
        for (const x of items) {
            if (S.seen.has(x.key)) continue;
            S.seen.add(x.key);
            const d = ymd(new Date(x.at));
            if (d !== day) { nodes.push(daySep(x.at)); day = d; }
            nodes.push(bubble(x));
        }
        const list = $('ib-items');
        if (where === 'top') {
            // Tanggalin ang dating unang separator kapag pareho ang araw ng huling idinagdag.
            const first = list.firstElementChild;
            if (first && first.dataset.day && first.dataset.day === day) first.remove();
            list.prepend(...nodes);
        } else {
            list.append(...nodes);
            if (day) S.lastDay = day;
        }
        return nodes.length;
    }
    const nearBottom = () => { const s = $('ib-scroll'); return s.scrollHeight - s.scrollTop - s.clientHeight < 80; };
    const pinBottom = () => { const s = $('ib-scroll'); s.scrollTop = s.scrollHeight; S.pinTop = s.scrollTop; };
    const toBottom = () => { pinBottom(); S.stick = true; $('ib-pill').classList.add('hidden'); };
    $('ib-pill').addEventListener('click', toBottom);
    $('ib-scroll').addEventListener('scroll', () => {
        // Ang scroll na galing sa pinBottom ay hindi galaw ng tao: hindi nito binabago ang "nasa ibaba".
        if (S.stick && Math.abs($('ib-scroll').scrollTop - S.pinTop) < 2) return;
        S.stick = nearBottom();
        if (S.stick) { S.pinTop = $('ib-scroll').scrollTop; $('ib-pill').classList.add('hidden'); }
    });
    // Nagbabago ang taas (larawang na-load, lumitaw ang reply box): manatili sa ibaba kung doon nakatingin.
    const pin = new ResizeObserver(() => { if (S.stick) pinBottom(); });
    pin.observe($('ib-scroll')); pin.observe($('ib-items'));
    $('ib-items').addEventListener('click', e => { const b = e.target.closest('.ib-bubble'); if (b && !e.target.closest('a')) b.classList.toggle('ib-show-time'); });

    const eventsOn = () => $('ib-events').checked;
    let threadSeq = 0;
    async function openThread(psid, name) {
        const seq = ++threadSeq;
        Object.assign(S, {psid, name, older: null, newer: null, seen: new Set(), lastDay: null, pausedAsked: false});
        $('ib-who').textContent = name; $('ib-who-sub').textContent = 'PSID ' + psid;
        $('ib-items').replaceChildren(); $('ib-older').classList.add('hidden'); $('ib-to-panel').disabled = false;
        $('ib-text').value = ''; $('ib-image').value = ''; preview(); $('ib-send-note').textContent = '';
        $('ib-image-row').classList.add('hidden'); $('ib-image-row').classList.remove('flex'); $('ib-attach').setAttribute('aria-expanded', 'false');
        show('thread');
        loadList();
        try {
            const data = await getJson(D.threadUrl, {page_id: S.page, psid, events: eventsOn() ? 1 : 0});
            if (seq !== threadSeq) return;
            render(data.items, 'bottom');
            S.older = data.older; S.newer = data.newer;
            $('ib-older').classList.toggle('hidden', !data.has_older);
            if (!data.items.length) $('ib-items').append(el('li', {class: 'py-6 text-center text-sm text-slate-500', text: 'No messages yet.'}));
            toBottom();
        } catch (e) { if (seq === threadSeq) $('ib-items').replaceChildren(el('li', {class: 'py-6 text-center text-sm text-red-700', text: 'Could not load the conversation (' + (e.status || 'network') + ').'})); }
        loadPanel(seq);
    }
    $('ib-older').addEventListener('click', async () => {
        if (!S.older) return;
        const s = $('ib-scroll'), h = s.scrollHeight, top = s.scrollTop, psid = S.psid;
        $('ib-older').disabled = true;
        try {
            const data = await getJson(D.threadUrl, {page_id: S.page, psid, before: S.older, events: eventsOn() ? 1 : 0});
            if (psid !== S.psid) return;
            render(data.items, 'top');
            S.older = data.older;
            $('ib-older').classList.toggle('hidden', !data.has_older);
            s.scrollTop = top + (s.scrollHeight - h);
        } catch (e) { /* subukan ulit sa susunod na click */ }
        $('ib-older').disabled = false;
    });
    async function poll() {
        if (document.hidden || !S.psid || !S.newer || S.busy) return;
        const psid = S.psid; S.busy = true;
        try {
            const data = await getJson(D.threadUrl, {page_id: S.page, psid, after: S.newer, events: eventsOn() ? 1 : 0});
            if (psid !== S.psid) return;
            const stay = nearBottom();
            const added = render(data.items, 'bottom');
            S.newer = data.newer;
            if (added) { if (stay) toBottom(); else $('ib-pill').classList.remove('hidden'); }
            if (data.items.some(x => x.side === 'in')) loadPanel(threadSeq);
        } catch (e) { /* tahimik; susubok ulit pagkalipas ng 5 segundo */ }
        finally { S.busy = false; }
    }
    setInterval(() => { poll(); if (!document.hidden) loadList(); }, POLL);

    // ---- Panel ----
    // Ang mga sequence ng customer; ang puwedeng sumagot ay nakakapag-subscribe at unsubscribe.
    function sequencesBox(p) {
        const STATUS = {active: 'bg-green-100 text-green-800', paused: 'bg-amber-100 text-amber-900', completed: 'bg-blue-100 text-blue-800', unsubscribed: 'bg-slate-200 text-slate-700', error: 'bg-red-100 text-red-800'};
        const note = el('p', {class: 'mt-1 min-h-[1rem] text-xs', 'aria-live': 'polite'});
        const act = async (action, id) => {
            const res = await postJson(D.sequenceUrl, {page_id: S.page, psid: p.psid, sequence_id: id, action});
            if (!res.ok || res.data.error) { note.className = 'mt-1 min-h-[1rem] text-xs text-red-700'; note.textContent = errText(res, 'Not saved.') || res.data.error; return; }
            loadPanel(threadSeq);
        };
        const rows = (p.sequences || []).map(s => el('li', {class: 'flex flex-wrap items-center gap-x-2 gap-y-0.5 py-1 text-xs'}, [
            el('span', {class: 'min-w-0 flex-1 break-words font-medium', text: s.name}),
            el('span', {class: 'rounded-full px-1.5 py-0.5 ' + (STATUS[s.status] || ''), text: s.status}),
            el('span', {class: 'w-full text-slate-500', text: s.status === 'active' || s.status === 'paused'
                ? 'Step ' + s.step + (s.next_send ? ' · next ' + new Date(s.next_send).toLocaleString('en-US', {timeZone: 'Asia/Manila', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'}) : '') : ''}),
            p.can_send && (s.status === 'active' || s.status === 'paused') ? (() => { const b = el('button', {type: 'button', class: 'cb-btn cb-btn-sm', text: 'Unsubscribe'});
                b.addEventListener('click', () => { if (confirm('Unsubscribe from ' + s.name + '?')) act('unsubscribe', s.id); }); return b; })() : null,
        ]));
        const out = [rows.length ? el('ul', {class: 'divide-y divide-slate-100'}, rows) : el('p', {class: 'text-xs text-slate-500', text: 'Not in any sequence.'})];
        if (p.can_send && (p.all_sequences || []).length) {
            const pick = el('select', {class: 'min-w-0 flex-1 rounded-md border border-slate-300 px-2 py-1 text-xs', 'aria-label': 'Sequence to subscribe to'},
                p.all_sequences.map(s => el('option', {value: s.id, text: s.name})));
            const go = el('button', {type: 'button', class: 'cb-btn cb-btn-sm', text: 'Subscribe'});
            go.addEventListener('click', () => act('subscribe', parseInt(pick.value, 10)));
            out.push(el('div', {class: 'mt-2 flex items-center gap-2'}, [pick, go]));
        }
        out.push(note);
        return out;
    }

    function section(title, children, open = true) {
        return el('details', {class: 'border-t border-slate-100 py-2', open}, [el('summary', {class: 'cursor-pointer text-xs font-semibold uppercase tracking-wide text-slate-500', text: title}), el('div', {class: 'mt-1.5'}, children)]);
    }
    const kv = (k, v) => [el('dt', {class: 'text-slate-500', text: k}), el('dd', {class: 'min-w-0 break-words', text: v ?? '—'})];
    async function loadPanel(seq) {
        const psid = S.psid;
        try {
            const p = await getJson(D.panelUrl, {page_id: S.page, psid});
            if (seq !== threadSeq || psid !== S.psid) return;
            S.panel = p; paintPanel(p); paintReply(p);
        } catch (e) { if (seq === threadSeq) $('ib-panel-body').replaceChildren(el('p', {class: 'text-red-700', text: 'Could not load the customer (' + (e.status || 'network') + ').'})); }
    }
    function paintPanel(p) {
        const body = [];
        body.push(el('div', {class: 'pb-2'}, [el('div', {class: 'break-words text-base font-semibold', text: p.name}),
            el('dl', {class: 'mt-1 grid grid-cols-[5.5rem_1fr] gap-x-2 gap-y-0.5 text-xs'}, [...kv('PSID', p.psid), ...kv('Ad ID', p.ad_id), ...kv('Source', p.source),
                ...kv('First seen', p.first_seen ? new Date(p.first_seen).toLocaleString('en-US', {timeZone: 'Asia/Manila'}) : null)])]));

        const status = el('div', {class: 'space-y-1'}, [
            el('div', {}, [el('span', {class: 'text-xs text-slate-500', text: 'CHAT STATUS '}), el('span', {class: 'font-medium', text: p.chat_status || '—'})]),
            p.waiting ? el('div', {class: 'text-xs text-slate-600', text: p.waiting}) : null,
        ]);
        if (p.can_send) {
            const sw = el('input', {type: 'checkbox', role: 'switch', checked: !p.bot_paused, id: 'ib-bot-switch'});
            sw.addEventListener('change', async () => {
                sw.disabled = true;
                const res = await postJson(D.pauseUrl, {page_id: S.page, psid: p.psid, paused: !sw.checked});
                sw.disabled = false;
                if (!res.ok) { sw.checked = !sw.checked; return alert(errText(res, 'Not saved.')); }
                p.bot_paused = res.data.bot_paused; sw.checked = !p.bot_paused; lbl.lastChild.textContent = p.bot_paused ? 'Off' : 'On'; loadList();
            });
            const lbl = el('label', {class: 'flex items-center gap-2 text-sm', for: 'ib-bot-switch'}, [sw, 'Bot for this customer: ', el('strong', {text: p.bot_paused ? 'Off' : 'On'})]);
            status.append(lbl);
        } else {
            status.append(el('div', {class: 'text-sm', text: 'Bot for this customer: ' + (p.bot_paused ? 'Off' : 'On')}));
        }
        body.push(section('Status', status));

        const rows = p.fields.map(f => {
            const long = f.value.length > 80 || f.value.includes('\n');
            const val = el('button', {type: 'button', class: 'block w-full truncate text-left hover:text-blue-700', text: f.value === '' ? '(empty)' : f.value, title: 'Open'});
            val.addEventListener('click', () => openField(f, p));
            return el('div', {class: 'grid grid-cols-[7rem_1fr] gap-2 border-b border-slate-50 py-0.5 text-xs'}, [
                el('span', {class: 'break-words font-mono text-slate-600', text: f.key}), long || p.can_edit ? val : el('span', {class: 'break-words', text: f.value})]);
        });
        body.push(section('Fields', rows.length ? rows : el('p', {class: 'text-xs text-slate-500', text: 'No fields yet.'})));

        const bf = p.botfields.map(b => el('div', {class: 'grid grid-cols-[7rem_1fr] gap-2 py-0.5 text-xs'}, [el('span', {class: 'break-words text-slate-600', text: b.label}),
            el('span', {class: 'line-clamp-3 whitespace-pre-line break-words', text: b.value})]));
        bf.push(el('a', {href: D.pagesUrl, class: 'mt-1 inline-block text-xs text-blue-700 hover:underline', text: 'Edit in Page settings'}));
        body.push(section('Page botfields', bf, false));

        body.push(section('Subscribed sequences', sequencesBox(p)));

        body.push(section('Flow', p.flow ? el('a', {href: p.flow.url, class: 'text-blue-700 hover:underline', text: p.flow.name + ' (open in editor)'}) : el('p', {class: 'text-xs text-slate-500', text: 'No flow on this page.'})));

        body.push(section('Executed flows/steps', p.runs.length ? el('ol', {class: 'space-y-1.5 text-xs'}, p.runs.map(r => el('li', {}, [
            el('div', {class: 'text-slate-500', text: new Date(r.at).toLocaleString('en-US', {timeZone: 'Asia/Manila'}) + (r.flow ? ' · ' + r.flow : '')}),
            r.path.length ? el('div', {class: 'break-words font-mono', text: r.path.join(' → ')}) : null,
            el('div', {class: 'break-words', text: r.result})]))) : el('p', {class: 'text-xs text-slate-500', text: 'No runs yet.'}), false));

        body.push(section('Error logs', p.errors.length ? el('ol', {class: 'space-y-1 text-xs'}, p.errors.map(x => el('li', {class: 'break-words'}, [
            el('span', {class: 'text-slate-500', text: new Date(x.at).toLocaleString('en-US', {timeZone: 'Asia/Manila'}) + ' '}), el('span', {class: 'text-red-700', text: x.text})]))) : el('p', {class: 'text-xs text-slate-500', text: 'No errors.'}), false));

        body.push(section('Media and files', p.media.length ? el('div', {class: 'grid grid-cols-3 gap-1'}, p.media.map(m => {
            const a = imageLink(m.url); a.firstChild.className = 'h-20 w-full rounded object-cover'; return a;
        })) : el('p', {class: 'text-xs text-slate-500', text: 'No images.'}), false));

        $('ib-panel-body').replaceChildren(...body);
    }

    // Field sa modal: buong laman; ang CEO ay puwedeng magpalit o magbura.
    function openField(f, p) {
        const box = [];
        if (p.can_edit) {
            const ta = el('textarea', {rows: '8', maxlength: '16000', class: 'w-full rounded-md border border-slate-300 px-2 py-1 font-mono text-sm', 'aria-label': 'Value of ' + f.key});
            ta.value = f.value;
            const note = el('span', {class: 'text-xs', 'aria-live': 'polite'});
            const save = async body => {
                const res = await postJson(D.fieldsUrl, Object.assign({page_id: S.page, psid: p.psid, key: f.key}, body));
                if (!res.ok) { note.className = 'text-xs text-red-700'; note.textContent = errText(res, 'Not saved.'); return; }
                closeModal(); loadPanel(threadSeq);
            };
            const saveBtn = el('button', {type: 'button', class: 'cb-btn cb-btn-primary cb-btn-sm', text: 'Save'});
            const clearBtn = el('button', {type: 'button', class: 'cb-btn cb-btn-sm', text: 'Clear'});
            saveBtn.addEventListener('click', () => save({value: ta.value}));
            clearBtn.addEventListener('click', () => { if (confirm('Clear ' + f.key + '?')) save({clear: true}); });
            box.push(ta, el('div', {class: 'mt-2 flex items-center gap-2'}, [saveBtn, clearBtn, note]));
        } else {
            box.push(el('div', {class: 'max-h-[60vh] overflow-y-auto whitespace-pre-wrap break-words rounded-md bg-slate-50 p-2 font-mono text-xs', text: f.value}));
        }
        openModal(f.key, box);
    }
    function openModal(title, children) { $('ib-modal-title').textContent = title; $('ib-modal-body').replaceChildren(...children); $('ib-modal').classList.remove('hidden'); $('ib-modal-close').focus(); }
    function closeModal() { $('ib-modal').classList.add('hidden'); }
    $('ib-modal-close').addEventListener('click', closeModal);
    $('ib-modal').addEventListener('click', e => { if (e.target === $('ib-modal')) closeModal(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeModal(); $('ib-roles')?.classList.add('hidden'); } });

    // ---- Reply box ----
    function paintReply(p) {
        const can = D.canSend === '1';
        $('ib-reply').classList.toggle('hidden', !can);
        if (!can) return;
        const open = p.can_reply_window;
        $('ib-window-note').classList.toggle('hidden', open);
        ['ib-text', 'ib-image', 'ib-send', 'ib-attach'].forEach(id => { $(id).disabled = !open; });
    }
    const isUrl = v => /^https?:\/\/[^\s]+$/i.test(v);
    function preview() {
        const v = $('ib-image').value.trim(), img = $('ib-image-preview');
        if (isUrl(v)) { img.src = v; img.classList.remove('hidden'); } else { img.removeAttribute('src'); img.classList.add('hidden'); }
    }
    $('ib-image').addEventListener('input', preview);
    $('ib-attach').addEventListener('click', () => {
        const row = $('ib-image-row'), open = row.classList.toggle('hidden') === false;
        row.classList.toggle('flex', open); $('ib-attach').setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) $('ib-image').focus();
    });
    $('ib-text').addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); $('ib-reply').requestSubmit(); } });
    $('ib-reply').addEventListener('submit', async e => {
        e.preventDefault();
        const text = $('ib-text').value.trim(), image = $('ib-image').value.trim(), note = $('ib-send-note');
        const say = (t, cls) => { note.className = 'min-w-0 flex-1 text-xs ' + cls; note.textContent = t; };
        if (!text && !image) return say('Type a message or attach an image link.', 'text-red-700');
        if (image && !isUrl(image)) return say('The image link must start with http:// or https://.', 'text-red-700');
        if (!S.psid) return;
        // Isang beses kada usapan: itanong kung ipa-pause ang bot habang tao ang sumasagot.
        let pause = false;
        if (S.panel && !S.panel.bot_paused && !S.pausedAsked) { S.pausedAsked = true; pause = confirm('Pause the bot for this customer?'); }
        $('ib-send').disabled = true; say('Sending…', 'text-slate-500');
        const psid = S.psid;
        const res = await postJson(D.sendUrl, {page_id: S.page, psid, text, image_url: image, pause});
        $('ib-send').disabled = false;
        if (!res.ok) return say(errText(res, 'Not sent.'), 'text-red-700');
        if (psid !== S.psid) return;
        const item = res.data.item;
        if (item.id > (S.newer?.out || 0)) { render([item], 'bottom'); toBottom(); }
        if (item.status === 'sent') { $('ib-text').value = ''; $('ib-image').value = ''; preview(); say('', ''); } else say('Not sent: ' + (item.error || 'unknown error'), 'text-red-700');
        if (pause) loadPanel(threadSeq);
        loadList();
    });

    // ---- Executed actions ----
    $('ib-events').checked = localStorage.getItem(EVENTS_KEY) === '1';
    $('ib-events').addEventListener('change', () => { localStorage.setItem(EVENTS_KEY, eventsOn() ? '1' : '0'); if (S.psid) openThread(S.psid, S.name); });

    // ---- Roles (CEO) ----
    const roles = $('ib-roles');
    if (roles) {
        $('ib-gear').addEventListener('click', () => { roles.classList.remove('hidden'); roles.querySelector('input:not([disabled])')?.focus(); });
        roles.querySelector('[data-close]').addEventListener('click', () => roles.classList.add('hidden'));
        roles.addEventListener('click', e => { if (e.target === roles) roles.classList.add('hidden'); });
        roles.querySelector('form').addEventListener('submit', async e => {
            e.preventDefault();
            const picked = [...roles.querySelectorAll('input[name="roles[]"]:checked')].map(i => i.value);
            const res = await postJson(D.rolesUrl, {roles: picked}), n = $('ib-roles-note');
            n.className = 'text-xs ' + (res.ok ? 'text-green-700' : 'text-red-700'); n.textContent = res.ok ? 'Saved.' : errText(res, 'Not saved.');
        });
    }

    // ---- Simula ----
    const select = $('ib-page'), saved = localStorage.getItem(PAGE_KEY);
    if (saved && [...select.options].some(o => o.value === saved)) select.value = saved;
    S.page = select.value;
    select.addEventListener('change', () => {
        S.page = select.value; localStorage.setItem(PAGE_KEY, S.page);
        S.psid = null; S.panel = null; threadSeq++;
        $('ib-items').replaceChildren(); $('ib-who').textContent = 'Pick a conversation'; $('ib-who-sub').textContent = ''; $('ib-older').classList.add('hidden');
        $('ib-reply').classList.add('hidden'); $('ib-to-panel').disabled = true;
        $('ib-panel-body').replaceChildren(el('p', {class: 'text-slate-500', text: 'Pick a conversation.'}));
        show('list'); loadList();
    });
    paintTabs();
    loadList();
})();
</script>
@endif
@endsection
