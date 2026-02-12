<x-layout>
  <x-slot name="title">Sender Name</x-slot>
  <x-slot name="heading">Waybills Sender Name</x-slot>

  <div class="p-4 space-y-4">

    {{-- Filters --}}
    <form method="GET" action="{{ url('/jnt/waybills/sender-name') }}" class="flex items-end gap-3">
      <div>
        <label class="block text-sm font-medium mb-1">Date (ts_date)</label>
        <input
          type="date"
          name="date"
          value="{{ $selectedDate }}"
          class="border border-gray-300 rounded px-3 py-2"
        />
      </div>

      <button type="submit" class="bg-gray-800 hover:bg-gray-900 text-white px-4 py-2 rounded">
        Filter
      </button>

      <div class="text-sm text-gray-500 pb-2">
        Default: yesterday
      </div>
    </form>

    {{-- Table --}}
    <div class="overflow-x-auto border rounded">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-100">
          <tr>
            <th class="text-left px-4 py-2 border-b">DATE</th>
            <th class="text-left px-4 py-2 border-b">PAGE</th>
            <th class="text-left px-4 py-2 border-b">SENDER NAME (latest)</th>
            <th class="text-left px-4 py-2 border-b">MAPPING CREATED</th>
          </tr>
        </thead>

        <tbody>
          @forelse($rows as $r)
            <tr class="border-b hover:bg-gray-50">
              <td class="px-4 py-2">{{ $displayDate }}</td>

              <td class="px-4 py-2">{{ $r->PAGE }}</td>

              <td class="px-4 py-2">
                {{ $r->SENDER_NAME !== '' ? $r->SENDER_NAME : '—' }}
              </td>

              {{-- ✅ red if DATE - MAPPING_CREATED > 8 days (date-only) --}}
              <td class="px-4 py-2 {{ $r->is_stale ? 'bg-red-100 text-red-700 font-semibold' : '' }}">
                {{ $r->mapping_created_display ?: '—' }}
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="4" class="px-4 py-6 text-center text-gray-500">
                No pages found in macro_output for {{ $displayDate }}.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <div class="text-sm text-gray-500">
      Rows: {{ count($rows) }}
    </div>

  </div>
</x-layout>
