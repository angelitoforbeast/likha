{{-- resources/views/ads_manager/adsets.blade.php --}}
<x-layout>
  <x-slot name="header">
    <h2 class="text-xl font-semibold leading-tight text-gray-800">
      📊 Ad Sets
    </h2>
  </x-slot>

  <div class="py-6">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

      {{-- Tabs --}}
      <div class="flex space-x-4 border-b mb-4">
        <a
          id="tab-campaigns"
          href="#"
          class="pb-2 border-b-2 {{ request()->routeIs('ads_manager.index') ? 'border-blue-600 font-semibold' : 'border-transparent text-gray-500' }}"
        >
          Campaigns
        </a>
        <a
          id="tab-adsets"
          href="#"
          class="pb-2 border-b-2 {{ request()->routeIs('ads_manager.adsets') ? 'border-blue-600 font-semibold' : 'border-transparent text-gray-500' }}"
        >
          Ad Sets
        </a>
      </div>

      {{-- Filters Form --}}
      <form
        id="filtersForm"
        method="GET"
        action="{{ route('ads_manager.adsets') }}"
        class="flex flex-wrap gap-4 items-end bg-white shadow rounded-lg p-4 mb-4"
      >
        {{-- Start Date --}}
        <div>
          <label for="start_date" class="block text-sm font-medium text-gray-700">Start Date</label>
          <input
            type="date"
            name="start_date"
            id="start_date"
            value="{{ request('start_date') }}"
            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"
          >
        </div>

        {{-- End Date --}}
        <div>
          <label for="end_date" class="block text-sm font-medium text-gray-700">End Date</label>
          <input
            type="date"
            name="end_date"
            id="end_date"
            value="{{ request('end_date') }}"
            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"
          >
        </div>

        {{-- Campaign checkboxes hidden so they persist --}}
        @foreach($campaigns as $campaignName)
          <input
            type="hidden"
            name="campaigns[]"
            value="{{ $campaignName }}"
            @if(!in_array($campaignName, $selectedCampaigns)) disabled @endif
          >
        @endforeach

        {{-- Apply --}}
        <div class="flex items-center ml-auto space-x-2">
          <button
            type="submit"
            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded"
          >
            Apply Filters
          </button>
        </div>
      </form>

      {{-- Ad Sets Table --}}
      <div class="overflow-x-auto bg-white shadow rounded-lg">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
          <thead class="bg-gray-100">
            <tr>
              <th class="px-4 py-2 text-left">Campaign Name</th>
              <th class="px-4 py-2 text-left">Ad Set Name</th>
              <th class="px-4 py-2 text-left">Total Spend</th>
              <th class="px-4 py-2 text-left">Impressions</th>
              <th class="px-4 py-2 text-left">Reach</th>
              <th class="px-4 py-2 text-left">Messages</th>
              <th class="px-4 py-2 text-left">Avg. Hook Rate</th>
              <th class="px-4 py-2 text-left">Avg. Hold Rate</th>
            </tr>
          </thead>
          <tbody class="bg-white divide-y divide-gray-100">
            @forelse ($ads as $campaignName => $adsets)
              @foreach ($adsets as $adsetName => $group)
                @php
                  $totalSpend       = $group->sum(fn($ad) => (float) $ad->amount_spent);
                  $totalImpressions = $group->sum(fn($ad) => (int)   $ad->impressions);
                  $totalReach       = $group->sum(fn($ad) => (int)   $ad->reach);
                  $totalMessages    = $group->sum(fn($ad) => (int)   $ad->messages);
                  $avgHook          = $group->avg(fn($ad) => (float) $ad->hook_rate);
                  $avgHold          = $group->avg(fn($ad) => (float) $ad->hold_rate);
                @endphp
                <tr>
                  <td class="px-4 py-2 font-medium text-gray-800">{{ $campaignName }}</td>
                  <td class="px-4 py-2">{{ $adsetName }}</td>
                  <td class="px-4 py-2">₱{{ number_format($totalSpend, 2) }}</td>
                  <td class="px-4 py-2">{{ number_format($totalImpressions) }}</td>
                  <td class="px-4 py-2">{{ number_format($totalReach) }}</td>
                  <td class="px-4 py-2">{{ number_format($totalMessages) }}</td>
                  <td class="px-4 py-2">{{ number_format($avgHook, 2) }}%</td>
                  <td class="px-4 py-2">{{ number_format($avgHold, 2) }}%</td>
                </tr>
              @endforeach
            @empty
              <tr>
                <td colspan="8" class="px-4 py-4 text-center text-gray-500">
                  No ad sets found.
                </td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>

    </div>
  </div>

  {{-- Tab click handler --}}
  <script>
    document.getElementById('tab-campaigns').addEventListener('click', e => {
      e.preventDefault();
      const params = new URLSearchParams(new FormData(document.getElementById('filtersForm')));
      window.location = `{{ route('ads_manager.index') }}?${params}`;
    });

    document.getElementById('tab-adsets').addEventListener('click', e => {
      e.preventDefault();
      // Already on adsets, do nothing
    });
  </script>
</x-layout>
