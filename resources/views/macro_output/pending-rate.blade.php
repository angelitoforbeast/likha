<x-layout>
  <x-slot name="title">Pending Rate</x-slot>
  <x-slot name="heading">Pending Rate</x-slot>

  <div class="max-w-6xl mx-auto mt-6 bg-white shadow-md rounded-lg p-6 space-y-4">

    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
      <div class="text-sm text-gray-600">
        Showing <b>7 days</b> by default (including today).<br>
        Range: <b>{{ $start_date }}</b> to <b>{{ $end_date }}</b>
      </div>

      {{-- Optional quick filter (works if you pass ?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD) --}}
      <form method="GET" action="{{ url('/encoder/pending-rate') }}" class="flex flex-wrap items-end gap-2">
        <div>
          <label class="block text-xs text-gray-600 mb-1">Start Date</label>
          <input type="date" name="start_date" value="{{ request('start_date', $start_date) }}"
                 class="border border-gray-300 rounded px-2 py-1 text-sm">
        </div>
        <div>
          <label class="block text-xs text-gray-600 mb-1">End Date</label>
          <input type="date" name="end_date" value="{{ request('end_date', $end_date) }}"
                 class="border border-gray-300 rounded px-2 py-1 text-sm">
        </div>
        <button class="bg-gray-900 text-white px-3 py-2 rounded text-sm">
          Apply
        </button>
      </form>
    </div>

    <div class="overflow-auto border rounded-lg">
      <table class="min-w-full text-sm">
        <thead class="bg-gray-50 text-gray-700">
          <tr>
            <th class="text-left px-3 py-2 border-b">Date</th>
            <th class="text-right px-3 py-2 border-b">Proceed</th>
            <th class="text-right px-3 py-2 border-b">Cannot Proceed</th>
            <th class="text-right px-3 py-2 border-b">ODZ</th>
            <th class="text-right px-3 py-2 border-b">Blank</th>
            <th class="text-right px-3 py-2 border-b">Total</th>
            <th class="text-right px-3 py-2 border-b">TCPR (Cannot Proceed)</th>
            <th class="text-right px-3 py-2 border-b">TCPR 2 (Cannot Proceed + ODZ)</th>
          </tr>
        </thead>

        <tbody>
          @php
            $sumProceed = 0;
            $sumCannot = 0;
            $sumOdz = 0;
            $sumBlank = 0;
            $sumTotal = 0;
          @endphp

          @forelse ($data as $row)
            @php
              $sumProceed += $row->proceed;
              $sumCannot  += $row->cannot_proceed;
              $sumOdz     += $row->odz;
              $sumBlank   += $row->blank;
              $sumTotal   += $row->total;

              // Conditional formatting:
              // > 3% red, > 2% yellow
              $pr = $row->pending_rate;
              $tpr = $row->total_pending_rate;

              $prClass = $pr > 3 ? 'bg-red-100 text-red-900 font-semibold'
                        : ($pr > 2 ? 'bg-yellow-100 text-yellow-900 font-semibold' : '');

              $tprClass = $tpr > 3 ? 'bg-red-100 text-red-900 font-semibold'
                         : ($tpr > 2 ? 'bg-yellow-100 text-yellow-900 font-semibold' : '');
            @endphp

            <tr class="hover:bg-gray-50">
              <td class="px-3 py-2 border-b whitespace-nowrap">
                {{ \Carbon\Carbon::parse($row->ts_date)->format('M j Y') }}
              </td>

              <td class="px-3 py-2 border-b text-right">{{ number_format($row->proceed) }}</td>
              <td class="px-3 py-2 border-b text-right">{{ number_format($row->cannot_proceed) }}</td>
              <td class="px-3 py-2 border-b text-right">{{ number_format($row->odz) }}</td>
              <td class="px-3 py-2 border-b text-right">{{ number_format($row->blank) }}</td>
              <td class="px-3 py-2 border-b text-right">{{ number_format($row->total) }}</td>

              <td class="px-3 py-2 border-b text-right {{ $prClass }}">
                {{ number_format($pr, 2) }}%
              </td>

              <td class="px-3 py-2 border-b text-right {{ $tprClass }}">
                {{ number_format($tpr, 2) }}%
              </td>
            </tr>

          @empty
            <tr>
              <td colspan="8" class="px-3 py-6 text-center text-gray-500">
                No records found for the selected date range.
              </td>
            </tr>
          @endforelse
        </tbody>

        @php
          $overallPendingRate = $sumTotal > 0 ? ($sumCannot / $sumTotal) * 100 : 0;
          $overallTotalPendingRate = $sumTotal > 0 ? (($sumCannot + $sumOdz) / $sumTotal) * 100 : 0;

          $overallPrClass = $overallPendingRate > 3 ? 'bg-red-100 text-red-900 font-semibold'
                           : ($overallPendingRate > 2 ? 'bg-yellow-100 text-yellow-900 font-semibold' : '');

          $overallTprClass = $overallTotalPendingRate > 3 ? 'bg-red-100 text-red-900 font-semibold'
                            : ($overallTotalPendingRate > 2 ? 'bg-yellow-100 text-yellow-900 font-semibold' : '');
        @endphp

        <tfoot class="bg-gray-50">
          <tr>
            <th class="text-left px-3 py-2 border-t">TOTAL</th>
            <th class="text-right px-3 py-2 border-t">{{ number_format($sumProceed) }}</th>
            <th class="text-right px-3 py-2 border-t">{{ number_format($sumCannot) }}</th>
            <th class="text-right px-3 py-2 border-t">{{ number_format($sumOdz) }}</th>
            <th class="text-right px-3 py-2 border-t">{{ number_format($sumBlank) }}</th>
            <th class="text-right px-3 py-2 border-t">{{ number_format($sumTotal) }}</th>
            <th class="text-right px-3 py-2 border-t {{ $overallPrClass }}">
              {{ number_format($overallPendingRate, 2) }}%
            </th>
            <th class="text-right px-3 py-2 border-t {{ $overallTprClass }}">
              {{ number_format($overallTotalPendingRate, 2) }}%
            </th>
          </tr>
        </tfoot>

      </table>
    </div>

    <div class="text-xs text-gray-500">
      Conditional formatting: <b>&gt; 2%</b> = Yellow, <b>&gt; 3%</b> = Red
    </div>

  </div>
</x-layout>
