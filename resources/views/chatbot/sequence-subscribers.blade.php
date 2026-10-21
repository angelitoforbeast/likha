@extends('layouts.chatblast')

@section('title', 'Chatbot · Sequence · ' . $sequence->name . ' · Subscribers')
@section('heading', $sequence->name . ': subscribers')

@section('description')
    Newest first, {{ \App\Http\Controllers\ChatbotSequenceController::PER_PAGE }} at a time. Times are Manila time. Click a row for its details.
@endsection

@section('actions')
    <a href="{{ route('chatbot.sequences.edit', $sequence->id) }}" class="text-blue-700 hover:underline">← Edit sequence</a>
@endsection

@push('styles')
<style>
    /* Ang kahon ay kasing-taas ng laman pero hanggang sa natitirang taas ng screen lang (.cb-page ng layout), kaya ang
       horizontal bar ay laging kita at ang page ay hindi nag-i-scroll. */
    #ss-table thead th { position: sticky; top: 0; z-index: 10; background: #fff; }
</style>
@endpush

@section('content')
@php
    $fmt = fn ($t) => $t ? $t->timezone('Asia/Manila')->format('Y-m-d H:i') : '—';
    $color = ['active' => 'bg-green-100 text-green-800', 'paused' => 'bg-amber-100 text-amber-900', 'completed' => 'bg-blue-100 text-blue-800',
        'unsubscribed' => 'bg-slate-200 text-slate-700', 'error' => 'bg-red-100 text-red-800'];
@endphp
<div class="cb-page">
<form method="GET" class="cb-filters mb-3 flex flex-wrap items-end gap-3 text-sm">
    <label class="flex flex-col text-xs">Status
        <select name="status" class="border px-2 py-1 text-sm text-gray-900">
            <option value="">All</option>
            @foreach(\App\Models\ChatbotSequenceSubscription::STATUSES as $st)<option value="{{ $st }}" @selected($status === $st)>{{ ucfirst($st) }}</option>@endforeach
        </select>
    </label>
    <label class="flex flex-col text-xs">Page
        <select name="page_id" class="max-w-[14rem] border px-2 py-1 text-sm text-gray-900">
            <option value="">All pages</option>
            @foreach($pages as $id => $name)<option value="{{ $id }}" @selected($pageId === (string) $id)>{{ $name }}</option>@endforeach
        </select>
    </label>
    <button class="cb-btn cb-btn-primary">Show</button>
</form>

@if($rows->isEmpty())
    <p class="cb-card px-4 py-3 text-sm text-slate-600">No subscribers{{ $status || $pageId !== '' ? ' with these filters' : ' yet' }}.</p>
@else
    <div id="ss-box" class="cb-card min-h-[12rem] shrink overflow-auto">
        <table id="ss-table" class="cb-table">
            <thead><tr><th>Customer</th><th>Page</th><th class="w-28">Status</th><th class="w-20">Step</th><th class="w-36">Next send</th><th class="w-36">Last sent</th><th class="w-24">Enrolled via</th><th class="w-32">Exit reason</th></tr></thead>
            <tbody>
            @foreach($rows as $r)
                @php
                    $name = $names[$r->page_id . ':' . $r->psid] ?? null;
                    $detail = ['Customer' => ($name ? $name . ' · ' : '') . 'PSID ' . $r->psid, 'Page' => ($pages[(string) $r->page_id] ?? 'Page ' . $r->page_id) . ' (' . $r->page_id . ')',
                        'Status' => $r->status, 'Step' => $r->current_step . ' of ' . $stepCount, 'Next send' => $fmt($r->next_send_at), 'Last sent' => $fmt($r->last_sent_at),
                        'Enrolled via' => $r->enrolled_via, 'Enrolled' => $fmt($r->created_at), 'Exit reason' => $r->exit_reason ?? '—', 'Updated' => $fmt($r->updated_at)];
                @endphp
                <tr data-row="{{ $r->id }}" data-detail="{{ json_encode($detail) }}" tabindex="0" class="cursor-pointer">
                    <td class="break-words">{{ $name ?? '' }} <span class="text-xs text-slate-500">PSID …{{ substr((string) $r->psid, -4) }}</span></td>
                    <td class="break-words">{{ $pages[(string) $r->page_id] ?? 'Page ' . $r->page_id }}</td>
                    <td><span class="rounded-full px-2 py-0.5 text-xs {{ $color[$r->status] ?? '' }}">{{ $r->status }}</span></td>
                    <td>{{ $r->status === 'completed' ? '—' : $r->current_step }}</td>
                    <td class="text-xs">{{ $fmt($r->next_send_at) }}</td>
                    <td class="text-xs">{{ $fmt($r->last_sent_at) }}</td>
                    <td class="text-xs">{{ $r->enrolled_via }}</td>
                    <td class="break-words text-xs">{{ $r->exit_reason ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @if($next)
        <div class="mt-2"><a class="cb-btn cb-btn-sm" href="{{ request()->fullUrlWithQuery(['before' => $next]) }}">Older</a></div>
    @endif
@endif
</div>

<div id="ss-modal" class="fixed inset-0 z-50 hidden overflow-y-auto bg-slate-900/30 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="ss-title">
    <div class="mx-auto w-full max-w-lg rounded-xl bg-white p-5 text-sm shadow-lg">
        <div class="mb-3 flex items-center justify-between"><div id="ss-title" class="font-semibold">Subscriber</div>
            <button type="button" id="ss-close" class="px-2 text-slate-500 hover:text-slate-800" aria-label="Close">✕</button></div>
        <dl id="ss-detail" class="grid grid-cols-[7rem_1fr] gap-x-3 gap-y-1"></dl>
        <p class="mt-3 text-xs text-slate-500">The customer's executed actions are in Inbox → "View executed actions".</p>
    </div>
</div>
<script>
(function () {
    const modal = document.getElementById('ss-modal'), dl = document.getElementById('ss-detail');
    const open = tr => {
        const d = JSON.parse(tr.dataset.detail);
        dl.replaceChildren(...Object.entries(d).flatMap(([k, v]) => { const dt = document.createElement('dt'), dd = document.createElement('dd');
            dt.className = 'text-slate-500'; dt.textContent = k; dd.className = 'break-words'; dd.textContent = v; return [dt, dd]; }));
        modal.classList.remove('hidden'); document.getElementById('ss-close').focus();
    };
    document.querySelectorAll('tr[data-row]').forEach(tr => { tr.addEventListener('click', () => open(tr)); tr.addEventListener('keydown', e => { if (e.key === 'Enter') open(tr); }); });
    const close = () => modal.classList.add('hidden');
    document.getElementById('ss-close').addEventListener('click', close);
    modal.addEventListener('click', e => { if (e.target === modal) close(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
})();
</script>
@endsection
