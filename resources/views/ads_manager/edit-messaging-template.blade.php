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
          $arrow = $currentSort === $column ? ($currentDirection === 'asc' ? ' ↑' : ' ↓') : '';
          $url = request()->fullUrlWithQuery(['sort_by' => $column, 'sort_direction' => $newDirection]);
          return "<a href='" . htmlspecialchars($url) . "' class='hover:underline font-semibold'>" . e($label) . "$arrow</a>";
      }
    @endphp

    <thead class="bg-gray-100 text-xs">
      <tr>
        <th class="border px-2 py-1 min-w-[140px]">{!! sortLink('page_name', 'Page Name', $sortBy, $sortDirection) !!}</th>
        <th class="border px-2 py-1 min-w-[220px]">{!! sortLink('campaign_id', 'Campaign ID', $sortBy, $sortDirection) !!}</th>
        <th class="border px-2 py-1 min-w-[240px]">{!! sortLink('body_ad_settings', 'Body', $sortBy, $sortDirection) !!}</th>
        <th class="border px-2 py-1 min-w-[180px]">{!! sortLink('headline', 'Headline', $sortBy, $sortDirection) !!}</th>
        <th class="border px-2 py-1 min-w-[180px]">{!! sortLink('welcome_message', 'Welcome Message', $sortBy, $sortDirection) !!}</th>
        <th class="border px-2 py-1 min-w-[130px]">{!! sortLink('quick_reply_1', 'Quick Reply 1', $sortBy, $sortDirection) !!}</th>
        <th class="border px-2 py-1 min-w-[130px]">{!! sortLink('quick_reply_2', 'Quick Reply 2', $sortBy, $sortDirection) !!}</th>
        <th class="border px-2 py-1 min-w-[130px]">{!! sortLink('quick_reply_3', 'Quick Reply 3', $sortBy, $sortDirection) !!}</th>
        <th class="border px-2 py-1 min-w-[110px]">{!! sortLink('ad_set_delivery', 'Ad Set Delivery', $sortBy, $sortDirection) !!}</th>
        <th class="border px-2 py-1 min-w-[80px]">Action</th>
      </tr>
    </thead>

    <tbody>
      @foreach($creatives as $creative)
        <tr>
          <form method="POST" action="{{ route('ads_manager_creatives.update', $creative->id) }}">
            @csrf
            @method('PUT')

            <td class="border px-2 py-1 align-top">{{ $creative->page_name }}</td>
            <td class="border px-2 py-1 align-top">{{ $creative->campaign_name }}</td>
            <td class="border px-2 py-1 align-top whitespace-pre-wrap">{{ $creative->body_ad_settings }}</td>
            <td class="border px-2 py-1 align-top">
              <textarea name="headline" rows="1" class="auto-resize w-full border rounded p-1 text-sm leading-snug overflow-hidden resize-none">{{ $creative->headline }}</textarea>
            </td>

            <td class="border px-2 py-1 align-top">
              <textarea name="welcome_message" rows="1" class="auto-resize w-full border rounded p-1 text-sm leading-snug overflow-hidden resize-none">{{ $creative->welcome_message }}</textarea>
            </td>
            <td class="border px-2 py-1 align-top">
  <textarea name="quick_reply_1" rows="1" class="auto-resize w-full border rounded p-1 text-sm leading-snug overflow-hidden resize-none">{{ $creative->quick_reply_1 }}</textarea>
</td>
<td class="border px-2 py-1 align-top">
  <textarea name="quick_reply_2" rows="1" class="auto-resize w-full border rounded p-1 text-sm leading-snug overflow-hidden resize-none">{{ $creative->quick_reply_2 }}</textarea>
</td>
<td class="border px-2 py-1 align-top">
  <textarea name="quick_reply_3" rows="1" class="auto-resize w-full border rounded p-1 text-sm leading-snug overflow-hidden resize-none">{{ $creative->quick_reply_3 }}</textarea>
</td>

            <td class="border px-2 py-1 text-center align-top">
              {{ $creative->ad_set_delivery }}
            </td>
            <td class="border px-2 py-1 text-center align-top">
              <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-sm">Save</button>
            </td>
          </form>
        </tr>
      @endforeach
    </tbody>
  </table>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      document.querySelectorAll('.auto-resize').forEach(function (el) {
        function resize() {
          el.style.height = 'auto';
          el.style.height = (el.scrollHeight) + 'px';
        }

        resize();
        el.addEventListener('input', resize);
      });
    });
  </script>
</x-layout>
