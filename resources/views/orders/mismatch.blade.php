<x-layout>
  <x-slot name="heading">
    📅 Tally Details for 
    <span class="text-blue-600 font-semibold">
      {{ \Carbon\Carbon::parse($date)->format('F j, Y') }}
    </span>
  </x-slot>

  {{-- ✅ Date Filter Dropdown that redirects to /orders/tally/{date} --}}
  <form method="GET" action="" class="mb-4" onsubmit="return redirectToDate(event)">
    <label for="date" class="text-sm font-medium mr-2">Filter by Date:</label>
    <select name="date" id="date" class="border rounded px-2 py-1 text-sm">
      <option value="">-- All Dates --</option>
      @foreach ($availableDates as $d)
        <option value="{{ $d }}" {{ $d == $date ? 'selected' : '' }}>
          {{ \Carbon\Carbon::parse($d)->format('F j, Y') }}
        </option>
      @endforeach
    </select>
    <button type="submit" class="ml-2 bg-blue-500 text-white px-3 py-1 rounded text-sm">Go</button>
  </form>

  {{-- ✅ Summary --}}
  <div class="mb-4 text-sm">
    <div>Total Likha Orders: {{ $summary['total'] }}</div>
    <div class="text-green-600">✅ Matched: {{ $summary['matched'] }}</div>
    <div class="text-red-600">❌ Missing: {{ $summary['missing'] }}</div>
    <div class="text-yellow-600">❗ Duplicated: {{ $summary['duplicated'] }}</div>
  </div>

  {{-- ✅ Results Table --}}
  <div class="overflow-x-auto">
    <table class="table-auto w-full text-sm border">
      <thead class="bg-gray-100">
        <tr>
          <th class="border px-2 py-1">Date</th>
          <th class="border px-2 py-1">Page Name</th>
          <th class="border px-2 py-1">Likha Name</th>
          <th class="border px-2 py-1">Matched Macro Name(s)</th>
          <th class="border px-2 py-1">Status</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($results as $row)
          <tr class="{{ Str::contains($row['status'], '❌') ? 'bg-red-50' : (Str::contains($row['status'], '❗') ? 'bg-yellow-50' : 'bg-green-50') }}">
            <td class="border px-2 py-1">{{ $row['date'] }}</td>
            <td class="border px-2 py-1">{{ $row['page_name'] }}</td>
            <td class="border px-2 py-1">{{ $row['likha_name'] }}</td>
            <td class="border px-2 py-1">{{ $row['matched_names'] }}</td>
            <td class="border px-2 py-1 font-bold">{{ $row['status'] }}</td>
          </tr>
        @empty
          <tr>
            <td colspan="5" class="text-center py-4">No records found.</td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>

  {{-- ✅ JavaScript Redirect on Select --}}
  <script>
    function redirectToDate(e) {
      e.preventDefault();
      const selectedDate = document.getElementById('date').value;
      if (selectedDate) {
        window.location.href = `/orders/tally/${selectedDate}`;
      }
    }
  </script>
</x-layout>
