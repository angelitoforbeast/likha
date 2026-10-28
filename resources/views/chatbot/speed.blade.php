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
    $chart = ['hours' => array_map(fn ($h) => $h === null ? null : ['median' => $h['median'], 'p90' => $h['p90']], $hours)];
    $cols = ['Messages', 'Answered', 'Unanswered', 'Average', 'Median', 'p90', '≤ 30 s', '≤ 1 min', '≤ 5 min', 'Not on bot'];
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

    <section aria-labelledby="h-hours">
        <h2 id="h-hours" class="mb-2 text-base font-semibold">{{ $f['date'] }}{{ $f['date'] === $dates['today'] ? ' (today)' : ($f['date'] === $dates['yesterday'] ? ' (yesterday)' : '') }} by hour</h2>
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
            @if($total['unconfirmed'] > 0) {{ number_format($total['unconfirmed']) }} answered without a send confirmation from Forbeast: their time is when Likha handed the reply over.@endif
            @if($refused_off) {{ number_format($refused_off) }} messages came in on pages with the bot Off and were not stored.@endif
        </p>
    </section>

    <section aria-labelledby="h-chart" class="cb-card p-3">
        <h2 id="h-chart" class="mb-1 text-sm font-medium">Median and p90 reply time by hour (seconds)</h2>
        <div class="h-56"><canvas id="sp-chart" role="img" aria-label="Median and p90 first reply time per hour"></canvas></div>
    </section>
</div>

<script type="application/json" id="sp-data">{!! json_encode($chart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
(() => {
    if (!window.Chart) return;
    const data = JSON.parse(document.getElementById('sp-data').textContent);
    const get = k => data.hours.map(h => h === null ? null : h[k]);
    new Chart(document.getElementById('sp-chart'), {type: 'line', data: {labels: data.hours.map((_, i) => String(i).padStart(2, '0')), datasets: [
        {label: 'Median', data: get('median'), borderColor: '#2563eb', backgroundColor: '#2563eb', tension: .25, spanGaps: true, pointRadius: 2},
        {label: 'p90', data: get('p90'), borderColor: '#d97706', backgroundColor: '#d97706', tension: .25, spanGaps: true, pointRadius: 2},
    ]}, options: {responsive: true, maintainAspectRatio: false, interaction: {mode: 'index', intersect: false}, plugins: {legend: {position: 'bottom'}}, scales: {y: {beginAtZero: true}}}});
})();
</script>
@endpush
