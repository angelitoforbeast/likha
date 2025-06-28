<x-layout>
  <x-slot name="heading">Ads Manager Summary</x-slot>

  <!-- Filters -->
  <form method="GET" id="filterForm" class="mb-4 flex flex-wrap items-center gap-4">
    <input type="date"
           name="start_date"
           value="{{ request('start_date') }}"
           onchange="document.getElementById('filterForm').submit()"
           class="border px-2 py-1 rounded">

    <span>to</span>

    <input type="date"
           name="end_date"
           value="{{ request('end_date') }}"
           onchange="document.getElementById('filterForm').submit()"
           class="border px-2 py-1 rounded">

    <input type="text"
           name="search"
           value="{{ request('search') }}"
           placeholder="Search campaign..."
           class="border px-2 py-1 rounded w-64"
           oninput="debouncedSubmit()">
  </form>

  <!-- Table -->
  <div class="overflow-y-auto max-h-[600px] border border-gray-300 rounded shadow-sm">
    <table class="min-w-full text-sm border-collapse border border-gray-300">
      <thead class="bg-gray-100 sticky top-0 z-10">
        <tr>
          <th class="border border-gray-300 px-3 py-2 text-left">Campaign</th>
          <th class="border border-gray-300 px-3 py-2 text-right">Amount Spent</th>
          <th class="border border-gray-300 px-3 py-2 text-center">Orders</th>
          <th class="border border-gray-300 px-3 py-2 text-right">CPP</th>
          <th class="border border-gray-300 px-3 py-2 text-right">CPM</th>
          <th class="border border-gray-300 px-3 py-2 text-right">CPI</th>
        </tr>
      </thead>
      <tbody class="bg-white">
        @forelse ($matrix as $row)
          <tr class="hover:bg-gray-50">
            <td class="border border-gray-200 px-3 py-2">{{ $row['page'] }}</td>
            <td class="border border-gray-200 px-3 py-2 text-right">₱{{ number_format($row['spent'], 2) }}</td>
            <td class="border border-gray-200 px-3 py-2 text-center">{{ $row['orders'] }}</td>
            <td class="border border-gray-200 px-3 py-2 text-right">
              {{ $row['cpp'] !== null ? '₱' . number_format($row['cpp'], 2) : '—' }}
            </td>
            <td class="border border-gray-200 px-3 py-2 text-right">
              {{ $row['cpm'] !== null ? '₱' . number_format($row['cpm'], 2) : '—' }}
            </td>
            <td class="border border-gray-200 px-3 py-2 text-right">
              {{ $row['cpi'] !== null ? '₱' . number_format($row['cpi'], 2) : '—' }}
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="6" class="text-center py-4 text-gray-500">No results found.</td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <!-- Debounce Search -->
  <script>
    let debounceTimer;
    function debouncedSubmit() {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(() => {
        document.getElementById('filterForm').submit();
      }, 600);
    }
  </script>
</x-layout>
