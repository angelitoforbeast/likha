<x-layout>
  <x-slot name="title">Card check</x-slot>
  <x-slot name="heading">Card check: {{ $s->card_label ?: 'statement' }} · {{ $s->period_start?->toDateString() }} – {{ $s->period_end?->toDateString() }}</x-slot>

  @php
    $peso = fn ($v) => '₱' . number_format((float) $v, 2);
    $labels = ['matched' => 'Tugma', 'none' => 'Wala sa Meta', 'group_off' => 'Grupo, hindi tally', 'credit' => 'Credit', 'not_ads' => 'Not ads'];
    $selectedCard = old('card_label', $s->card_label ?: ($suggest['label'] ?? null));
    $statusOf = function ($line) use ($result) {
        $r = $result['lines'][$line->id] ?? null;
        if (!$r) return 'none';
        return match ($r['match_kind']) {
            'sale_date', 'post_date', 'near' => 'matched',
            'group' => ($result['groups'][$r['group_key']]['tallied'] ?? false) ? 'matched' : 'group_off',
            default => $r['match_kind'],
        };
    };
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
    <div class="flex flex-wrap gap-2 mb-4 text-sm">
      <span class="px-3 py-1 rounded-full bg-green-100 text-green-800">Tugma {{ $t['matched_count'] }} · {{ $peso($t['matched_total']) }}</span>
      <span class="px-3 py-1 rounded-full bg-orange-100 text-orange-800">Wala sa card {{ $t['no_card_count'] }} · {{ $peso($t['no_card_total']) }}</span>
      <span class="px-3 py-1 rounded-full bg-red-100 text-red-800">Wala sa Meta {{ $t['no_meta_count'] }} · {{ $peso($t['no_meta_total']) }}</span>
      <span class="px-3 py-1 rounded-full bg-gray-100 text-gray-800">100-groups: {{ $t['groups_tallied'] }} tally · {{ $t['groups_untallied'] }} hindi</span>
      <span class="px-3 py-1 rounded-full bg-gray-100 text-gray-800">Card {{ $peso($t['card_total']) }} · Meta {{ $peso($t['meta_total']) }} · Diperensya {{ $peso($t['difference_total']) }}</span>
    </div>

    <div class="grid md:grid-cols-3 gap-4 mb-4">
      <div class="bg-white rounded shadow overflow-x-auto">
        <h3 class="font-semibold p-2">Bawat araw</h3>
        <table class="min-w-full text-sm">
          <thead class="bg-gray-100"><tr><th class="p-2 text-left">Petsa</th><th class="p-2 text-right">Card</th><th class="p-2 text-right">Meta</th><th class="p-2 text-right">Diff</th></tr></thead>
          <tbody>
            @foreach ($result['days'] as $date => $d)
              <tr class="{{ (float) $d['diff'] != 0 ? 'bg-red-50' : '' }}">
                <td class="p-2 border-t font-mono">{{ $date }}</td>
                <td class="p-2 border-t text-right">{{ number_format((float) $d['card'], 2) }}</td>
                <td class="p-2 border-t text-right">{{ number_format((float) $d['meta'], 2) }}</td>
                <td class="p-2 border-t text-right {{ (float) $d['diff'] != 0 ? 'text-red-700 font-semibold' : 'text-gray-400' }}">{{ number_format((float) $d['diff'], 2) }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      <div class="bg-white rounded shadow overflow-x-auto">
        <h3 class="font-semibold p-2">Mga grupo (umuulit na amount sa isang araw)</h3>
        <table class="min-w-full text-sm">
          <thead class="bg-gray-100"><tr><th class="p-2 text-left">Petsa</th><th class="p-2 text-right">Amount</th><th class="p-2 text-right">Card</th><th class="p-2 text-right">Meta</th></tr></thead>
          <tbody>
            @forelse ($result['groups'] as $g)
              <tr class="{{ $g['tallied'] ? '' : 'bg-red-50' }}">
                <td class="p-2 border-t font-mono">{{ $g['date'] }}</td>
                <td class="p-2 border-t text-right">{{ number_format((float) $g['amount'], 2) }}</td>
                <td class="p-2 border-t text-right">{{ $g['card_count'] }} · {{ number_format((float) $g['card_total'], 2) }}</td>
                <td class="p-2 border-t text-right">{{ $g['meta_count'] }} · {{ number_format((float) $g['meta_total'], 2) }}</td>
              </tr>
            @empty
              <tr><td colspan="4" class="p-2 text-gray-500">Wala.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>

      <div class="bg-white rounded shadow overflow-x-auto">
        <h3 class="font-semibold p-2">Nasa Meta pero wala sa card</h3>
        <table class="min-w-full text-sm">
          <tbody>
            @forelse ($result['meta_missing'] as $m)
              <tr>
                <td class="p-2 border-t font-mono">{{ $m['date'] }}</td>
                <td class="p-2 border-t">{{ $m['transaction_id'] }}<div class="text-xs text-gray-500">{{ $m['ad_account'] }}</div></td>
                <td class="p-2 border-t text-right">{{ number_format((float) $m['amount'], 2) }}</td>
              </tr>
            @empty
              <tr><td class="p-2 text-gray-500">Wala.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>

    <div class="bg-white rounded shadow mb-4" x-data="{ f: @js($status) }">
      <div class="flex flex-wrap items-center gap-2 p-2 text-sm">
        <span class="font-semibold mr-2">Mga linya</span>
        <button type="button" @click="f = ''" :class="f === '' ? 'bg-blue-600 text-white' : 'bg-gray-100'" class="px-2 py-1 rounded">Lahat</button>
        @foreach ($labels as $key => $label)
          <button type="button" @click="f = @js($key)" :class="f === @js($key) ? 'bg-blue-600 text-white' : 'bg-gray-100'" class="px-2 py-1 rounded">{{ $label }}</button>
        @endforeach
      </div>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-100">
            <tr>
              <th class="p-2 text-left">Sale</th><th class="p-2 text-left">Post</th><th class="p-2 text-left">Description</th>
              <th class="p-2 text-right">Amount</th><th class="p-2 text-left">Status</th><th class="p-2 text-left">Meta transaction / ad account</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($lines as $l)
              @php
                $st = $statusOf($l);
                $r = $result['lines'][$l->id] ?? [];
                $m = isset($r['payment_activity_id']) ? ($result['meta_by_id'][$r['payment_activity_id']] ?? null) : null;
              @endphp
              <tr data-status="{{ $st }}" x-show="f === '' || f === @js($st)" class="{{ in_array($st, ['none', 'group_off'], true) ? 'bg-red-50' : '' }}">
                <td class="p-2 border-t font-mono">{{ $l->sale_date?->toDateString() }}</td>
                <td class="p-2 border-t font-mono">{{ $l->post_date?->toDateString() }}</td>
                <td class="p-2 border-t font-mono">{{ $l->description }}</td>
                <td class="p-2 border-t text-right">{{ number_format((float) $l->amount, 2) }}{{ $l->is_credit ? '-' : '' }}</td>
                <td class="p-2 border-t">{{ $labels[$st] ?? $st }}
                  @if (($r['match_kind'] ?? '') === 'post_date') <span class="text-xs text-gray-500">(post date)</span>
                  @elseif (($r['match_kind'] ?? '') === 'near') <span class="text-xs text-gray-500">({{ $r['match_days'] > 0 ? '+' : '' }}{{ $r['match_days'] }} araw)</span>
                  @elseif (($r['match_kind'] ?? '') === 'group') <span class="text-xs text-gray-500">(grupo {{ $r['group_key'] }})</span>
                  @endif
                </td>
                <td class="p-2 border-t">@if ($m) {{ $m['transaction_id'] }} <span class="text-xs text-gray-500">{{ $m['ad_account'] }} · {{ $m['date'] }}</span> @endif</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>

    <details class="bg-white p-4 rounded shadow" @if ($errors->any()) open @endif>
      <summary class="cursor-pointer font-semibold">I-edit ang mga linya / itugma ulit (kasama ang mga bagong Meta import)</summary>
      <p class="text-xs text-gray-500 mt-1">Huling naitugma: {{ $s->matched_at?->timezone('Asia/Manila')->format('Y-m-d H:i') }}</p>
      <div class="mt-3">@include('ads_manager.payment.reconcile._lines_form')</div>
    </details>
  @endif
</x-layout>
