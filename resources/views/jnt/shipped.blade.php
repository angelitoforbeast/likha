{{-- resources/views/jnt/shipped.blade.php --}}
<x-layout>
  <x-slot name="title">Shipped Items • Likha</x-slot>

  <x-slot name="heading">
    <div class="text-xl font-bold">📦 J&T Shipped Items</div>
  </x-slot>

  <style>
    .th-month { font-size: 11px; letter-spacing: .03em; text-transform: uppercase; font-weight: 600; }
    .th-day   { font-size: 12px; font-weight: 700; }
    .copy-done { animation: pulse 0.9s ease; }
    @keyframes pulse {
      0% { transform: scale(1); opacity:.85; }
      50% { transform: scale(1.04); opacity:1; }
      100% { transform: scale(1); opacity:.85; }
    }
  </style>

  <div x-data="shipUI('{{ $start }}','{{ $end }}','{{ $mode }}')" x-init="init()">
    <!-- Controls -->
    <section class="bg-white rounded-xl shadow p-3">
      <div class="grid md:grid-cols-3 gap-3 items-end">
        <div class="md:col-span-2">
          <label class="block text-sm font-semibold mb-1">Date range (by submission_time)</label>
          <input id="shipRange" type="text" placeholder="Select date range"
                 class="w-full border border-gray-300 p-2 rounded-md shadow-sm cursor-pointer bg-white" readonly>
          <div class="text-xs text-gray-500 mt-1" x-text="dateLabel"></div>
        </div>

        <div class="space-y-2">
          <label class="flex items-center gap-2">
            <input type="checkbox" class="w-4 h-4" x-model="qtyMode" @change="apply()">
            <span class="text-sm">Interpret <span class="font-mono">"2 x NAME"</span> as quantity</span>
          </label>

          <label class="flex items-center gap-2">
            <input type="checkbox" class="w-4 h-4" x-model="perDate">
            <span class="text-sm">Show per date columns</span>
          </label>

          <div class="flex gap-2 md:justify-end">
            <button class="px-3 py-2 rounded border hover:bg-gray-50" @click="thisMonth()">This month</button>
            <button class="px-3 py-2 rounded border hover:bg-gray-50" @click="yesterday()">Yesterday</button>
            <button class="px-3 py-2 rounded border hover:bg-gray-50" :class="copied ? 'copy-done' : ''" @click="copyVisible()">
              Copy
            </button>
          </div>
        </div>
      </div>
    </section>

    <!-- Aggregated table (default) -->
    <section class="bg-white rounded-xl shadow p-3 mt-3" x-show="!perDate">
      <div class="flex items-center justify-between mb-2">
        <div class="font-semibold">Items (aggregated over range)</div>
        <div class="text-xs text-gray-500">Source: from_jnts — grouped by <em>item_name</em></div>
      </div>

      <div class="overflow-x-auto">
        <table id="tbl-agg" class="min-w-full border border-gray-200 bg-white text-xs">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-3 py-2 border-b text-left">Item</th>
              <th class="px-3 py-2 border-b text-right w-24">Qty</th>
            </tr>
          </thead>
          <tbody>
            @forelse($items as $it)
              <tr class="hover:bg-gray-50">
                <td class="px-3 py-2 border-b text-left">{{ $it['name'] }}</td>
                <td class="px-3 py-2 border-b text-right">{{ number_format($it['total']) }}</td>
              </tr>
            @empty
              <tr>
                <td class="px-3 py-6 text-center text-gray-500" colspan="2">No items for the selected date(s).</td>
              </tr>
            @endforelse
          </tbody>
          <tfoot class="bg-gray-50">
            <tr>
              <th class="px-3 py-2 border-t text-right">Grand Total</th>
              <th class="px-3 py-2 border-t text-right">{{ number_format($grandTotal) }}</th>
            </tr>
          </tfoot>
        </table>
      </div>
    </section>

    <!-- Pivot table: per date columns with merged month headers -->
    <section class="bg-white rounded-xl shadow p-3 mt-3" x-show="perDate">
      <div class="flex items-center justify-between mb-2">
        <div class="font-semibold">Items by Date</div>
        <div class="text-xs text-gray-500">Source: from_jnts — rows: <em>item</em>, columns: <em>DATE(submission_time)</em></div>
      </div>

      <div class="overflow-x-auto">
        <table id="tbl-pivot" class="min-w-full border border-gray-200 bg-white text-xs">
          <thead class="bg-gray-50">
            {{-- Row 1: Month groups --}}
            <tr>
              <th class="px-3 py-2 border-b text-left" rowspan="2">Item</th>
              @php
                // Build contiguous month groups from $dates
                $monthGroups = [];
                $current = null;
                foreach ($dates as $d) {
                  $mKey = \Carbon\Carbon::parse($d)->format('Y-m'); // group by year-month to keep order
                  $label = strtoupper(\Carbon\Carbon::parse($d)->format('F')); // "SEPTEMBER"
                  if (!$current || $current['key'] !== $mKey) {
                    if ($current) $monthGroups[] = $current;
                    $current = ['key' => $mKey, 'label' => $label, 'count' => 1];
                  } else {
                    $current['count']++;
                  }
                }
                if ($current) $monthGroups[] = $current;
              @endphp
              @foreach($monthGroups as $g)
                <th class="px-1 py-1 border-b text-center" colspan="{{ $g['count'] }}">
                  <span class="th-month">{{ $g['label'] }}</span>
                </th>
              @endforeach
              <th class="px-3 py-2 border-b text-right w-24" rowspan="2">Total</th>
            </tr>
            {{-- Row 2: Day numbers (DD) --}}
            <tr>
              @foreach($dates as $d)
                @php $day = \Carbon\Carbon::parse($d)->format('d'); @endphp
                <th class="px-1 py-1 border-b text-center th-day">{{ $day }}</th>
              @endforeach
            </tr>
          </thead>

          <tbody>
            @forelse($items as $it)
              <tr class="hover:bg-gray-50">
                <td class="px-3 py-2 border-b text-left">{{ $it['name'] }}</td>
                @foreach($dates as $d)
                  @php $q = $it['per_date'][$d] ?? 0; @endphp
                  <td class="px-1 py-2 border-b text-right">{{ $q ? number_format($q) : '' }}</td>
                @endforeach
                <td class="px-3 py-2 border-b text-right font-semibold">{{ number_format($it['total']) }}</td>
              </tr>
            @empty
              <tr>
                <td class="px-3 py-6 text-center text-gray-500" colspan="{{ 2 + count($dates) }}">No items for the selected date(s).</td>
              </tr>
            @endforelse
          </tbody>

          <tfoot class="bg-gray-50">
            <tr>
              <th class="px-3 py-2 border-t text-right" colspan="{{ 1 + count($dates) }}">Grand Total</th>
              <th class="px-3 py-2 border-t text-right">{{ number_format($grandTotal) }}</th>
            </tr>
          </tfoot>
        </table>
      </div>
    </section>
  </div>

  <script>document.title = 'Shipped Items • Likha';</script>

  {{-- flatpickr --}}
  <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

  <script>
    function shipUI(startDefault, endDefault, modeDefault){
      return {
        filters: { start_date: startDefault || '', end_date: endDefault || '' },
        qtyMode: (modeDefault === 'qty'), // true => quantity aware
        perDate: false,                   // show pivot columns
        dateLabel: 'Select dates',
        copied: false,

        ymd(d){ const p=n=>String(n).padStart(2,'0'); return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate()); },
        setDateLabel(){
          if (!this.filters.start_date || !this.filters.end_date) { this.dateLabel = 'Select dates'; return; }
          const s = new Date(this.filters.start_date+'T00:00:00');
          const e = new Date(this.filters.end_date  +'T00:00:00');
          const M = i => ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][i];
          const same = s.getTime()===e.getTime();
          this.dateLabel = same
            ? `${M(s.getMonth())} ${s.getDate()}, ${s.getFullYear()}`
            : `${M(s.getMonth())} ${s.getDate()}, ${s.getFullYear()} – ${M(e.getMonth())} ${e.getDate()}, ${e.getFullYear()}`;
        },
        apply(){
          const params = new URLSearchParams({
            start_date: this.filters.start_date || '',
            end_date:   this.filters.end_date   || '',
            mode:       this.qtyMode ? 'qty' : 'raw',
          });
          window.location = '{{ route('jnt.shipped') }}?' + params.toString();
        },
        thisMonth(){
          const now = new Date();
          const start = new Date(now.getFullYear(), now.getMonth(), 1);
          this.filters.start_date = this.ymd(start);
          this.filters.end_date   = this.ymd(now);
          this.setDateLabel(); this.apply();
        },
        yesterday(){
          const now = new Date(); now.setDate(now.getDate() - 1);
          const y = this.ymd(now);
          this.filters.start_date = y; this.filters.end_date = y;
          this.setDateLabel(); this.apply();
        },
        init(){
          this.setDateLabel();
          window.flatpickr('#shipRange', {
            mode: 'range',
            dateFormat: 'Y-m-d',
            defaultDate: [this.filters.start_date, this.filters.end_date].filter(Boolean),
            onClose: (sel) => {
              if (sel.length === 2) {
                this.filters.start_date = this.ymd(sel[0]);
                this.filters.end_date   = this.ymd(sel[1]);
              } else if (sel.length === 1) {
                this.filters.start_date = this.ymd(sel[0]);
                this.filters.end_date   = this.ymd(sel[0]);
              } else { return; }
              this.setDateLabel(); this.apply();
            },
            onReady: (_sd, _ds, inst) => {
              if (this.filters.start_date && this.filters.end_date) {
                inst.input.value = `${this.filters.start_date} to ${this.filters.end_date}`;
              }
            }
          });
        },

        // Copy whichever table is visible
        copyVisible(){
          const table = this.perDate ? document.getElementById('tbl-pivot') : document.getElementById('tbl-agg');
          if (!table) return;

          const rows = Array.from(table.querySelectorAll('tr'));
          const lines = rows.map(tr => {
            const cells = Array.from(tr.querySelectorAll('th,td'));
            return cells.map(td => td.innerText.replace(/\s*\n\s*/g, ' ').trim()).join('\t');
          });
          const text = lines.join('\n');

          if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(() => this.flashCopied());
          } else {
            const ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); this.flashCopied(); } finally { document.body.removeChild(ta); }
          }
        },
        flashCopied(){ this.copied = true; setTimeout(() => this.copied = false, 900); },
      }
    }
  </script>
</x-layout>
