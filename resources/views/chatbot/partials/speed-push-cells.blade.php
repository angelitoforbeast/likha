{{-- Ang pushed → sent sa Meta ng isang oras o ng araw sa /chatbot/speed. --}}
@php($sec = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.') . ' s')
<td class="text-right tabular-nums">{{ number_format($r['pushed']) }}</td>
<td class="text-right tabular-nums">{{ number_format($r['measured']) }}</td>
@foreach(['avg', 'median', 'p90', 'max'] as $k)<td class="text-right tabular-nums">{{ $sec($r[$k]) }}</td>@endforeach
<td class="text-right tabular-nums">{{ number_format($r['unconfirmed']) }}</td>
<td class="text-right tabular-nums">{{ number_format($r['botcake']) }}</td>
