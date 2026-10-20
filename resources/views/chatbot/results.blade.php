@extends('layouts.chatblast')

@section('title', 'Chatbot · Results')
@section('heading', 'Results')

@section('description')
    Which bot messages get replies, orders and deliveries, per flow version and per botfield version. Numbers only; refreshed every 5 minutes.
@endsection

@php
    $pct = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.') . '%';
    $dur = function ($s) {
        if ($s === null) return '—';
        if ($s < 60) return $s . ' s';
        if ($s < 3600) return intdiv($s, 60) . ' min';
        return intdiv($s, 3600) . ' h ' . intdiv($s % 3600, 60) . ' min';
    };
    $f = $filters;
    $keep = array_filter(['page_id' => $f['page_id'], 'from' => $f['from'], 'to' => $f['to'], 'flow_id' => $f['flow_id'], 'version_id' => $f['version_id']], fn ($v) => $v !== null);
    $botVersions = collect($by_message)->filter(fn ($m) => $m['version'] !== null)->unique('version')->values();
    $versionName = fn ($v) => 'v' . $v['id'] . ' · ' . $v['at'] . ($v['by'] ? ' · ' . $v['by'] : '') . ($v['note'] ? ' · ' . $v['note'] : '');
@endphp

@section('content')
<div class="shrink-0 space-y-5 pb-6">
    {{-- Mga filter: page, petsa, flow, at ang Publish ng flow. --}}
    <form method="GET" action="{{ route('chatbot.results') }}" class="cb-filters flex flex-wrap items-end gap-3 text-sm" aria-label="Filters">
        <label class="flex min-w-0 flex-col text-xs">Page
            <select name="page_id" class="max-w-[14rem] border px-2 py-1 text-sm text-gray-900">
                <option value="">All pages</option>
                @foreach($pages as $name => $id)<option value="{{ $id }}" @selected((string) $f['page_id'] === (string) $id)>{{ $name }}</option>@endforeach
            </select>
        </label>
        <label class="flex flex-col text-xs">From<input type="date" name="from" value="{{ $f['from'] }}" class="border px-2 py-1 text-sm text-gray-900"></label>
        <label class="flex flex-col text-xs">To<input type="date" name="to" value="{{ $f['to'] }}" class="border px-2 py-1 text-sm text-gray-900"></label>
        <label class="flex min-w-0 flex-col text-xs">Flow
            <select name="flow_id" class="max-w-[16rem] border px-2 py-1 text-sm text-gray-900">
                <option value="">All flows</option>
                @foreach($flows as $id => $name)<option value="{{ $id }}" @selected((string) $f['flow_id'] === (string) $id)>{{ $name }}</option>@endforeach
            </select>
        </label>
        <label class="flex min-w-0 flex-col text-xs">Flow version
            <select name="version_id" class="max-w-[20rem] border px-2 py-1 text-sm text-gray-900" @disabled(!$versions)>
                <option value="">{{ $versions ? 'All versions' : 'Pick a flow first' }}</option>
                @foreach($versions as $v)<option value="{{ $v['id'] }}" @selected((string) $f['version_id'] === (string) $v['id'])>{{ $versionName($v) }}</option>@endforeach
            </select>
        </label>
        <button class="cb-btn cb-btn-primary">Show</button>
        <a href="{{ route('chatbot.results.json', $keep) }}" class="text-xs text-blue-700 hover:underline">JSON</a>
    </form>

    {{-- Funnel --}}
    <section aria-labelledby="h-funnel">
        <h2 id="h-funnel" class="mb-2 text-base font-semibold">Funnel</h2>
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
            @foreach([
                ['Chats', number_format($funnel['chats']), 'customers who wrote'],
                ['Sent', number_format($funnel['sent']), number_format($funnel['customers_sent']) . ' customers reached'],
                ['Replied', $pct($funnel['replied_pct']), number_format($funnel['replied']) . ' of ' . number_format($funnel['sent']) . ' sends'],
                ['Orders', $pct($funnel['orders_pct']), number_format($funnel['orders']) . ' of ' . number_format($funnel['customers_sent']) . ' customers'],
                ['Delivered', $pct($funnel['delivered_pct']), number_format($funnel['delivered']) . ' of ' . number_format($funnel['orders']) . ' orders'],
                ['RTS', $pct($funnel['rts_pct']), number_format($funnel['rts']) . ' returned · ' . number_format($funnel['in_transit']) . ' in transit · ' . number_format($funnel['unmatched']) . ' unmatched'],
            ] as [$label, $value, $sub])
                <div class="cb-card px-3 py-2">
                    <div class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</div>
                    <div class="text-2xl font-semibold tabular-nums text-slate-900">{{ $value }}</div>
                    <div class="text-xs text-slate-500">{{ $sub }}</div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- By message --}}
    <section aria-labelledby="h-msg">
        <h2 id="h-msg" class="mb-2 text-base font-semibold">By message</h2>
        <div class="cb-card overflow-x-auto">
            <table class="cb-table min-w-[56rem]">
                <thead><tr><th>Node</th><th>Botfield</th><th>Version</th><th class="text-right">Sent</th><th class="text-right">Replied</th><th class="text-right">Median reply</th><th class="text-right">Orders after</th><th class="text-right">Delivered</th></tr></thead>
                <tbody>
                @forelse($by_message as $m)
                    <tr>
                        <td><span class="font-mono text-xs">{{ $m['node'] ?? '—' }}</span>@if($m['flow'])<div class="text-xs text-slate-500">{{ $m['flow'] }}</div>@endif</td>
                        <td>{{ $m['label'] ?? '(no botfield)' }}</td>
                        <td class="max-w-[22rem]">
                            @if($m['version'] !== null)
                                <details>
                                    <summary class="cursor-pointer text-sm">v{{ $m['version'] }}<span class="text-xs text-slate-500"> · changed on {{ $m['changed_on'] }}{{ $m['active'] ? ' · live' : '' }}</span></summary>
                                    <pre class="mt-1 max-h-48 overflow-auto whitespace-pre-wrap break-words rounded bg-slate-50 p-2 text-xs text-slate-700">{{ $m['value'] }}</pre>
                                </details>
                            @else — @endif
                        </td>
                        <td class="text-right tabular-nums">{{ number_format($m['sent']) }}</td>
                        <td class="text-right tabular-nums">{{ $pct($m['replied_pct']) }}</td>
                        <td class="text-right tabular-nums">{{ $dur($m['median_reply_seconds']) }}</td>
                        <td class="text-right tabular-nums">{{ number_format($m['orders']) }}</td>
                        <td class="text-right tabular-nums">{{ number_format($m['delivered']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-slate-500">No tagged sends in this range.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- By sequence step --}}
    <section aria-labelledby="h-seq">
        <h2 id="h-seq" class="mb-2 text-base font-semibold">By sequence step</h2>
        <div class="cb-card overflow-x-auto">
            <table class="cb-table min-w-[40rem]">
                <thead><tr><th>Sequence</th><th class="text-right">Step</th><th class="text-right">Sent</th><th class="text-right">Replied</th><th class="text-right">Unsubscribed</th><th class="text-right">Orders after</th><th class="text-right">Delivered</th></tr></thead>
                <tbody>
                @forelse($by_step as $s)
                    <tr>
                        <td>{{ $s['sequence'] }}</td>
                        <td class="text-right tabular-nums">{{ $s['step'] }}</td>
                        <td class="text-right tabular-nums">{{ number_format($s['sent']) }}</td>
                        <td class="text-right tabular-nums">{{ $pct($s['replied_pct']) }}</td>
                        <td class="text-right tabular-nums">{{ number_format($s['unsubscribed']) }}</td>
                        <td class="text-right tabular-nums">{{ number_format($s['orders']) }}</td>
                        <td class="text-right tabular-nums">{{ number_format($s['delivered']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-slate-500">No sequence sends in this range.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- By ad --}}
    <section aria-labelledby="h-ad">
        <h2 id="h-ad" class="mb-2 text-base font-semibold">By ad</h2>
        <div class="cb-card overflow-x-auto">
            <table class="cb-table min-w-[36rem]">
                <thead><tr><th>Ad</th><th class="text-right">Chats</th><th class="text-right">Orders</th><th class="text-right">Delivered</th></tr></thead>
                <tbody>
                @forelse($by_ad as $a)
                    <tr>
                        <td>{{ $a['name'] ?? '—' }}<div class="font-mono text-xs text-slate-500">{{ $a['ad_id'] }}</div></td>
                        <td class="text-right tabular-nums">{{ number_format($a['chats']) }}</td>
                        <td class="text-right tabular-nums">{{ number_format($a['orders']) }}</td>
                        <td class="text-right tabular-nums">{{ number_format($a['delivered']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-slate-500">No ad on the customers in this range.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- Compare --}}
    <section aria-labelledby="h-cmp">
        <h2 id="h-cmp" class="mb-2 text-base font-semibold">Compare</h2>
        <div class="grid gap-3 lg:grid-cols-2">
            <form method="GET" action="{{ route('chatbot.results') }}" class="cb-card cb-filters flex flex-wrap items-end gap-2 p-3 text-sm" aria-label="Compare two flow versions">
                @foreach($keep as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                <input type="hidden" name="compare" value="flow">
                <div class="w-full text-xs font-medium text-slate-700">Two flow versions</div>
                @if($versions)
                    @foreach(['a' => 'A', 'b' => 'B'] as $side => $label)
                        <label class="flex min-w-0 flex-col text-xs">{{ $label }}
                            <select name="{{ $side }}" class="max-w-[16rem] border px-2 py-1 text-sm text-gray-900">
                                @foreach($versions as $i => $v)<option value="{{ $v['id'] }}" @selected($pick && $pick[0] === 'flow' ? $pick[$side === 'a' ? 1 : 2] === $v['id'] : $i === ($side === 'a' ? min(1, count($versions) - 1) : 0))>{{ $versionName($v) }}</option>@endforeach
                            </select>
                        </label>
                    @endforeach
                    <button class="cb-btn">Compare</button>
                @else
                    <p class="text-xs text-slate-500">Pick a flow in the filters to compare its versions.</p>
                @endif
            </form>
            <form method="GET" action="{{ route('chatbot.results') }}" class="cb-card cb-filters flex flex-wrap items-end gap-2 p-3 text-sm" aria-label="Compare two botfield versions">
                @foreach($keep as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                <input type="hidden" name="compare" value="botfield">
                <div class="w-full text-xs font-medium text-slate-700">Two botfield versions</div>
                @if($botVersions->count() > 1)
                    @foreach(['a' => 'A', 'b' => 'B'] as $side => $label)
                        <label class="flex min-w-0 flex-col text-xs">{{ $label }}
                            <select name="{{ $side }}" class="max-w-[16rem] border px-2 py-1 text-sm text-gray-900">
                                @foreach($botVersions as $i => $m)<option value="{{ $m['version'] }}" @selected($pick && $pick[0] === 'botfield' ? $pick[$side === 'a' ? 1 : 2] === $m['version'] : $i === ($side === 'a' ? 1 : 0))>{{ $m['label'] }} · v{{ $m['version'] }} ({{ $m['changed_on'] }})</option>@endforeach
                            </select>
                        </label>
                    @endforeach
                    <button class="cb-btn">Compare</button>
                @else
                    <p class="text-xs text-slate-500">Botfield versions to compare appear once sends with two versions are in the range.</p>
                @endif
            </form>
        </div>

        @if($compare)
            @php($sides = ['a' => $compare['a'], 'b' => $compare['b']])
            <div class="cb-card mt-3 overflow-x-auto">
                <table class="cb-table min-w-[32rem]">
                    <thead><tr><th></th>
                        @foreach($sides as $k => $s)
                            <th class="normal-case">{{ strtoupper($k) }} ·
                                @if($compare['kind'] === 'flow') v{{ $s['label']['id'] }} {{ $s['label']['at'] ?? '' }}{{ ($s['label']['by'] ?? null) ? ' · ' . $s['label']['by'] : '' }}
                                @else {{ $s['label']['label'] ?? $s['label']['botfield'] ?? '' }} v{{ $s['label']['id'] }} ({{ $s['label']['changed_on'] ?? '?' }})
                                    @if(($s['label']['value'] ?? '') !== '')<details class="font-normal"><summary class="cursor-pointer text-xs text-slate-500">Value</summary><pre class="mt-1 max-h-40 overflow-auto whitespace-pre-wrap break-words text-xs text-slate-700">{{ $s['label']['value'] }}</pre></details>@endif
                                @endif
                            </th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                    @foreach([
                        ['Sent', fn ($s) => number_format($s['sent']) . ' (' . number_format($s['customers_sent']) . ' customers)'],
                        ['Replied', fn ($s) => $pct($s['replied_pct']) . ' (' . number_format($s['replied']) . ')'],
                        ['Median reply', fn ($s) => $dur($s['median_reply_seconds'])],
                        ['Orders', fn ($s) => $pct($s['orders_pct']) . ' (' . number_format($s['orders']) . ')'],
                        ['Delivered', fn ($s) => $pct($s['delivered_pct']) . ' (' . number_format($s['delivered']) . ')'],
                        ['RTS', fn ($s) => $pct($s['rts_pct']) . ' (' . number_format($s['rts']) . ')'],
                        ['Unmatched orders', fn ($s) => number_format($s['unmatched'])],
                    ] as [$label, $show])
                        <tr><td class="font-medium">{{ $label }}</td>@foreach($sides as $s)<td class="tabular-nums">{{ $show($s) }}</td>@endforeach</tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-1 text-xs text-slate-500">Small counts swing a lot: compare the numbers in brackets before trusting a percentage.</p>
        @endif
    </section>
</div>
@endsection
