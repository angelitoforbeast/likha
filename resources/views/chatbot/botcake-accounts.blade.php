@extends('layouts.chatblast')

@section('title', 'Chatbot · Botcake Accounts')
@section('heading', 'Botcake Accounts')

@section('description')
    Each person adds their Botcake account's Access Token once. Likha lists the account's pages and, with a button, copies the page tokens into the pages already in Likha. The token is stored encrypted and never shown again.
@endsection

{{-- Spec 272. No token is ever rendered here: not the account token, not a page token, not part of one. --}}
@section('content')
<div class="cb-page overflow-auto">
    @if(session('status'))
        <div class="mb-3 rounded-md border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-800" role="status" data-ba-status>{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-3 rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert" data-ba-error>{{ session('error') }}</div>
    @endif

    <div class="cb-card mb-4 p-4">
        <h2 class="mb-2 text-sm font-semibold text-slate-800">Add an account</h2>
        @error('access_token')
            <div class="mb-3 rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert" data-ba-form-error>{{ $message }} Nothing was saved.</div>
        @enderror
        <form method="POST" action="{{ route('chatbot.botcake-accounts.store') }}" class="flex flex-wrap items-end gap-2 text-sm" autocomplete="off">
            @csrf
            <label class="flex flex-col text-xs text-slate-500">Label
                <input name="label" maxlength="{{ \App\Services\BotcakeAccounts::MAX_LABEL }}" required value="{{ old('label') }}" placeholder="e.g. Marketing A – Botcake" class="w-64 rounded-md border border-slate-300 px-2 py-1 text-sm text-gray-900">
            </label>
            <label class="flex flex-col text-xs text-slate-500">Access Token
                <input type="password" name="access_token" required autocomplete="new-password" spellcheck="false" placeholder="Paste the token" class="w-80 rounded-md border border-slate-300 px-2 py-1 text-sm text-gray-900">
            </label>
            <button class="cb-btn cb-btn-primary">Save</button>
        </form>
        <details class="mt-3 text-sm text-slate-600" data-ba-help>
            <summary class="cursor-pointer text-slate-700">How to get your Access Token</summary>
            <ol class="mt-2 list-decimal space-y-1 pl-5">
                <li>Open botcake.io and click your name (top right), then your name at the top of the menu.</li>
                <li>Open the "Account settings" tab.</li>
                <li>In the "Access Token" row, click the copy icon only — do not click the other icons (they may reset or delete the token).</li>
                <li>Paste it here and save.</li>
            </ol>
        </details>
    </div>

    @if(!$accounts)
        <p class="cb-card px-4 py-3 text-sm text-slate-600">No account yet. Add one above.</p>
    @else
        <div class="cb-card mb-4 overflow-auto">
            <table class="cb-table" data-ba-accounts>
                <thead><tr><th>Account</th><th>Added</th><th class="w-20">Pages</th><th>Last check</th><th class="w-80">Actions</th></tr></thead>
                <tbody>
                @foreach($accounts as $a)
                    @php $failed = $a['check_ok'] === false || !$a['readable']; @endphp
                    <tr data-ba-account="{{ $a['id'] }}" class="{{ $failed ? 'bg-red-50' : '' }}">
                        <td class="font-medium">{{ $a['label'] }}</td>
                        <td class="text-xs text-slate-600">{{ $a['added_by'] }}<br>{{ $a['added_at'] }}
                            @if($a['updated_at'])<div class="text-slate-500">token updated {{ $a['updated_at'] }} by {{ $a['updated_by'] }}</div>@endif</td>
                        <td>{{ $a['pages'] ?? '–' }}</td>
                        <td class="text-xs">
                            @if($failed)
                                <span class="rounded-full bg-red-100 px-2 py-0.5 font-medium text-red-800" data-ba-check="failed">Failed</span> {{ $a['check_at'] }}
                                @if($a['check_reason'])<div class="mt-1 text-red-800">{{ $a['check_reason'] }}</div>@endif
                            @elseif($a['check_ok'])
                                <span class="rounded-full bg-green-100 px-2 py-0.5 font-medium text-green-800" data-ba-check="ok">OK</span> {{ $a['check_at'] }}
                            @else
                                <span class="text-slate-500">Not checked</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap">
                            <a href="{{ route('chatbot.botcake-accounts.pages', $a['id']) }}" class="cb-btn cb-btn-sm">View pages</a>
                            <form method="POST" action="{{ route('chatbot.botcake-accounts.update-tokens', $a['id']) }}" class="inline">
                                @csrf
                                <button class="cb-btn cb-btn-sm" onclick="return confirm('Copy this account\'s page tokens into the pages already in Likha whose token differs or is missing?')">Update tokens in Likha</button>
                            </form>
                            <form method="POST" action="{{ route('chatbot.botcake-accounts.remove', $a['id']) }}" class="inline">
                                @csrf
                                <button class="cb-btn cb-btn-sm text-red-700" onclick="return confirm('Remove this account? Its stored token is deleted. Page tokens already in Likha stay.')">Remove</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if($viewing)
        @php
            $statusText = [
                'differs' => ['token differs', 'bg-amber-100 text-amber-900'],
                'missing' => ['Likha has no token', 'bg-amber-100 text-amber-900'],
                'no_botcake_token' => ['Botcake gave no token for this page', 'bg-slate-200 text-slate-700'],
                'match' => ['token matches', 'bg-green-100 text-green-800'],
            ];
        @endphp
        <div class="cb-card p-4" data-ba-pages="{{ $viewing['id'] }}">
            <h2 class="mb-2 text-sm font-semibold text-slate-800">Pages of {{ $viewing['label'] }}</h2>
            @if($pageList['error'])
                <div class="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ $pageList['error'] }}</div>
            @else
                @php $c = $pageList['counts']; @endphp
                @php $inLikha = $c['differs'] + $c['missing'] + $c['no_botcake_token'] + $c['match']; @endphp
                <p class="mb-2 text-sm text-slate-700" data-ba-count>{{ $c['pages'] }} {{ $c['pages'] === 1 ? 'page' : 'pages' }}: {{ $inLikha }} in Likha ({{ implode(', ', array_filter([
                    $c['match'] ? $c['match'] . ' match' : null,
                    $c['differs'] ? $c['differs'] . ' differ' . ($c['differs'] === 1 ? 's' : '') : null,
                    $c['missing'] ? $c['missing'] . ' with no token in Likha' : null,
                    $c['no_botcake_token'] ? $c['no_botcake_token'] . ' with no token from Botcake' : null,
                ])) ?: 'none' }}), {{ $c['none'] }} not in Likha</p>
                <table class="cb-table">
                    <thead><tr><th>Botcake page</th><th>Page ID</th><th>Role</th><th>In Likha</th></tr></thead>
                    <tbody>
                    @foreach($pageList['rows'] as $r)
                        <tr data-ba-page="{{ $r['page_id'] }}" data-ba-page-status="{{ $r['status'] }}">
                            <td>{{ $r['name'] }}</td>
                            <td class="font-mono text-xs">{{ $r['page_id'] }}</td>
                            <td class="text-xs">{{ $r['role'] }}</td>
                            <td class="text-sm">
                                @if($r['likha'] === null)
                                    <span class="text-slate-500">Not in Likha</span>
                                @else
                                    In Likha as <span class="font-medium">{{ $r['likha'] }}</span> —
                                    <span class="rounded-full px-2 py-0.5 text-xs {{ $statusText[$r['status']][1] }}">{{ $statusText[$r['status']][0] }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif
</div>
@endsection
