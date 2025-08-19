<x-layout>
  <x-slot name="heading">Pancake Subscription Checker</x-slot>

  {{-- Single Date Filter --}}
  <form method="GET" class="mt-4 mb-6 flex items-end gap-4">
    <div>
      <label for="date" class="block font-semibold mb-1">Select Date:</label>
      <input type="date" id="date" name="date" value="{{ $date }}" class="border px-2 py-1 rounded"
             onchange="this.form.submit()">
    </div>
  </form>

  {{-- Table --}}
  <div class="overflow-auto">
    <table class="min-w-full border text-sm">
      <thead class="bg-gray-200">
        <tr>
          <th class="border px-2 py-1 text-left">Page</th>
          <th class="border px-2 py-1 text-right">Purchase (Ads)</th>
          <th class="border px-2 py-1 text-right">Order (Macro)</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($rows as $r)
          @php
            $purchases = $r['purchases'];
            $orders = $r['orders'];
            $diff = $purchases > 0 ? abs($purchases - $orders) / $purchases * 100 : 0;

            $color = '';
            if ($diff >= 50) {
                $color = 'bg-red-200';
            } elseif ($diff >= 20) {
                $color = 'bg-orange-200';
            } elseif ($diff >= 10) {
                $color = 'bg-yellow-200';
            }
          @endphp
          <tr class="{{ $color }}">
            <td class="border px-2 py-1">{{ $r['page'] }}</td>
            <td class="border px-2 py-1 text-right">{{ number_format($purchases) }}</td>
            <td class="border px-2 py-1 text-right">{{ number_format($orders) }}</td>
          </tr>
        @empty
          <tr>
            <td colspan="3" class="border px-2 py-3 text-center text-gray-500">No data for {{ $date }}.</td>
          </tr>
        @endforelse
      </tbody>
      <tfoot class="bg-gray-100 font-semibold">
        <tr>
          <td class="border px-2 py-1 text-right">TOTAL</td>
          <td class="border px-2 py-1 text-right">{{ number_format($totals['purchases'] ?? 0) }}</td>
          <td class="border px-2 py-1 text-right">{{ number_format($totals['orders'] ?? 0) }}</td>
        </tr>
      </tfoot>
    </table>
  </div>
</x-layout>
