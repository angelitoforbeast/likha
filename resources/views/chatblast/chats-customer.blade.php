@extends('layouts.chatblast')

@php
    $first = $chats->last();
    $who = $first?->name ?: 'PSID …' . mb_substr($psid, -4);
    $page = $first?->page_name ?? ($pageId !== '' ? 'Page ' . $pageId : null);
@endphp

@section('title', 'Chatblast · Chats · ' . $who)
@section('heading', $who)

@section('description')
    {{ $page ? $page . ' · ' : '' }}Messages in time order, oldest first{{ $chats->count() >= $max ? ' (the latest ' . $max . ')' : '' }}.
@endsection

@section('actions')
    <a href="{{ route('chatblast.chats') }}" class="text-blue-700 hover:underline">← All chats</a>
@endsection

@section('content')
@if($chats->isEmpty())
    <p class="cb-card px-4 py-3 text-sm text-slate-600">No messages from this customer.</p>
@else
    <ol class="cb-card divide-y divide-slate-100">
        @foreach($chats as $c)
            <li class="px-4 py-3 text-sm">
                <div class="text-xs text-slate-500">{{ $c->received_at?->timezone('Asia/Manila')->format('Y-m-d H:i:s') }}</div>
                <div class="mt-1 whitespace-pre-line break-words">{{ $c->message }}</div>
            </li>
        @endforeach
    </ol>
@endif
@endsection
