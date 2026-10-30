{{-- Ang laman ng Sequences section ng /chatbot/speed (sa ilalim ng heading), mula sa endpoint nito pagkatapos lumabas ng page. --}}
@php($sec = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.') . ' s')
@if(!empty($sequences['too_large']))
<p class="text-sm text-slate-600" data-sp-note="sequences">Sequences section too large to compute now. The other sections are complete; this one tries again within 2 minutes.</p>
@else
@php($st = $sequences['total'])
<p class="text-sm tabular-nums text-slate-600" data-sp-summary="sequences">Day: {{ number_format($st['due']) }} due · {{ number_format($st['sent']) }} sent · {{ number_format($st['failed']) }} failed · {{ number_format($st['skipped']) }} skipped; scheduled → pushed avg {{ $sec($st['scheduled_to_pushed']['avg']) }} · p90 {{ $sec($st['scheduled_to_pushed']['p90']) }} · max {{ $sec($st['scheduled_to_pushed']['max']) }}; pushed → sent at Meta: {{ number_format($st['pushed_to_meta']['n']) }} steps · avg {{ $sec($st['pushed_to_meta']['avg']) }} · p90 {{ $sec($st['pushed_to_meta']['p90']) }} · max {{ $sec($st['pushed_to_meta']['max']) }}</p>
@include('chatbot.partials.speed-chart', ['id' => 'seq', 'title' => 'Sequences by hour due: pushed → sent at Meta, average and p90', 'label' => 'Average and p90 of sequence steps from pushed to sent at Meta per hour, with the previous day as reference'])
<details class="sp-table mt-3" data-sp-table="sequences">
    <summary class="cursor-pointer select-none py-1 text-sm font-medium text-blue-700"><span class="sp-show">Show table</span><span class="sp-hide">Hide table</span></summary>
<div class="cb-card overflow-x-auto">
    <table class="cb-table min-w-[56rem]" data-table="sequences">
        <thead>
            <tr><th></th><th colspan="5"></th><th colspan="4" class="!text-center">Scheduled → pushed</th><th colspan="4" class="!text-center">Pushed → sent at Meta</th></tr>
            <tr><th>Hour</th>@foreach(['Due', 'Sent', 'Failed', 'Skipped', 'No message', 'Steps', 'Average', 'p90', 'Max', 'Steps', 'Average', 'p90', 'Max'] as $c)<th class="!text-right">{{ $c }}</th>@endforeach</tr>
        </thead>
        <tbody>
        @foreach($sequences['hours'] as $h => $r)
            <tr data-hour="{{ $h }}">
                <td class="tabular-nums">{{ str_pad($h, 2, '0', STR_PAD_LEFT) }}:00</td>
                @if($r === null)
                    @for($i = 0; $i < 13; $i++)<td></td>@endfor
                @else
                    @include('chatbot.partials.speed-seq-cells', ['r' => $r])
                @endif
            </tr>
        @endforeach
        </tbody>
        <tfoot><tr class="font-semibold"><td>Day</td>@include('chatbot.partials.speed-seq-cells', ['r' => $sequences['total']])</tr></tfoot>
    </table>
</div>
<p class="mt-2 text-xs text-slate-500">
    Scheduled = when the step was due. Pushed = when Likha handed the step's first message to Forbeast or Botcake. Sent at Meta = the sent time Forbeast reports (send_status). Botcake sends at once, so its sent time is the pushed time: those steps ({{ number_format($sequences['total']['botcake']) }} in this view) are not in "Pushed → sent at Meta". Scheduled is the step's own delay from the customer's previous step (or from joining the sequence), so waiting for the AI, a busy lock or a paused bot shows as delay. No message = the step ran but its flow sent nothing. Steps with no earlier history and no recorded due time have no scheduled time.
</p>
</details>
<details class="sp-table mt-3" data-sp-table="sequence-slowest">
    <summary class="cursor-pointer select-none py-1 text-sm font-medium text-blue-700"><span class="sp-show">Show slowest steps</span><span class="sp-hide">Hide slowest steps</span></summary>
<h3 class="mb-2 mt-2 text-sm font-semibold">Slowest steps of the day</h3>
<div class="cb-card overflow-x-auto">
    <table class="cb-table min-w-[52rem]" data-table="sequence-slowest">
        <thead><tr><th>Page</th><th>Sequence</th><th class="!text-right">Step</th><th>Scheduled</th><th>Pushed</th><th>Sent at Meta</th><th class="!text-right">Delay</th></tr></thead>
        <tbody>
        @forelse($sequences['slowest'] as $s)
            <tr>
                <td>{{ $s['page'] }}</td>
                <td>{{ $s['sequence'] }}</td>
                <td class="text-right tabular-nums">{{ $s['step'] }}</td>
                <td class="tabular-nums">{{ $s['scheduled'] ? substr($s['scheduled'], 11) : '—' }}</td>
                <td class="tabular-nums">{{ substr($s['pushed'], 11) }}@if($s['scheduled_to_pushed'] !== null) <span class="text-xs text-slate-500">+{{ $s['scheduled_to_pushed'] }} s</span>@endif</td>
                <td class="tabular-nums">@if($s['sent']){{ substr($s['sent'], 11) }}@if($s['sent_is_pushed']) <span class="text-xs text-slate-500">(Botcake: = pushed)</span>@else <span class="text-xs text-slate-500">+{{ $s['pushed_to_meta'] }} s</span>@endif @else — <span class="text-xs text-slate-500">(not confirmed)</span>@endif</td>
                <td class="text-right tabular-nums">{{ $s['delay'] }} s</td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-slate-500">No sequence steps sent this day.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
</details>
@endif
