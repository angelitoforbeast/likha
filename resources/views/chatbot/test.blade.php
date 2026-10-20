@extends('layouts.chatblast')

@section('title', 'Chatbot · Test')
@section('heading', 'Test')

@section('description')
    Chat as a pretend customer and see what the flow would send, which fields change and the path it takes.
@endsection

@section('content')
<p class="mb-4 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900" role="status">
    Nothing is sent to Botcake. Sheet rows are only shown, unless 'Write sheet rows for real' is on. Skip timers run as live. An AI step calls OpenAI for real (with the server's key).
    The test customer has its own fields, apart from real customers.
</p>

<div id="tst" data-send-url="{{ route('chatbot.test.send') }}" data-reset-url="{{ route('chatbot.test.reset') }}" data-skip-url="{{ route('chatbot.test.skip') }}" class="grid gap-4 lg:grid-cols-[1fr_22rem]">
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
                    @foreach($flows as $id => $name)<option value="{{ $id }}" @selected($pickFlow === $id)>{{ $name }}</option>@endforeach
                </select>
            </label>
            <label class="flex flex-col text-xs text-slate-500">Version
                <select id="t-version" class="rounded-md border border-slate-300 px-2 py-1 text-sm text-gray-900">
                    <option value="draft" @selected($pickVersion === 'draft')>Draft</option>
                    <option value="published" @selected($pickVersion === 'published')>Published (live)</option>
                </select>
            </label>
            <span id="t-pageflow" class="text-xs text-slate-500"></span>
            <button type="button" id="t-reset" class="cb-btn cb-btn-sm ml-auto">Reset test customer</button>
            <label class="flex w-full items-center gap-2 text-xs text-slate-700">
                <input type="checkbox" id="t-write-sheet" class="rounded border-slate-300"> Write sheet rows for real
                <span class="text-slate-500">(appends to the real spreadsheet; Botcake sends stay simulated)</span>
            </label>
        </div>
        <div id="t-log" class="mb-3 min-h-[12rem] space-y-2 overflow-y-auto rounded-md bg-slate-50 p-3 text-sm" style="max-height: 50vh" aria-live="polite"></div>
        <div id="t-skip" class="mb-3 flex-wrap items-center gap-2 rounded-md border border-sky-200 bg-sky-50 px-3 py-1.5 text-xs text-sky-900" style="display: none">
            <span>Skips in <strong id="t-skip-left" class="font-mono">0:00</strong> if no reply</span>
            <button type="button" id="t-skip-now" class="cb-btn cb-btn-sm">Skip now</button>
        </div>
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
    $('t-page').addEventListener('change', () => { showPageFlow(); $('t-log').replaceChildren(); stopSkip(); });
    showPageFlow();

    // "Write sheet rows for real": naaalala kada browser.
    const SHEET_KEY = 'chatbot-test-write-sheet';
    $('t-write-sheet').checked = localStorage.getItem(SHEET_KEY) === '1';
    $('t-write-sheet').addEventListener('change', () => localStorage.setItem(SHEET_KEY, $('t-write-sheet').checked ? '1' : '0'));
    const base = () => ({page_name: $('t-page').value, flow_id: $('t-flow').value, version: $('t-version').value, write_sheet: $('t-write-sheet').checked});

    function render(data) {
        (data.sends || []).forEach(s => s.messages.forEach(m => log(el('div', {class: 'flex'}, el('div', {class: 'max-w-[80%] whitespace-pre-line break-words rounded-lg bg-white px-3 py-1.5 shadow-sm'},
            m.type === 'image' ? [el('img', {src: m.url, alt: '', referrerpolicy: 'no-referrer', class: 'max-h-40 rounded'}), el('div', {class: 'break-all text-xs text-slate-500', text: m.url})] : m.text)))));
        (data.sheet_rows || []).forEach(r => log(r.error
            ? el('div', {class: 'rounded-md border border-red-300 bg-red-50 px-2 py-1 text-xs text-red-800'}, [el('div', {class: 'font-semibold', text: 'Sheet row not written: ' + r.tab}), el('div', {class: 'break-words', text: r.error})])
            : el('div', {class: 'rounded-md border border-green-300 bg-green-50 px-2 py-1 text-xs'}, [el('div', {class: 'font-semibold', text: r.note || ('Sheet row (not written): ' + r.tab)}), el('div', {class: 'break-words', text: r.row.join(' | ')})])));
        if (data.stopped) log(el('div', {class: 'text-xs text-red-700', text: data.stopped}));
        if (data.waiting) log(el('div', {class: 'text-xs text-slate-500', text: 'Waiting for the customer\'s reply…'}));
        $('t-path').replaceChildren(...data.path.map(p => el('li', {text: p.node + ' · ' + p.type + (p.port ? ' → ' + p.port : '') + (p.note ? ' · ' + p.note : '')})));
        $('t-changes').replaceChildren(...(data.changes.length ? data.changes.map(c => el('li', {class: 'break-words', text: c.key + ': ' + (c.from ?? '—') + ' → ' + (c.to ?? '(cleared)')})) : [el('li', {class: 'text-slate-500', text: 'No change'})]));
        renderFields(data.fields);
        startSkip(data.skip_in);
    }

    // Ang skip timer: countdown sa page, at tanong sa server kada 2 s habang may hinihintay (ang server ang nagpapasya).
    let skipAt = null, tickTimer = null, pollTimer = null, busy = false;
    function stopSkip() { skipAt = null; clearInterval(tickTimer); clearInterval(pollTimer); $('t-skip').style.display = 'none'; }
    function startSkip(seconds) {
        stopSkip();
        if (seconds == null) return;
        skipAt = Date.now() + seconds * 1000;
        $('t-skip').style.display = 'flex'; // the class flex would override the hidden attribute
        const show = () => { const left = Math.max(0, Math.ceil((skipAt - Date.now()) / 1000)); $('t-skip-left').textContent = Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0'); };
        show();
        tickTimer = setInterval(show, 1000);
        pollTimer = setInterval(() => skip(false), 2000);
    }
    async function skip(now) {
        if (busy) return;
        busy = true;
        try {
            const {ok, data} = await post(box.dataset.skipUrl, {...base(), now});
            if (!ok) { stopSkip(); return log(el('div', {class: 'text-xs text-red-700', text: data.error || 'Could not skip.'})); }
            if (data.ran) { log(el('div', {class: 'text-xs text-slate-500', text: 'No reply in time: skipped.'})); render(data); }
            else if (data.skip_in == null) stopSkip();
        } finally { busy = false; }
    }
    $('t-skip-now').addEventListener('click', () => skip(true));

    $('t-form').addEventListener('submit', async ev => {
        ev.preventDefault();
        const msg = $('t-msg').value;
        stopSkip(); // a reply cancels the timer, as live
        log(el('div', {class: 'flex justify-end'}, el('div', {class: 'max-w-[80%] whitespace-pre-line break-words rounded-lg bg-blue-600 px-3 py-1.5 text-white', text: msg || '(empty)'})));
        $('t-msg').value = '';
        const {ok, data} = await post(box.dataset.sendUrl, {...base(), message: msg});
        if (!ok) return log(el('div', {class: 'text-xs text-red-700', text: data.error || data.message || 'Could not run.'}));
        render(data);
    });
    function renderFields(f) {
        const entries = Object.entries(f || {});
        $('t-fields').replaceChildren(...(entries.length ? entries.flatMap(([k, v]) => [el('dt', {class: 'font-mono text-slate-500 break-words', text: k}), el('dd', {class: 'break-words', text: v})]) : [el('dd', {class: 'text-slate-500', text: 'None'})]));
    }
    $('t-reset').addEventListener('click', async () => {
        const {ok, data} = await post(box.dataset.resetUrl, {page_name: $('t-page').value});
        if (!ok) return log(el('div', {class: 'text-xs text-red-700', text: data.error || 'Not reset.'}));
        stopSkip();
        $('t-log').replaceChildren(el('div', {class: 'text-xs text-slate-500', text: 'Test customer reset.'}));
        $('t-path').replaceChildren(); $('t-changes').replaceChildren(); renderFields({});
    });
})();
</script>
@endsection
