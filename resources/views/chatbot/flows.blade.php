@extends('layouts.chatblast')

@section('title', 'Chatbot · Flow')
@section('heading', 'Flow')

@section('description')
    Each page's FLOW (in Page settings) picks one of these flows; it runs on every chat of that page and answers through Botcake.
@endsection

@section('content')
@include('chatbot.partials.switch', ['repliesOn' => $repliesOn])

<div id="flows" data-store-url="{{ route('chatbot.flows.store') }}" data-base-url="{{ url('/chatbot/flows') }}">
    <form id="new-flow" class="mb-4 flex flex-wrap items-end gap-2 text-sm">
        <label class="flex flex-col text-xs text-slate-500">New flow
            <input name="name" maxlength="100" required placeholder="e.g. Main COD" class="rounded-md border border-slate-300 px-2 py-1 text-sm text-gray-900">
        </label>
        <button class="cb-btn cb-btn-primary">Create</button>
        <span id="notice" class="text-xs" aria-live="polite"></span>
    </form>

    @if($flows->isEmpty())
        <p class="cb-card px-4 py-3 text-sm text-slate-600">No flows yet. Create one, then pick it as a page's FLOW in Page settings.</p>
    @else
        <div class="cb-card overflow-x-auto">
            <table class="cb-table">
                <thead><tr><th>Flow</th><th>Used by</th><th class="w-40">Updated</th><th class="w-64">Actions</th></tr></thead>
                <tbody>
                @foreach($flows as $f)
                    <tr data-id="{{ $f->id }}" data-name="{{ $f->name }}">
                        <td class="break-words"><a href="{{ route('chatbot.flows.edit', $f->id) }}" class="font-medium text-blue-700 hover:underline">{{ $f->name }}</a></td>
                        <td class="break-words text-slate-600">{{ implode(', ', $usedBy[(string) $f->id] ?? []) ?: '—' }}</td>
                        <td class="text-xs text-slate-500">{{ $f->updated_at?->timezone('Asia/Manila')->format('Y-m-d H:i') }}</td>
                        <td class="whitespace-nowrap">
                            <a href="{{ route('chatbot.flows.edit', $f->id) }}" class="cb-btn cb-btn-sm">Edit</a>
                            <button type="button" data-act="rename" class="cb-btn cb-btn-sm">Rename</button>
                            <button type="button" data-act="duplicate" class="cb-btn cb-btn-sm">Duplicate</button>
                            <button type="button" data-act="delete" class="cb-btn cb-btn-sm">Delete</button>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<script>
(function () {
    const box = document.getElementById('flows'), csrf = document.querySelector('meta[name="csrf-token"]').content;
    const notice = (t, cls) => { const n = document.getElementById('notice'); n.className = 'text-xs ' + cls; n.textContent = t; };
    const call = (method, url, body) => fetch(url, {method, headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}, body: body ? JSON.stringify(body) : undefined})
        .then(async r => ({ok: r.ok, data: await r.json().catch(() => ({}))}));

    document.getElementById('new-flow').addEventListener('submit', async ev => {
        ev.preventDefault();
        const {ok, data} = await call('POST', box.dataset.storeUrl, {name: new FormData(ev.target).get('name')});
        if (!ok) return notice(data.error || data.message || 'Not created.', 'text-red-600');
        location.href = box.dataset.baseUrl + '/' + data.id;
    });
    box.querySelectorAll('[data-act]').forEach(b => b.addEventListener('click', async () => {
        const tr = b.closest('tr'), id = tr.dataset.id, name = tr.dataset.name, act = b.dataset.act;
        let res;
        if (act === 'rename') {
            const next = prompt('New name for "' + name + '"', name);
            if (next === null) return;
            res = await call('POST', box.dataset.baseUrl + '/' + id + '/rename', {name: next});
        } else if (act === 'duplicate') {
            res = await call('POST', box.dataset.baseUrl + '/' + id + '/duplicate');
        } else {
            if (!confirm('Delete the flow "' + name + '"?')) return;
            res = await call('DELETE', box.dataset.baseUrl + '/' + id);
        }
        if (!res.ok) return notice(res.data.error || res.data.message || 'Not done.', 'text-red-600');
        location.reload();
    }));
})();
</script>
@endsection
