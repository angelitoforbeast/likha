<x-layout>
  <x-slot name="header">
    <h2 class="text-xl font-semibold leading-tight text-gray-800">
      📊 Campaigns
    </h2>
  </x-slot>

  <div class="py-6">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

      {{-- Tabs --}}
      <div class="flex space-x-4 border-b mb-4">
        <a
          href="{{ route('ads_manager.index', request()->only(['start_date','end_date','campaigns'])) }}"
          class="pb-2 border-b-2 {{ request()->routeIs('ads_manager.index') ? 'border-blue-600 font-semibold' : 'border-transparent text-gray-500' }}"
        >
          Campaigns
        </a>
        <a
          href="{{ route('ads_manager.adsets', request()->only(['start_date','end_date','campaigns'])) }}"
          class="pb-2 border-b-2 {{ request()->routeIs('ads_manager.adsets') ? 'border-blue-600 font-semibold' : 'border-transparent text-gray-500' }}"
        >
          Ad Sets
        </a>
      </div>

      <form
        id="filtersForm"
        method="GET"
        action="{{ route('ads_manager.index') }}"
        class="bg-white shadow rounded-lg p-4 mb-6"
      >
        {{-- Date Filters --}}
        <div class="flex flex-wrap gap-4 items-end mb-4">
          <div>
            <label for="start_date" class="block text-sm font-medium text-gray-700">Start Date</label>
            <input
              type="date"
              name="start_date"
              id="start_date"
              value="{{ request('start_date') }}"
              class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"
              onchange="this.form.submit()"
            >
          </div>

          <div>
            <label for="end_date" class="block text-sm font-medium text-gray-700">End Date</label>
            <input
              type="date"
              name="end_date"
              id="end_date"
              value="{{ request('end_date') }}"
              class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"
              onchange="this.form.submit()"
            >
          </div>
        </div>

        {{-- Campaigns Table --}}
        <div class="overflow-x-auto bg-white rounded-lg">
          <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-100">
              <tr>
                <th class="px-4 py-2"></th>
                <th class="px-4 py-2 text-left">Campaign Name</th>
                <th class="px-4 py-2 text-left">Amount Spent</th>
                <th class="px-4 py-2 text-left">Budget</th>
                <th class="px-4 py-2 text-left">Impressions</th>
                <th class="px-4 py-2 text-left">Messages</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-100">
              @forelse ($ads as $campaignName => $group)
                @php
                  $spent       = $group->sum(fn($ad) => (float) $ad->amount_spent);
                  $budget      = $group->sum(fn($ad) => (float) $ad->budget);
                  $impressions = $group->sum(fn($ad) => (int)   $ad->impressions);
                  $messages    = $group->sum(fn($ad) => (int)   $ad->messages);
                @endphp
                <tr>
                  <td class="px-4 py-2">
                    <input
                      type="checkbox"
                      name="campaigns[]"
                      value="{{ $campaignName }}"
                      onchange="this.form.submit()"
                      {{ in_array($campaignName, $selectedCampaigns) ? 'checked' : '' }}
                    >
                  </td>
                  <td class="px-4 py-2 font-medium text-gray-800">{{ $campaignName }}</td>
                  <td class="px-4 py-2">₱{{ number_format($spent, 2) }}</td>
                  <td class="px-4 py-2">₱{{ number_format($budget, 2) }}</td>
                  <td class="px-4 py-2">{{ number_format($impressions) }}</td>
                  <td class="px-4 py-2">{{ number_format($messages) }}</td>
                </tr>
              @empty
                <tr>
                  <td colspan="6" class="px-4 py-4 text-center text-gray-500">
                    No campaigns found.
                  </td>
                </tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </form>

    </div>
  </div>
</x-layout>
