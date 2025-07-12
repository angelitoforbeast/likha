<x-layout>
  <x-slot name="heading">📊 Orders Tally</x-slot>

  <form method="GET" action="{{ route('orders.tally') }}" class="mb-4">
    <label class="mr-2 text-sm font-medium">Filter by Date:</label>
    <select name="date" onchange="this.form.submit()" class="border rounded px-2 py-1 text-sm">
      <option value="">-- All Dates --</option>
      @foreach ($availableDates as $date)
        <option value="{{ $date }}" {{ $filterDate === $date ? 'selected' : '' }}>{{ $date }}</option>
      @endforeach
    </select>
  </form>

  <div class="mb-4 flex gap-4 text-sm">
    <div class="bg-green-100 text-green-800 px-4 py-2 rounded">Likha Orders Total: {{ number_format($totals['likha_orders']) }}</div>
    <div class="bg-blue-100 text-blue-800 px-4 py-2 rounded">Macro Output Total: {{ number_format($totals['macro_output']) }}</div>
  </div>

  @if ($filterDate)
    <div class="w-full text-sm mb-2 overflow-x-auto">
        <table class="table-auto border w-full text-center font-semibold">
            <thead class="bg-gray-100">
                <tr>
                    <th class="border px-2 py-1 text-left">Total for {{ $filterDate }}</th>
                    <th class="border px-2 py-1">Likha Orders</th>
                    <th class="border px-2 py-1">Macro Output</th>
                    <th class="border px-2 py-1">Difference</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="border px-2 py-1 text-left">Grand Total</td>
                    <td class="border px-2 py-1">{{ number_format($dailyTotal['likha_orders']) }}</td>
                    <td class="border px-2 py-1">{{ number_format($dailyTotal['macro_output']) }}</td>
                    <td class="border px-2 py-1 font-bold text-red-600">{{ number_format($dailyTotal['difference']) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
  @endif

  <div class="overflow-x-auto">
    <table class="table-auto w-full text-sm border">
      <thead class="bg-gray-100">
        <tr>
          <th class="border px-2 py-1">Date</th>
          <th class="border px-2 py-1">Page</th>
          <th class="border px-2 py-1">Likha Orders</th>
          <th class="border px-2 py-1">Macro Output</th>
          <th class="border px-2 py-1">Difference</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($combined as $row)
          <tr class="{{ $row['difference'] !== 0 ? 'bg-red-50 text-red-600' : '' }}">
            <td class="border px-2 py-1">
              <a href="{{ url('/orders/tally/' . $row['date']) }}" class="underline">{{ $row['date'] }}</a>
            </td>
            <td class="border px-2 py-1">{{ $row['page_name'] }}</td>
            <td class="border px-2 py-1 text-center">{{ $row['likha_count'] }}</td>
            <td class="border px-2 py-1 text-center">{{ $row['macro_count'] }}</td>
            <td class="border px-2 py-1 text-center font-bold">{{ $row['difference'] }}</td>
          </tr>
        @empty
          <tr><td colspan="5" class="text-center py-4">No data found.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</x-layout>
