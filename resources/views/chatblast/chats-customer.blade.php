@extends('layouts.chatblast')

@php
    $first = $chats->last();
    $who = $first?->name ?: 'PSID …' . mb_substr($psid, -4);
    $page = $first?->page_name ?? 'Unknown page';
@endphp

@section('title', 'Chatblast · Chats · ' . $who)
@section('heading', $who)

@section('description')
    {{ $page ? $page . ' · ' : '' }}Messages in time order, oldest first{{ $chats->count() >= $max ? ' (the latest ' . $max . ')' : '' }}.
@endsection

@section('actions')
    <a href="{{ route($chatsRoute) }}" class="text-blue-700 hover:underline">← All chats</a>
@endsection

@section('content')
@if(($fields ?? null) !== null)
    {{-- Ang customer fields ng chatbot. Ang CEO ang nakakapagbago o nakakabura. --}}
    <section id="cfields" class="cb-card mb-4 px-4 py-3 text-sm" data-url="{{ route('chatbot.customer-fields') }}" data-page-id="{{ $pageId }}" data-psid="{{ $psid }}">
        <h2 class="mb-2 font-semibold">Customer fields</h2>
        @if(!$fields && !$canEditFields)
            <p class="text-slate-500">No fields yet.</p>
        @endif
        <table class="w-full table-fixed text-sm">
            <tbody>
            @foreach($fields as $key => $value)
                <tr class="border-b border-slate-100 align-top" data-key="{{ $key }}">
                    <td class="w-40 py-1.5 pr-3 font-mono text-xs text-slate-600 break-words">{{ $key }}</td>
                    <td class="py-1.5">
                        @if($canEditFields)
                            <div class="flex flex-wrap items-start gap-2">
                                <textarea rows="1" maxlength="16000" class="min-w-0 flex-1 rounded-md border border-slate-300 px-2 py-1 text-sm" aria-label="Value of {{ $key }}">{{ $value }}</textarea>
                                <button type="button" data-save class="cb-btn cb-btn-sm">Save</button>
                                <button type="button" data-clear class="cb-btn cb-btn-sm">Clear</button>
                            </div>
                        @else
                            <span class="whitespace-pre-line break-words">{{ $value }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @if($canEditFields)
            <form id="cfield-new" class="mt-2 flex flex-wrap items-end gap-2">
                <label class="flex flex-col text-xs text-slate-500">New field<input name="key" maxlength="100" required class="rounded-md border border-slate-300 px-2 py-1 text-sm text-gray-900"></label>
                <label class="flex flex-col text-xs text-slate-500">Value<input name="value" maxlength="16000" class="rounded-md border border-slate-300 px-2 py-1 text-sm text-gray-900"></label>
                <button class="cb-btn cb-btn-sm">Add</button>
            </form>
        @endif
        <p id="cfield-note" class="mt-1 min-h-[1rem] text-xs" aria-live="polite"></p>
    </section>
    @if($canEditFields)
    <script>
    (function () {
        const box = document.getElementById('cfields'), csrf = document.querySelector('meta[name="csrf-token"]').content;
        const note = (t, cls) => { const n = document.getElementById('cfield-note'); n.className = 'mt-1 min-h-[1rem] text-xs ' + cls; n.textContent = t; };
        const save = async (body, reload) => {
            const res = await fetch(box.dataset.url, {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
                body: JSON.stringify(Object.assign({page_id: box.dataset.pageId, psid: box.dataset.psid}, body))});
            const data = await res.json().catch(() => ({}));
            if (!res.ok) return note(data.error || data.message || 'Not saved.', 'text-red-600');
            if (reload) location.reload(); else note('Saved.', 'text-green-700');
        };
        box.querySelectorAll('tr[data-key]').forEach(tr => {
            tr.querySelector('[data-save]').addEventListener('click', () => save({key: tr.dataset.key, value: tr.querySelector('textarea').value}, false));
            tr.querySelector('[data-clear]').addEventListener('click', () => { if (confirm('Clear ' + tr.dataset.key + '?')) save({key: tr.dataset.key, clear: true}, true); });
        });
        document.getElementById('cfield-new')?.addEventListener('submit', ev => { ev.preventDefault(); const f = new FormData(ev.target); save({key: f.get('key'), value: f.get('value')}, true); });
    })();
    </script>
    @endif
@endif

@if($chats->isEmpty())
    <p class="cb-card px-4 py-3 text-sm text-slate-600">No messages from this customer.</p>
@else
    <ol class="cb-card divide-y divide-slate-100">
        @foreach($chats as $c)
            <li class="px-4 py-3 text-sm">
                <div class="text-xs text-slate-500">{{ $c->received_at?->timezone('Asia/Manila')->format('Y-m-d H:i:s') }}</div>
                <div class="mt-1 whitespace-pre-line break-words">@include('chatblast.partials.message', ['c' => $c])</div>
            </li>
        @endforeach
    </ol>
@endif
@endsection
