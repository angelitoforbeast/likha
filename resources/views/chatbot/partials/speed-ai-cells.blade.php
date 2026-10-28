{{-- With AI, no AI at AI time ng isang row ng /chatbot/speed. --}}
@php($sec = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.') . ' s')
@foreach(['with_ai', 'no_ai'] as $g)
<td class="text-right tabular-nums">{{ number_format($r[$g]['n']) }}</td>
@foreach(['avg', 'median', 'p90', 'max'] as $k)<td class="text-right tabular-nums">{{ $sec($r[$g][$k]) }}</td>@endforeach
@endforeach
@foreach(['avg', 'p90', 'max'] as $k)<td class="text-right tabular-nums">{{ $sec($r['ai_time'][$k]) }}</td>@endforeach
