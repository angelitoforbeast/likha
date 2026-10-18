@extends('layouts.chatblast')

@section('title', 'Chatblast · Chats')
@section('heading', 'Chats')

@section('description')
    Customer messages that Botcake passes to this site from the JSON API block in each page's Default reply flow. View only: nothing here is sent to the customer. Kept for 90 days.
@endsection

@section('actions')
    <button id="setup-open" type="button" class="cb-btn">Botcake setup</button>
@endsection

@section('content')
<form method="GET" action="{{ route('chatblast.chats') }}" class="cb-filters mb-4 flex flex-wrap items-end gap-3 text-sm">
    <label class="flex flex-col text-xs">Page
        <select name="page_id" class="border px-2 py-1 text-sm text-gray-900 max-w-full">
            <option value="">All pages</option>
            @foreach($pages as $p)
                <option value="{{ $p->page_id }}" @selected($filters['page'] === (string) $p->page_id)>{{ $p->page_name ?? 'Unknown page (ID ' . $p->page_id . ')' }}</option>
            @endforeach
        </select>
    </label>
    <label class="flex flex-col text-xs">Date
        <input type="date" name="date" value="{{ $filters['date'] }}" class="border px-2 py-1 text-sm text-gray-900">
    </label>
    <label class="flex flex-col text-xs">Search
        <input type="search" name="q" value="{{ $filters['q'] }}" maxlength="100" placeholder="Name or text" class="border px-2 py-1 text-sm text-gray-900">
    </label>
    <button class="cb-btn cb-btn-primary">Show</button>
    @if($filters['page'] !== '' || $filters['date'] !== '' || $filters['q'] !== '')
        <a href="{{ route('chatblast.chats') }}" class="text-sm text-blue-700 hover:underline">Clear</a>
    @endif
</form>

@if(!$ready)
    <p class="cb-card px-4 py-3 text-sm text-slate-600">The chats table is not set up yet (run the migration).</p>
@elseif($chats->isEmpty())
    <p class="cb-card px-4 py-3 text-sm text-slate-600">No messages yet{{ ($filters['page'] !== '' || $filters['date'] !== '' || $filters['q'] !== '') ? ' for this filter' : '' }}. Open "Botcake setup" for the values to put in Botcake.</p>
@else
    <div class="cb-card">
        <table class="cb-table table-fixed">
            <thead>
                <tr>
                    <th class="w-28 sm:w-36">Time</th>
                    <th class="hidden w-40 sm:table-cell">Page</th>
                    <th class="w-32 sm:w-44">Customer</th>
                    <th>Message</th>
                </tr>
            </thead>
            <tbody>
            @foreach($chats as $c)
                <tr>
                    <td class="text-xs text-slate-500">{{ $c->received_at?->timezone('Asia/Manila')->format('Y-m-d H:i') }}</td>
                    <td class="hidden break-words sm:table-cell">{{ $c->page_name ?? 'Unknown page' }}</td>
                    <td class="break-words">
                        @if($c->psid)
                            <a href="{{ route('chatblast.chats.customer', array_filter(['page_id' => $c->page_id, 'psid' => $c->psid], fn ($v) => $v !== null)) }}" class="text-blue-700 hover:underline">{{ $c->name ?: 'PSID …' . mb_substr($c->psid, -4) }}</a>
                        @else
                            {{ $c->name ?: '—' }}
                        @endif
                        <div class="text-xs text-slate-500 sm:hidden">{{ $c->page_name ?? 'Unknown page' }}</div>
                    </td>
                    <td class="whitespace-pre-line break-words">@if((string) $c->message !== ""){{ $c->message }}@else<span class="italic text-slate-400">no text (sticker or photo?)</span>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-3 flex items-center gap-3 text-sm text-slate-600">
        <span>Page {{ $chats->currentPage() }} · {{ $chats->count() }} message{{ $chats->count() === 1 ? "" : "s" }}</span>
        @if($chats->previousPageUrl())<a href="{{ $chats->previousPageUrl() }}" class="cb-btn cb-btn-sm">‹ Newer</a>@endif
        @if($chats->nextPageUrl())<a href="{{ $chats->nextPageUrl() }}" class="cb-btn cb-btn-sm">Older ›</a>@endif
    </div>
