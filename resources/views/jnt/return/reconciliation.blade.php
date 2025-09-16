<x-layout>
  <x-slot name="title">J&T Return Reconciliation • Likha</x-slot>
  <x-slot name="heading"><div class="text-xl font-bold">📦 J&T Return Reconciliation</div></x-slot>

  <div x-data="reconUI('{{ $start }}','{{ $end }}')" x-init="init()" class="space-y-4">
    {{-- Filters --}}
    <section class="bg-white rounded-xl shadow p-4">
      <div class="grid md:grid-cols-3 gap-3 items-end">
        <div class="md:col-span-2">
          <label class="block text-sm font-semibold mb-1">Date range (by signingtime)</label>
          <input id="filterRange" type="text" class="w-full border p-2 rounded bg-white cursor-pointer" readonly>
          <div class="text-xs text-gray-500 mt-1" x-text="dateLabel"></div>
        </div>

        <div class="flex gap-2 md:justify-end">
          <button class="px-3 py-2 rounded border hover:bg-gray-50" @click="thisMonth()">This month</button>
          <button class="px-3 py-2 rounded border hover:bg-gray-50" @click="yesterday()">Yesterday</button>
          <button class="px-3 py-2 rounded border hover:bg-gray-50" @click="apply()">Apply</button>
        </div>
      </div>
    </section>

    {{-- SUMMARY TABLE --}}
    <section class="bg-white rounded-xl shadow p-4">
      <div class="font-semibold mb-2">Summary</div>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm border border-gray-200">
          <thead>
            {{-- Row 1: Month segments + Total --}}
            <tr class="bg-gray-50">
              <th class="px-3 py-2 border-b text-left align-bottom" rowspan="2"> </th>
              @foreach($monthSegments as $seg)
                <th class="px-3 py-2 border-b text-center" colspan="{{ $seg['span'] }}">{{ $seg['label'] }}</th>
              @endforeach
              <th class="px-3 py-2 border-b text-right align-bottom" rowspan="2">Total</th>
            </tr>
            {{-- Row 2: Day numbers --}}
            <tr class="bg-gray-50">
              @foreach($dates as $d)
                @php $dd = (int) \Carbon\Carbon::parse($d)->format('d'); @endphp
                <th class="px-2 py-1 border-b text-center whitespace-nowrap">{{ $dd }}</th>
              @endforeach
            </tr>
          </thead>
          <tbody>
            {{-- Missing row --}}
            <tr class="hover:bg-gray-50">
              <td class="px-3 py-2 border-b font-semibold">Missing</td>
              @foreach($dates as $d)
                <td class="px-2 py-2 border-b text-right">{{ number_format($countsMissing[$d] ?? 0) }}</td>
              @endforeach
              <td class="px-3 py-2 border-b text-right font-bold">{{ number_format($totalMissing) }}</td>
            </tr>

            {{-- Existing row --}}
            <tr class="hover:bg-gray-50">
              <td class="px-3 py-2 border-b font-semibold">Existing</td>
              @foreach($dates as $d)
                <td class="px-2 py-2 border-b text-right">{{ number_format($countsExisting[$d] ?? 0) }}</td>
              @endforeach
              <td class="px-3 py-2 border-b text-right font-bold">{{ number_format($totalExisting) }}</td>
            </tr>

            {{-- Total row --}}
            <tr class="bg-gray-50">
              <td class="px-3 py-2 border-b font-semibold">Total</td>
              @foreach($dates as $d)
                @php
                  $sumDay = ($countsMissing[$d] ?? 0) + ($countsExisting[$d] ?? 0);
                @endphp
                <td class="px-2 py-2 border-b text-right font-semibold">{{ number_format($sumDay) }}</td>
              @endforeach
              <td class="px-3 py-2 border-b text-right font-extrabold">{{ number_format($grandTotal) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    {{-- MISSING WAYBILLS ONLY --}}
    <section class="bg-white rounded-xl shadow p-4">
      <div class="flex items-center justify-between mb-2">
        <div class="font-semibold">Missing Waybills</div>
        <div class="text-xs text-gray-500">Returned in from_jnts but NOT found in jnt_return_scanned</div>
      </div>

      <div class="overflow-x-auto">
        <table class="min-w-full text-sm border border-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-3 py-2 border-b text-left">Waybill</th>
              <th class="px-3 py-2 border-b text-left">Signing Time</th>
            </tr>
          </thead>
          <tbody>
            @forelse($missingList as $r)
              <tr class="hover:bg-gray-50">
                <td class="px-3 py-2 border-b">{{ $r['waybill'] }}</td>
                <td class="px-3 py-2 border-b">{{ $r['signingtime'] }}</td>
              </tr>
            @empty
              <tr><td colspan="2" class="px-3 py-6 text-center text-gray-500">No missing waybills in range.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </section>
  </div>

  {{-- flatpickr --}}
  <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css" />

  <script>
    function reconUI(startDefault, endDefault){
      return {
        filters: { start_date: startDefault || '', end_date: endDefault || '' },
        dateLabel: 'Select dates',
        ymd(d){ const p=n=>String(n).padStart(2,'0'); return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate()); },
        setDateLabel(){
          if (!this.filters.start_date || !this.filters.end_date) { this.dateLabel='Select dates'; return; }
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
            end_date:   this.filters.end_date   || ''
          });
          window.location = "{{ route('jnt.return.reconciliation') }}?" + params.toString();
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
          flatpickr('#filterRange', {
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
        }
      }
    }
  </script>
</x-layout>
