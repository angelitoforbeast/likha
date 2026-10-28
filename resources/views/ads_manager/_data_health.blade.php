{{-- Data health: bawat ad account at kung may data kahapon (sync man o upload). Ang may "Ads" link lang ang nakakakita. --}}
@php
  use App\Services\MetaAds\DataHealth;
  $live = $health['live'];
  $badge = [
    DataHealth::OK           => 'bg-green-100 text-green-800',
    DataHealth::MISSING      => 'bg-red-100 text-red-800',
    DataHealth::NOT_READABLE => 'bg-red-100 text-red-800',
    DataHealth::NEW          => 'bg-yellow-100 text-yellow-800',
    DataHealth::UNTICKED     => 'bg-gray-200 text-gray-800',
    DataHealth::QUIET        => 'bg-gray-100 text-gray-700',
  ];
  $rows = fn (int $n) => $n . ' ' . ($n === 1 ? 'row' : 'rows');
@endphp
<div class="border rounded p-4 space-y-3" id="ads-data-health">
  <div class="flex items-center justify-between flex-wrap gap-2">
    <h2 class="font-semibold">Data health · kahapon {{ $live['day'] }}</h2>
    <span class="text-xs text-gray-500">{{ $live['attention'] }} of {{ count($live['accounts']) }} need attention</span>
  </div>
  <p class="text-xs text-gray-500">
    Bawat ad account (Facebook connection, /ads_manager/ad_account at mga upload) at kung may data kahapon.
    Missing yesterday = may spend sa 3 araw bago pero wala kahapon. Not readable by API = i-upload nang manual o i-connect ng may access.
  </p>
  <div class="text-xs {{ ($live['refused']['today'] + $live['refused']['yesterday']) > 0 ? 'text-red-700' : 'text-gray-600' }}">
    Refused (lower values): today {{ $rows($live['refused']['today']) }} · yesterday {{ $rows($live['refused']['yesterday']) }}
  </div>
  <div class="divide-y border rounded">
    @forelse ($live['accounts'] as $a)
      <div class="flex flex-wrap items-start gap-x-3 gap-y-1 p-2 text-sm">
        <span class="min-w-0 flex-1 basis-48">
          <span class="font-medium break-words">{{ $a['name'] }}</span>
          @if ($a['id'] !== '')<span class="font-mono text-xs text-gray-500 break-all">{{ $a['id'] }}</span>@endif
          <span class="block text-xs text-gray-500">{{ $a['source'] }} · latest {{ $a['latest_day'] ?? '—' }}</span>
        </span>
        <span class="text-xs font-mono shrink-0">{{ $rows($a['rows']) }} · ₱{{ number_format($a['spend'], 2) }}</span>
        <span class="text-xs px-2 py-0.5 rounded shrink-0 {{ $badge[$a['status']] ?? '' }}">{{ $a['status'] }}</span>
      </div>
    @empty
      <div class="p-2 text-xs text-gray-500">Walang ad account na kilala.</div>
    @endforelse
  </div>
</div>
