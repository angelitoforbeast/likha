<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Checker 1 – Summary</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <!-- Tailwind (CDN) -->
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- Flatpickr -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <style>
    .flatpickr-calendar{ z-index:9999 !important; }
    body { background: #f3f4f6; }
  </style>
</head>
<body class="text-gray-900">
  <!-- Top bar -->
  <nav class="bg-white border-b sticky top-0 z-40">
    <div class="max-w-7xl mx-auto px-4">
      <div class="h-14 flex items-center justify-between">
        <div class="font-semibold text-lg">Checker 1 – Summary</div>
        <div class="text-sm text-gray-500">Date range: {{ $start }} → {{ $end }}</div>
      </div>
    </div>
  </nav>

  <main class="max-w-7xl mx-auto px-4 py-6 space-y-4">
    <!-- Filters -->
    <section class="bg-white rounded-xl shadow p-4">
      <form method="GET" action="{{ route('encoder.checker1.summary') }}" class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
        <div>
          <label for="start" class="block text-sm font-medium mb-1">Start date</label>
          <input id="start" name="start" type="text"
                 class="w-full border rounded px-3 py-2"
                 value="{{ $start ?? '' }}" placeholder="YYYY-MM-DD" autocomplete="off">
        </div>
        <div>
          <label for="end" class="block text-sm font-medium mb-1">End date</label>
          <input id="end" name="end" type="text"
                 class="w-full border rounded px-3 py-2"
                 value="{{ $end ?? '' }}" placeholder="YYYY-MM-DD" autocomplete="off">
        </div>
        <div class="md:col-span-3 flex gap-2 pt-6">
          <button class="px-3 py-2 rounded bg-blue-600 text-white hover:bg-blue-700">Apply</button>
          <a href="{{ route('encoder.checker1.summary') }}"
             class="px-3 py-2 rounded border hover:bg-gray-50">Reset (Last 7 Days)</a>
        </div>
      </form>
    </section>

    <!-- A) STATUS: Users × Dates (Last Status Editor per Row) -->
    <section class="bg-white rounded-xl shadow p-4 overflow-x-auto">
      <div class="flex items-center justify-between mb-2">
        <div class="font-semibold">Status Last-Editor Matrix</div>
        <div class="text-xs text-gray-500">Counts rows where the <em>latest</em> STATUS change (by timestamp) was made by the user on that date.</div>
      </div>

      @php
        $dateCount = count($dates ?? []);
        $colTotals = array_fill(0, $dateCount, 0);
        $grandTotal = 0;
      @endphp

      <table class="min-w-full text-sm">
        <thead>
          <tr class="bg-gray-50">
            <th class="px-3 py-2 text-left border">User</th>
            @foreach ($prettyDates as $label)
              <th class="px-3 py-2 text-center border whitespace-nowrap">{{ $label }}</th>
            @endforeach
            <th class="px-3 py-2 text-center border">Total</th>
          </tr>
        </thead>
        <tbody>
          @forelse ($matrix as $row)
            @php
              $rowTotal = array_sum($row['counts'] ?? []);
              $grandTotal += $rowTotal;
              foreach (($dates ?? []) as $i => $d) {
                  $colTotals[$i] += ($row['counts'][$d] ?? 0);
              }
            @endphp
            <tr class="hover:bg-gray-50">
              <td class="px-3 py-2 border font-medium whitespace-nowrap">{{ $row['user'] }}</td>
              @foreach ($dates as $d)
                <td class="px-3 py-2 text-center border">{{ $row['counts'][$d] ?? 0 }}</td>
              @endforeach
              <td class="px-3 py-2 text-center border font-semibold">{{ $rowTotal }}</td>
            </tr>
          @empty
            <tr>
              <td class="px-3 py-4 text-center text-gray-500 border" colspan="{{ ($dateCount + 2) }}">
                No data for the selected date range.
              </td>
            </tr>
          @endforelse
        </tbody>

        @if (!empty($matrix))
          <tfoot>
            <tr class="bg-gray-50 font-semibold">
              <td class="px-3 py-2 border text-right">Total</td>
              @foreach ($colTotals as $ct)
                <td class="px-3 py-2 border text-center">{{ $ct }}</td>
              @endforeach
              <td class="px-3 py-2 border text-center">{{ $grandTotal }}</td>
            </tr>
          </tfoot>
        @endif
      </table>
    </section>

    <!-- B) HISTORICAL LOGS: Users × Dates (Distinct Rows Edited) -->
    <section class="bg-white rounded-xl shadow p-4 overflow-x-auto">
      <div class="flex items-center justify-between mb-2">
        <div class="font-semibold">Historical Edits Matrix (Distinct Rows per User × Date)</div>
        <div class="text-xs text-gray-500">
          Each cell counts how many <em>distinct rows</em> had at least one historical edit by the user on that date.
        </div>
      </div>

      @php
        $dateCount2 = count($dates ?? []);
        $colTotals2 = array_fill(0, $dateCount2, 0);
        $grandTotal2 = 0;
      @endphp

      <table class="min-w-full text-sm">
        <thead>
          <tr class="bg-gray-50">
            <th class="px-3 py-2 text-left border">User</th>
            @foreach ($prettyDates as $label)
              <th class="px-3 py-2 text-center border whitespace-nowrap">{{ $label }}</th>
            @endforeach
            <th class="px-3 py-2 text-center border">Total</th>
          </tr>
        </thead>
        <tbody>
          @forelse ($histMatrix as $row)
            @php
              $rowTotal2 = array_sum($row['counts'] ?? []);
              $grandTotal2 += $rowTotal2;
              foreach (($dates ?? []) as $i => $d) {
                  $colTotals2[$i] += ($row['counts'][$d] ?? 0);
              }
            @endphp
            <tr class="hover:bg-gray-50">
              <td class="px-3 py-2 border font-medium whitespace-nowrap">{{ $row['user'] }}</td>
              @foreach ($dates as $d)
                <td class="px-3 py-2 text-center border">{{ $row['counts'][$d] ?? 0 }}</td>
              @endforeach
              <td class="px-3 py-2 text-center border font-semibold">{{ $rowTotal2 }}</td>
            </tr>
          @empty
            <tr>
              <td class="px-3 py-4 text-center text-gray-500 border" colspan="{{ ($dateCount2 + 2) }}">
                No historical edits for the selected date range.
              </td>
            </tr>
          @endforelse
        </tbody>

        @if (!empty($histMatrix))
          <tfoot>
            <tr class="bg-gray-50 font-semibold">
              <td class="px-3 py-2 border text-right">Total</td>
              @foreach ($colTotals2 as $ct)
                <td class="px-3 py-2 border text-center">{{ $ct }}</td>
              @endforeach
              <td class="px-3 py-2 border text-center">{{ $grandTotal2 }}</td>
            </tr>
          </tfoot>
        @endif
      </table>
    </section>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <script>
    (function(){
      const startEl = document.getElementById('start');
      const endEl   = document.getElementById('end');

      // Initialize Flatpickr (controller already sets defaults to last 7 days)
      flatpickr("#start", { dateFormat: "Y-m-d", defaultDate: startEl.value || null });
      flatpickr("#end",   { dateFormat: "Y-m-d", defaultDate: endEl.value   || null });
    })();
  </script>
</body>
</html>
