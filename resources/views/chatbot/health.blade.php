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
        ['First reply avg', 'reply_avg', 's', null],
        ['First reply median', 'reply_median', 's', null],
        ['First reply p90', 'reply_p90', 's', 'reply_p90'],
        ['First reply max', 'reply_max', 's', 'reply_max'],
        ['Replies sent', 'sent', '', null],
        ['Failed replies', 'failed', '', null],
        ['Failed % (all)', 'failed_pct', '%', null],
        ['Failed % (without customer not reachable)', 'failed_pct_excl', '%', 'failed_pct'],
        ['AI calls', 'ai_calls', '', null],
        ['AI calls failed %', 'ai_failed_pct', '%', 'ai_failed_pct'],
        ['AI time avg', 'ai_avg', 's', null],
        ['AI time p90', 'ai_p90', 's', 'ai_p90'],
        ['AI time max', 'ai_max', 's', null],
        ['Orders', 'orders', '', null],
        ['Sheet writes failed', 'sheet_failed', '', 'sheet_failed'],
    ];
    $f = $filters;
    $keep = array_filter(['page_id' => $f['page_id'], 'from' => $f['from'], 'to' => $f['to']], fn ($v) => $v !== null);
    $chart = ['days' => $days, 'hourly' => $hourly];
@endphp

@section('content')
<div class="shrink-0 space-y-5 pb-8">
    @if(session('status'))<p class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ session('status') }}</p>@endif
    @if($errors->any())
        <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">
            @foreach($errors->all() as $e)<p>{{ $e }}</p>@endforeach
        </div>
    @endif

    <form method="GET" action="{{ route('chatbot.health') }}" class="cb-filters flex flex-wrap items-end gap-3 text-sm" aria-label="Filters">
        <label class="flex min-w-0 flex-col text-xs">Page
            <select name="page_id" class="max-w-[14rem] border px-2 py-1 text-sm text-gray-900">
                <option value="">All pages</option>
                @foreach($pages as $name => $id)<option value="{{ $id }}" @selected((string) $f['page_id'] === (string) $id)>{{ $name }}</option>@endforeach
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
                </tbody>
            </table>
            @if($isCeo)<button class="cb-btn cb-btn-primary">Save thresholds</button>
            @else<p class="text-xs text-slate-500">Only the CEO can change the thresholds.</p>@endif
        </form>
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
