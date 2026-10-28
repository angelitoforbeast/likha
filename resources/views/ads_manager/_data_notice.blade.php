{{-- Pulang paalala mula sa huling 12:00 na check (ads:data-health --record) kapag may account na Missing / New / Not readable. --}}
@php
  use App\Services\MetaAds\DataHealth;
  $check = $health['last'];
  $need = $check ? array_values(array_filter($check['accounts'] ?? [], fn ($a) => in_array($a['status'], DataHealth::ATTENTION, true))) : [];
@endphp
@if ($need)
  <div class="rounded border border-red-300 bg-red-50 text-red-800 px-4 py-3 text-sm space-y-1" id="ads-data-notice" role="alert">
    <div class="font-semibold">
      ⚠ {{ count($need) }} {{ count($need) === 1 ? 'ad account needs' : 'ad accounts need' }} attention
      <span class="font-normal text-xs">(check for {{ $check['day'] }}, {{ $check['checked_at'] }})</span>
    </div>
    <ul class="text-xs space-y-0.5">
      @foreach ($need as $a)
        <li class="break-words">{{ $a['name'] }} @if ($a['id'] !== '')<span class="font-mono break-all">{{ $a['id'] }}</span>@endif — {{ $a['status'] }}</li>
      @endforeach
    </ul>
    <a href="#ads-data-health" class="text-xs underline">See Data health</a>
  </div>
@endif
