@extends('layouts.chatblast')

@section('title', 'Chatbot · Health')
@section('heading', 'Health')

@section('description')
    How fast the bot answers, how many replies fail and why, and how the AI calls behave: today against yesterday, and day by day. Refreshed every 5 minutes; the lights follow the thresholds below.
@endsection

@php
    // Mga format: segundo, porsyento, at ang pagbabago laban sa kahapon.
    $num = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.');
    $fmt = fn ($v, $unit) => $v === null ? '—' : $num($v) . ($unit === 's' ? ' s' : ($unit === '%' ? '%' : ''));
    $change = function ($key, $unit) use ($tiles, $num) {
        $d = $tiles['delta'][$key] ?? null;
        if ($d === null) return 'no data to compare';
        $p = $tiles['delta_pct'][$key] ?? null;
        $sign = $d > 0 ? '+' : '';
        return $sign . rtrim(rtrim(number_format($d, 1), '0'), '.') . ($unit === 's' ? ' s' : ($unit === '%' ? ' pts' : '')) . ($p !== null && $unit !== '%' ? ' (' . $sign . $num($p) . '%)' : '') . ' vs yesterday';
    };
    $dot = ['green' => 'bg-emerald-500', 'amber' => 'bg-amber-400', 'red' => 'bg-red-500', 'none' => 'bg-slate-300'];
    $t = $tiles['today'];
    // [label, key sa tile, unit, key ng ilaw (kung meron)]
    $cards = [
        ['Inbound messages', 'inbound', '', null],
        ['Answered', 'answered_pct', '%', 'answered_pct'],
        ['First reply avg (accepted)', 'reply_avg', 's', null],
        ['First reply median (accepted)', 'reply_median', 's', null],
        ['First reply p90 (accepted)', 'reply_p90', 's', 'reply_p90'],
        ['First reply max (accepted)', 'reply_max', 's', 'reply_max'],
        ['Real first reply median', 'real_median', 's', null],
        ['Real first reply p90', 'real_p90', 's', null],
        ['Real first reply max', 'real_max', 's', null],
        ['Replies sent', 'sent', '', null],
        ['Failed replies', 'failed', '', null],
        ['Failed % (all)', 'failed_pct', '%', null],
        ['Failed % (without customer not reachable)', 'failed_pct_excl', '%', 'failed_pct'],
        ['AI calls', 'ai_calls', '', null],
        ['AI calls failed', 'ai_failed', '', null],
        ['AI calls failed %', 'ai_failed_pct', '%', 'ai_failed_pct'],
        ['AI calls retried', 'ai_retried', '', null],
        ['AI time avg', 'ai_avg', 's', null],
        ['AI time p90', 'ai_p90', 's', 'ai_p90'],
        ['AI time max', 'ai_max', 's', null],
        ['Orders', 'orders', '', null],
        ['Sheet writes failed', 'sheet_failed', '', 'sheet_failed'],
        ['Comments in', 'comments_in', '', null],
        ['Comments replied', 'comments_replied', '', null],
        ['Comments failed', 'comments_failed', '', null],
        ['Attachments ignored', 'ignored', '', null],
    ];
    $f = $filters;
    $keep = array_filter(['page_id' => $f['page_id'], 'bot' => $f['bot'], 'from' => $f['from'], 'to' => $f['to']], fn ($v) => $v !== null);
    $chart = ['days' => $days, 'hourly' => $hourly];
    // Ang "first reply" ng page sa Forbeast ay ang pagtanggap ng Forbeast, hindi ang padala sa customer: may note kapag may numero ang Forbeast.
    $fbLag = $forbeast && $forbeast['status'] === 'ok' ? $forbeast['overall']['send_lag_seconds']['value'] : null;
    // Isang page ang pinili: ang lag ng page na iyon (kung may numero ang Forbeast para rito).
    $fbPage = $fbLag !== null && $f['page_id'] !== null ? collect($forbeast['pages'])->firstWhere('page_id', (string) $f['page_id']) : null;
    if ($fbPage) $fbLag = $fbPage['send_lag_seconds'];
    $fbNote = $fbLag !== null && in_array($f['bot'], [null, 'Forbeast'], true) && ($f['page_id'] === null || in_array((string) $f['page_id'], $forbeast['page_ids'], true))
        ? 'accepted by Forbeast; real send lag: ' . $fbLag . 's' : null;
@endphp

