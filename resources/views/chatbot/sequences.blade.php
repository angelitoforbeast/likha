@extends('layouts.chatblast')

@section('title', 'Chatbot · Sequences')
@section('heading', 'Sequences')

@section('description')
    Timed follow-ups: each step runs a published flow after its delay, inside the send hours and Messenger's 24-hour window. Customers join from a flow's Sequence step or the Inbox.
@endsection

@push('styles')
<style>
    #sq-box { max-height: calc(100vh - 19rem); min-height: 12rem; }
    #sq-table thead th { position: sticky; top: 0; z-index: 10; background: #fff; }
</style>
@endpush

@section('content')
@include('chatbot.partials.switch', ['repliesOn' => $repliesOn])

<form method="POST" action="{{ route('chatbot.sequences.store') }}" class="mb-4 flex flex-wrap items-end gap-2 text-sm">
    @csrf
    <label class="flex flex-col text-xs text-slate-500">Name
        <input name="name" maxlength="120" required value="{{ old('name') }}" placeholder="e.g. PREORDER SEQUENCE" class="rounded-md border border-slate-300 px-2 py-1 text-sm text-gray-900">
    </label>
    <button class="cb-btn cb-btn-primary">+ New sequence</button>
    @error('name')<span class="text-xs text-red-700" role="alert">{{ $message }}</span>@enderror
</form>

@if($sequences->isEmpty())
    <p class="cb-card px-4 py-3 text-sm text-slate-600">No sequences yet. Name one above, then add its steps.</p>
@else
    <div id="sq-box" class="cb-card overflow-auto">
        <table id="sq-table" class="cb-table">
            <thead><tr><th>Sequence</th><th class="w-20">Steps</th><th class="w-24">Enrolled</th><th class="w-24">Completed</th><th class="w-24">Status</th><th class="w-48">Actions</th></tr></thead>
            <tbody>
            @foreach($sequences as $s)
                <tr data-sequence="{{ $s->id }}">
                    <td class="break-words"><a href="{{ route('chatbot.sequences.edit', $s->id) }}" class="font-medium text-blue-700 hover:underline">{{ $s->name }}</a>
                        @if($s->description)<div class="line-clamp-1 text-xs text-slate-500">{{ $s->description }}</div>@endif</td>
                    <td>{{ $s->steps_count }}</td>
                    <td>{{ (int) ($counts[$s->id]['active'] ?? 0) }}</td>
                    <td>{{ (int) ($counts[$s->id]['completed'] ?? 0) }}</td>
                    <td><span class="rounded-full px-2 py-0.5 text-xs {{ $s->active ? 'bg-green-100 text-green-800' : 'bg-slate-200 text-slate-700' }}">{{ $s->active ? 'Active' : 'Paused' }}</span></td>
                    <td class="whitespace-nowrap">
                        <a href="{{ route('chatbot.sequences.edit', $s->id) }}" class="cb-btn cb-btn-sm">Edit</a>
                        <a href="{{ route('chatbot.sequences.subscribers', $s->id) }}" class="cb-btn cb-btn-sm">Subscribers</a>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection
