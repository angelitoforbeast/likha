{{-- Ang mga row ng isang upload o Sync run na tinanggihan dahil may numerong bumaba (buong row, walang ginalaw). --}}
@php use App\Services\MetaAds\DataHealth; @endphp
<details class="cursor-pointer mt-1" data-refused="{{ $ref['count'] }}">
  <summary class="text-red-700 font-medium">⛔ {{ DataHealth::label($ref['count']) }}</summary>
  <div class="mt-1 space-y-0.5 text-[10px] text-gray-700">
    @foreach ($ref['rows'] as $x)
      <div class="font-mono break-words">{{ $x['day'] }} ad {{ $x['ad_id'] }} · {{ $x['account'] ?? 'account unknown' }}:
        @foreach ($x['metrics'] as $f => $m){{ $f }} {{ DataHealth::num($m['stored']) }} → {{ DataHealth::num($m['incoming']) }}@if (!$loop->last); @endif @endforeach
      </div>
    @endforeach
    @if ($ref['count'] > count($ref['rows']))
      <div class="text-gray-500">… at {{ $ref['count'] - count($ref['rows']) }} pa</div>
    @endif
  </div>
</details>