@endif

{{-- Ang Setup box: modal sa gitna (hindi sticky). Isara: ✕, Escape, o pindot sa labas. Walang key na ipinapakita: ang
     block ay isinusulat ng botcake:chat-setup; dito ay kung may key ang page (oo/hindi) at ang mga tinanggihan ngayon. --}}
<div id="setup" class="fixed inset-0 z-50 hidden overflow-y-auto bg-slate-900/30 p-4 sm:p-8" aria-labelledby="setup-title" role="dialog" aria-modal="true">
    <div class="mx-auto w-full max-w-2xl rounded-xl bg-white p-5 text-sm shadow-lg">
        <div class="mb-3 flex items-center justify-between">
            <div id="setup-title" class="font-semibold">Botcake setup: Chat to site block</div>
            <button id="setup-close" type="button" class="px-2 text-slate-500 hover:text-slate-800" aria-label="Close">✕</button>
        </div>
        <p class="mb-3 text-slate-600">Each page's Default Reply gets a "Chat to site" JSON API block with the page's own key. The block is written by the server command <span class="font-mono">php artisan botcake:chat-setup "Page name"</span> (or <span class="font-mono">--all --dry-run</span> first, then <span class="font-mono">--all --confirm</span>); keys are never shown here. Chats are kept only from our pages' own customers.</p>

        <dl class="grid grid-cols-[6rem_1fr] gap-x-3 gap-y-2">
            <dt class="text-slate-500">Method</dt>
            <dd class="font-mono">POST</dd>
            <dt class="text-slate-500">URL</dt>
            <dd class="break-all font-mono">{{ $setup['url'] }}</dd>
            <dt class="text-slate-500">Header</dt>
            <dd><span class="font-mono">X-Chat-Key</span> = the page's own key</dd>
            <dt class="text-slate-500">Body (JSON)</dt>
            <dd><code class="break-all font-mono text-xs">{{ $setup['body'] }}</code></dd>
        </dl>

        <h3 class="mb-2 mt-4 font-medium">Page keys</h3>
        @if($setup['pages'])
            <table class="cb-table table-fixed">
                <thead><tr><th>Page</th><th class="w-32">Botcake ID</th><th class="w-24">Own key</th></tr></thead>
                <tbody>
                @foreach($setup['pages'] as $p)
                    <tr><td class="break-words">{{ $p['name'] }}</td><td class="break-all font-mono text-xs">{{ $p['page_id'] }}</td><td>{{ $p['has_key'] ? 'yes' : 'no' }}</td></tr>
                @endforeach
                </tbody>
            </table>
        @else
            <p class="text-slate-500">No page has a Botcake ID yet.</p>
        @endif

        <h3 class="mb-2 mt-4 font-medium">Refused today</h3>
        <ul class="space-y-1">
            @foreach($setup['refused'] as $label => $n)
                <li class="flex justify-between gap-3"><span>{{ $label }}</span><span class="font-mono">{{ $n }}</span></li>
            @endforeach
        </ul>
        <p class="mt-3 text-xs text-slate-500">"PSID not on the page" chats are answered but not kept. The site answers <span class="font-mono">{"ok":true}</span> and never replies to the customer.</p>
        <p id="setup-note" class="mt-2 min-h-[1rem] text-xs text-green-700" aria-live="polite"></p>
    </div>
</div>

<script>
(function () {
    const box = document.getElementById('setup'), note = document.getElementById('setup-note');
    const open = () => { box.classList.remove('hidden'); document.getElementById('setup-close').focus(); };
    const close = () => { box.classList.add('hidden'); note.textContent = ''; document.getElementById('setup-open').focus(); };
    document.getElementById('setup-open').addEventListener('click', open);
    document.getElementById('setup-close').addEventListener('click', close);
    box.addEventListener('click', e => { if (e.target === box) close(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !box.classList.contains('hidden')) close(); });
    box.querySelectorAll('[data-copy]').forEach(b => b.addEventListener('click', () => {
        navigator.clipboard.writeText(b.dataset.copy).then(() => { note.textContent = 'Copied.'; }, () => { note.textContent = 'Copy failed; select the text instead.'; });
    }));
})();
</script>
@endsection
