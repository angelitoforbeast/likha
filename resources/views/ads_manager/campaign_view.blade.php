<x-layout>
  <x-slot name="heading">
    <h1 class="text-xl font-semibold text-gray-800">📊 Saved Offline Ads</h1>
  </x-slot>

  @if(session('success'))
    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-2 rounded mb-4 shadow-sm">
      {!! session('success') !!}
    </div>
  @endif

  <div class="w-full max-h-[600px] overflow-y-auto border rounded shadow-sm">
  <table class="w-full text-sm text-left text-gray-800">
    <thead class="bg-gray-100 sticky top-0 z-10 shadow-sm">
      <tr>
        <th class="px-3 py-2 border-b whitespace-nowrap">📅 Start</th>
        <th class="px-3 py-2 border-b whitespace-nowrap">📣 Campaign ID</th>
        <th class="px-3 py-2 border-b whitespace-nowrap">🎯 Ad ID</th>
        <th class="px-3 py-2 border-b">📛 Campaign Name</th>
        <th class="px-3 py-2 border-b">🧩 Ad Set Name</th>
        <th class="px-3 py-2 border-b text-right whitespace-nowrap">💰 Spent</th>
        <th class="px-3 py-2 border-b text-right whitespace-nowrap">👁️ Impr.</th>
        <th class="px-3 py-2 border-b text-right whitespace-nowrap">💬 Msgs</th>
        <th class="px-3 py-2 border-b text-right whitespace-nowrap">📊 Budget</th>
        <th class="px-3 py-2 border-b whitespace-nowrap">🚚 Delivery</th>
        <th class="px-3 py-2 border-b text-right whitespace-nowrap">📡 Reach</th>
        <th class="px-3 py-2 border-b text-right whitespace-nowrap">🎣 Hook</th>
        <th class="px-3 py-2 border-b text-right whitespace-nowrap">🕒 Hold</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-gray-200">
      @forelse($ads as $ad)
        <tr class="hover:bg-gray-50">
          <td class="px-3 py-2">{{ $ad->reporting_starts }}</td>
          <td class="px-3 py-2 break-all">{{ $ad->campaign_id }}</td>
          <td class="px-3 py-2 break-all">{{ $ad->ad_id }}</td>
          <td class="px-3 py-2 whitespace-normal break-words">{{ $ad->campaign_name }}</td>
          <td class="px-3 py-2 whitespace-normal break-words">{{ $ad->adset_name }}</td>
          <td class="px-3 py-2 text-right">₱{{ number_format($ad->amount_spent, 2) }}</td>
          <td class="px-3 py-2 text-right">{{ number_format($ad->impressions) }}</td>
          <td class="px-3 py-2 text-right">{{ number_format($ad->messages) }}</td>
          <td class="px-3 py-2 text-right">₱{{ number_format($ad->budget, 2) }}</td>
          <td class="px-3 py-2">{{ $ad->ad_delivery }}</td>
          <td class="px-3 py-2 text-right">{{ number_format($ad->reach) }}</td>
          <td class="px-3 py-2 text-right">{{ $ad->hook_rate }}</td>
          <td class="px-3 py-2 text-right">{{ $ad->hold_rate }}</td>
        </tr>
      @empty
        <tr>
          <td colspan="13" class="px-4 py-4 text-center text-gray-500">
            No records found.
          </td>
        </tr>
      @endforelse
    </tbody>
  </table>
</div>

  <div class="mt-4 flex justify-end">
    <form
      method="POST"
      action="{{ route('offline_ads.delete_all') }}"
      onsubmit="return confirm('Are you sure you want to delete ALL records?');"
    >
      @csrf
      @method('DELETE')
      <button
        type="submit"
        class="text-sm bg-red-500 hover:bg-red-600 text-white px-3 py-1.5 rounded shadow-sm"
      >
        🗑️ Delete All
      </button>
    </form>
  </div>

  <div class="mt-6 flex justify-center">
    {{ $ads->links() }}
  </div>
</x-layout>