@extends('layouts.chatblast')

@section('title', 'Chatbot · Test')
@section('heading', 'Test')

@section('description')
    Chat as a pretend customer and see what the flow would send, which fields change and the path it takes.
@endsection

@section('content')
<p class="mb-4 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900" role="status">
    Nothing is sent to Botcake and no sheet row is written; rows are shown here instead. An AI step calls OpenAI for real (with the server's key).
    The test customer has its own fields, apart from real customers.
</p>

<div id="tst" data-send-url="{{ route('chatbot.test.send') }}" data-reset-url="{{ route('chatbot.test.reset') }}" class="grid gap-4 lg:grid-cols-[1fr_22rem]">
    <section class="cb-card flex flex-col p-4">
        <div class="mb-3 flex flex-wrap items-end gap-3 text-sm">
            <label class="flex flex-col text-xs text-slate-500">Page
                <select id="t-page" class="max-w-[16rem] rounded-md border border-slate-300 px-2 py-1 text-sm text-gray-900">
                    @foreach($pages as $name => $id)<option value="{{ $name }}" data-flow="{{ $pageFlows[$name] ?? '' }}">{{ $name }}</option>@endforeach
                </select>
            </label>
            <label class="flex flex-col text-xs text-slate-500">Flow
                <select id="t-flow" class="max-w-[16rem] rounded-md border border-slate-300 px-2 py-1 text-sm text-gray-900">
                    <option value="">The page's flow</option>
                    @foreach($flows as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            </label>
            <span id="t-pageflow" class="text-xs text-slate-500"></span>
            <button type="button" id="t-reset" class="cb-btn cb-btn-sm ml-auto">Reset test customer</button>
        </div>
        <div id="t-log" class="mb-3 min-h-[12rem] space-y-2 overflow-y-auto rounded-md bg-slate-50 p-3 text-sm" style="max-height: 50vh" aria-live="polite"></div>
        <form id="t-form" class="flex gap-2">
            <input id="t-msg" maxlength="2000" autocomplete="off" placeholder="Type as the customer…" class="min-w-0 flex-1 rounded-md border border-slate-300 px-2 py-1 text-sm">
            <button class="cb-btn cb-btn-primary">Send</button>
        </form>
    </section>
    <aside class="space-y-4 text-sm">
        <section class="cb-card p-4"><h2 class="mb-2 font-semibold">Path</h2><ol id="t-path" class="list-decimal space-y-0.5 pl-5 text-xs"></ol></section>
        <section class="cb-card p-4"><h2 class="mb-2 font-semibold">Field changes</h2><ul id="t-changes" class="space-y-0.5 text-xs"></ul></section>
        <section class="cb-card p-4"><h2 class="mb-2 font-semibold">Test customer fields</h2><dl id="t-fields" class="grid grid-cols-[8rem_1fr] gap-x-2 gap-y-0.5 text-xs"></dl></section>
    </aside>
</div>

<script>
(function () {
    const box = document.getElementById('tst'), $ = id => document.getElementById(id), csrf = document.querySelector('meta[name="csrf-token"]').content;
    function el(tag, attrs = {}, children = []) {
        const e = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs)) { if (v == null) continue; if (k === 'class') e.className = v; else if (k === 'text') e.textContent = v; else e.setAttribute(k, v); }
        for (const c of [].concat(children)) if (c != null) e.append(c);
        return e;
    }
    const post = (url, body) => fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}, body: JSON.stringify(body)})
        .then(async r => ({ok: r.ok, data: await r.json().catch(() => ({}))}));
    const log = node => { $('t-log').append(node); $('t-log').scrollTop = $('t-log').scrollHeight; };
    const showPageFlow = () => { const f = $('t-page').selectedOptions[0]?.dataset.flow; $('t-pageflow').textContent = f ? 'Page flow: ' + f : 'This page has no flow yet.'; };
    $('t-page').addEventListener('change', () => { showPageFlow(); $('t-log').replaceChildren(); });
    showPageFlow();

    $('t-form').addEventListener('submit', async ev => {
        ev.preventDefault();
        const msg = $('t-msg').value;
        log(el('div', {class: 'flex justify-end'}, el('div', {class: 'max-w-[80%] whitespace-pre-line break-words rounded-lg bg-blue-600 px-3 py-1.5 text-white', text: msg || '(empty)'})));
        $('t-msg').value = '';
        const {ok, data} = await post(box.dataset.sendUrl, {page_name: $('t-page').value, flow_id: $('t-flow').value, message: msg});
        if (!ok) return log(el('div', {class: 'text-xs text-red-700', text: data.error || data.message || 'Could not run.'}));
        (data.sends || []).forEach(s => s.messages.forEach(m => log(el('div', {class: 'flex'}, el('div', {class: 'max-w-[80%] whitespace-pre-line break-words rounded-lg bg-white px-3 py-1.5 shadow-sm'},
            m.type === 'image' ? [el('img', {src: m.url, alt: '', referrerpolicy: 'no-referrer', class: 'max-h-40 rounded'}), el('div', {class: 'break-all text-xs text-slate-500', text: m.url})] : m.text)))));
        (data.sheet_rows || []).forEach(r => log(el('div', {class: 'rounded-md border border-green-300 bg-green-50 px-2 py-1 text-xs'}, [el('div', {class: 'font-semibold', text: 'Sheet row (not written): ' + r.tab}), el('div', {class: 'break-words', text: r.row.join(' | ')})])));
        if (data.stopped) log(el('div', {class: 'text-xs text-red-700', text: data.stopped}));
        if (data.waiting) log(el('div', {class: 'text-xs text-slate-500', text: 'Waiting for the customer\'s reply…'}));
        $('t-path').replaceChildren(...data.path.map(p => el('li', {text: p.node + ' · ' + p.type + (p.port ? ' → ' + p.port : '') + (p.note ? ' · ' + p.note : '')})));
        $('t-changes').replaceChildren(...(data.changes.length ? data.changes.map(c => el('li', {class: 'break-words', text: c.key + ': ' + (c.from ?? '—') + ' → ' + (c.to ?? '(cleared)')})) : [el('li', {class: 'text-slate-500', text: 'No change'})]));
        renderFields(data.fields);
    });
    function renderFields(f) {
        const entries = Object.entries(f || {});
        $('t-fields').replaceChildren(...(entries.length ? entries.flatMap(([k, v]) => [el('dt', {class: 'font-mono text-slate-500 break-words', text: k}), el('dd', {class: 'break-words', text: v})]) : [el('dd', {class: 'text-slate-500', text: 'None'})]));
    }
    $('t-reset').addEventListener('click', async () => {
        const {ok, data} = await post(box.dataset.resetUrl, {page_name: $('t-page').value});
        if (!ok) return log(el('div', {class: 'text-xs text-red-700', text: data.error || 'Not reset.'}));
        $('t-log').replaceChildren(el('div', {class: 'text-xs text-slate-500', text: 'Test customer reset.'}));
        $('t-path').replaceChildren(); $('t-changes').replaceChildren(); renderFields({});
    });
})();
</script>
@endsection
