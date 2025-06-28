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
    <div class="mt-6">
      <table class="w-full border text-sm">
        <thead class="bg-gray-200">
          <tr>
            <th class="border px-2 py-1">Campaign</th>
            <th class="border px-2 py-1">Spend</th>
            <th class="border px-2 py-1">Cost per Message</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($ads as $ad)
            <tr>
              <td class="border px-2 py-1">{{ $ad['campaign_name'] }}</td>
              <td class="border px-2 py-1">₱{{ $ad['spend'] }}</td>
              <td class="border px-2 py-1">₱{{ $ad['cost_per_message'] ?? 'N/A' }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endisset
</x-layout>
