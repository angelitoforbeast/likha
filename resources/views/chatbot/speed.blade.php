@extends('layouts.chatblast')

@section('title', 'Chatbot · Speed')
@section('heading', 'Speed')

@section('description')
    How fast the bot answers, hour by hour: from the customer's message to the bot's first reply that was actually sent (Meta's sent time from Forbeast when known). Delivered and seen are not used: they depend on the customer's phone. Today refreshes every 2 minutes.
@endsection

@php
    $num = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.');
    $sec = fn ($v) => $v === null ? '—' : $num($v) . ' s';
    $pct = fn ($v) => $v === null ? '—' : $num($v) . '%';
    $f = $filters;
    $keep = array_filter(['page_id' => $f['page_id'], 'bot' => $f['bot']], fn ($v) => $v !== null);
    $day = fn ($d) => route('chatbot.speed', $keep + ['date' => $d], false);
    $cols = ['Messages', 'Answered', 'Unanswered', 'Average', 'Median', 'p90', 'Max', '≤ 30 s', '≤ 1 min', '≤ 5 min', 'Not on bot'];
@endphp

@section('content')
<div class="shrink-0 space-y-5 pb-6">
    <nav class="flex flex-wrap items-center gap-2 text-sm" aria-label="Day">
        <a href="{{ $day($dates['prev']) }}" class="cb-btn" aria-label="Previous day">← {{ $dates['prev'] }}</a>
        @if($f['date'] !== $dates['today'])<a href="{{ $day($dates['today']) }}" class="cb-btn">Today</a>@endif
        @if($f['date'] !== $dates['yesterday'])<a href="{{ $day($dates['yesterday']) }}" class="cb-btn">Yesterday</a>@endif
        @if($dates['next'])<a href="{{ $day($dates['next']) }}" class="cb-btn" aria-label="Next day">{{ $dates['next'] }} →</a>@endif
    </nav>

    <form method="GET" action="{{ route('chatbot.speed') }}" class="cb-filters flex flex-wrap items-end gap-3 text-sm" aria-label="Filters">
        <label class="flex flex-col text-xs">Day<input type="date" name="date" value="{{ $f['date'] }}" max="{{ $dates['today'] }}" class="border px-2 py-1 text-sm text-gray-900"></label>
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
        <button class="cb-btn cb-btn-primary">Show</button>
    </form>

    <section aria-labelledby="h-meta">
        <h2 id="h-meta" class="mb-1 text-base font-semibold">Reply time at Meta (customer sent → our reply sent, Meta's clock)</h2>
        @if($meta['state'] === 'botcake')
            <p class="text-sm text-slate-600" data-sp-meta="botcake">Botcake gives no Meta times. Choose Forbeast or all bots to see this section.</p>
        @elseif($meta['state'] === 'not_linked')
            <p class="text-sm text-slate-600" data-sp-meta="not-linked">Not linked to Forbeast. This site has no Forbeast link, so there are no Meta times here.</p>
        @else
            <p class="text-xs text-slate-500" data-sp-meta="scope">Only pages on Forbeast are in this section. {{ number_format($by_bot['Botcake']['messages']) }} of the day's messages went through Botcake and are not in it.</p>
            @if($meta['state'] === 'error')
                <p class="mt-1 text-sm text-amber-700" role="status" data-sp-meta="error">Forbeast did not answer ({{ $meta['at'] }}). {{ $meta['error'] }} The rest of this page is not affected; it tries again within a minute.</p>
            @else
                @php($mt = $meta['total'])
                <p class="text-sm tabular-nums text-slate-600" data-sp-summary="meta">Day: {{ number_format($mt['messages']) }} messages · {{ number_format($mt['bot_answered']) }} bot answered · {{ number_format($mt['person_answered']) }} by another app or a person · {{ number_format($mt['unanswered']) }} unanswered · avg {{ $sec($mt['avg']) }} · median {{ $sec($mt['median']) }} · p90 {{ $sec($mt['p90']) }} · max {{ $sec($mt['max']) }} · Meta → Forbeast avg {{ $sec($mt['meta_to_forbeast_avg']) }}</p>
                @include('chatbot.partials.speed-chart', ['id' => 'meta', 'title' => 'Reply time at Meta by hour: average, median (dashed) and p90', 'label' => 'Average, median and p90 seconds from the customer\'s message sent to our reply sent, on Meta\'s clock, per hour, with the previous day as reference'])
                <details class="sp-table mt-3" data-sp-table="meta">
                    <summary class="cursor-pointer select-none py-1 text-sm font-medium text-blue-700"><span class="sp-show">Show table</span><span class="sp-hide">Hide table</span></summary>
                <div class="cb-card overflow-x-auto">
                    <table class="cb-table min-w-[48rem]" data-table="meta">
                        <thead><tr><th>Hour</th>@foreach(['Messages', 'Bot answered', 'By another app or a person', 'Unanswered', 'Average', 'Median', 'p90', 'Max', 'Meta → Forbeast avg'] as $c)<th class="!text-right">{{ $c }}</th>@endforeach</tr></thead>
                        <tbody>
                        @foreach($meta['hours'] as $h => $r)
                            <tr data-hour="{{ $h }}">
                                <td class="tabular-nums">{{ str_pad($h, 2, '0', STR_PAD_LEFT) }}:00</td>
                                @if($r === null)
                                    @for($i = 0; $i < 9; $i++)<td></td>@endfor
                                @else
                                    @include('chatbot.partials.speed-meta-cells', ['r' => $r])
                                @endif
                            </tr>
                        @endforeach
                        </tbody>
                        <tfoot><tr class="font-semibold" data-row="total"><td>Day</td>@include('chatbot.partials.speed-meta-cells', ['r' => $meta['total']])</tr></tfoot>
                    </table>
                </div>
                <p class="mt-2 text-xs text-slate-500">
                    Forbeast's numbers, on Meta's clock at both ends: from when the customer's message was sent to when our reply was sent (Manila hours). Bot answered and By another app or a person = who sent the first reply; Botcake replies count here (as another app), since Forbeast sees them only as replies from the page. Meta → Forbeast avg = how long Meta took to hand the customer's message to Forbeast. Today refreshes every 2 minutes.
                </p>
                </details>
            @endif
        @endif
    </section>

    <section aria-labelledby="h-hours">
        <h2 id="h-hours" class="mb-1 text-base font-semibold">{{ $f['date'] }}{{ $f['date'] === $dates['today'] ? ' (today)' : ($f['date'] === $dates['yesterday'] ? ' (yesterday)' : '') }} by hour</h2>
        <p class="text-sm tabular-nums text-slate-600" data-sp-summary="hours">Day: {{ number_format($total['messages']) }} messages · {{ number_format($total['answered']) }} answered · avg {{ $sec($total['avg']) }} · median {{ $sec($total['median']) }} · p90 {{ $sec($total['p90']) }} · max {{ $sec($total['max']) }}</p>
        @include('chatbot.partials.speed-chart', ['id' => 'reply', 'title' => 'Reply time by hour: average, median and p90', 'label' => 'Average, median and p90 first reply time per hour, with the previous day as reference'])
        <details class="sp-table mt-3" data-sp-table="hours">
            <summary class="cursor-pointer select-none py-1 text-sm font-medium text-blue-700"><span class="sp-show">Show table</span><span class="sp-hide">Hide table</span></summary>
        <div class="cb-card overflow-x-auto">
            <table class="cb-table min-w-[48rem]" data-table="hours">
                <thead><tr><th>Hour</th>@foreach($cols as $c)<th class="!text-right">{{ $c }}</th>@endforeach</tr></thead>
                <tbody>
                @foreach($hours as $h => $r)
                    <tr data-hour="{{ $h }}">
                        <td class="tabular-nums">{{ str_pad($h, 2, '0', STR_PAD_LEFT) }}:00</td>
                        @if($r === null)
                            @foreach($cols as $c)<td></td>@endforeach
                        @else
                            @include('chatbot.partials.speed-cells', ['r' => $r])
                        @endif
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                    <tr class="font-semibold" data-row="total"><td>Day</td>@include('chatbot.partials.speed-cells', ['r' => $total])</tr>
                    @foreach($by_bot as $bot => $r)
                        <tr class="text-slate-600" data-row="{{ $bot }}"><td>{{ $bot }}</td>@include('chatbot.partials.speed-cells', ['r' => $r])</tr>
                    @endforeach
                </tfoot>
            </table>
        </div>
        <p class="mt-2 text-xs text-slate-500">
            Hour = when the customer's message arrived (Manila). Answered = a bot reply to the same customer within 24 hours that was sent (failed, test and sequence sends are not replies). ≤ 30 s / 1 min / 5 min are counted against all messages. Attachments the bot ignores by design are left out. Not on bot = messages on a page without a flow at that time; they are not in the other numbers. The Forbeast and Botcake rows are for the whole day and follow the page filter only.
            @if($total['unconfirmed'] > 0) {{ number_format($total['unconfirmed']) }} answered without a confirmed sent time (a Forbeast reply not confirmed yet, or a reply recorded before sent times were kept): their time is when Likha handed the reply over.@endif
            @if($refused_off) {{ number_format($refused_off) }} messages came in on pages with the bot Off and were not stored.@endif
        </p>
        </details>
    </section>

    <section aria-labelledby="h-ai">
        <h2 id="h-ai" class="mb-1 text-base font-semibold">With AI vs no AI, by hour</h2>
        <p class="text-sm tabular-nums text-slate-600" data-sp-summary="ai">Day: @foreach(['with_ai' => 'with AI', 'no_ai' => 'no AI'] as $g => $gl){{ $gl }} {{ number_format($total[$g]['n']) }} answered · avg {{ $sec($total[$g]['avg']) }} · median {{ $sec($total[$g]['median']) }} · p90 {{ $sec($total[$g]['p90']) }} · max {{ $sec($total[$g]['max']) }}; @endforeach AI time avg {{ $sec($total['ai_time']['avg']) }} · p90 {{ $sec($total['ai_time']['p90']) }} · max {{ $sec($total['ai_time']['max']) }}</p>
        @include('chatbot.partials.speed-chart', ['id' => 'ai', 'title' => 'With AI vs no AI by hour: average (solid), median (dashed) and AI time', 'label' => 'Average and median reply time with AI and without AI, and the AI time, per hour, with the previous day as reference', 'tall' => true])
        <details class="sp-table mt-3" data-sp-table="ai">
            <summary class="cursor-pointer select-none py-1 text-sm font-medium text-blue-700"><span class="sp-show">Show table</span><span class="sp-hide">Hide table</span></summary>
        <div class="cb-card overflow-x-auto">
            <table class="cb-table min-w-[60rem]" data-table="ai">
                <thead>
                    <tr><th></th><th colspan="5" class="!text-center">With AI</th><th colspan="5" class="!text-center">No AI (flow only)</th><th colspan="3" class="!text-center">AI time</th></tr>
                    <tr><th>Hour</th>@foreach(['Answered', 'Average', 'Median', 'p90', 'Max', 'Answered', 'Average', 'Median', 'p90', 'Max', 'Average', 'p90', 'Max'] as $c)<th class="!text-right">{{ $c }}</th>@endforeach</tr>
                </thead>
                <tbody>
                @foreach($hours as $h => $r)
                    <tr data-hour="{{ $h }}">
                        <td class="tabular-nums">{{ str_pad($h, 2, '0', STR_PAD_LEFT) }}:00</td>
                        @if($r === null)
                            @for($i = 0; $i < 13; $i++)<td></td>@endfor
                        @else
                            @include('chatbot.partials.speed-ai-cells', ['r' => $r])
                        @endif
                    </tr>
                @endforeach
                </tbody>
                <tfoot><tr class="font-semibold"><td>Day</td>@include('chatbot.partials.speed-ai-cells', ['r' => $total])</tr></tfoot>
            </table>
        </div>
        <p class="mt-2 text-xs text-slate-500">With AI = the first reply came from a run that called the AI (any AI request of that chat, answered or not). AI time = how long the AI itself took for that reply: request created → answered, summed over the run's answered calls.</p>
        </details>
    </section>

    <section aria-labelledby="h-push">
        <h2 id="h-push" class="mb-1 text-base font-semibold">Replies: pushed → sent at Meta, by hour</h2>
        @php($pt = $push['total'])
        <p class="text-sm tabular-nums text-slate-600" data-sp-summary="push">Day: {{ number_format($pt['pushed']) }} pushed (Forbeast) · {{ number_format($pt['measured']) }} measured · avg {{ $sec($pt['avg']) }} · median {{ $sec($pt['median']) }} · p90 {{ $sec($pt['p90']) }} · max {{ $sec($pt['max']) }} · {{ number_format($pt['unconfirmed']) }} not confirmed yet · {{ number_format($pt['botcake']) }} Botcake (no Meta time)</p>
        @include('chatbot.partials.speed-chart', ['id' => 'push', 'title' => 'Replies pushed → sent at Meta by hour: average, p90 and max', 'label' => 'Average, p90 and max seconds from pushed to sent at Meta for bot replies per hour, max on the right axis, with the previous day as reference'])
        <details class="sp-table mt-3" data-sp-table="push">
            <summary class="cursor-pointer select-none py-1 text-sm font-medium text-blue-700"><span class="sp-show">Show table</span><span class="sp-hide">Hide table</span></summary>
        <div class="cb-card overflow-x-auto">
            <table class="cb-table min-w-[44rem]" data-table="push">
                <thead><tr><th>Hour</th>@foreach(['Pushed (Forbeast)', 'Measured', 'Average', 'Median', 'p90', 'Max', 'Not confirmed yet', 'Botcake (no Meta time)'] as $c)<th class="!text-right">{{ $c }}</th>@endforeach</tr></thead>
                <tbody>
                @foreach($push['hours'] as $h => $r)
                    <tr data-hour="{{ $h }}">
                        <td class="tabular-nums">{{ str_pad($h, 2, '0', STR_PAD_LEFT) }}:00</td>
                        @if($r === null)
                            @for($i = 0; $i < 8; $i++)<td></td>@endfor
                        @else
                            @include('chatbot.partials.speed-push-cells', ['r' => $r])
                        @endif
                    </tr>
                @endforeach
                </tbody>
                <tfoot><tr class="font-semibold"><td>Day</td>@include('chatbot.partials.speed-push-cells', ['r' => $push['total']])</tr></tfoot>
            </table>
        </div>
        <p class="mt-2 text-xs text-slate-500">
            The bot's replies on Forbeast (sequence steps are in Sequences below). Hour = when Likha pushed the reply. Pushed = when Forbeast accepted the reply from Likha (the reply's recorded sent time, written right after Forbeast's gateway answered). Sent at Meta = the sent time Forbeast reports back (send_status). Measured = pushed replies with that confirmation; Not confirmed yet = no send_status received. Replies Meta refused are not here (they have no sent time). Botcake sends at once and reports no separate Meta time, so its replies are only counted.
        </p>
        </details>
    </section>

    <section aria-labelledby="h-seq">
        <h2 id="h-seq" class="mb-1 text-base font-semibold">Sequences by hour due</h2>
        @if(!empty($sequences['too_large']))
        <p class="text-sm text-slate-600" data-sp-note="sequences">Sequences section too large to compute now. The other sections are complete; this one tries again within 2 minutes.</p>
        @else
        @php($st = $sequences['total'])
        <p class="text-sm tabular-nums text-slate-600" data-sp-summary="sequences">Day: {{ number_format($st['due']) }} due · {{ number_format($st['sent']) }} sent · {{ number_format($st['failed']) }} failed · {{ number_format($st['skipped']) }} skipped; scheduled → pushed avg {{ $sec($st['scheduled_to_pushed']['avg']) }} · p90 {{ $sec($st['scheduled_to_pushed']['p90']) }} · max {{ $sec($st['scheduled_to_pushed']['max']) }}; pushed → sent at Meta: {{ number_format($st['pushed_to_meta']['n']) }} steps · avg {{ $sec($st['pushed_to_meta']['avg']) }} · p90 {{ $sec($st['pushed_to_meta']['p90']) }} · max {{ $sec($st['pushed_to_meta']['max']) }}</p>
        @include('chatbot.partials.speed-chart', ['id' => 'seq', 'title' => 'Sequences by hour due: pushed → sent at Meta, average and p90', 'label' => 'Average and p90 of sequence steps from pushed to sent at Meta per hour, with the previous day as reference'])
        <details class="sp-table mt-3" data-sp-table="sequences">
            <summary class="cursor-pointer select-none py-1 text-sm font-medium text-blue-700"><span class="sp-show">Show table</span><span class="sp-hide">Hide table</span></summary>
        <div class="cb-card overflow-x-auto">
            <table class="cb-table min-w-[56rem]" data-table="sequences">
                <thead>
                    <tr><th></th><th colspan="5"></th><th colspan="4" class="!text-center">Scheduled → pushed</th><th colspan="4" class="!text-center">Pushed → sent at Meta</th></tr>
                    <tr><th>Hour</th>@foreach(['Due', 'Sent', 'Failed', 'Skipped', 'No message', 'Steps', 'Average', 'p90', 'Max', 'Steps', 'Average', 'p90', 'Max'] as $c)<th class="!text-right">{{ $c }}</th>@endforeach</tr>
                </thead>
                <tbody>
                @foreach($sequences['hours'] as $h => $r)
                    <tr data-hour="{{ $h }}">
                        <td class="tabular-nums">{{ str_pad($h, 2, '0', STR_PAD_LEFT) }}:00</td>
                        @if($r === null)
                            @for($i = 0; $i < 13; $i++)<td></td>@endfor
                        @else
                            @include('chatbot.partials.speed-seq-cells', ['r' => $r])
                        @endif
                    </tr>
                @endforeach
                </tbody>
                <tfoot><tr class="font-semibold"><td>Day</td>@include('chatbot.partials.speed-seq-cells', ['r' => $sequences['total']])</tr></tfoot>
            </table>
        </div>
        <p class="mt-2 text-xs text-slate-500">
            Scheduled = when the step was due. Pushed = when Likha handed the step's first message to Forbeast or Botcake. Sent at Meta = the sent time Forbeast reports (send_status). Botcake sends at once, so its sent time is the pushed time: those steps ({{ number_format($sequences['total']['botcake']) }} in this view) are not in "Pushed → sent at Meta". Scheduled is the step's own delay from the customer's previous step (or from joining the sequence), so waiting for the AI, a busy lock or a paused bot shows as delay. No message = the step ran but its flow sent nothing. Steps with no earlier history and no recorded due time have no scheduled time.
        </p>
        </details>
        <details class="sp-table mt-3" data-sp-table="sequence-slowest">
            <summary class="cursor-pointer select-none py-1 text-sm font-medium text-blue-700"><span class="sp-show">Show slowest steps</span><span class="sp-hide">Hide slowest steps</span></summary>
        <h3 class="mb-2 mt-2 text-sm font-semibold">Slowest steps of the day</h3>
        <div class="cb-card overflow-x-auto">
            <table class="cb-table min-w-[52rem]" data-table="sequence-slowest">
                <thead><tr><th>Page</th><th>Sequence</th><th class="!text-right">Step</th><th>Scheduled</th><th>Pushed</th><th>Sent at Meta</th><th class="!text-right">Delay</th></tr></thead>
                <tbody>
                @forelse($sequences['slowest'] as $s)
                    <tr>
                        <td>{{ $s['page'] }}</td>
                        <td>{{ $s['sequence'] }}</td>
                        <td class="text-right tabular-nums">{{ $s['step'] }}</td>
                        <td class="tabular-nums">{{ $s['scheduled'] ? substr($s['scheduled'], 11) : '—' }}</td>
                        <td class="tabular-nums">{{ substr($s['pushed'], 11) }}@if($s['scheduled_to_pushed'] !== null) <span class="text-xs text-slate-500">+{{ $s['scheduled_to_pushed'] }} s</span>@endif</td>
                        <td class="tabular-nums">@if($s['sent']){{ substr($s['sent'], 11) }}@if($s['sent_is_pushed']) <span class="text-xs text-slate-500">(Botcake: = pushed)</span>@else <span class="text-xs text-slate-500">+{{ $s['pushed_to_meta'] }} s</span>@endif @else — <span class="text-xs text-slate-500">(not confirmed)</span>@endif</td>
                        <td class="text-right tabular-nums">{{ $s['delay'] }} s</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-slate-500">No sequence steps sent this day.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        </details>
        @endif
    </section>
</div>

<script type="application/json" id="sp-data">{!! json_encode($charts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection

@push('styles')
<style>
    /* Ang label ng bawat nakatagong table: "Show" kapag sarado, "Hide" kapag bukas. */
    .sp-table[open] > summary .sp-show, .sp-table:not([open]) > summary .sp-hide { display: none; }
</style>
@endpush

@push('scripts')
<script>
// Sarado ang mga table sa simula; ang binuksan ay naaalala ng browser na ito (localStorage). Kapag walang storage
// (private window o naka-block), sarado lang ang lahat.
document.querySelectorAll('details[data-sp-table]').forEach(d => {
    const key = 'chatbot-speed-table:' + d.dataset.spTable;
    try { if (localStorage.getItem(key) === 'open') d.open = true; } catch (e) { /* walang storage */ }
    d.addEventListener('toggle', () => { try { d.open ? localStorage.setItem(key, 'open') : localStorage.removeItem(key); } catch (e) { /* hindi maaalala */ } });
});
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
(() => {
    if (!window.Chart) return;
    const data = JSON.parse(document.getElementById('sp-data').textContent);
    const pad = h => String(h).padStart(2, '0');
    const fmt = v => v === null || v === undefined ? '—' : (Math.round(v * 10) / 10).toLocaleString() + ' s';
    // Kada chart, ang mga linya: [serye, pangalan, kulay, putol-putol, kapal, axis]. Ang naunang araw ay ang parehong mga
    // linya, manipis, mapusyaw at tuldok-tuldok (napapatay sa legend). Ang null ay puwang, hindi 0.
    const charts = {
        meta: [['avg', 'Average', '#0f766e', false, 2.5], ['median', 'Median', '#2563eb', true, 2], ['p90', 'p90', '#d97706', false, 2]],
        reply: [['avg', 'Average', '#0f766e', false, 2.5], ['median', 'Median', '#2563eb', false, 2], ['p90', 'p90', '#d97706', false, 2]],
        ai: [['with_ai_avg', 'With AI avg', '#7c3aed', false, 2.5], ['no_ai_avg', 'No AI avg', '#0891b2', false, 2.5],
            ['with_ai_median', 'With AI median', '#7c3aed', true, 1.5], ['no_ai_median', 'No AI median', '#0891b2', true, 1.5], ['ai_time_avg', 'AI time avg', '#db2777', false, 1]],
        push: [['avg', 'Average', '#2563eb', false, 2.5], ['p90', 'p90', '#d97706', false, 2], ['max', 'Max (right axis)', '#dc2626', false, 1.5, 'y2']],
        seq: [['avg', 'Average', '#2563eb', false, 2.5], ['p90', 'p90', '#d97706', false, 2]],
    };
    for (const [id, lines] of Object.entries(charts)) {
        const el = document.getElementById('sp-chart-' + id);
        const c = data[id];
        if (!el || !c) continue;
        // Spec 188-1: ang average lang ang nakikita sa simula (c.shown); ang iba ay nasa legend, nakatago. Ang y-axis ay
        // hanggang c.top (mga 3 × ang karaniwang average): ang lampas ay iginuguhit sa taas (tatsulok) na may tunay na
        // value bilang label at sa tooltip (real). Ang right axis (max) ay may sariling sukat, hindi pinuputol.
        const clamp = (vals, axis) => axis ? vals : vals.map(v => v === null ? null : Math.min(v, c.top));
        const over = (vals, axis, yes, no) => vals.map(v => !axis && v !== null && v > c.top ? yes : no);
        const sets = lines.map(([key, label, color, dashed, width, axis]) => ({label, data: clamp(c.series[key], axis), real: c.series[key], borderColor: color, backgroundColor: color,
            borderWidth: width, borderDash: dashed ? [6, 4] : [], pointRadius: over(c.series[key], axis, 4, 2), pointStyle: over(c.series[key], axis, 'triangle', 'circle'),
            tension: .25, spanGaps: false, yAxisID: axis || 'y', hidden: !c.shown.includes(key)}))
            .concat(lines.map(([key, label, color, , , axis]) => ({label: label + ' (' + data.prev_date + ')', data: clamp(c.prev[key], axis), real: c.prev[key], borderColor: color + '66',
                backgroundColor: color + '66', borderWidth: 1, borderDash: [2, 3], pointRadius: 0, pointHitRadius: 4, tension: .25, spanGaps: false, yAxisID: axis || 'y', hidden: !c.shown.includes(key)})));
        const counts = (part, i) => Object.entries(c[part] || {}).map(([k, v]) => k + ' ' + (v[i] ?? '—')).join(', ');
        const scales = {x: {ticks: {maxRotation: 0, autoSkipPadding: 6}}, y: {beginAtZero: true, max: c.top, title: {display: true, text: 'seconds'}}};
        if (lines.some(l => l[5] === 'y2')) scales.y2 = {display: 'auto', beginAtZero: true, position: 'right', grid: {drawOnChartArea: false}, title: {display: true, text: 'max, seconds'}};
        // Ang label ng value na lampas sa taas: ang una ay sa itaas ng gilid, ang kasunod sa parehong oras ay pababa.
        const overLabels = {id: 'spOver', afterDatasetsDraw(chart) {
            const {ctx, chartArea} = chart;
            const used = {};
            ctx.save();
            ctx.font = '10px sans-serif';
            ctx.textAlign = 'center';
            chart.data.datasets.forEach((ds, i) => {
                if (ds.yAxisID !== 'y' || !chart.isDatasetVisible(i)) return;
                chart.getDatasetMeta(i).data.forEach((pt, j) => {
                    const v = ds.real[j];
                    if (v === null || v <= c.top) return;
                    const k = used[j] = (used[j] ?? -1) + 1;
                    ctx.fillStyle = ds.borderColor;
                    ctx.fillText(fmt(v), pt.x, k === 0 ? chartArea.top - 4 : chartArea.top + 4 + 11 * k);
                });
            });
            ctx.restore();
        }};
        new Chart(el, {type: 'line', data: {labels: c.series[lines[0][0]].map((_, i) => pad(i)), datasets: sets}, plugins: [overLabels], options: {
            responsive: true, maintainAspectRatio: false, interaction: {mode: 'index', intersect: false}, layout: {padding: {top: 14}},
            plugins: {
                legend: {position: 'bottom', labels: {boxWidth: 12, boxHeight: 2, font: {size: 11}}},
                tooltip: {callbacks: {
                    title: items => items.length ? pad(items[0].dataIndex) + ':00–' + pad(items[0].dataIndex) + ':59' : '',
                    label: item => item.dataset.label + ': ' + fmt(item.dataset.real[item.dataIndex]),
                    footer: items => items.length ? [data.date + ': ' + counts('counts', items[0].dataIndex), data.prev_date + ': ' + counts('prev_counts', items[0].dataIndex)] : [],
                }},
            },
            scales,
        }});
    }
})();
</script>
@endpush