@section('content')
<div class="shrink-0 space-y-5 pb-8">
    @include('chatbot.partials.send-lag-alarm', ['sendLagAlarm' => $sendLagAlarm])
    @if(session('status'))<p class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ session('status') }}</p>@endif
    @if($errors->any())
        <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">
            @foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach
        </div>
    @endif

    {{-- Mga page na may problema sa huling oras (BotHealth::problems): ang pula muna; pindutin ang page para sa mga pumalya nito. --}}
    <section aria-labelledby="h-problems" class="space-y-2" id="page-problems">
        <h2 id="h-problems" class="text-lg font-semibold">Pages with problems</h2>
        @if($problems)
            <div class="cb-card overflow-x-auto">
                <table class="cb-table" data-list="page-problems">
                    <thead><tr><th>Page</th><th class="text-right">Sends</th><th class="text-right">Failed</th><th class="text-right">Failed % (without not reachable)</th><th>Top reason</th><th>First failure</th><th>Last failure</th></tr></thead>
                    <tbody>
                    @foreach($problems as $p)
                        <tr data-problem="{{ $p['page_id'] }}" data-light="{{ $p['light'] }}" class="{{ $p['light'] === 'red' ? 'bg-red-50' : 'bg-amber-50' }}">
                            <td><button type="button" class="flex items-center gap-2 text-left font-medium text-blue-700 hover:underline" data-pp-toggle aria-expanded="false" aria-controls="pp-failures-{{ $p['page_id'] }}">
                                <span class="inline-block h-3 w-3 shrink-0 rounded-full {{ $dot[$p['light']] }}" aria-hidden="true"></span>{{ $p['page'] }}<span class="sr-only"> — {{ $p['light'] }}</span></button></td>
                            <td class="text-right">{{ $p['sends'] }}</td>
                            <td class="text-right">{{ $p['failed'] }}@if($p['unreachable'])<div class="text-xs text-slate-500">{{ $p['unreachable'] }} not reachable</div>@endif</td>
                            <td class="text-right">{{ $fmt($p['failed_pct'], '%') }}</td>
                            <td class="min-w-[10rem]">{{ $p['reason'] }}</td>
                            <td class="whitespace-nowrap">{{ substr($p['first_at'], 11, 5) }}</td>
                            <td class="whitespace-nowrap">{{ substr($p['last_at'], 11, 5) }}</td>
                        </tr>
                        <tr id="pp-failures-{{ $p['page_id'] }}" hidden>
                            <td colspan="7" class="bg-slate-50">
                                <p class="mb-1 text-xs font-medium text-slate-600">Latest {{ count($p['failures']) }} failed sends of {{ $p['page'] }}</p>
                                <ul class="space-y-1.5 text-xs">
                                    @foreach($p['failures'] as $x)
                                        <li class="break-words"><span class="whitespace-nowrap font-medium">{{ $x['at'] }}</span> · {{ $x['reason'] }} · <span class="text-slate-600">{{ $x['error'] }}</span>
                                            @if($x['inbox']) · <a href="{{ $x['inbox'] }}" class="whitespace-nowrap text-blue-700 hover:underline">Customer in Inbox</a>@endif</li>
                                    @endforeach
                                </ul>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="cb-card px-3 py-2 text-sm text-slate-600" data-list="page-problems">No page problems in the last hour.</p>
        @endif
        <p class="text-xs text-slate-500">Last {{ \App\Services\Chatbot\BotHealth::PROBLEM_MINUTES }} minutes, per page, refreshed every minute. Red: {{ $thresholds['page_blocked']['red'] ?? '—' }}+ restricted or permission failures, or {{ $thresholds['page_failed_pct']['red'] ?? '—' }}%+ failed with {{ $thresholds['page_min_sends']['amber'] }}+ sends, or {{ \App\Services\Chatbot\ChatEchoes::limit('unanswered_customers') }}+ customers not answered in {{ \App\Services\Chatbot\ChatEchoes::limit('unanswered_minutes') }} min. Amber: {{ $thresholds['page_blocked']['amber'] }}+ restricted or permission failures, or {{ $thresholds['page_failed_pct']['amber'] }}%+ failed with {{ $thresholds['page_min_sends']['amber'] }}+ sends. "Customer not reachable" does not count; skipped sends (paused) are left out.</p>
    </section>

    {{-- Sino ang sumagot (echo ng Meta, ChatEchoes): ang hindi nasagot ng anumang app at ang dobleng sagot ng dalawang app. --}}
    <section aria-labelledby="h-echoes" class="space-y-2" id="who-answered">
        <h2 id="h-echoes" class="text-lg font-semibold">Who answered</h2>
        @if($echoes['pages'])
            <div class="cb-card overflow-x-auto">
                <table class="cb-table" data-list="who-answered">
                    <thead><tr><th>Page</th><th class="text-right">Not answered in {{ \App\Services\Chatbot\ChatEchoes::limit('unanswered_minutes') }} min</th><th>Latest</th><th class="text-right">Main flow doubles</th><th>Latest</th><th class="text-right">Leftover doubles</th><th>Latest</th><th>New customers</th></tr></thead>
                    <tbody>
                    @foreach($echoes['pages'] as $e)
                        <tr data-echo-page="{{ $e['page_id'] }}">
                            <td class="font-medium">{{ $e['page'] }}</td>
                            <td class="text-right">{{ $e['unanswered'] }}@if($e['unanswered_messages'] > $e['unanswered'])<div class="text-xs text-slate-500">{{ $e['unanswered_messages'] }} messages</div>@endif</td>
                            <td class="text-xs">@foreach($e['examples'] as $x)<a href="{{ $x['inbox'] }}" class="block whitespace-nowrap text-blue-700 hover:underline">{{ substr($x['at'], 11, 5) }} · Customer in Inbox</a>@endforeach</td>
                            <td class="text-right" data-main-doubles="{{ $e['main_doubles'] }}">{{ $e['main_doubles'] }}</td>
                            <td class="text-xs">@foreach($e['main_examples'] as $x)<a href="{{ $x['inbox'] }}" class="block whitespace-nowrap text-blue-700 hover:underline">{{ substr($x['at'], 11, 5) }} · New customer in Inbox</a>@endforeach</td>
                            <td class="text-right" data-leftovers="{{ $e['leftovers'] }}">{{ $e['leftovers'] }}</td>
                            <td class="text-xs">@foreach($e['leftover_examples'] as $x)<a href="{{ $x['inbox'] }}" class="block whitespace-nowrap text-blue-700 hover:underline">{{ substr($x['at'], 11, 5) }} · {{ $x['senders'] }}</a>@endforeach</td>
                            <td class="whitespace-nowrap text-xs">{{ $e['new_customers'] }} new, {{ $e['cbb_new'] }} answered by CBB</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="cb-card px-3 py-2 text-sm text-slate-600" data-list="who-answered">{{ $echoes['measured'] ? 'Every customer was answered, no double replies in the last hour.' : 'No echoes yet: Forbeast has not sent any page echo in the last 24 hours.' }}</p>
        @endif
        <p class="text-xs text-slate-500">Last {{ \App\Services\Chatbot\ChatEchoes::HEALTH_MINUTES }} minutes, from Meta's echo of every message a page sends (any app or a person). Not answered: a customer message with no echo from any sender within {{ \App\Services\Chatbot\ChatEchoes::limit('unanswered_minutes') }} min (bot-On pages with echoes; attachments the bot ignores are left out). Double reply: two different apps answering one customer within {{ \App\Services\Chatbot\ChatEchoes::limit('double_seconds') }} s. Main flow double (urgent): a new customer, whose first message to the page in Likha's records came in this window, answered by both CBB (Chatbot Builder or Botcake) and the Likha bot's main flow within {{ \App\Services\Chatbot\ChatEchoes::limit('unanswered_minutes') }} min; a customer already known to Chatbot Builder but new to Likha counts as new. Leftover doubles (not urgent): every other double reply, e.g. old customers still in a CBB sequence or loop. New customers: how many came in this window and how many of them CBB answered; a CBB whose main flow is off answers none.</p>
    </section>

    <form method="GET" action="{{ route('chatbot.health') }}" class="cb-filters flex flex-wrap items-end gap-3 text-sm" aria-label="Filters">
        <label class="flex min-w-0 flex-col text-xs">Page
            <select name="page_id" class="max-w-[14rem] border px-2 py-1 text-sm text-gray-900">
                <option value="">All pages</option>
                @foreach($pages as $name => $id)<option value="{{ $id }}" @selected((string) $f['page_id'] === (string) $id)>{{ $name }}</option>@endforeach
            </select>
        </label>
        <label class="flex flex-col text-xs">Bot
            <select name="bot" class="border px-2 py-1 text-sm text-gray-900">
                <option value="">Lahat</option>
                @foreach($bots as $bot)<option value="{{ $bot }}" @selected($f['bot'] === $bot)>{{ $bot }}</option>@endforeach
            </select>
        </label>
        <label class="flex flex-col text-xs">From<input type="date" name="from" value="{{ $f['from'] }}" class="border px-2 py-1 text-sm text-gray-900"></label>
        <label class="flex flex-col text-xs">To<input type="date" name="to" value="{{ $f['to'] }}" class="border px-2 py-1 text-sm text-gray-900"></label>
        <button class="cb-btn cb-btn-primary">Show</button>
        <span class="text-xs text-slate-500">Up to 31 days. Today and yesterday below are always the real today and yesterday.</span>
    </form>

    {{-- Pangkalahatang ilaw at mga tile: ngayon laban sa kahapon. --}}
    <section aria-labelledby="h-today" class="space-y-3">
        <h2 id="h-today" class="flex items-center gap-2 text-lg font-semibold">
            <span class="inline-block h-3 w-3 rounded-full {{ $dot[$lights['overall']['light']] }}" aria-hidden="true"></span>
            Today ({{ $tiles['dates']['today'] }}) vs yesterday
            <span class="text-sm font-normal text-slate-500">overall: {{ $lights['overall']['light'] === 'none' ? 'no data' : $lights['overall']['light'] }}</span>
        </h2>
        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
            @foreach($cards as [$label, $key, $unit, $lightKey])
                @php($l = $lightKey ? $lights[$lightKey] : null)
                <div class="cb-card p-3" data-tile="{{ $key }}">
                    <div class="flex items-start justify-between gap-2 text-xs text-slate-500">
                        <span>{{ $label }}</span>
                        @if($l)<span class="mt-0.5 inline-block h-3 w-3 shrink-0 rounded-full {{ $dot[$l['light']] }}" title="{{ $l['light'] }}" aria-label="{{ $l['light'] === 'none' ? 'no data' : $l['light'] }}"></span>@endif
                    </div>
                    <div class="mt-1 text-2xl font-semibold">{{ $fmt($t[$key], $unit) }}</div>
                    <div class="text-xs text-slate-500">{{ $change($key, $unit) }}</div>
                    @if($l)<div class="mt-1 text-xs text-slate-400">{{ $l['rule'] }}</div>@endif
                    @if($fbNote && str_starts_with($key, 'reply_'))<div class="mt-1 text-xs text-amber-700" data-note="forbeast-lag">Forbeast pages: {{ $fbNote }}</div>@endif
                </div>
            @endforeach
            <div class="cb-card p-3" data-tile="queue">
                <div class="flex items-start justify-between gap-2 text-xs text-slate-500">
                    <span>Queue now</span>
                    <span class="mt-0.5 inline-block h-3 w-3 shrink-0 rounded-full {{ $dot[$lights['queue']['light']] }}" title="{{ $lights['queue']['light'] }}"></span>
                </div>
                <div class="mt-1 text-2xl font-semibold">{{ $queue['total'] ?? '—' }}</div>
                <div class="text-xs text-slate-500">
                    @foreach($queue['queues'] as $name => $n){{ $name }}: {{ $n ?? '?' }}@unless($loop->last) · @endunless @endforeach
                </div>
                @if($queue['note'])<div class="text-xs text-amber-700">{{ $queue['note'] }}</div>@endif
                <div class="mt-1 text-xs text-slate-400">{{ $lights['queue']['rule'] }}</div>
            </div>
        </div>
        <p class="text-xs text-slate-500">Failed replies count every send that is not "sent" (failed and skipped). "Not answered" messages ({{ $t['not_answered'] }} today) are left out of the reply times. AI call records are kept for 2 days only, so the AI graph is short.</p>
    </section>

    {{-- Ang totoong padala: kailan talaga nakarating sa customer (Forbeast: send_status; Botcake: ang oras ng padala nito). --}}
    @if($real)
        <section aria-labelledby="h-real" class="space-y-3" data-panel="real-send">
            <h2 id="h-real" class="text-lg font-semibold">Real send time</h2>
            <p class="text-xs text-slate-500">"Accepted" is when the bot took the reply; "real" is when it reached the customer. Botcake sends at once, so both are the same; Forbeast queues first. A Forbeast reply not confirmed yet is pending, not fast; after 24 hours without a confirmation it is unconfirmed.</p>
            @if(!$real['available'] && $forbeast !== null)
                <p class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800" data-note="real-unavailable">Forbeast pages: real send time not available yet (Forbeast update pending). The real first reply above counts Botcake pages only.</p>
            @endif
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                @if($real['pending'])
                    <div class="cb-card p-3" data-tile="pending" data-light="{{ $real['pending']['light'] }}">
                        <div class="flex items-start justify-between gap-2 text-xs text-slate-500">
                            <span>Pending (Forbeast)</span>
                            <span class="mt-0.5 inline-block h-3 w-3 shrink-0 rounded-full {{ $dot[$real['pending']['light']] }}" title="{{ $real['pending']['light'] }}" aria-label="{{ $real['pending']['light'] }}"></span>
                        </div>
                        <div class="mt-1 text-2xl font-semibold">{{ number_format($real['pending']['count']) }}</div>
                        <div class="text-xs text-slate-500">oldest: {{ $real['pending']['count'] ? number_format($real['pending']['oldest']) . ' s' : '—' }}</div>
                        <div class="mt-1 text-xs text-slate-400">&gt; {{ \App\Services\Chatbot\RealSendTime::PENDING_RED }} s red</div>
                    </div>
                @endif
                @if($real['available'])
                    <div class="cb-card p-3" data-tile="unconfirmed">
                        <div class="text-xs text-slate-500">Unconfirmed (over 24 h)</div>
                        <div class="mt-1 text-2xl font-semibold">{{ number_format($real['unconfirmed']) }}</div>
                        <div class="text-xs text-slate-500">{{ $f['from'] }} to {{ $f['to'] }}</div>
                    </div>
                    @foreach(['median' => 'median', 'p90' => 'p90', 'max' => 'max'] as $k => $label)
                        <div class="cb-card p-3" data-tile="lag_{{ $k }}">
                            <div class="text-xs text-slate-500">Forbeast accept → sent {{ $label }}</div>
                            <div class="mt-1 text-2xl font-semibold">{{ $fmt($real['lag'][$k], 's') }}</div>
                            <div class="text-xs text-slate-500">today, {{ number_format($real['lag']['n']) }} sent</div>
                        </div>
                    @endforeach
                @endif
            </div>
            <div class="cb-card overflow-x-auto">
                <table class="cb-table" data-list="real-pages">
                    <thead><tr><th>Page</th><th>Bot</th><th class="text-right">Real first reply median</th><th class="text-right">p90</th><th class="text-right">max</th>
                        @if($real['pending'])<th class="text-right">Pending</th><th class="text-right">Oldest pending</th>@endif<th class="text-right">Unconfirmed</th></tr></thead>
                    <tbody>
                    @forelse($real['pages'] as $p)
                        <tr data-page="{{ $p['page_id'] }}"><td>{{ $p['page'] }}</td><td>{{ $p['bot'] }}</td><td class="text-right">{{ $fmt($p['real_median'], 's') }}</td>
                            <td class="text-right">{{ $fmt($p['real_p90'], 's') }}</td><td class="text-right">{{ $fmt($p['real_max'], 's') }}</td>
                            @if($real['pending'])<td class="text-right">{{ $p['pending'] }}</td>
                                <td class="text-right {{ ($p['oldest'] ?? 0) > \App\Services\Chatbot\RealSendTime::PENDING_RED ? 'font-semibold text-red-700' : '' }}">{{ $p['oldest'] === null ? '—' : number_format($p['oldest']) . ' s' }}</td>@endif
                            <td class="text-right">{{ $p['unconfirmed'] }}</td></tr>
                    @empty
                        <tr><td colspan="8" class="text-slate-500">No replies today.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-slate-500">Per page: today's real first reply. Pending is now; unconfirmed is for the dates above.</p>
        </section>
    @endif

    @if($forbeast)
        @include('chatbot.partials.forbeast-health', ['fb' => $forbeast])
    @endif

    {{-- Paghahambing kada bot (ang bot ng page ngayon), ngayon at kahapon; hindi sakop ng Bot filter. --}}
    <section aria-labelledby="h-compare" class="space-y-2">
        <h2 id="h-compare" class="text-lg font-semibold">Compare bots</h2>
        <div class="cb-card overflow-x-auto">
            <table class="cb-table" data-list="compare">
                <thead><tr><th>Bot</th><th>Day</th><th class="text-right">Inbound</th><th class="text-right">Answered</th><th class="text-right">First reply median (accepted)</th>
                    <th class="text-right">p90</th>@if($real)<th class="text-right">Real first reply median / p90</th>@endif<th class="text-right">Failed % (without not reachable)</th><th>Top reasons</th><th class="text-right">AI failed</th>
                    <th class="text-right">Comments in / replied</th><th class="text-right">Switched to Botcake</th></tr></thead>
                <tbody>
                @foreach($compare as $c)
                    @foreach(['today' => 'Today', 'yesterday' => 'Yesterday'] as $day => $dayLabel)
                        @php($x = $c[$day])
                        <tr><td>{{ $c['bot'] }}</td><td>{{ $dayLabel }}</td><td class="text-right">{{ $x['inbound'] }}</td><td class="text-right">{{ $fmt($x['answered_pct'], '%') }}</td>
                            <td class="text-right">{{ $fmt($x['reply_median'], 's') }}</td><td class="text-right">{{ $fmt($x['reply_p90'], 's') }}@if($fbLag !== null && $c['bot'] === 'Forbeast' && $day === 'today')<div class="text-xs text-amber-700">accepted by Forbeast; real send lag: {{ $fbLag }}s</div>@endif</td>@if($real)<td class="text-right">{{ $fmt($x['real_median'], 's') }} / {{ $fmt($x['real_p90'], 's') }}</td>@endif<td class="text-right">{{ $fmt($x['failed_pct_excl'], '%') }}</td>
                            <td>{{ $x['top_reasons'] ? implode(', ', array_map(fn ($r) => $r[0] . ' (' . $r[1] . ')', $x['top_reasons'])) : '—' }}</td>
                            <td class="text-right">{{ $fmt($x['ai_failed_pct'], '%') }}</td><td class="text-right">{{ $x['comments_in'] }} / {{ $x['comments_replied'] }}</td>
                            <td class="text-right">{{ $x['auto_switches'] }}</td></tr>
                    @endforeach
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-xs text-slate-500">Each page counts under its bot now: a page that switched today counts all day under its current bot. "Switched to Botcake" is on the row of the bot it left. Comments in also counts comments still waiting, skipped, and "unknown" (Forbeast could not tell whether Meta posted it), so in is more than replied + failed.</p>
    </section>

    {{-- Mga dahilan ng pumalya ngayon. --}}
    <section aria-labelledby="h-reasons" class="space-y-2">
        <h2 id="h-reasons" class="text-lg font-semibold">Why replies failed today</h2>
        <table class="cb-table cb-card max-w-2xl">
            <thead><tr><th>Reason</th><th class="text-right">Today</th><th class="text-right">Yesterday</th></tr></thead>
            <tbody>
            @foreach(\App\Services\Chatbot\BotHealth::REASONS as $key => $label)
                <tr><td>{{ $label }}</td><td class="text-right">{{ $t['reasons'][$key] }}</td><td class="text-right">{{ $tiles['yesterday']['reasons'][$key] }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </section>

    {{-- Mga graph --}}
    <section aria-labelledby="h-graphs" class="space-y-3">
        <h2 id="h-graphs" class="text-lg font-semibold">{{ $f['from'] }} to {{ $f['to'] }}, one point per day</h2>
        <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
            <div class="cb-card p-3"><h3 class="mb-1 text-sm font-medium">First reply time (seconds)</h3><div class="h-56"><canvas id="bh-reply" role="img" aria-label="First reply time per day: average, p90 and max"></canvas></div></div>
            <div class="cb-card p-3"><h3 class="mb-1 text-sm font-medium">Replies sent and failed, by reason</h3><div class="h-56"><canvas id="bh-replies" role="img" aria-label="Replies sent and failed per day, by reason"></canvas></div></div>
            <div class="cb-card p-3"><h3 class="mb-1 text-sm font-medium">AI calls and time (seconds)</h3><div class="h-56"><canvas id="bh-ai" role="img" aria-label="AI calls per day and AI average and max time"></canvas></div></div>
            <div class="cb-card p-3"><h3 class="mb-1 text-sm font-medium">Inbound messages and orders</h3><div class="h-56"><canvas id="bh-flow" role="img" aria-label="Inbound messages and orders per day"></canvas></div></div>
            <div class="cb-card p-3 lg:col-span-2"><h3 class="mb-1 text-sm font-medium">Today by hour: reply time (seconds) and failed sends</h3><div class="h-56"><canvas id="bh-hour" role="img" aria-label="Today by hour: average and max reply time and failed sends"></canvas></div></div>
        </div>
    </section>

    {{-- Mga ilaw at thresholds --}}
    <section aria-labelledby="h-rules" class="space-y-2">
        <h2 id="h-rules" class="text-lg font-semibold">Lights and thresholds</h2>
        <form method="POST" action="{{ route('chatbot.health.thresholds', $keep) }}" class="space-y-2">
            @csrf
            <table class="cb-table cb-card">
                <thead><tr><th>Light</th><th>Today</th><th>Rule</th>@if($isCeo)<th>Amber</th><th>Red (blank: none)</th>@endif</tr></thead>
                <tbody>
                @foreach($metaLights as $key => [$label, , $unit, $dir])
                    @php($l = $lights[$key])
                    <tr data-rule="{{ $key }}">
                        <td><span class="mr-2 inline-block h-3 w-3 rounded-full align-middle {{ $dot[$l['light']] }}" aria-hidden="true"></span>{{ $label }}<span class="sr-only"> — {{ $l['light'] === 'none' ? 'no data' : $l['light'] }}</span></td>
                        <td>{{ $key === 'queue' ? ($queue['total'] ?? '—') : ($l['value'] === null ? '—' : $num($l['value']) . ($unit === 's' ? ' s' : ($unit === '%' ? '%' : ''))) }}</td>
                        <td class="text-slate-600">{{ $l['rule'] }}</td>
                        @if($isCeo)
                            <td><input type="number" step="any" min="0" name="t[{{ $key }}][amber]" value="{{ $thresholds[$key]['amber'] }}" class="w-24 border px-2 py-1 text-sm" aria-label="{{ $label }} amber"></td>
                            <td><input type="number" step="any" min="0" name="t[{{ $key }}][red]" value="{{ $thresholds[$key]['red'] }}" class="w-24 border px-2 py-1 text-sm" aria-label="{{ $label }} red"></td>
                        @endif
                    </tr>
                @endforeach
                {{-- Ang mga ilaw kada page (Pages with problems): "≥", sa huling oras. --}}
                @foreach($pageLights as $key => [$label, $unit])
                    @php($u = $unit === '%' ? '%' : '')
                    <tr data-rule="{{ $key }}">
                        <td>{{ $label }}</td>
                        <td>—</td>
                        <td class="text-slate-600">{{ $key === 'page_min_sends' ? 'failed % counts from ' . $thresholds[$key]['amber'] . ' sends' : '≥ ' . $thresholds[$key]['amber'] . $u . ' amber' . ($thresholds[$key]['red'] !== null ? ', ≥ ' . $thresholds[$key]['red'] . $u . ' red' : '') }}</td>
                        @if($isCeo)
                            <td><input type="number" step="any" min="0" name="t[{{ $key }}][amber]" value="{{ $thresholds[$key]['amber'] }}" class="w-24 border px-2 py-1 text-sm" aria-label="{{ $label }} {{ $key === 'page_min_sends' ? 'sends' : 'amber' }}"></td>
                            <td>@unless($key === 'page_min_sends')<input type="number" step="any" min="0" name="t[{{ $key }}][red]" value="{{ $thresholds[$key]['red'] }}" class="w-24 border px-2 py-1 text-sm" aria-label="{{ $label }} red">@endunless</td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
            @if($isCeo)<button class="cb-btn cb-btn-primary">Save thresholds</button>
            @else<p class="text-xs text-slate-500">Only the CEO can change the thresholds.</p>@endif
        </form>
    </section>

    {{-- Ang mensaheng attachment lang (larawan, voice, video, reel, file, fallback; hindi sticker o Like): CEO lang ang nagbabago. --}}
    <section aria-labelledby="h-attachments" class="space-y-2">
        <h2 id="h-attachments" class="text-lg font-semibold">Photos, voice and other attachments</h2>
        <p class="text-sm text-slate-600">A customer message that is only a photo, voice note, video, reel, file or fallback (no text). Stickers and Likes are always answered.</p>
        @if($isCeo)
            <form method="POST" action="{{ route('chatbot.health.attachments', $keep) }}" class="flex flex-wrap items-end gap-3 text-sm">
                @csrf
                <label class="flex flex-col text-xs">The bot
                    <select name="mode" class="border px-2 py-1 text-sm text-gray-900">
                        <option value="ignore" @selected($attachments === 'ignore')>Ignores them (logged, kept for later)</option>
                        <option value="reply" @selected($attachments === 'reply')>Answers them like text</option>
                    </select>
                </label>
                <button class="cb-btn cb-btn-primary">Save</button>
            </form>
        @else
            <p class="text-sm">The bot {{ $attachments === 'ignore' ? 'ignores them (logged, kept for later).' : 'answers them like text.' }}</p>
        @endif
    </section>

    {{-- Mga listahan --}}
    <section aria-labelledby="h-slow" class="space-y-2">
        <h2 id="h-slow" class="text-lg font-semibold">20 slowest first replies</h2>
        <div class="cb-card overflow-x-auto">
            <table class="cb-table" data-list="slow">
                <thead><tr><th>Received</th><th>Page</th><th class="text-right">Seconds</th><th>Node</th><th>Several messages within 10 s</th><th></th></tr></thead>
                <tbody>
                @forelse($slow as $r)
                    <tr><td>{{ $r['at'] }}</td><td>{{ $r['page'] }}</td><td class="text-right">{{ $r['seconds'] }}</td><td>{{ $r['node'] ?? '—' }}</td>
                        <td>{{ $r['burst'] ? 'Yes' : 'No' }}</td>
                        <td>@if($r['url'])<a href="{{ $r['url'] }}" class="text-blue-700 hover:underline">Reply</a>@endif</td></tr>
                @empty
                    <tr><td colspan="6" class="text-slate-500">No answered messages in this range.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section aria-labelledby="h-fail" class="space-y-2">
        <h2 id="h-fail" class="text-lg font-semibold">Latest 20 failed sends</h2>
        <div class="cb-card overflow-x-auto">
            <table class="cb-table" data-list="failures">
                <thead><tr><th>Sent</th><th>Page</th><th>Reason</th><th>HTTP</th><th>Node</th><th>Error</th><th></th></tr></thead>
                <tbody>
                @forelse($failures as $r)
                    <tr><td>{{ $r['at'] }}</td><td>{{ $r['page'] }}</td><td>{{ $r['reason'] }}</td><td>{{ $r['http'] ?? '—' }}</td><td>{{ $r['node'] ?? '—' }}</td>
                        <td class="max-w-md break-words text-slate-600">{{ $r['error'] }}</td>
                        <td><a href="{{ $r['url'] }}" class="text-blue-700 hover:underline">Reply</a></td></tr>
                @empty
                    <tr><td colspan="7" class="text-slate-500">No failed sends in this range.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>

{{-- Ang data ng mga graph: JSON na hindi masisira ng < > & ' ". --}}
<script type="application/json" id="bh-data">{!! json_encode($chart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection

@push('scripts')
<script>
    // Pages with problems: ang page ang nagbubukas at nagsasara ng mga pumalya nito.
    for (const b of document.querySelectorAll('[data-pp-toggle]')) {
        b.addEventListener('click', () => {
            const row = document.getElementById(b.getAttribute('aria-controls'));
            row.hidden = !row.hidden;
            b.setAttribute('aria-expanded', String(!row.hidden));
        });
    }
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
(() => {
    if (!window.Chart) return;
    const data = JSON.parse(document.getElementById('bh-data').textContent);
    const labels = data.days.map(d => d.date.slice(5));
    const get = k => data.days.map(x => x[k]);
    const reason = k => data.days.map(x => x.reasons[k]);
    const C = {blue: '#2563eb', amber: '#d97706', red: '#dc2626', slate: '#64748b', green: '#059669', violet: '#7c3aed'};
    const base = {responsive: true, maintainAspectRatio: false, interaction: {mode: 'index', intersect: false}, plugins: {legend: {position: 'bottom'}}};
    const line = (id, sets, extra = {}) => new Chart(document.getElementById(id), {type: 'line', data: {labels: extra.labels || labels, datasets: sets.map(s => ({tension: .25, spanGaps: true, pointRadius: 2, ...s}))}, options: {...base, scales: {y: {beginAtZero: true}, ...(extra.scales || {})}}});

    line('bh-reply', [
        {label: 'Average', data: get('reply_avg'), borderColor: C.blue, backgroundColor: C.blue},
        {label: 'p90', data: get('reply_p90'), borderColor: C.amber, backgroundColor: C.amber},
        {label: 'Max', data: get('reply_max'), borderColor: C.red, backgroundColor: C.red},
    ]);

    new Chart(document.getElementById('bh-replies'), {type: 'bar', data: {labels, datasets: [
        {label: 'Sent', data: get('sent'), backgroundColor: C.green, stack: 's'},
        {label: 'Page restricted by Facebook', data: reason('restricted'), backgroundColor: '#7f1d1d', stack: 's'},
        {label: 'Permission error', data: reason('permission'), backgroundColor: '#be185d', stack: 's'},
        {label: 'Customer not reachable', data: reason('unreachable'), backgroundColor: C.slate, stack: 's'},
        {label: 'Botcake error', data: reason('botcake'), backgroundColor: C.red, stack: 's'},
        {label: 'Outside 24-hour window', data: reason('window'), backgroundColor: C.violet, stack: 's'},
        {label: 'No answer', data: reason('timeout'), backgroundColor: C.amber, stack: 's'},
        {label: 'Other', data: reason('other'), backgroundColor: '#94a3b8', stack: 's'},
    ]}, options: {...base, scales: {x: {stacked: true}, y: {stacked: true, beginAtZero: true}}}});

    line('bh-ai', [
        {label: 'AI calls', data: get('ai_calls'), borderColor: C.slate, backgroundColor: C.slate, yAxisID: 'y'},
        {label: 'Average time (s)', data: get('ai_avg'), borderColor: C.blue, backgroundColor: C.blue, yAxisID: 'y2'},
        {label: 'Max time (s)', data: get('ai_max'), borderColor: C.red, backgroundColor: C.red, yAxisID: 'y2'},
    ], {scales: {y2: {position: 'right', beginAtZero: true, grid: {drawOnChartArea: false}}}});

    line('bh-flow', [
        {label: 'Inbound messages', data: get('inbound'), borderColor: C.blue, backgroundColor: C.blue},
        {label: 'Orders', data: get('orders'), borderColor: C.green, backgroundColor: C.green},
    ]);

    new Chart(document.getElementById('bh-hour'), {data: {labels: data.hourly.map(h => String(h.hour).padStart(2, '0') + ':00'), datasets: [
        {type: 'bar', label: 'Failed sends', data: data.hourly.map(h => h.failed), backgroundColor: C.red, yAxisID: 'y2'},
        {type: 'line', label: 'Average reply (s)', data: data.hourly.map(h => h.avg), borderColor: C.blue, backgroundColor: C.blue, spanGaps: true, yAxisID: 'y'},
        {type: 'line', label: 'Max reply (s)', data: data.hourly.map(h => h.max), borderColor: C.amber, backgroundColor: C.amber, spanGaps: true, yAxisID: 'y'},
    ]}, options: {...base, scales: {y: {beginAtZero: true}, y2: {position: 'right', beginAtZero: true, grid: {drawOnChartArea: false}}}}});
})();
</script>
@endpush
