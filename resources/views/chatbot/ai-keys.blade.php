@extends('layouts.chatblast')

@section('title', 'Chatbot · AI keys')
@section('heading', 'AI keys')

@section('description')
    The company's own AI keys that answer the chatbot's AI step. A page tries its providers in order; inside a provider the keys take turns, and a key that fails is rested and tried again later. Only the last 4 characters of a key are ever shown.
@endsection

@section('actions')
    <a href="{{ route('chatbot.pages.index') }}" class="text-slate-600 hover:text-slate-900">Page settings</a>
@endsection

@section('content')
{{-- Ang header (alert, add form) ay nakapirmi; ang mga key, ang usage at ang mga lipat ang nag-i-scroll (.cb-scroll). --}}
<div class="cb-page" id="aik" data-data-url="{{ route('chatbot.ai-keys.data') }}" data-store-url="{{ route('chatbot.ai-keys.store') }}" data-base-url="{{ url('/chatbot/ai-keys') }}">
    <div id="aik-alert" class="mb-2 hidden rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert"></div>
    <div id="aik-legacy" class="mb-2 hidden rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">No OpenAI key is in the pool yet, so OpenAI still uses the server's key. Add one here to use the pool.</div>
    <form id="aik-add" class="cb-card mb-3 grid grid-cols-1 gap-2 p-3 text-xs text-slate-600 sm:grid-cols-[10rem_12rem_minmax(0,1fr)_auto] sm:items-end" autocomplete="off">
        <label class="flex min-w-0 flex-col">Provider
            <select name="provider" class="mt-0.5 rounded border border-slate-300 px-1 py-1 text-sm text-gray-900">
                @foreach($providers as $p)<option value="{{ $p['name'] }}">{{ $p['label'] }}</option>@endforeach
            </select>
        </label>
        <label class="flex min-w-0 flex-col">Label<input name="label" maxlength="60" placeholder="e.g. pool-1" class="mt-0.5 rounded border border-slate-300 px-1 py-1 text-sm text-gray-900"></label>
        <label class="flex min-w-0 flex-col">Key<input name="key" type="password" maxlength="300" spellcheck="false" autocomplete="new-password" placeholder="Paste the key" class="mt-0.5 rounded border border-slate-300 px-1 py-1 font-mono text-sm text-gray-900"></label>
        <button class="cb-btn cb-btn-primary justify-center">Add key</button>
        <p id="aik-add-msg" class="min-h-[1rem] text-xs sm:col-span-4" aria-live="polite"></p>
    </form>

    <div class="cb-scroll space-y-4">
        <section class="cb-card min-w-0 p-3">
            <h2 class="mb-2 text-sm font-semibold text-slate-900">Keys</h2>
            <p id="aik-empty" class="hidden text-sm text-slate-500">No keys yet.</p>
            <ul id="aik-keys" class="divide-y divide-slate-100 text-sm"></ul>
        </section>
        <section class="cb-card min-w-0 p-3">
            <h2 class="mb-1 text-sm font-semibold text-slate-900">Usage sent to Forbeast</h2>
            <p class="mb-2 text-xs text-slate-500">Each AI answer is reported to the gateway its page uses, which charges it to the page's account.</p>
            <ul id="aik-usage" class="space-y-1 text-sm"></ul>
        </section>
        <section class="cb-card min-w-0 p-3">
            <h2 class="mb-2 text-sm font-semibold text-slate-900">Switches between keys</h2>
            <ul id="aik-events" class="space-y-1 text-sm"></ul>
            <div class="mt-2 flex items-center gap-2 text-xs">
                <button id="aik-prev" type="button" class="cb-btn cb-btn-sm">Newer</button>
                <span id="aik-page" class="text-slate-600"></span>
                <button id="aik-next" type="button" class="cb-btn cb-btn-sm">Older</button>
            </div>
        </section>
    </div>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const root = document.getElementById('aik'), csrf = document.querySelector('meta[name="csrf-token"]').content;
    const $ = id => document.getElementById(id);
    const el = (tag, attrs = {}, kids = []) => {
        const e = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs)) { if (k === 'text') e.textContent = v; else e.setAttribute(k, v); }
        for (const c of kids) if (c) e.append(c);
        return e;
    };
    const call = async (method, url, body) => {
        const r = await fetch(url, {method, headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}, body: body ? JSON.stringify(body) : undefined});
        let data = {};
        try { data = await r.json(); } catch (e) {}
        return {ok: r.ok, data};
    };
    let page = 1, pages = 1;
    const results = {ok: 'OK', invalid: 'invalid key', no_credit: 'no credit', rate_limited: 'rate limited', failing: 'no answer', model: 'test model refused (not the key)', request: 'refused (not the key)'};
    const statusClass = s => s === 'OK' ? 'text-green-700' : s.startsWith('Down') || s === 'Cannot decrypt' ? 'text-red-700' : 'text-slate-600';

    function keyRow(k) {
        const btn = (text, fn, cls = '') => { const b = el('button', {type: 'button', class: 'cb-btn cb-btn-sm ' + cls, text}); b.addEventListener('click', () => fn(b)); return b; };
        const msg = el('span', {class: 'text-xs text-slate-600', 'aria-live': 'polite'});
        const act = async (b, method, url, body, done) => {
            b.disabled = true; msg.textContent = '…';
            try {
                const {ok, data} = await call(method, url, body);
                msg.textContent = ok ? (done ? done(data) : 'Saved.') : (data.error || data.message || 'Not saved.');
                if (ok) load();
            } catch (e) { msg.textContent = 'No connection.'; } finally { b.disabled = false; }
        };
        const base = root.dataset.baseUrl + '/' + k.id;
        return el('li', {class: 'flex flex-wrap items-start justify-between gap-2 py-2'}, [
            el('div', {class: 'min-w-0 break-words'}, [
                el('div', {class: 'font-medium text-slate-900', text: k.label + ' · ' + k.provider_label + ' · …' + k.last4}),
                el('div', {class: 'text-xs ' + statusClass(k.status), text: k.status + (k.until ? ' until ' + k.until : '')}),
                el('div', {class: 'text-xs text-slate-500', text: 'Last used: ' + (k.last_used || 'never') + ' · Last test: ' + (k.test ? (results[k.test] || k.test) + ' at ' + k.tested_at : 'none')}),
            ]),
            el('div', {class: 'flex flex-wrap items-center gap-1'}, [
                btn('Test', b => act(b, 'POST', base + '/test', null, d => 'Test: ' + (results[d.result] || d.result))),
                btn(k.enabled ? 'Disable' : 'Enable', b => act(b, 'POST', base + '/enabled', {enabled: !k.enabled})),
                btn('Rename', b => { const v = prompt('New label for ' + k.label, k.label); if (v !== null) act(b, 'POST', base + '/label', {label: v}); }),
                btn('Delete', b => { if (confirm('Delete the key ' + k.label + ' (…' + k.last4 + ')? Its encrypted value is erased.')) act(b, 'DELETE', base, null, () => 'Deleted.'); }, 'text-red-700'),
                msg,
            ]),
        ]);
    }

    const usageNames = {pending: 'waiting', sent: 'sent', needs_price: 'sent without a price', unbilled: 'unbilled (nobody connected the page)', page_not_found: 'page not in Forbeast', invalid: 'refused', no_gateway: 'no gateway set up'};
    function usageRow(u) {
        const parts = Object.entries(u.counts || {}).map(([k, n]) => n + ' ' + (usageNames[k] || k));
        return el('li', {class: 'break-words'}, [
            el('span', {class: 'font-medium text-slate-900', text: u.label + ': '}),
            el('span', {class: 'text-slate-700', text: parts.length ? parts.join(' · ') : 'nothing yet'}),
            u.paused ? el('div', {class: 'text-xs text-red-700', text: 'Paused since ' + u.paused.at + ': ' + u.paused.error + ' Nothing is dropped; it tries again every 15 minutes.'}) : null,
        ]);
    }

    async function load() {
        let res;
        try { res = await call('GET', root.dataset.dataUrl + '?page=' + page); } catch (e) { $('aik-add-msg').textContent = 'No connection: could not load.'; return; }
        if (!res.ok) { $('aik-add-msg').textContent = res.data.error || 'Could not load.'; return; }
        const d = res.data;
        $('aik-keys').replaceChildren(...d.keys.map(keyRow));
        $('aik-empty').classList.toggle('hidden', d.keys.length > 0);
        $('aik-legacy').classList.toggle('hidden', !d.legacy);
        const a = $('aik-alert');
        a.classList.toggle('hidden', !d.alert);
        a.textContent = d.alert ? 'All AI keys failed at ' + d.alert.at + ' (page ' + d.alert.page + '): the AI step took its failed path. Test the keys or add one.' : '';
        $('aik-usage').replaceChildren(...(d.usage.length ? d.usage.map(usageRow) : [el('li', {class: 'text-slate-500', text: 'No gateway is set up.'})]));
        page = d.events.page; pages = d.events.pages;
        $('aik-events').replaceChildren(...(d.events.events.length ? d.events.events.map(e => el('li', {class: 'break-words text-slate-700', text: e.at + ' · ' + e.page + ' · ' + e.provider + ': ' + e.from + ' → ' + (e.to || 'none') + ' (' + e.reason + ')'})) : [el('li', {class: 'text-slate-500', text: 'No switches yet.'})]));
        $('aik-page').textContent = 'Page ' + page + ' of ' + pages + ' (' + d.events.total + ')';
        $('aik-prev').disabled = page <= 1; $('aik-next').disabled = page >= pages;
    }
    $('aik-prev').addEventListener('click', () => { page = Math.max(1, page - 1); load(); });
    $('aik-next').addEventListener('click', () => { page = Math.min(pages, page + 1); load(); });
    $('aik-add').addEventListener('submit', async e => {
        e.preventDefault();
        const f = e.target, b = f.querySelector('button'), m = $('aik-add-msg');
        b.disabled = true; m.className = 'min-h-[1rem] text-xs sm:col-span-4 text-slate-600'; m.textContent = 'Saving…';
        try {
            const {ok, data} = await call('POST', root.dataset.storeUrl, {provider: f.elements.provider.value, label: f.elements.label.value, key: f.elements.key.value});
            m.className = 'min-h-[1rem] text-xs sm:col-span-4 ' + (ok ? 'text-green-700' : 'text-red-700');
            m.textContent = ok ? 'Key added. Press Test to check it.' : (data.error || data.message || 'Not saved.');
            if (ok) { f.elements.key.value = ''; f.elements.label.value = ''; load(); }
        } catch (err) { m.textContent = 'No connection: not saved.'; } finally { b.disabled = false; }
    });
    load();
})();
</script>
@endpush
