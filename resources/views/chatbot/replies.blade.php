@extends('layouts.chatblast')

@section('title', 'Chatbot · Replies')
@section('heading', 'Replies')

@section('description')
    Every message the chatbot sent (or skipped, or failed to send) and every sheet row, newest first. One day at a time.
@endsection

@push('styles')
<style>
    /* Ang table lang ang nag-i-scroll; ang header ng table ay nananatili sa itaas ng kahon. */
    /* Ang kahon ay kasing-taas ng laman pero hanggang sa natitirang taas ng screen lang (.cb-page ng layout), kaya ang
       horizontal bar ay laging kita at ang page ay hindi nag-i-scroll. */
    #rp-table thead th { position: sticky; top: 0; z-index: 10; background: #fff; }
</style>
@endpush

@section('content')
@include('chatbot.partials.switch', ['repliesOn' => $repliesOn])

<div id="rp" class="cb-page" data-data-url="{{ route('chatbot.replies.data') }}" data-base-url="{{ url('/chatbot/replies') }}">
    <form id="rp-filters" class="cb-filters mb-3 flex flex-wrap items-end gap-3 text-sm">
        <label class="flex flex-col text-xs">Date<input type="date" name="date" value="{{ $today }}" class="border px-2 py-1 text-sm text-gray-900"></label>
        <label class="flex flex-col text-xs">Page
            <select name="page_id" class="max-w-[14rem] border px-2 py-1 text-sm text-gray-900">
                <option value="">All pages</option>
                @foreach($pages as $name => $id)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
        </label>
        <label class="flex flex-col text-xs">Status
            <select name="status" class="border px-2 py-1 text-sm text-gray-900"><option value="">All</option><option value="sent">Sent</option><option value="failed">Failed</option><option value="skipped">Skipped</option></select>
        </label>
        <label class="flex flex-col text-xs">Search (this date)<input type="search" name="q" maxlength="100" placeholder="Text or error" class="border px-2 py-1 text-sm text-gray-900"></label>
        <button class="cb-btn cb-btn-primary">Show</button>
        <span id="rp-count" class="text-xs text-slate-500"></span>
    </form>

    <div id="rp-box" class="cb-card min-h-[16rem] shrink overflow-auto">
        <table id="rp-table" class="cb-table table-fixed">
            <thead><tr><th class="w-20">Time</th><th class="w-36">Page</th><th class="w-24">Status</th><th>What</th></tr></thead>
            <tbody id="rp-rows"></tbody>
        </table>
    </div>
    <div class="mt-2"><button type="button" id="rp-more" class="cb-btn cb-btn-sm hidden">Older</button></div>

    <div id="rp-modal" class="fixed inset-0 z-50 hidden overflow-y-auto bg-slate-900/30 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="rp-title">
        <div class="mx-auto w-full max-w-2xl rounded-xl bg-white p-5 text-sm shadow-lg">
            <div class="mb-3 flex items-center justify-between"><div id="rp-title" class="font-semibold">Reply</div>
                <button type="button" id="rp-close" class="px-2 text-slate-500 hover:text-slate-800" aria-label="Close">✕</button></div>
            <dl id="rp-detail" class="grid grid-cols-[7rem_1fr] gap-x-3 gap-y-1"></dl>
        </div>
    </div>
</div>

<script>
(function () {
    const box = document.getElementById('rp'), $ = id => document.getElementById(id);
    const COLOR = {sent: 'bg-green-100 text-green-800', failed: 'bg-red-100 text-red-800', skipped: 'bg-slate-200 text-slate-700'};
    let next = null, shown = 0;
    function el(tag, attrs = {}, children = []) {
        const e = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs)) { if (v == null) continue; if (k === 'class') e.className = v; else if (k === 'text') e.textContent = v; else e.setAttribute(k, v); }
        for (const c of [].concat(children)) if (c != null) e.append(c);
        return e;
    }
    async function load(more) {
        const q = new URLSearchParams(new FormData($('rp-filters')));
        if (more && next) q.set('before', next);
        const res = await fetch(box.dataset.dataUrl + '?' + q, {headers: {'Accept': 'application/json'}});
        if (!res.ok) { $('rp-count').textContent = 'Could not load (' + res.status + ').'; return; }
        const data = await res.json();
        if (!more) { $('rp-rows').replaceChildren(); shown = 0; }
        data.rows.forEach(r => {
            const tr = el('tr', {class: 'cursor-pointer', tabindex: '0'}, [
                el('td', {class: 'text-xs text-slate-500', text: r.time}),
                el('td', {class: 'break-words', text: r.page}),
                el('td', {}, el('span', {class: 'rounded-full px-2 py-0.5 text-xs ' + (COLOR[r.status] || ''), text: r.status})),
                el('td', {class: 'break-words'}, [el('div', {class: 'line-clamp-2', text: r.summary}), r.error ? el('div', {class: 'text-xs text-red-700', text: r.error}) : null]),
            ]);
            const open = () => detail(r.id);
            tr.addEventListener('click', open);
            tr.addEventListener('keydown', ev => { if (ev.key === 'Enter') open(); });
            $('rp-rows').append(tr);
        });
        shown += data.rows.length; next = data.next;
        $('rp-more').classList.toggle('hidden', !next);
        $('rp-count').textContent = shown ? shown + (next ? '+' : '') + ' on ' + data.date : 'Nothing on ' + data.date + '.';
    }
    async function detail(id) {
        const res = await fetch(box.dataset.baseUrl + '/' + id, {headers: {'Accept': 'application/json'}});
        if (!res.ok) return;
        const r = await res.json(), dl = $('rp-detail');
        const content = r.kind === 'sheet'
            ? el('div', {}, [el('div', {class: 'text-xs text-slate-500', text: (r.content.sheet || '') + ' · ' + (r.content.tab || '')}), el('ol', {class: 'list-decimal pl-5'}, (r.content.row || []).map(c => el('li', {class: 'break-words', text: c}))),
                r.link ? el('a', {href: r.link, target: '_blank', rel: 'noopener noreferrer', class: 'text-sm text-blue-700 hover:underline', text: 'Open row'}) : null])
            : el('div', {class: 'space-y-1'}, (r.content || []).map(m => m.type === 'image' ? el('div', {class: 'break-all text-blue-700', text: '[image] ' + m.url}) : el('div', {class: 'whitespace-pre-line break-words rounded-lg bg-blue-50 px-2 py-1', text: m.text})));
        const rows = [['Time', r.time], ['Page', r.page + ' (' + r.page_id + ')'], ['Customer', r.psid], ['Chat', r.chat_id ?? '—'], ['Flow', (r.flow ?? '—') + ' · ' + (r.node ?? '')],
            ['Kind', r.kind], ['Status', r.status + (r.http ? ' (HTTP ' + r.http + ')' : '')], ['Error', r.error ?? '—']];
        dl.replaceChildren(...rows.flatMap(([k, v]) => [el('dt', {class: 'text-slate-500', text: k}), el('dd', {class: 'break-words', text: String(v)})]),
            el('dt', {class: 'text-slate-500', text: 'Content'}), el('dd', {}, content));
        $('rp-modal').classList.remove('hidden'); $('rp-close').focus();
    }
    const close = () => $('rp-modal').classList.add('hidden');
    $('rp-close').addEventListener('click', close);
    $('rp-modal').addEventListener('click', e => { if (e.target === $('rp-modal')) close(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
    $('rp-filters').addEventListener('submit', ev => { ev.preventDefault(); load(false); });
    $('rp-more').addEventListener('click', () => load(true));
    load(false);
})();
</script>
@endsection
