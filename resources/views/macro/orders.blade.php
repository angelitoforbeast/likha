<x-layout>
  <x-slot name="title">Orders per Item</x-slot>
  <x-slot name="heading">Orders per Item — Per Date</x-slot>

  @once
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <style>
      [x-cloak]{display:none!important}
      th, td { vertical-align: middle; }
      /* Frozen header (top) + frozen summary columns (Item/Total/Average, left) */
      .frz-wrap { max-height: calc(100vh - 240px); overflow: auto; }
      .frz { border-collapse: separate; border-spacing: 0; }

      /* Consistent zebra — opaque bg KADA cell (kasama ang frozen) para pantay ang
         kulay sa buong row at walang nakikitang laman sa likod ng frozen cells. */
      .frz tbody tr:nth-child(odd)  td { background: #ffffff; }
      .frz tbody tr:nth-child(even) td { background: #f7f8fa; }
      .frz tbody tr:hover td        { background: #eef2ff; }

      /* Header (top-frozen) */
      .frz thead th { position: sticky; top: 0; z-index: 4; background: #eef1f5; }
      .frz tfoot th { background: #eef1f5; font-weight: 600; }

      /* Left-frozen columns — left offsets set via JS (c1=Item, c2=Total, c3=Average) */
      .frz .col-frz { position: sticky; z-index: 3; }
      .frz thead th.col-frz { z-index: 6; }       /* corner: top + left, ibabaw ng lahat */
      .frz .c3 { border-right: 2px solid #cbd5e1; } /* divider: frozen block | dates */
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
  <div class="bg-white rounded-xl shadow p-4">
    <div class="flex justify-between items-center mb-2">
      <div class="text-sm font-medium">Orders by Item <span class="text-gray-500">(quantity prefix removed, order count)</span></div>
      <button type="button" id="copyBtn" class="px-3 py-1.5 text-sm rounded-lg border bg-white hover:bg-gray-50">Copy table</button>
    </div>

    <div class="frz-wrap">
    <table id="ordersTable" class="min-w-full text-sm frz">
      <thead>
        <tr>
          <th class="text-left p-2 border-b align-bottom col-frz c1" rowspan="2">Item</th>
          <th class="text-right p-2 border-b align-bottom col-frz c2" rowspan="2">Total</th>
          <th class="text-right p-2 border-b align-bottom col-frz c3" rowspan="2">Average<br><span class="text-[10px] font-normal text-gray-500">/day</span></th>
          @foreach(($monthGroups ?? []) as $m)
            <th class="text-center p-2 border-b whitespace-nowrap" colspan="{{ $m['count'] ?? 0 }}">{{ $m['label'] ?? '' }}</th>
          @endforeach
        </tr>
        <tr>
          @foreach($dateKeys as $dk)
            <th class="text-right p-2 border-b whitespace-nowrap">{{ $dayLabels[$dk] ?? '' }}</th>
          @endforeach
        </tr>
      </thead>

      <tbody>
        @forelse($pivotRows as $row)
          <tr>
            <td class="p-2 border-b col-frz c1">{{ $row['label'] ?? '—' }}</td>
            <td class="p-2 border-b text-right font-semibold col-frz c2">{{ number_format($row['total'] ?? 0) }}</td>
            <td class="p-2 border-b text-right text-gray-600 col-frz c3">{{ number_format($row['avg'] ?? 0, 1) }}</td>
            @foreach($dateKeys as $dk)
              @php $v = (int)($row['dates'][$dk] ?? 0); @endphp
              <td class="p-2 border-b text-right">{{ $v ? number_format($v) : '' }}</td>
            @endforeach
          </tr>
        @empty
          <tr><td class="p-2 text-gray-500" colspan="{{ count($dateKeys) + 3 }}">No results para sa range na ito.</td></tr>
        @endforelse
      </tbody>

      <tfoot>
        <tr>
          <th class="text-left p-2 border-t col-frz c1">Totals</th>
          <th class="text-right p-2 border-t col-frz c2">{{ number_format($grandTotal ?? 0) }}</th>
          <th class="text-right p-2 border-t col-frz c3">{{ number_format($grandAvg ?? 0, 1) }}</th>
          @foreach($dateKeys as $dk)
            @php $cv = (int)($colTotals[$dk] ?? 0); @endphp
            <th class="text-right p-2 border-t">{{ $cv ? number_format($cv) : '' }}</th>
          @endforeach
        </tr>
      </tfoot>
    </table>
    </div>
  </div>

  <script>
    // I-set ang sticky offsets para pixel-perfect ang freeze:
    //  • top ng 2nd header row (day numbers) = taas ng 1st header row
    //  • left ng frozen columns: c1=0, c2=lapad(c1), c3=lapad(c1)+lapad(c2)
    function frzOffsets() {
      const t = document.getElementById('ordersTable');
      if (!t) return;
      // top — 2nd header row sumusunod sa 1st
      const r1 = t.querySelector('thead tr:first-child');
      const h  = r1 ? r1.getBoundingClientRect().height : 0;
      t.querySelectorAll('thead tr:nth-child(2) th').forEach(function (th) { th.style.top = h + 'px'; });
      // left — base sa lapad ng header cells (uniform ang column width sa buong table)
      const c1 = t.querySelector('thead .c1');
      const c2 = t.querySelector('thead .c2');
      const w1 = c1 ? c1.getBoundingClientRect().width : 0;
      const w2 = c2 ? c2.getBoundingClientRect().width : 0;
      t.querySelectorAll('.c1').forEach(function (el) { el.style.left = '0px'; });
      t.querySelectorAll('.c2').forEach(function (el) { el.style.left = w1 + 'px'; });
      t.querySelectorAll('.c3').forEach(function (el) { el.style.left = (w1 + w2) + 'px'; });
    }

    document.addEventListener('DOMContentLoaded', function () {
      if (window.flatpickr) {
        flatpickr('#date_range', { mode: 'range', dateFormat: 'Y-m-d', allowInput: true });
      }
      frzOffsets();
      window.addEventListener('load', frzOffsets);
      window.addEventListener('resize', frzOffsets);
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
