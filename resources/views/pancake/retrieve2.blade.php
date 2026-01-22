{{-- resources/views/pancake/retrieve2.blade.php --}}
<x-layout>
  <x-slot name="title">Pancake Retrieve 2</x-slot>

  <x-slot name="heading">
    Pancake Retrieve 2 (Missing in Macro Output)
  </x-slot>

  @php
    if (!function_exists('btnClass')) {
      function btnClass($active) {
        return $active
          ? 'inline-flex items-center px-3 py-2 rounded-md bg-blue-600 text-white text-sm font-medium'
          : 'inline-flex items-center px-3 py-2 rounded-md bg-gray-100 hover:bg-gray-200 text-gray-800 text-sm font-medium';
      }
    }

    $presetUi = $preset ?? '';
    if ($presetUi === 'month') $presetUi = 'this_month';
  @endphp

  <div class="max-w-7xl mx-auto mt-6 bg-white shadow-md rounded-lg p-6 space-y-4">

    <div class="text-sm text-gray-600">
      Shows rows from <b>pancake_conversations</b> that <b>do NOT</b> exist in <b>macro_output</b>,
      comparing <b>(pancake_page_name + full_name)</b> vs <b>(PAGE + fb_name)</b>.
      Matching is <b>trimmed</b> + <b>case-insensitive</b>.
      <br>
      Date filter is based on <b>pancake_conversations.created_at</b> converted to <b>{{ $tz }}</b> day.
      <br>
      <b>SHOP DETAILS</b> shows the <b>most common</b> value from <b>macro_output → SHOP DETAILS</b> for the same
      <b>ts_date</b> and <b>PAGE</b>.
    </div>

    {{-- Preset buttons --}}
    <div class="flex flex-wrap gap-2 items-center">
      <a href="{{ url('/pancake/retrieve2') }}?preset=last7"
         class="{{ btnClass($presetUi === 'last7') }}">Last 7 Days</a>

      <a href="{{ url('/pancake/retrieve2') }}?preset=yesterday"
         class="{{ btnClass($presetUi === 'yesterday' || $presetUi === '') }}">Yesterday</a>

      <a href="{{ url('/pancake/retrieve2') }}?preset=today"
         class="{{ btnClass($presetUi === 'today') }}">Today</a>

      <a href="{{ url('/pancake/retrieve2') }}?preset=this_month"
         class="{{ btnClass($presetUi === 'this_month') }}">This Month</a>
    </div>

    {{-- Manual filters --}}
    <form method="GET" action="{{ url('/pancake/retrieve2') }}" class="bg-gray-50 border rounded-lg p-4">
      <div class="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">

        <div class="md:col-span-1">
          <label class="block text-xs text-gray-600 mb-1">Date From</label>
          <input type="date" name="date_from" value="{{ request('date_from', $date_from) }}"
                 class="w-full border border-gray-300 rounded-md px-2 py-2 text-sm">
        </div>

        <div class="md:col-span-1">
          <label class="block text-xs text-gray-600 mb-1">Date To</label>
          <input type="date" name="date_to" value="{{ request('date_to', $date_to) }}"
                 class="w-full border border-gray-300 rounded-md px-2 py-2 text-sm">
        </div>

        <div class="md:col-span-2">
          <label class="block text-xs text-gray-600 mb-1">Page contains</label>
          <input type="text" name="page_contains" value="{{ request('page_contains', $page_contains ?? '') }}"
                 placeholder="e.g. Vivien"
                 class="w-full border border-gray-300 rounded-md px-2 py-2 text-sm">
        </div>

        <div class="md:col-span-2">
          <label class="block text-xs text-gray-600 mb-1">Name contains</label>
          <input type="text" name="name_contains" value="{{ request('name_contains', $name_contains ?? '') }}"
                 placeholder="e.g. Ronilo"
                 class="w-full border border-gray-300 rounded-md px-2 py-2 text-sm">
        </div>

        <div class="md:col-span-6 flex gap-2">
          <button type="submit"
                  class="inline-flex items-center px-4 py-2 rounded-md bg-blue-600 text-white text-sm font-medium">
            Apply
          </button>

          <a href="{{ url('/pancake/retrieve2') }}"
             class="inline-flex items-center px-4 py-2 rounded-md bg-gray-200 hover:bg-gray-300 text-gray-800 text-sm font-medium">
            Clear
          </a>

          <div class="ml-auto text-sm text-gray-600">
            Range: <b>{{ $date_from }}</b> to <b>{{ $date_to }}</b> |
            Showing: <b>{{ $rows->total() }}</b>
          </div>
        </div>
      </div>
    </form>

    <style>
      /* normal table text but keeps line breaks */
      .cell-wrap {
        white-space: pre-wrap;
        word-break: break-word;
        line-height: 1.35;
      }
    </style>

    {{-- Table --}}
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm border border-gray-200">
        <thead class="bg-gray-100">
          <tr>
            <th class="text-left px-3 py-2 border-b whitespace-nowrap">Date Created</th>
            <th class="text-left px-3 py-2 border-b whitespace-nowrap">Page</th>
            <th class="text-left px-3 py-2 border-b whitespace-nowrap">Full Name</th>
            <th class="text-left px-3 py-2 border-b whitespace-nowrap">SHOP DETAILS</th>
            <th class="text-left px-3 py-2 border-b whitespace-nowrap">customers_chat</th>
          </tr>
        </thead>

        <tbody>
          @forelse ($rows as $r)
            <tr class="hover:bg-gray-50 align-top">
              <td class="px-3 py-2 border-b whitespace-nowrap">
                {{ $r->date_created }}
              </td>

              <td class="px-3 py-2 border-b whitespace-nowrap">
                {{ $r->page }}
              </td>

              <td class="px-3 py-2 border-b whitespace-nowrap">
                {{ $r->full_name }}
              </td>

              <td class="px-3 py-2 border-b">
                @if (!empty($r->shop_details))
                  <div class="cell-wrap">{{ $r->shop_details }}</div>
                @else
                  <span class="text-gray-400">—</span>
                @endif
              </td>

              <td class="px-3 py-2 border-b">
                @if (!empty($r->customers_chat))
                  <div class="cell-wrap">{{ $r->customers_chat }}</div>
                @else
                  <span class="text-gray-400">—</span>
                @endif
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="5" class="px-3 py-6 text-center text-gray-500">
                No results for the selected range/filters.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    {{-- Pagination --}}
    <div>
      {{ $rows->links() }}
    </div>

  </div>
</x-layout>
