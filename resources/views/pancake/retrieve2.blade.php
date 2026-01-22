<x-layout>
  <x-slot name="title">Pancake Retrieve 2</x-slot>
  <x-slot name="heading">Pancake Retrieve 2 — Missing in Macro Output</x-slot>

  @php
    // helper for active preset button
    function btnClass($isActive) {
      return $isActive
        ? 'px-3 py-2 rounded-md bg-blue-600 text-white text-sm'
        : 'px-3 py-2 rounded-md bg-gray-100 text-gray-800 text-sm hover:bg-gray-200';
    }
  @endphp

  <div class="max-w-7xl mx-auto mt-6 bg-white shadow-md rounded-lg p-6 space-y-4">

    <div class="text-sm text-gray-600">
      Shows rows from <b>pancake_conversations</b> that <b>do NOT</b> exist in <b>macro_output</b>,
      comparing <b>(pancake_page_name + full_name)</b> vs <b>(PAGE + fb_name)</b>.
      Matching is <b>trimmed</b> + <b>case-insensitive</b>.
      <br>
      Date filter is based on <b>pancake_conversations.created_at</b>. Timezone: <b>{{ $tz }}</b>.
    </div>

    {{-- Preset buttons --}}
    <div class="flex flex-wrap gap-2 items-center">
      <a href="{{ url('/pancake/retrieve2') }}?preset=last7"
         class="{{ btnClass($preset === 'last7') }}">Last 7 Days</a>

      <a href="{{ url('/pancake/retrieve2') }}?preset=yesterday"
         class="{{ btnClass($preset === 'yesterday') }}">Yesterday</a>

      <a href="{{ url('/pancake/retrieve2') }}?preset=today"
         class="{{ btnClass($preset === 'today') }}">Today</a>

      <a href="{{ url('/pancake/retrieve2') }}?preset=month"
         class="{{ btnClass($preset === 'month') }}">This Month</a>

      <a href="{{ url('/pancake/retrieve2') }}"
         class="px-3 py-2 rounded-md bg-white border border-gray-300 text-gray-800 text-sm hover:bg-gray-50">
        Reset
      </a>
    </div>

    {{-- Manual filters --}}
    <form method="GET" action="{{ url('/pancake/retrieve2') }}" class="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
      {{-- keep preset when doing manual filters (optional: you can remove this if you want manual to override preset) --}}
      <input type="hidden" name="preset" value="{{ $preset }}">

      <div class="md:col-span-2">
        <label class="block text-xs text-gray-600 mb-1">Page contains</label>
        <input type="text" name="page" value="{{ $pageSearch }}"
               class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
               placeholder="e.g. Chelsea Mercado">
      </div>

      <div class="md:col-span-2">
        <label class="block text-xs text-gray-600 mb-1">Full name contains</label>
        <input type="text" name="name" value="{{ $nameSearch }}"
               class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm"
               placeholder="e.g. Juan Dela Cruz">
      </div>

      <div>
        <label class="block text-xs text-gray-600 mb-1">Date from</label>
        <input type="date" name="date_from" value="{{ $dateFrom }}"
               class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
      </div>

      <div>
        <label class="block text-xs text-gray-600 mb-1">Date to</label>
        <input type="date" name="date_to" value="{{ $dateTo }}"
               class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm">
      </div>

      <div class="md:col-span-6 flex gap-2">
        <button type="submit"
                class="px-4 py-2 rounded-md bg-blue-600 text-white text-sm hover:bg-blue-700">
          Apply Filters
        </button>

        <a href="{{ url('/pancake/retrieve2') }}?preset={{ $preset !== '' ? $preset : 'yesterday' }}"
           class="px-4 py-2 rounded-md bg-gray-100 text-gray-800 text-sm hover:bg-gray-200">
          Clear Text Filters
        </a>
      </div>
    </form>

    {{-- Summary --}}
    <div class="text-sm text-gray-700">
      Range: <b>{{ $dateFrom }}</b> to <b>{{ $dateTo }}</b> |
      Total results: <b>{{ $rows->total() }}</b>
    </div>

    {{-- Table --}}
    <div class="overflow-auto border border-gray-200 rounded-lg">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50">
          <tr class="text-left">
            <th class="px-3 py-2 border-b whitespace-nowrap">Date Created</th>
            <th class="px-3 py-2 border-b whitespace-nowrap">Page</th>
            <th class="px-3 py-2 border-b whitespace-nowrap">Full Name</th>
            <th class="px-3 py-2 border-b">customers_chat</th>
          </tr>
        </thead>
        <tbody>
          @forelse ($rows as $r)
            <tr class="hover:bg-gray-50 align-top">
              <td class="px-3 py-2 border-b whitespace-nowrap">
                {{ $r->date_created }}
              </td>

              <td class="px-3 py-2 border-b whitespace-nowrap">
                {{ $r->page ?? '— (no mapping in pancake_id)' }}
              </td>

              <td class="px-3 py-2 border-b whitespace-nowrap">
                {{ $r->full_name }}
              </td>

              <td class="px-3 py-2 border-b">
                <div class="max-w-4xl whitespace-pre-wrap break-words">{{ $r->customers_chat }}</div>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="4" class="px-3 py-6 text-center text-gray-500">
                No results for the selected filters.
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
