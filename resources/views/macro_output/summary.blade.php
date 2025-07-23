<x-layout>
  <x-slot name="heading">
    <div class="text-xl font-bold">📊 Encoder Summary (Grouped by Date and Page)</div>
  </x-slot>
<form method="GET" action="{{ route('macro_output.summary') }}" class="mt-4 mb-6 px-4 flex gap-4 flex-wrap items-end">
  <div>
    <label class="text-sm font-medium">Start Date</label>
    <input type="date" name="start_date" value="{{ request('start_date') }}" class="border rounded px-2 py-1" />
  </div>
  <div>
    <label class="text-sm font-medium">End Date</label>
    <input type="date" name="end_date" value="{{ request('end_date') }}" class="border rounded px-2 py-1" />
  </div>
  <div>
    <button type="submit" class="bg-gray-700 text-white px-4 py-2 rounded hover:bg-gray-800">
      Filter
    </button>
  </div>
</form>

  <div class="overflow-x-auto mt-6 px-4">
    <table class="min-w-full text-sm border">
      <thead class="bg-gray-200">
        <tr>
          <th class="border px-3 py-2 text-left">Date</th>
          <th class="border px-3 py-2 text-left">Page</th>
          <th class="border px-3 py-2 text-center">Total</th>
          <th class="border px-3 py-2 text-center">PROCEED</th>
          <th class="border px-3 py-2 text-center">CANNOT PROCEED</th>
          <th class="border px-3 py-2 text-center">ODZ</th>
          <th class="border px-3 py-2 text-center">BLANK</th>
        </tr>
      </thead>
      <tbody>
        @if (!empty($totalCounts))
  <tr class="bg-yellow-100 font-semibold">
    <td class="border px-3 py-2" colspan="2">TOTAL</td>
    <td class="border px-3 py-2 text-center">{{ $totalCounts['TOTAL'] }}</td>
    <td class="border px-3 py-2 text-center">{{ $totalCounts['PROCEED'] }}</td>
    <td class="border px-3 py-2 text-center">{{ $totalCounts['CANNOT PROCEED'] }}</td>
    <td class="border px-3 py-2 text-center">{{ $totalCounts['ODZ'] }}</td>
    <td class="border px-3 py-2 text-center">{{ $totalCounts['BLANK'] }}</td>
  </tr>
@endif

        @forelse ($summary as $date => $pages)
          @foreach ($pages as $page => $counts)
            <tr>
              <td class="border px-3 py-2">{{ $date }}</td>
              <td class="border px-3 py-2">{{ $page }}</td>
              <td class="border px-3 py-2 text-center">{{ $counts['TOTAL'] }}</td>
              <td class="border px-3 py-2 text-center">{{ $counts['PROCEED'] }}</td>
              <td class="border px-3 py-2 text-center">{{ $counts['CANNOT PROCEED'] }}</td>
              <td class="border px-3 py-2 text-center">{{ $counts['ODZ'] }}</td>
              <td class="border px-3 py-2 text-center">{{ $counts['BLANK'] }}</td>
            </tr>
          @endforeach
        @empty
          <tr>
            <td colspan="7" class="text-center py-4 text-gray-500">No data available.</td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
</x-layout>
