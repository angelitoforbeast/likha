@extends('layouts.chatblast')

@section('title', 'Chatbot · Owners')
@section('heading', 'Page owners')

@section('description')
    @if($ceo)
        Every page connected in Forbeast and which site answers it. Claim a new page, move a page to {{ \App\Services\Chatbot\ForbeastSite::label($other) }}, or ask for one of theirs; the other site's CEO accepts. Values, saved customer details and follow-ups move with the page.
    @else
        Pages connected in Forbeast that no site has yet, and the pages on {{ \App\Services\Chatbot\ForbeastSite::label($site) }}. Claim a new page to bring it here.
    @endif
@endsection

@php
    // Ang oras mula sa Forbeast (ISO), sa oras ng Manila; ang hindi mabasa ay ipinapakita gaya ng dati.
    $at = function (?string $iso) {
        if ($iso === null) return '';
        try { return \Carbon\Carbon::parse($iso)->timezone('Asia/Manila')->format('M j, Y g:i A'); } catch (\Throwable) { return $iso; }
    };
    $label = fn (?string $s) => \App\Services\Chatbot\ForbeastSite::label($s);
    $text = fn ($v) => is_scalar($v) ? (string) $v : '';
@endphp

@section('content')
<div class="cb-page">
    @if(session('owners_status'))
        <div class="mb-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ session('owners_status') }}</div>
    @endif
    @if(session('owners_error'))
        <div class="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">{{ session('owners_error') }}</div>
    @endif

    @if(!$linked)
        <div class="cb-card p-4 text-sm text-slate-700">
            <p class="font-medium text-slate-900">This site is not linked to Forbeast yet.</p>
            <p class="mt-1">Set FORBEAST_SITE_KEY (the site system's fbs_ key) and FORBEAST_SITE_SECRET (its webhook secret) in the server's environment, with GATEWAY_URL. Until then nothing here can be read or changed.</p>
        </div>
    @else
        @if($error)
            <div class="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">Cannot read the pages: {{ $error }}</div>
        @endif

        @if($history)
            <section class="cb-card mb-3 min-w-0 p-3" data-history="{{ $history['page'] }}">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold text-slate-900">History of {{ collect($pages)->firstWhere('id', $history['page'])['name'] ?? 'page ' . $history['page'] }}</h2>
                    <a href="{{ url('/chatbot/owners') }}" class="text-sm text-slate-500 hover:text-slate-900">Close</a>
                </div>
                @if($history['error'])
                    <p class="mt-2 text-sm text-red-700">{{ $history['error'] }}</p>
                @elseif(!$history['items'])
                    <p class="mt-2 text-sm text-slate-500">No requests yet.</p>
                @endif
                <ol class="mt-2 space-y-2 text-sm">
                    @foreach($history['items'] as $t)
                        <li class="break-words rounded-md border border-slate-100 p-2">
                            <div class="font-medium text-slate-800">#{{ $text($t['id'] ?? '') }} · {{ $text($t['direction'] ?? '') }} {{ $label($text($t['from'] ?? '')) }} → {{ $label($text($t['to'] ?? '')) }} · {{ $text($t['status'] ?? '') }}</div>
                            <div class="text-slate-500">asked by {{ $text($t['requested_by'] ?? '') }} · {{ $at($text($t['requested_at'] ?? '') ?: null) }}</div>
                            @if(is_array($t['steps'] ?? null))
                                <ul class="mt-1 space-y-0.5 text-xs text-slate-600">
                                    @foreach($t['steps'] as $s)
                                        @if(is_array($s))
                                            <li>{{ $text($s['step'] ?? '') }} · {{ $text($s['status'] ?? '') }}@if($text($s['actor'] ?? '') !== '') · {{ $text($s['actor']) }}@endif @if($text($s['at'] ?? '') !== '') · {{ $at($text($s['at'])) }}@endif @if($text($s['error'] ?? ($s['message'] ?? '')) !== '') · {{ $text($s['error'] ?? $s['message']) }}@endif</li>
                                        @endif
                                    @endforeach
                                </ul>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </section>
        @endif

        @if(!$error && !$pages)
            <p class="text-sm text-slate-500">Forbeast lists no pages yet.</p>
        @endif

        @php
            // Isang page kada row, naka-grupo: ang walang may-ari sa taas, ang sa site na ito, at (CEO lang) ang sa kabila.
            // Naka-sort na ayon sa pangalan ang $pages; ang marketing ay walang page ng kabilang site (sinala sa controller).
            $groups = [['available', 'Available to claim', null, 'No pages to claim right now.'], ['site', 'On ' . $label($site), $site, 'No pages on ' . $label($site) . ' yet.']];
            if ($ceo) $groups[] = ['other', 'On ' . $label($other), $other, 'No pages on ' . $label($other) . '.'];
        @endphp
        <div class="cb-scroll space-y-4">
            @foreach($groups as [$group, $title, $owner, $empty])
            @php $inGroup = array_values(array_filter($pages, fn ($p) => $p['owner'] === $owner)); @endphp
            <section data-group="{{ $group }}" class="min-w-0">
            <h2 class="mb-2 text-sm font-semibold text-slate-900">{{ $title }} <span class="font-normal text-slate-500">({{ count($inGroup) }})</span></h2>
            @if(!$inGroup)
                <p class="text-sm text-slate-500">{{ $empty }}</p>
            @endif
            <ul class="space-y-3">
                @foreach($inGroup as $p)
                    @php
                        $ownerText = $p['owner'] === null ? 'Unowned' : ($p['owner'] === $site ? 'This site (' . $label($site) . ')' : $label($p['owner']));
                        $actions = [];
                        if ($p['owner'] === null) $actions[] = ['take', 'Claim', \App\Services\Chatbot\ForbeastSite::phrase('TAKE', $p['name'], $site), 'The page comes here with the bot Off until you set it up and turn it on.'];
                        elseif ($ceo && $p['owner'] === $site) $actions[] = ['move', 'Move to ' . $label($other), \App\Services\Chatbot\ForbeastSite::phrase('TRANSFER', $p['name'], $other), $label($other) . "'s CEO has to accept. The page keeps working here until then."];
                        elseif ($ceo) $actions[] = ['ask', 'Ask for this page', \App\Services\Chatbot\ForbeastSite::phrase('TRANSFER', $p['name'], $site), $label($p['owner']) . "'s CEO has to accept. The page keeps working there until then."];
                    @endphp
                    <li data-page="{{ $p['id'] }}" class="cb-card min-w-0 p-3">
                        <div class="flex min-w-0 items-start gap-3">
                            @if($p['picture'])
                                <img src="{{ $p['picture'] }}" alt="" class="h-10 w-10 shrink-0 rounded-full object-cover" loading="lazy" referrerpolicy="no-referrer">
                            @else
                                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-slate-100 text-sm font-semibold text-slate-500" aria-hidden="true">{{ mb_strtoupper(mb_substr($p['name'], 0, 1)) }}</span>
                            @endif
                            <div class="min-w-0 flex-1">
                                <div class="break-words font-medium text-slate-900">{{ $p['name'] }}</div>
                                <div class="text-xs text-slate-500">Page ID {{ $p['id'] }}@if($p['connected_at']) · connected {{ $at($p['connected_at']) }}@endif</div>
                            </div>
                            <span data-owner class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium {{ $p['owner'] === null ? 'bg-amber-50 text-amber-800' : ($p['owner'] === $site ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-700') }}">{{ $ownerText }}</span>
                        </div>

                        @if($p['hold'])
                            <p data-hold class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-2 py-1.5 text-sm text-amber-800" role="status">Follow-ups paused for transfer #{{ $p['hold']['transfer'] }} since {{ $at($p['hold']['since']) }}</p>
                        @endif

                        @if($p['pending'])
                            <ul class="mt-3 space-y-2">
                                @foreach($p['pending'] as $r)
                                    @php($ours = $r['by'] === $site)
                                    <li class="rounded-md border border-slate-200 bg-slate-50 p-2 text-sm">
                                        <div class="break-words text-slate-800">{{ $ours ? 'This site asked to move it to ' . $label($r['to']) : $label($r['by']) . ' asks to move it to ' . $label($r['to']) }} <span class="text-slate-500">({{ $r['direction'] }})</span></div>
                                        <div class="break-words text-xs text-slate-500">by {{ $r['requested_by'] }} · {{ $at($r['requested_at']) }}</div>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            @if($ours)
                                                <form method="POST" action="{{ url('/chatbot/owners/cancel') }}" data-action="cancel" data-target="{{ $r['id'] }}">
                                                    @csrf <input type="hidden" name="target" value="{{ $r['id'] }}">
                                                    <button class="cb-btn cb-btn-sm">Cancel request</button>
                                                </form>
                                            @else
                                                @include('chatbot.partials.owners-confirm', ['action' => 'accept', 'target' => $r['id'], 'button' => 'Accept',
                                                    'phrase' => \App\Services\Chatbot\ForbeastSite::phrase('TRANSFER', $p['name'], $r['to']), 'note' => 'The move starts now: values, saved details and follow-ups are copied, then the page answers on ' . $label($r['to']) . '.'])
                                                <form method="POST" action="{{ url('/chatbot/owners/decline') }}" data-action="decline" data-target="{{ $r['id'] }}">
                                                    @csrf <input type="hidden" name="target" value="{{ $r['id'] }}">
                                                    <button class="cb-btn cb-btn-sm">Decline</button>
                                                </form>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <div class="mt-3 flex flex-wrap items-start gap-2">
                            @foreach($actions as [$action, $button, $phrase, $note])
                                @include('chatbot.partials.owners-confirm', ['action' => $action, 'target' => $p['id'], 'button' => $button, 'phrase' => $phrase, 'note' => $note])
                            @endforeach
                            <a href="{{ url('/chatbot/owners?history=' . $p['id']) }}" class="cb-btn cb-btn-sm">History</a>
                        </div>
                    </li>
                @endforeach
            </ul>
            </section>
            @endforeach
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
    // Kapag binuksan ang Claim (o ibang confirm): makikita ang buong form sa loob ng scroll at nasa password na ang cursor.
    document.addEventListener('toggle', (e) => {
        const d = e.target;
        if (!(d instanceof HTMLDetailsElement) || !d.matches('[data-owners-confirm]') || !d.open) return;
        d.querySelector('form')?.scrollIntoView({block: 'nearest', behavior: 'smooth'});
        d.querySelector('input[name="password"]')?.focus({preventScroll: true});
    }, true);
</script>
@endpush
