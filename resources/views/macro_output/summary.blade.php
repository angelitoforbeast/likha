{{-- resources/views/macro_output/summary.blade.php --}}
<x-layout>
  <x-slot name="heading">
    <div class="text-xl font-bold">📊 Encoder Summary (Grouped by Date and Page)</div>
  </x-slot>

  <form id="filterForm" method="GET" action="{{ route('macro_output.summary') }}" class="mt-4 mb-6 px-4 flex gap-4 flex-wrap items-end">
    <div>
      <label class="text-sm font-medium">Start Date</label>
      <input
        id="start_date"
        type="date"
        name="start_date"
        value="{{ request('start_date') }}"
        class="border rounded px-2 py-1"
      />
    </div>
    <div>
      <label class="text-sm font-medium">End Date</label>
      <input
        id="end_date"
        type="date"
        name="end_date"
        value="{{ request('end_date') }}"
        class="border rounded px-2 py-1"
      />
    </div>
    <div class="flex gap-2">
      <button type="submit" class="bg-gray-700 text-white px-4 py-2 rounded hover:bg-gray-800">
        Filter
      </button>

      {{-- TODAY button --}}
      <button
        type="button"
        id="btnToday"
        class="bg-blue-600 text-white px-3 py-2 rounded hover:bg-blue-700 text-xs"
      >
        Today
      </button>

      {{-- YESTERDAY button --}}
      <button
        type="button"
        id="btnYesterday"
        class="bg-indigo-600 text-white px-3 py-2 rounded hover:bg-indigo-700 text-xs"
      >
        Yesterday
      </button>
    </div>
  </form>

  {{-- Copy button (above table) --}}
  <div class="px-4 mb-2 flex justify-end">
    <button
      type="button"
      id="copyTableBtn"
      class="px-4 py-2 rounded text-sm font-semibold"
      style="background-color:#00ff00; color:#000;"
    >
      Copy Table
    </button>
  </div>

  <div class="overflow-x-auto mt-2 px-4">
    <table class="min-w-full text-sm border" id="summaryTable">
      <thead class="bg-gray-200">
        <tr>
          <th class="border px-3 py-2 text-left">Date</th>
          <th class="border px-3 py-2 text-left">Page</th>
          <th class="border px-3 py-2 text-center">Total</th>
          <th class="border px-3 py-2 text-center">PROCEED</th>
          <th class="border px-3 py-2 text-center">CANNOT PROCEED</th>
          <th class="border px-3 py-2 text-center">ODZ</th>
          <th class="border px-3 py-2 text-center">BLANK</th>
          <th class="border px-3 py-2 text-center">Downloaded By</th>
          <th class="border px-3 py-2 text-center">Downloaded At</th>
          <th class="border px-3 py-2 text-center">Scanned Waybill</th>
          <th class="border px-3 py-2 text-center">Matched Waybills</th>
          <th class="border px-3 py-2 text-center">No Waybill</th>
        </tr>
      </thead>
      <tbody>
        @if (!empty($totalCounts))
          @php
            // No Waybill (TOTAL) = PROCEED - MATCHED_WAYBILLS; clamp to 0 to avoid negatives
            $totalNoWaybill = max(($totalCounts['PROCEED'] ?? 0) - ($totalCounts['MATCHED_WAYBILLS'] ?? 0), 0);
          @endphp
          <tr class="bg-yellow-100 font-semibold">
            <td class="border px-3 py-2" colspan="2">TOTAL</td>
            <td class="border px-3 py-2 text-center">{{ $totalCounts['TOTAL'] }}</td>
            <td class="border px-3 py-2 text-center">{{ $totalCounts['PROCEED'] }}</td>
            <td class="border px-3 py-2 text-center">{{ $totalCounts['CANNOT PROCEED'] }}</td>
            <td class="border px-3 py-2 text-center">{{ $totalCounts['ODZ'] }}</td>
            <td class="border px-3 py-2 text-center">{{ $totalCounts['BLANK'] }}</td>
            <td colspan="2" class="border px-3 py-2 text-center text-gray-400">N/A</td>
            <td class="border px-3 py-2 text-center">{{ $totalCounts['SCANNED_WAYBILLS'] }}</td>
            <td class="border px-3 py-2 text-center">{{ $totalCounts['MATCHED_WAYBILLS'] }}</td>
            <td class="border px-3 py-2 text-center {{ $totalNoWaybill > 0 ? 'bg-red-600 text-white font-semibold' : '' }}">
              {{ $totalNoWaybill }}
            </td>
          </tr>
        @endif

        @forelse ($summary as $date => $pages)
          @foreach ($pages as $page => $counts)
            @php
              // Row-level No Waybill = PROCEED - MATCHED_WAYBILLS; clamp to 0
              $noWaybill = max(($counts['PROCEED'] ?? 0) - ($counts['MATCHED_WAYBILLS'] ?? 0), 0);
            @endphp
            <tr>
              <td class="border px-3 py-2">{{ $date }}</td>
              <td class="border px-3 py-2">{{ $page }}</td>
              <td class="border px-3 py-2 text-center">{{ $counts['TOTAL'] }}</td>
              <td class="border px-3 py-2 text-center">{{ $counts['PROCEED'] }}</td>
              <td class="border px-3 py-2 text-center">{{ $counts['CANNOT PROCEED'] }}</td>
              <td class="border px-3 py-2 text-center">{{ $counts['ODZ'] }}</td>
              <td class="border px-3 py-2 text-center">{{ $counts['BLANK'] }}</td>
              <td class="border px-3 py-2 text-center">{{ $counts['downloaded_by'] ?? '-' }}</td>
              <td class="border px-3 py-2 text-center">
                {{ $counts['downloaded_at'] ? \Carbon\Carbon::parse($counts['downloaded_at'])->format('Y-m-d H:i') : '-' }}
              </td>
              <td class="border px-3 py-2 text-center">{{ $counts['SCANNED_WAYBILLS'] ?? 0 }}</td>
              <td class="border px-3 py-2 text-center">{{ $counts['MATCHED_WAYBILLS'] ?? 0 }}</td>
              <td class="border px-3 py-2 text-center {{ $noWaybill > 0 ? 'bg-red-600 text-white font-semibold' : '' }}">
                {{ $noWaybill }}
              </td>
            </tr>
          @endforeach
        @empty
          <tr>
            <td colspan="12" class="text-center py-4 text-gray-500">No data available.</td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <script>
    // Helper: format JS Date to YYYY-MM-DD
    function formatDateToYMD(date) {
      const year = date.getFullYear();
      const month = String(date.getMonth() + 1).padStart(2, '0');
      const day = String(date.getDate()).padStart(2, '0');
      return `${year}-${month}-${day}`;
    }

    // TODAY / YESTERDAY buttons
    (function () {
      const form = document.getElementById('filterForm');
      const startInput = document.getElementById('start_date');
      const endInput = document.getElementById('end_date');
      const btnToday = document.getElementById('btnToday');
      const btnYesterday = document.getElementById('btnYesterday');

      if (btnToday) {
        btnToday.addEventListener('click', function () {
          const today = new Date();
          const formatted = formatDateToYMD(today);
          startInput.value = formatted;
          endInput.value = formatted;
          form.submit();
        });
      }

      if (btnYesterday) {
        btnYesterday.addEventListener('click', function () {
          const d = new Date();
          d.setDate(d.getDate() - 1);
          const formatted = formatDateToYMD(d);
          startInput.value = formatted;
          endInput.value = formatted;
          form.submit();
        });
      }
    })();

    // COPY TABLE button
    (function () {
      const copyBtn = document.getElementById('copyTableBtn');
      const table = document.getElementById('summaryTable');

      if (!copyBtn || !table) return;

      copyBtn.addEventListener('click', async function () {
        let text = '';
        const rows = table.querySelectorAll('tr');

        rows.forEach(row => {
          const cols = row.querySelectorAll('th, td');
          const rowData = [];
          cols.forEach(col => {
            // Replace newlines to keep it clean when pasting
            const cellText = col.innerText.replace(/\s+/g, ' ').trim();
            rowData.push(cellText);
          });
          text += rowData.join('\t') + '\n';
        });

        // Try modern clipboard API first
        try {
          await navigator.clipboard.writeText(text);
          alert('Table copied to clipboard.');
        } catch (err) {
          // Fallback using execCommand
          const textarea = document.createElement('textarea');
          textarea.value = text;
          document.body.appendChild(textarea);
          textarea.select();
          try {
            document.execCommand('copy');
            alert('Table copied to clipboard.');
          } catch (err2) {
            alert('Failed to copy table.');
          }
          document.body.removeChild(textarea);
        }
      });
    })();
  </script>
</x-layout>
