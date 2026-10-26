{{-- Ang tunay na padala ng Forbeast (GET /v1/health/sends, kada minuto): ang "sent" sa Likha ay tinanggap lang ng Forbeast. --}}
@php
    $fbDot = ['green' => 'bg-emerald-500', 'amber' => 'bg-amber-400', 'red' => 'bg-red-500', 'none' => 'bg-slate-300'];
    $fbNum = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.');
    $fbOk = in_array($fb['status'], ['ok'], true);
    $fbWhy = ['not_available' => 'Forbeast health is not available yet (Forbeast has not turned it on).', 'stale' => 'Not available: no answer from Forbeast for more than 5 minutes.', 'none' => 'Not available: no reading yet.'];
    $o = $fb['overall'];
    // [label, key, unit]
    $fbTiles = [['Send lag now', 'send_lag_seconds', 's'], ['Queued sends', 'queued_sends', ''], ['Sent per minute (5 min)', 'sent_per_min_5m', ''],
        ['Failed % (60 min)', 'failed_pct_60m', '%'], ['Forward lag', 'forward_lag_seconds', 's'], ['Workers', 'workers', '']];
    // Ang 24 oras na linya ng overall send lag (inline SVG).
    $w = 600; $h = 120; $end = now()->getTimestamp(); $start = $end - 86400;
    $max = max(array_merge([(float) ($fb['thresholds']['send_lag_red'] ?? 120)], array_map(fn ($s) => (float) $s['lag'], $fb['series'])));
    $pt = fn ($s) => round(($s['at'] - $start) / 86400 * $w, 1) . ',' . round($h - (float) $s['lag'] / $max * ($h - 10), 1);
    $redY = round($h - (float) ($fb['thresholds']['send_lag_red'] ?? 120) / $max * ($h - 10), 1);
@endphp
<section aria-labelledby="h-forbeast" class="space-y-3" data-panel="forbeast" data-status="{{ $fb['status'] }}">
    <h2 id="h-forbeast" class="text-lg font-semibold">Forbeast sends
        <span class="text-sm font-normal text-slate-500">@if($fb['updated_at'])last updated {{ $fb['updated_at'] }}@endif</span>
    </h2>
    @unless($fbOk)
        <p class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600" role="status">{{ $fbWhy[$fb['status']] }}@if($fb['updated_at']) The numbers below are from the last reading.@endif</p>
    @endunless
    @if($o)
        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6 {{ $fbOk ? '' : 'opacity-60' }}">
            @foreach($fbTiles as [$label, $key, $unit])
                @php($light = $fbOk ? $o[$key]['light'] : 'none')
                <div class="cb-card p-3" data-fb="{{ $key }}" data-light="{{ $light }}">
                    <div class="flex items-start justify-between gap-2 text-xs text-slate-500">
                        <span>{{ $label }}</span>
                        @if($o[$key]['light'] !== 'none')<span class="mt-0.5 inline-block h-3 w-3 shrink-0 rounded-full {{ $fbDot[$light] }}" title="{{ $light }}" aria-label="{{ $light === 'none' ? 'not available' : $light }}"></span>@endif
                    </div>
                    <div class="mt-1 text-2xl font-semibold">
                        @if($key === 'workers'){{ $o['workers']['value'] }} ({{ $o['workers']['status'] ?? 'unknown' }})
                        @else{{ $fbNum($o[$key]['value']) }}{{ $unit === 's' ? ' s' : $unit }}@endif
                    </div>
                </div>
            @endforeach
        </div>
        <p class="text-xs text-slate-500">Lights from Forbeast's thresholds: send lag and forward lag &gt; {{ $fb['thresholds']['send_lag_amber'] }} s amber, &gt; {{ $fb['thresholds']['send_lag_red'] }} s red; failed &gt; {{ $fb['thresholds']['failed_amber'] }}% amber, &gt; {{ $fb['thresholds']['failed_red'] }}% red; workers red when not running.</p>

        <div class="cb-card p-3">
            <h3 class="mb-1 text-sm font-medium">Send lag, last 24 hours (seconds; the dashed line is red)</h3>
            <svg viewBox="0 0 {{ $w }} {{ $h }}" class="h-32 w-full" preserveAspectRatio="none" role="img" aria-label="Forbeast send lag over the last 24 hours, highest {{ $fbNum($max) }} seconds">
                <line x1="0" y1="{{ $redY }}" x2="{{ $w }}" y2="{{ $redY }}" stroke="#dc2626" stroke-dasharray="4 4" stroke-width="1" vector-effect="non-scaling-stroke"/>
                @if(count($fb['series']) > 1)
                    <polyline fill="none" stroke="#2563eb" stroke-width="2" vector-effect="non-scaling-stroke" points="{{ implode(' ', array_map($pt, $fb['series'])) }}"/>
                @endif
            </svg>
            <div class="flex justify-between text-xs text-slate-400"><span>24 h ago</span><span>max {{ $fbNum($max) }} s</span><span>now</span></div>
        </div>

        <div class="cb-card overflow-x-auto">
            <table class="cb-table" data-list="forbeast-pages">
                <thead><tr><th>Page</th><th class="text-right">Send lag</th><th class="text-right">Queued</th><th class="text-right">Sent / min</th><th class="text-right">Failed % (60 min)</th></tr></thead>
                <tbody>
                @forelse($fb['pages'] as $r)
                    <tr><td><span class="mr-2 inline-block h-3 w-3 rounded-full align-middle {{ $fbDot[$fbOk ? $r['light'] : 'none'] }}" aria-hidden="true"></span>{{ $r['page'] }}</td>
                        <td class="text-right">{{ $fbNum($r['send_lag_seconds']) }} s</td><td class="text-right">{{ $fbNum($r['queued_sends']) }}</td>
                        <td class="text-right">{{ $fbNum($r['sent_per_min_5m']) }}</td><td class="text-right">{{ $fbNum($r['failed_pct_60m']) }}%</td></tr>
                @empty
                    <tr><td colspan="5" class="text-slate-500">Forbeast sent no numbers for the pages on Forbeast.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @endif
</section>
