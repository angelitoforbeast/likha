<x-layout>
  <x-slot name="heading">Facebook Ads Data</x-slot>

  <form method="GET" action="{{ route('fb_ads.fetch') }}" class="space-y-4">
    <div class="flex items-center gap-4">
      <div>
        <label for="start_date" class="font-semibold">Start Date:</label>
        <input type="date" name="start_date" id="start_date" required class="border rounded px-2 py-1">
      </div>
      <div>
        <label for="end_date" class="font-semibold">End Date:</label>
        <input type="date" name="end_date" id="end_date" required class="border rounded px-2 py-1">
      </div>
      <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">GET DATA</button>
    </div>
  </form>

  @isset($ads)
    <div class="mt-6 overflow-auto">
      <table class="w-full border text-sm">
        <thead class="bg-gray-200">
          <tr>
            <th class="border px-2 py-1">Reporting Start</th>
            <th class="border px-2 py-1">Reporting End</th>
            <th class="border px-2 py-1">Campaign Name</th>
            <th class="border px-2 py-1">Ad Set Name</th>
            <th class="border px-2 py-1">Amount Spent (PHP)</th>
            <th class="border px-2 py-1">Impressions</th>
            <th class="border px-2 py-1">Messaging Conversations Started</th>
            <th class="border px-2 py-1">Ad Set Budget</th>
            <th class="border px-2 py-1">Delivery</th>
            <th class="border px-2 py-1">Page Name</th>
            <th class="border px-2 py-1">Ad Delivery</th>
            <th class="border px-2 py-1">Ad ID</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($ads as $ad)
            <tr>
              <td class="border px-2 py-1">{{ $ad['reporting_start'] }}</td>
              <td class="border px-2 py-1">{{ $ad['reporting_end'] }}</td>
              <td class="border px-2 py-1">{{ $ad['campaign_name'] }}</td>
              <td class="border px-2 py-1">{{ $ad['ad_set_name'] }}</td>
              <td class="border px-2 py-1">₱{{ $ad['amount_spent'] }}</td>
              <td class="border px-2 py-1">{{ $ad['impressions'] }}</td>
              <td class="border px-2 py-1">{{ $ad['conversations_started'] ?? 'N/A' }}</td>
              <td class="border px-2 py-1">{{ $ad['ad_set_budget'] }}</td>
              <td class="border px-2 py-1">{{ $ad['delivery'] }}</td>
              <td class="border px-2 py-1">{{ $ad['page_name'] }}</td>
              <td class="border px-2 py-1">{{ $ad['ad_delivery'] }}</td>
              <td class="border px-2 py-1">{{ $ad['ad_id'] }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endisset
</x-layout>
