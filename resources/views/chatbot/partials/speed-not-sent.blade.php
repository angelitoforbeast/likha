{{-- Ang listahan ng Not sent ng /chatbot/speed (pinakabago muna): walang teksto ng customer, ang code lang ng error ng Meta. --}}
<div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
    <label class="flex items-center gap-1 text-xs">Hour
        <select data-sp-notsent-hour class="border px-2 py-1 text-sm text-gray-900">
            <option value="">Whole day</option>
            @for($h = 0; $h < 24; $h++)<option value="{{ $h }}" @selected($hour === $h)>{{ str_pad($h, 2, '0', STR_PAD_LEFT) }}:00</option>@endfor
        </select>
    </label>
    <span class="text-xs text-slate-500">{{ number_format($total) }} {{ $total === 1 ? 'message' : 'messages' }}@if($total > count($items)) (newest {{ number_format(count($items)) }} shown)@endif</span>
</div>
<div class="cb-card mt-2 overflow-x-auto">
    <table class="cb-table min-w-[44rem]" data-table="not-sent">
        <thead><tr><th>Message</th><th>Page</th><th>Bot</th><th>Result</th><th class="!text-right">Failed tries</th><th class="!text-right">Wait</th><th>Meta error</th><th></th></tr></thead>
        <tbody>
        @forelse($items as $r)
            <tr>
                <td class="tabular-nums">{{ substr($r['at'], 11) }}</td>
                <td>{{ $r['page'] }}</td>
                <td>{{ $r['bot'] }}</td>
                <td>{{ $r['kind'] === 'never' ? 'Never sent' : 'Sent late' }}</td>
                <td class="text-right tabular-nums">{{ number_format($r['attempts']) }}</td>
                <td class="text-right tabular-nums">{{ $r['wait'] === null ? '—' : number_format($r['wait']) . ' s' }}</td>
                <td class="tabular-nums">{{ $r['code'] === null ? '—' : '#' . $r['code'] . ($r['subcode'] !== null ? ' / ' . $r['subcode'] : '') }}</td>
                <td><a href="{{ $r['inbox'] }}" class="text-blue-700 underline hover:text-blue-900">Inbox</a></td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-slate-500">No message was left unsent {{ $hour === null ? 'this day' : 'in this hour' }}.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<p class="mt-2 text-xs text-slate-500">Message = when the customer's message arrived. Never sent = no reply went through within 24 hours; Sent late = a reply went through after the failed tries, Wait = how long the customer waited for it. Meta error = the code (and subcode) of the last failed try, when Meta gave one.</p>
