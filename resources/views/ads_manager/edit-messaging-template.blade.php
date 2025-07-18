<x-layout>
  <x-slot name="heading">💬 Edit Messaging Template (Per Campaign)</x-slot>

  @if(session('success'))
    <div class="bg-green-100 text-green-800 px-4 py-2 rounded mb-4">
      {{ session('success') }}
    </div>
  @endif

  <table class="table-auto w-full border text-sm">
 @php
    function sortLink($column, $label, $currentSort, $currentDirection) {
        $newDirection = ($currentSort === $column && $currentDirection === 'asc') ? 'desc' : 'asc';
        $arrow = '';

        if ($currentSort === $column) {
            $arrow = $currentDirection === 'asc' ? ' ↑' : ' ↓';
        }

        $url = request()->fullUrlWithQuery(['sort_by' => $column, 'sort_direction' => $newDirection]);

        return "<a href='" . htmlspecialchars($url) . "' class='hover:underline font-semibold'>" . e($label) . "$arrow</a>";
    }
@endphp

<thead class="bg-gray-100 text-xs">
    <tr>
        <th class="border px-2 py-1">{!! sortLink('page_name', 'Page Name', $sortBy, $sortDirection) !!}</th>
        <th class="border px-2 py-1">{!! sortLink('campaign_name', 'Campaign Name', $sortBy, $sortDirection) !!}</th>
        <th class="border px-2 py-1">{!! sortLink('campaign_id', 'Campaign ID', $sortBy, $sortDirection) !!}</th>
        <th class="border px-2 py-1">{!! sortLink('body_ad_settings', 'Body', $sortBy, $sortDirection) !!}</th>
        <th class="border px-2 py-1">{!! sortLink('headline', 'Headline', $sortBy, $sortDirection) !!}</th>
        <th class="border px-2 py-1">{!! sortLink('welcome_message', 'Welcome Message', $sortBy, $sortDirection) !!}</th>
        <th class="border px-2 py-1">{!! sortLink('quick_reply_1', 'Quick Reply 1', $sortBy, $sortDirection) !!}</th>
        <th class="border px-2 py-1">{!! sortLink('quick_reply_2', 'Quick Reply 2', $sortBy, $sortDirection) !!}</th>
        <th class="border px-2 py-1">{!! sortLink('quick_reply_3', 'Quick Reply 3', $sortBy, $sortDirection) !!}</th>
        <th class="border px-2 py-1">{!! sortLink('ad_set_delivery', 'Ad Set Delivery', $sortBy, $sortDirection) !!}</th>
        <th class="border px-2 py-1">Action</th>
    </tr>
</thead>

    <tbody>
  @foreach($creatives as $creative)
  <tr>
    <form method="POST" action="{{ route('ads_manager_creatives.update', $creative->id) }}">
      @csrf
      @method('PUT')

      <td class="border px-2 py-1">{{ $creative->page_name }}</td>
      <td class="border px-2 py-1">{{ $creative->campaign_name }}</td>
      <td class="border px-2 py-1">{{ $creative->campaign_id }}</td>
      <td class="border px-2 py-1">{{ $creative->body_ad_settings }}</td>
      <td class="border px-2 py-1">{{ $creative->headline }}</td>

      <td class="border px-2 py-1">
        <textarea name="welcome_message" class="w-full border rounded p-1">{{ $creative->welcome_message }}</textarea>
      </td>
      <td class="border px-2 py-1">
        <input type="text" name="quick_reply_1" class="w-full border rounded p-1" value="{{ $creative->quick_reply_1 }}">
      </td>
      <td class="border px-2 py-1">
        <input type="text" name="quick_reply_2" class="w-full border rounded p-1" value="{{ $creative->quick_reply_2 }}">
      </td>
      <td class="border px-2 py-1">
        <input type="text" name="quick_reply_3" class="w-full border rounded p-1" value="{{ $creative->quick_reply_3 }}">
      </td>
      <td class="border px-2 py-1">{{ $creative->ad_set_delivery }}</td>

      <td class="border px-2 py-1 text-center">
        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-sm">Save</button>
      </td>
    </form>
  </tr>
  @endforeach
</tbody>

  </table>
</x-layout>
