<x-layout>
  <x-slot name="title">Orders per Item</x-slot>
  <x-slot name="heading">Orders per Item — Per Date</x-slot>

  @once
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <style>
      [x-cloak]{display:none!important}
      th, td { vertical-align: middle; }
    </style>
  @endonce

  {{-- Filters --}}
  <form id="filtersForm" method="get" class="bg-white p-4 rounded-xl shadow mb-4 grid md:grid-cols-6 gap-4">
    <div class="md:col-span-2">
      <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
      <input id="q" type="text" name="q" value="{{ $q }}" placeholder="Search item / page..."
             class="w-full border rounded-lg px-3 py-2" autocomplete="off" />
    </div>

    <div class="md:col-span-2">
      <label class="block text-sm font-medium text-gray-700 mb-1">Date range</label>
      <input id="date_range" type="text" name="date_range" placeholder="YYYY-MM-DD to YYYY-MM-DD"
             value="{{ $startStr.' to '.$endStr }}"
             class="w-full border rounded-lg px-3 py-2" autocomplete="off"/>
      <p class="text-xs text-gray-500 mt-1">Default: last 7 days, <strong>excluding today</strong>.</p>
    </div>

    <div class="flex items-start gap-2 md:mt-7">
      <button type="submit" class="px-4 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700">Apply</button>
      <a href="{{ route('macro.orders') }}" class="px-4 py-2 rounded-lg border hover:bg-gray-50">Reset</a>
    </div>
  </form>

  {{-- Summary line --}}
  <div class="mb-3 text-sm text-gray-700">
    <strong>{{ number_format($grandTotal) }}</strong> total orders ·
    <strong>{{ number_format($pivotRows->count()) }}</strong> item{{ $pivotRows->count() === 1 ? '' : 's' }} ·
    {{ $startStr }} → {{ $endStr }} ({{ $numDays }} day{{ $numDays === 1 ? '' : 's' }})
  </div>

  {{-- Pivot table --}}
  <div class="bg-white rounded-xl shadow p-4 overflow-auto">
    <div class="flex justify-between items-center mb-2">
      <div class="text-sm font-medium">Orders by Item <span class="text-gray-500">(quantity prefix removed, order count)</span></div>
      <button type="button" id="copyBtn" class="px-3 py-1.5 text-sm rounded-lg border bg-white hover:bg-gray-50">Copy table</button>
    </div>

    <table id="ordersTable" class="min-w-full text-sm">
      <thead>
        <tr class="bg-gray-50">
          <th class="text-left p-2 border-b align-bottom" rowspan="2"
              style="position: sticky; left: 0; background: #f9fafb;">Item</th>
          @foreach(($monthGroups ?? []) as $m)
            <th class="text-center p-2 border-b whitespace-nowrap" colspan="{{ $m['count'] ?? 0 }}">{{ $m['label'] ?? '' }}</th>
          @endforeach
          <th class="text-right p-2 border-b align-bottom bg-gray-100" rowspan="2" style="border-left:2px solid #e5e7eb;">Total</th>
          <th class="text-right p-2 border-b align-bottom bg-gray-100" rowspan="2">Average<br><span class="text-[10px] font-normal text-gray-500">/day</span></th>
        </tr>
        <tr class="bg-gray-50">
          @foreach($dateKeys as $dk)
            <th class="text-right p-2 border-b whitespace-nowrap">{{ $dayLabels[$dk] ?? '' }}</th>
          @endforeach
        </tr>
      </thead>

      <tbody>
        @forelse($pivotRows as $row)
          <tr class="odd:bg-white even:bg-gray-50">
            <td class="p-2 border-b" style="position: sticky; left: 0; background: white;">{{ $row['label'] ?? '—' }}</td>
            @foreach($dateKeys as $dk)
              @php $v = (int)($row['dates'][$dk] ?? 0); @endphp
              <td class="p-2 border-b text-right">{{ $v ? number_format($v) : '' }}</td>
            @endforeach
            <td class="p-2 border-b text-right font-semibold bg-gray-50" style="border-left:2px solid #e5e7eb;">{{ number_format($row['total'] ?? 0) }}</td>
            <td class="p-2 border-b text-right text-gray-600 bg-gray-50">{{ number_format($row['avg'] ?? 0, 1) }}</td>
          </tr>
        @empty
          <tr><td class="p-2 text-gray-500" colspan="{{ count($dateKeys) + 3 }}">No results para sa range na ito.</td></tr>
        @endforelse
      </tbody>

      <tfoot class="bg-gray-50">
        <tr>
          <th class="text-left p-2 border-t" style="position: sticky; left: 0; background: #f9fafb;">Totals</th>
          @foreach($dateKeys as $dk)
            @php $cv = (int)($colTotals[$dk] ?? 0); @endphp
            <th class="text-right p-2 border-t">{{ $cv ? number_format($cv) : '' }}</th>
          @endforeach
          <th class="text-right p-2 border-t bg-gray-100" style="border-left:2px solid #e5e7eb;">{{ number_format($grandTotal ?? 0) }}</th>
          <th class="text-right p-2 border-t bg-gray-100">{{ number_format($grandAvg ?? 0, 1) }}</th>
        </tr>
      </tfoot>
    </table>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      if (window.flatpickr) {
        flatpickr('#date_range', { mode: 'range', dateFormat: 'Y-m-d', allowInput: true });
      }
      const btn = document.getElementById('copyBtn');
      if (btn) btn.addEventListener('click', function () {
        const tbl = document.getElementById('ordersTable');
        if (!tbl) return;
        let txt = '';
        for (const tr of tbl.querySelectorAll('tr')) {
          const cells = [...tr.children].map(td => (td.innerText || '').replace(/\s+/g, ' ').trim());
          txt += cells.join('\t') + '\n';
        }
        navigator.clipboard.writeText(txt).then(function () {
          btn.textContent = '✓ Copied';
          setTimeout(function () { btn.textContent = 'Copy table'; }, 1500);
        });
      });
    });
  </script>
</x-layout>
