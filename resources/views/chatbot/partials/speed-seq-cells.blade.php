{{-- Ang mga numero ng sequence steps ng isang oras o ng araw sa /chatbot/speed. --}}
@php($sec = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.') . ' s')
@foreach(['due', 'sent', 'failed', 'skipped'] as $k)<td class="text-right tabular-nums">{{ number_format($r[$k]) }}</td>@endforeach
@foreach(['scheduled_to_pushed', 'pushed_to_meta'] as $g)
<td class="text-right tabular-nums">{{ number_format($r[$g]['n']) }}</td>
@foreach(['avg', 'p90', 'max'] as $k)<td class="text-right tabular-nums">{{ $sec($r[$g][$k]) }}</td>@endforeach
@endforeach
