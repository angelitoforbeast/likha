@extends('layouts.chatblast')

@section('title', 'Chatblast · Broadcast')
@section('heading', 'Broadcast')

@section('description')
    One message to a chosen audience of PSIDs, sent like a chatblast message: written to the page's message field, read back, then the flow. Preview the count, send a test, then send.
@endsection

@section('content')
<div id="bc" class="cb-page"
     data-base="{{ url('/chatblast/broadcast') }}"
     data-show="{{ url('/chatblast/messages') }}">

    <p id="notice" class="text-xs mb-2 min-h-[1rem]" aria-live="polite"></p>

    <div class="cb-scroll space-y-4 pb-6">
    <div class="grid gap-4 xl:grid-cols-2">
    <form id="form" class="cb-card p-4 text-sm space-y-3" autocomplete="off">
        <input type="hidden" name="id" id="bc-id">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold">New broadcast <span id="draft-label" class="text-xs font-normal text-slate-500"></span></h2>
            <button type="button" id="new" class="cb-btn cb-btn-sm">Start over</button>
        </div>
        <label class="flex flex-col text-xs text-gray-600">Name<input name="name" maxlength="100" placeholder="e.g. 11.11 sale" class="border rounded px-2 py-1 text-sm text-gray-900"></label>

        <fieldset>
            <legend class="text-xs text-gray-600 mb-1">Pages <span class="text-slate-400">({{ count($pages) }} with the chatblast setup)</span>
                <button type="button" id="all" class="ml-2 text-blue-700 hover:underline">Select all</button>
                <button type="button" id="none" class="ml-2 text-blue-700 hover:underline">Clear</button>
            </legend>
            <div id="pages" class="grid max-h-48 grid-cols-1 gap-x-4 overflow-auto rounded border border-slate-200 p-2 sm:grid-cols-2">
                @forelse($pages as $p)
                    <label class="flex items-center gap-2 py-0.5"><input type="checkbox" name="pages[]" value="{{ $p }}"> <span>{{ $p }}</span></label>
                @empty
                    <p class="text-xs text-slate-500">No page has the chatblast setup yet.</p>
                @endforelse
            </div>
        </fieldset>

        <div class="grid grid-cols-2 gap-3 md:grid-cols-3">
            <label class="flex flex-col text-xs text-gray-600">Orders
                <select name="orders" class="border rounded px-2 py-1 text-sm text-gray-900"><option value="any">Any</option><option value="with">With an order</option><option value="without">Without an order</option></select>
            </label>
            <label class="flex flex-col text-xs text-gray-600">Phone in Botcake
                <select name="phone" class="border rounded px-2 py-1 text-sm text-gray-900"><option value="any">Any</option><option value="has">Has a phone</option><option value="none">No phone</option></select>
            </label>
            <label class="flex flex-col text-xs text-gray-600">Cap per page per hour
                <input name="cap" type="number" min="1" max="{{ $maxCap }}" value="{{ $defaultCap }}" class="border rounded px-2 py-1 text-sm text-gray-900">
            </label>
            <label class="flex flex-col text-xs text-gray-600">Subscribed before<input name="subscribed_before" type="date" class="border rounded px-2 py-1 text-sm text-gray-900"></label>
            <label class="flex flex-col text-xs text-gray-600">Subscribed on or after<input name="subscribed_from" type="date" class="border rounded px-2 py-1 text-sm text-gray-900"></label>
            <span></span>
            <label class="flex flex-col text-xs text-gray-600">Last interaction before<input name="interaction_before" type="date" class="border rounded px-2 py-1 text-sm text-gray-900"></label>
            <label class="flex flex-col text-xs text-gray-600">Last interaction on or after<input name="interaction_from" type="date" class="border rounded px-2 py-1 text-sm text-gray-900"></label>
        </div>

        <div class="flex items-end gap-2">
            <label class="flex flex-1 flex-col text-xs text-gray-600">Flow
                <select name="flow" id="flow" class="border rounded px-2 py-1 text-sm text-gray-900"><option value="">Each page's own flow (flow.shipping)</option></select>
            </label>
            <button type="button" id="load-flows" class="cb-btn">Load flows</button>
        </div>
        <p id="flow-note" class="text-xs text-slate-500"></p>

        <label class="flex flex-col text-xs text-gray-600">Text (goes into the message field exactly as written; no placeholders)
            <textarea name="text" rows="4" maxlength="1000" class="border rounded px-2 py-1 text-sm font-mono text-gray-900"></textarea>
            <span id="chars" class="text-right text-slate-400">0 / 1000</span>
        </label>

        <div class="flex gap-2"><button class="cb-btn cb-btn-primary" id="preview-btn">Preview count</button></div>
    </form>

    <div class="cb-card p-4 text-sm">
        <h2 class="font-semibold mb-2">Preview</h2>
        <p id="empty-preview" class="text-xs text-slate-500">Pick the pages and filters, then "Preview count". Nothing is sent until you confirm.</p>
        <div id="preview" class="hidden space-y-2">
            <p id="warning" class="hidden rounded bg-amber-50 px-2 py-1 text-xs font-medium text-amber-900"></p>
            <table class="cb-table"><thead><tr><th>Page</th><th class="text-right">PSIDs</th><th>Flow</th><th>Test</th></tr></thead><tbody id="preview-rows"></tbody></table>
            <p class="font-medium">Total to send: <span id="total"></span></p>
            <div class="flex gap-2"><button type="button" id="send" class="cb-btn cb-btn-primary">Send…</button></div>
            <div id="tests" class="space-y-1 text-xs"></div>
        </div>
    </div>
    </div>

    <div class="cb-card p-4 text-sm">
        <h2 class="font-semibold mb-2">Broadcasts</h2>
        <div id="list" class="space-y-4"></div>
    </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const root = document.getElementById('bc');
    const base = root.dataset.base;
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const form = document.getElementById('form');
    const $ = (id) => document.getElementById(id);
    const el = (tag, attrs = {}, ...kids) => {
        const e = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs)) {
            if (k === 'class') e.className = v; else if (k.startsWith('on')) e.addEventListener(k.slice(2), v); else e.setAttribute(k, v);
        }
        for (const k of kids) e.append(k instanceof Node ? k : document.createTextNode(k ?? ''));
        return e;
    };
    const notice = (text, bad = false) => { const n = $('notice'); n.textContent = text; n.className = 'text-xs mb-2 min-h-[1rem] ' + (bad ? 'text-red-700' : 'text-slate-600'); };
    const api = async (method, path, body) => {
        const r = await fetch(base + path, { method, headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, body: body ? JSON.stringify(body) : undefined });
        const json = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(json.error || json.message || ('HTTP ' + r.status));
        return json;
    };
    const fmt = (n) => Number(n || 0).toLocaleString();
    const input = () => {
        const fd = new FormData(form);
        const out = { pages: fd.getAll('pages[]') };
        for (const k of ['id', 'name', 'orders', 'phone', 'cap', 'subscribed_before', 'subscribed_from', 'interaction_before', 'interaction_from', 'flow', 'text']) out[k] = fd.get(k) || '';
        return out;
    };
    let current = null;

    $('all').onclick = () => form.querySelectorAll('input[name="pages[]"]').forEach(c => c.checked = true);
    $('none').onclick = () => form.querySelectorAll('input[name="pages[]"]').forEach(c => c.checked = false);
    form.text.addEventListener('input', () => $('chars').textContent = form.text.value.length + ' / 1000');
    $('new').onclick = () => { form.reset(); $('bc-id').value = ''; current = null; $('draft-label').textContent = ''; $('preview').classList.add('hidden'); $('empty-preview').classList.remove('hidden'); notice(''); };

    $('load-flows').onclick = async () => {
        try {
            const r = await api('POST', '/flows', { pages: input().pages });
            const sel = $('flow'), keep = sel.value;
            sel.replaceChildren(el('option', { value: '' }, "Each page's own flow (flow.shipping)"));
            for (const f of r.names) sel.append(el('option', { value: f.name }, f.name + ' (' + f.pages + ' page' + (f.pages === 1 ? '' : 's') + ')'));
            sel.value = keep;
            const errs = Object.entries(r.errors || {});
            $('flow-note').textContent = errs.length ? 'Could not read the flows of: ' + errs.map(([p, e]) => p + ' (' + e + ')').join(', ') : '';
        } catch (e) { notice(e.message, true); }
    };

    const showPreview = (b) => {
        current = b;
        $('bc-id').value = b.state === 'draft' ? b.id : '';
        $('draft-label').textContent = b.state === 'draft' ? '(draft #' + b.id + ')' : '';
        $('empty-preview').classList.add('hidden');
        $('preview').classList.remove('hidden');
        $('warning').textContent = b.warning || '';
        $('warning').classList.toggle('hidden', !b.warning);
        $('preview-rows').replaceChildren(...b.pages.map(p => el('tr', {},
            el('td', {}, p.page),
            el('td', { class: 'text-right' + (p.error ? ' text-slate-400 line-through' : '') }, fmt(p.count)),
            el('td', { class: p.error ? 'text-red-700' : '' }, p.error ? p.error + ' (left out)' : (p.flow_name || '') + ' #' + (p.flow_id || '')),
            el('td', {}, p.error ? '' : el('button', { type: 'button', class: 'cb-btn cb-btn-sm', onclick: () => test(b.id, p.page) }, 'Send test')),
        )));
        $('total').textContent = fmt(b.total);
        $('send').disabled = b.state !== 'draft' || b.total === 0 || !b.text.trim();
    };

    form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        try { showPreview(await api('POST', '/preview', input())); notice('Preview saved as a draft. Nothing was sent.'); }
        catch (e) { notice(e.message, true); }
    });

    const test = async (id, page) => {
        try {
            const t = await api('POST', '/' + id + '/test', { page });
            notice('Test queued for ' + page + ' (row #' + t.id + ').');
            setTimeout(() => loadTests(id), 1500);
        } catch (e) { notice(e.message, true); }
    };
    const loadTests = async (id) => {
        const p = await api('GET', '/' + id + '/progress');
        $('tests').replaceChildren(...(p.tests || []).map(t => el('div', {}, el('a', { href: root.dataset.show + '/' + t.id, target: '_blank', class: 'text-blue-700 hover:underline' }, '#' + t.id),
            ' ' + t.at + ' · ' + t.page + ' · ' + t.status + ' · ' + t.steps.map(s => s.step + ' ' + (s.http ?? '–')).join(', ') + (t.reason ? ' · ' + t.reason : ''))));
    };

    $('send').onclick = async () => {
        if (!current) return;
        const lines = current.pages.filter(p => !p.error).map(p => '  ' + p.page + ': ' + fmt(p.count) + ' · flow ' + p.flow_name);
        const out = current.pages.filter(p => p.error).map(p => '  ' + p.page + ': ' + p.error);
        const msg = 'Send this broadcast to ' + fmt(current.total) + ' customers?\n\n' + lines.join('\n') + (out.length ? '\n\nLeft out:\n' + out.join('\n') : '')
            + '\n\nCap: ' + current.cap + ' per page per hour.' + (current.warning ? '\n\n' + current.warning : '');
        if (!confirm(msg)) return;
        try { await api('POST', '/' + current.id + '/start', { total: current.total }); notice('Started.'); $('new').click(); load(); }
        catch (e) { notice(e.message, true); }
    };

    const control = async (id, action, body) => { try { await api('POST', '/' + id + '/' + action, body); load(); } catch (e) { notice(e.message, true); } };

    const card = (b, p) => {
        const head = el('div', { class: 'flex flex-wrap items-center gap-2' },
            el('span', { class: 'font-medium' }, '#' + b.id + ' ' + b.name),
            el('span', { class: 'rounded-full bg-slate-100 px-2 py-0.5 text-xs' }, b.state + (b.state === 'sending' && !b.queued_all ? ' (queueing)' : '')),
            el('span', { class: 'text-xs text-slate-500' }, (b.started_at ? 'started ' + b.started_at + ' · ' : '') + 'cap ' + b.cap + '/page/hour'));
        if (b.state === 'sending') head.append(el('button', { class: 'cb-btn cb-btn-sm', onclick: () => control(b.id, 'pause') }, 'Pause'));
        if (b.state === 'paused' || (b.state === 'sending' && p.progress.some(x => x.paused))) head.append(el('button', { class: 'cb-btn cb-btn-sm', onclick: () => control(b.id, 'resume') }, 'Resume'));
        if (['sending', 'paused'].includes(b.state)) {
            head.append(el('button', { class: 'cb-btn cb-btn-sm', onclick: () => { const c = prompt('Cap per page per hour', b.cap); if (c) control(b.id, 'cap', { cap: Number(c) }); } }, 'Change cap'));
            head.append(el('button', { class: 'cb-btn cb-btn-sm text-red-700', onclick: () => confirm('Stop this broadcast? The rows not sent yet are cancelled.') && control(b.id, 'stop') }, 'Stop'));
        }
        if (b.state === 'draft') head.append(el('button', { class: 'cb-btn cb-btn-sm', onclick: () => { fill(b); showPreview(b); } }, 'Open draft'));
        const th = (t, right) => el('th', right ? { class: 'text-right' } : {}, t);
        const td = (v, cls = 'text-right') => el('td', { class: cls }, v);
        const rows = p.progress.map(x => el('tr', {}, td(x.page, ''), td(x.error ? '–' : fmt(x.audience)), td(fmt(x.queued)), td(fmt(x.sent)), td(fmt(x.failed)), td(fmt(x.unknown)),
            td(fmt(x.skipped + x.cancelled)), td(x.error ? 'Left out: ' + x.error : (x.paused ? 'Paused: ' + x.paused : (x.flow_name || '')), x.error || x.paused ? 'text-red-700' : 'text-slate-500')));
        return el('div', { class: 'space-y-2 border-t border-slate-100 pt-3 first:border-0 first:pt-0' }, head,
            el('p', { class: 'whitespace-pre-wrap rounded bg-slate-50 px-2 py-1 font-mono text-xs' }, b.text),
            b.state === 'draft' ? el('span') : el('table', { class: 'cb-table' }, el('thead', {}, el('tr', {}, th('Page'), th('Audience', 1), th('Queued', 1), th('Sent', 1), th('Failed', 1), th('Unknown', 1), th('Skipped / cancelled', 1), th('Flow / paused'))), el('tbody', {}, ...rows)));
    };

    const fill = (b) => {
        form.reset();
        form.name.value = b.name; form.text.value = b.text; form.cap.value = b.cap; $('chars').textContent = b.text.length + ' / 1000';
        for (const [k, v] of Object.entries(b.filters || {})) if (form[k]) form[k].value = v ?? '';
        const chosen = new Set(b.pages.map(p => p.page));
        form.querySelectorAll('input[name="pages[]"]').forEach(c => c.checked = chosen.has(c.value));
        if (b.flow) { if (![...form.flow.options].some(o => o.value === b.flow)) form.flow.append(el('option', { value: b.flow }, b.flow)); form.flow.value = b.flow; }
    };

    const load = async () => {
        try {
            const { broadcasts } = await api('GET', '/data');
            const cards = [];
            for (const b of broadcasts.slice(0, 5)) cards.push(card(b, b.state === 'draft' ? { progress: [] } : await api('GET', '/' + b.id + '/progress')));
            $('list').replaceChildren(...(cards.length ? cards : [el('p', { class: 'text-xs text-slate-500' }, 'No broadcast yet.')]));
        } catch (e) { notice(e.message, true); }
    };
    load();
    setInterval(() => { if (!document.hidden) load(); }, 10000);
})();
</script>
@endpush
