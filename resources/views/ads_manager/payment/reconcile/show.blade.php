<x-layout>
  <x-slot name="title">Card check</x-slot>
  <x-slot name="heading">Card check: {{ $s->card_label ?: 'statement' }} · {{ $s->period_start?->toDateString() }} – {{ $s->period_end?->toDateString() }}</x-slot>

  @php
    $peso = fn ($v) => ((float) $v < 0 ? '-' : '') . '₱' . number_format(abs((float) $v), 2);
    $num = fn ($v) => number_format((float) $v, 2);
    $shortId = fn ($id) => mb_strlen((string) $id) > 14 ? mb_substr((string) $id, 0, 6) . '…' . mb_substr((string) $id, -4) : (string) $id;
    $selectedCard = old('card_label', $s->card_label ?: ($suggest['label'] ?? null));
    $marks = ['matched' => 'Tugma', 'card_only' => 'Wala sa Meta', 'meta_only' => 'Wala sa card', 'group_off' => 'Hindi tally'];
    $tint = fn ($row) => match ($row['status']) {
        'card_only' => 'bg-red-50',
        'meta_only' => 'bg-amber-50',
        'group_off' => ($row['group']['more'] ?? '') === 'card' ? 'bg-red-50' : 'bg-amber-50',
        default     => '',
    };
    $link = fn (array $q) => route('ads_payment.reconcile.show', array_merge([$s], array_filter($q, fn ($v) => $v !== null && $v !== '')));
  @endphp

  <div class="mb-3 text-sm"><a href="{{ route('ads_payment.reconcile.index') }}" class="text-blue-700 hover:underline">← Lahat ng statement</a>
    <span class="text-gray-500 ml-2">{{ strtoupper($s->bank) }} · {{ $s->source_kind }} · {{ $s->original_name }}</span></div>

  @if (session('status')) <div class="bg-green-50 border border-green-300 text-green-800 p-2 rounded mb-3 text-sm">{{ session('status') }}</div> @endif

  @if ($s->status === 'preview')
    <div class="bg-white p-4 rounded shadow mb-4">
      <h2 class="font-semibold">Preview: tingnan kung tama ang nabasa bago itugma</h2>
      @if ($suggest)
        <p class="text-sm mt-1">Mungkahing card:
          @if ($suggest['label']) <b>{{ $suggest['label'] }}</b> ({{ $suggest['scores'][$suggest['label']] }} na amount ang tugma)
          @else <span class="text-gray-500">wala, walang amount na tumugma sa alinmang card</span> @endif
        </p>
      @endif
      <div class="mt-3">@include('ads_manager.payment.reconcile._lines_form')</div>
    </div>
  @else
    @php $t = $result['totals']; @endphp

    {{-- Summary bar: bawat chip ay filter ng mga row. --}}
    <div class="flex flex-wrap gap-2 mb-3 text-sm sticky top-0 z-10 bg-gray-100 py-2">
      <a href="{{ $link([]) }}" class="px-3 py-1 rounded-full border {{ $status === '' ? 'bg-blue-600 text-white border-blue-600' : 'bg-white' }}">Lahat</a>
      <a href="{{ $link(['status' => 'matched']) }}" class="px-3 py-1 rounded-full border border-green-300 {{ $status === 'matched' ? 'ring-2 ring-green-600' : '' }} bg-green-100 text-green-800">Tugma {{ $t['matched_count'] }} · {{ $peso($t['matched_total']) }}</a>
      <a href="{{ $link(['status' => 'card_only']) }}" class="px-3 py-1 rounded-full border border-red-300 {{ $status === 'card_only' ? 'ring-2 ring-red-600' : '' }} bg-red-100 text-red-800">Wala sa Meta {{ $t['no_meta_count'] }} · {{ $peso($t['no_meta_total']) }}</a>
      <a href="{{ $link(['status' => 'meta_only']) }}" class="px-3 py-1 rounded-full border border-amber-300 {{ $status === 'meta_only' ? 'ring-2 ring-amber-600' : '' }} bg-amber-100 text-amber-800">Wala sa card {{ $t['no_card_count'] }} · {{ $peso($t['no_card_total']) }}</a>
      <a href="{{ $link(['status' => 'group_off']) }}" class="px-3 py-1 rounded-full border {{ $status === 'group_off' ? 'ring-2 ring-gray-600' : '' }} bg-white text-gray-800">Hindi tally na grupo {{ $t['groups_untallied'] }}</a>
      <span class="px-3 py-1 rounded-full bg-white border {{ (float) $t['difference_total'] != 0 ? 'text-red-700 font-semibold' : 'text-gray-700' }}">Card {{ $peso($t['card_total']) }} · Meta {{ $peso($t['meta_total']) }} · Diperensya {{ $peso($t['difference_total']) }}</span>
    </div>
    <p class="text-xs text-gray-500 -mt-2 mb-3">Ang bilang sa chips ay ng card lines at Meta records; ang umuulit na amount sa isang araw ay isang row.</p>

    @php
      $vdays = $view['days'];
      $range = $vdays === [] ? '' : ($vdays[0]['date'] ?? 'walang petsa') . ' – ' . (end($vdays)['date'] ?? 'walang petsa');
    @endphp
    <div class="flex items-center justify-between text-sm mb-2">
      <span class="text-gray-600">{{ $range }}</span>
      @if ($view['pages'] > 1)
        <span class="flex items-center gap-2">
          @if ($view['page'] > 1) <a class="text-blue-700 hover:underline" href="{{ $link(['status' => $status, 'page' => $view['page'] - 1]) }}">← Nauna</a> @endif
          <span>Page {{ $view['page'] }} / {{ $view['pages'] }}</span>
          @if ($view['page'] < $view['pages']) <a class="text-blue-700 hover:underline" href="{{ $link(['status' => $status, 'page' => $view['page'] + 1]) }}">Susunod →</a> @endif
        </span>
      @endif
    </div>

    <div class="bg-white rounded shadow overflow-x-auto mb-4">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-100">
          <tr>
            <th class="p-2 text-left w-[45%]" colspan="3">Card statement</th>
            <th class="p-2 text-center w-28">Status</th>
            <th class="p-2 text-left w-[45%]" colspan="4">Meta</th>
          </tr>
          <tr class="text-xs text-gray-600">
            <th class="px-2 text-left">Sale / post</th><th class="px-2 text-left">Description</th><th class="px-2 text-right">Amount</th>
            <th></th>
            <th class="px-2 text-left">Petsa</th><th class="px-2 text-left">Transaction</th><th class="px-2 text-left">Ad account</th><th class="px-2 text-right">Amount</th>
          </tr>
        </thead>
        <tbody>
          @forelse ($vdays as $day)
            <tr class="bg-gray-50"><td colspan="8" class="px-2 pt-3 pb-1 font-semibold font-mono">{{ $day['date'] ?? 'Walang petsa' }}</td></tr>
            @foreach ($day['rows'] as $row)
              <tr data-status="{{ $row['status'] }}" class="{{ $tint($row) }} border-t align-top">
                @if ($row['group'] && $row['card'])
                  <td class="p-2 font-mono">{{ $row['card'][0]['sale_date'] }}</td>
                  <td class="p-2"><b>{{ $row['group']['card_text'] }}</b>
                    <details class="text-xs text-gray-500"><summary class="cursor-pointer">mga linya</summary>@foreach ($row['card'] as $l)<div class="font-mono">{{ $l['description'] }}</div>@endforeach</details></td>
                  <td class="p-2 text-right">{{ $num($row['card_cents'] / 100) }}</td>
                @elseif ($row['card'])
                  @php $l = $row['card'][0]; @endphp
                  <td class="p-2 font-mono whitespace-nowrap">{{ $l['sale_date'] ?? '—' }}<div class="text-xs text-gray-500">{{ $l['post_date'] }}</div></td>
                  <td class="p-2 font-mono break-all">{{ $l['description'] }}</td>
                  <td class="p-2 text-right">{{ $num($l['amount']) }}</td>
                @else
                  <td class="p-2" colspan="3"></td>
                @endif

                <td class="p-2 text-center whitespace-nowrap font-semibold {{ $row['status'] === 'matched' ? 'text-green-700' : ($row['status'] === 'card_only' ? 'text-red-700' : 'text-amber-700') }}">
                  {{ $row['status'] === 'matched' ? '✓ ' : '' }}{{ $marks[$row['status']] }}
                  @if ($row['match_kind'] === 'post_date') <div class="text-xs font-normal text-gray-500">post date</div>
                  @elseif ($row['match_kind'] === 'near') <div class="text-xs font-normal text-gray-500">{{ $row['match_days'] > 0 ? '+' : '' }}{{ $row['match_days'] }} araw</div>
                  @endif
                </td>

                @if ($row['group'])
                  <td class="p-2 font-mono">{{ $row['meta'][0]['date'] ?? '' }}</td>
                  <td class="p-2" colspan="2"><b>{{ $row['group']['meta_text'] }}</b>
                    @if ($row['meta'])
                      <details class="text-xs text-gray-500"><summary class="cursor-pointer">mga transaction</summary>@foreach ($row['meta'] as $m)<div title="{{ $m['transaction_id'] }}">{{ $shortId($m['transaction_id']) }} · {{ $m['ad_account'] }}</div>@endforeach</details>
                    @endif</td>
                  <td class="p-2 text-right">{{ $num($row['meta_cents'] / 100) }}</td>
                @elseif ($row['meta'])
                  @php $m = $row['meta'][0]; @endphp
                  <td class="p-2 font-mono whitespace-nowrap">{{ $m['date'] }}</td>
                  <td class="p-2 font-mono" title="{{ $m['transaction_id'] }}">{{ $shortId($m['transaction_id']) }}</td>
                  <td class="p-2">{{ $m['ad_account'] }}</td>
                  <td class="p-2 text-right">{{ $num($m['amount']) }}</td>
                @else
                  <td class="p-2" colspan="4"></td>
                @endif
              </tr>
            @endforeach
            <tr data-day-total="{{ $day['date'] ?? '' }}" class="border-t-2 border-gray-300 font-semibold text-xs">
              <td class="p-2" colspan="2">Total ng araw{{ $status !== '' ? ' (buong araw, hindi lang ang naka-filter)' : '' }}</td>
              <td class="p-2 text-right">{{ $num($day['card_total']) }}</td>
              <td class="p-2 text-center text-gray-500">diff</td>
              <td class="p-2" colspan="2"></td>
              <td class="p-2 text-right">{{ $num($day['meta_total']) }}</td>
              <td class="p-2 text-right {{ (float) $day['diff'] != 0 ? 'text-red-700' : 'text-gray-400' }}">{{ $num($day['diff']) }}</td>
            </tr>
          @empty
            <tr><td colspan="8" class="p-4 text-center text-gray-500">Walang row sa filter na ito.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>

    @if ($others->isNotEmpty())
      <details class="bg-white p-4 rounded shadow mb-4">
        <summary class="cursor-pointer font-semibold">Ibang linya: credit at hindi ads ({{ $others->count() }}), hindi itinutugma</summary>
        <table class="min-w-full text-sm mt-2">
          @foreach ($others as $l)
            <tr class="border-t text-gray-600">
              <td class="p-1 font-mono">{{ $l->sale_date?->toDateString() }}</td>
              <td class="p-1 font-mono">{{ $l->description }}</td>
              <td class="p-1 text-right">{{ $num($l->amount) }}{{ $l->is_credit ? '-' : '' }}</td>
              <td class="p-1">{{ $l->is_ads ? 'Credit' : 'Not ads' }}</td>
            </tr>
          @endforeach
        </table>
      </details>
    @endif

    <details class="bg-white p-4 rounded shadow" @if ($errors->any() || request()->has('lp')) open @endif>
      <summary class="cursor-pointer font-semibold">I-edit ang mga linya / itugma ulit (kasama ang mga bagong Meta import)</summary>
      <p class="text-xs text-gray-500 mt-1">Huling naitugma: {{ $s->matched_at?->timezone('Asia/Manila')->format('Y-m-d H:i') }}</p>
      <div class="mt-3">@include('ads_manager.payment.reconcile._lines_form')</div>
    </details>
  @endif
</x-layout>
