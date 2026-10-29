{{-- Ang reply time sa orasan ng Meta (mula sa Forbeast) ng isang oras o ng araw sa /chatbot/speed. --}}
@php($sec = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.') . ' s')
@foreach(['messages', 'bot_answered', 'person_answered', 'unanswered'] as $k)<td class="text-right tabular-nums">{{ number_format($r[$k]) }}</td>@endforeach
@foreach(['avg', 'median', 'p90', 'max', 'meta_to_forbeast_avg'] as $k)<td class="text-right tabular-nums">{{ $sec($r[$k]) }}</td>@endforeach
