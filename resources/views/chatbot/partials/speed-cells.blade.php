{{-- Ang mga numero ng isang row ng /chatbot/speed (oras, araw o bot). --}}
@php($num = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.'))
<td class="text-right tabular-nums">{{ number_format($r['messages']) }}</td>
<td class="text-right tabular-nums">{{ number_format($r['answered']) }}</td>
<td class="text-right tabular-nums">{{ number_format($r['unanswered']) }}</td>
@foreach(['avg', 'median', 'p90', 'max'] as $k)<td class="text-right tabular-nums">{{ $r[$k] === null ? '—' : $num($r[$k]) . ' s' }}</td>@endforeach
@foreach([30, 60, 300] as $s)<td class="text-right tabular-nums" title="{{ number_format($r["within_$s"]) }} messages">{{ $r["pct_$s"] === null ? '—' : $num($r["pct_$s"]) . '%' }}</td>@endforeach
<td class="text-right tabular-nums">{{ number_format($r['not_on_bot']) }}</td>
<td class="text-right tabular-nums">{{ number_format($r['not_sent']['never']) }}</td>
<td class="text-right tabular-nums">{{ number_format($r['not_sent']['late']) }}</td>
<td class="text-right tabular-nums">{{ $r['not_sent']['late_avg'] === null ? '—' : $num($r['not_sent']['late_avg']) . ' s' }}</td>
