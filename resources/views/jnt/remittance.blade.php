{{-- resources/views/jnt/remittance.blade.php --}}
<x-layout>
  <x-slot name="title">Remittance • Likha</x-slot>

  <x-slot name="heading">
    <div class="text-xl font-bold">📦 J&T Remittance</div>
  </x-slot>

  <div x-data="remitUI('{{ $start }}','{{ $end }}')" x-init="init()">
    <!-- Filters (auto-apply on close, no Filter button) -->
    <section class="bg-white rounded-xl shadow p-3">
      <div class="grid md:grid-cols-3 gap-3 items-end">
        <div class="md:col-span-2">
          <label class="block text-sm font-semibold mb-1">Date range</label>
          <input id="remitRange" type="text" placeholder="Select date range"
                 class="w-full border border-gray-300 p-2 rounded-md shadow-sm cursor-pointer bg-white" readonly>
          <div class="text-xs text-gray-500 mt-1" x-text="dateLabel"></div>
        </div>

        <div class="flex gap-2 md:justify-end">
          <button class="px-3 py-2 rounded border hover:bg-gray-50" @click="thisMonth()">This month</button>
          <button class="px-3 py-2 rounded border hover:bg-gray-50" @click="yesterday()">Yesterday</button>
        </div>
      </div>
    </section>

    <!-- Table -->
    <section class="bg-white rounded-xl shadow p-3 mt-3">
      <div class="flex items-center justify-between mb-2">
        <div class="font-semibold">Summary</div>
        <div class="text-xs text-gray-500">
          Source: from_jnts — Delivered by <em>signingtime</em>; Pickups by <em>submission_time</em>
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="min-w-full border border-gray-200 bg-white text-xs">
          <thead class="bg-gray-50">
            <tr class="text-left">
              <th class="px-3 py-2 border-b">Date</th>
              <th class="px-3 py-2 border-b text-right">Number of Delivered</th>
              <th class="px-3 py-2 border-b text-right">COD Sum</th>
              <th class="px-3 py-2 border-b text-right">COD Fee (81.5% × 1.12%)</th>
              <th class="px-3 py-2 border-b text-right">Parcels Picked up</th>
              <th class="px-3 py-2 border-b text-right">Total Shipping Cost</th>
              <th class="px-3 py-2 border-b text-right">Remittance</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($rows as $r)
              <tr class="hover:bg-gray-50">
                <td class="px-3 py-2 border-b whitespace-nowrap">{{ $r['date'] }}</td>
                <td class="px-3 py-2 border-b text-right">{{ number_format($r['delivered']) }}</td>
                <td class="px-3 py-2 border-b text-right">{{ number_format($r['cod_sum'], 2) }}</td>
                <td class="px-3 py-2 border-b text-right">{{ number_format($r['cod_fee'], 2) }}</td>
                <td class="px-3 py-2 border-b text-right">{{ number_format($r['picked']) }}</td>
                <td class="px-3 py-2 border-b text-right">{{ number_format($r['ship_cost'], 2) }}</td>
                <td class="px-3 py-2 border-b text-right font-semibold">{{ number_format($r['remittance'], 2) }}</td>
              </tr>
            @empty
              <tr>
                <td class="px-3 py-6 text-center text-gray-500" colspan="7">No data for the selected date(s).</td>
              </tr>
            @endforelse
          </tbody>

          {{-- ====== TOTALS ROW (COD Fee editable; Remittance recalculates) ====== --}}
          <tfoot class="bg-gray-50"
                 x-data="codFeeTotals({
                   codSum: {{ json_encode($totals['cod_sum']) }},
                   codFee: {{ json_encode($totals['cod_fee']) }},
                   shipCost: {{ json_encode($totals['ship_cost']) }}
                 })">
            <tr>
              <th class="px-3 py-2 border-t text-right">TOTAL</th>
              <th class="px-3 py-2 border-t text-right">{{ number_format($totals['delivered']) }}</th>
              <th class="px-3 py-2 border-t text-right">{{ number_format($totals['cod_sum'], 2) }}</th>

              {{-- Editable COD Fee (TOTAL) --}}
              <th class="px-3 py-2 border-t text-right">
                <div class="flex items-center justify-end gap-2">
                  <input type="number" step="0.01"
                         class="w-32 border rounded px-2 py-1 text-right"
                         :value="codFeeEffective.toFixed(2)"
                         @input="onInput($event)"
                         @blur="sanitize()">
                  <button type="button"
                          class="text-xs px-2 py-1 border rounded hover:bg-gray-100"
                          @click="reset()">Reset</button>
                </div>
                <div class="text-[10px] text-gray-500 mt-1" x-show="isOverridden()">
                  overridden (was <span x-text="money(codFeeDefault)"></span>)
                </div>
              </th>

              <th class="px-3 py-2 border-t text-right">{{ number_format($totals['picked']) }}</th>
              <th class="px-3 py-2 border-t text-right">{{ number_format($totals['ship_cost'], 2) }}</th>

              {{-- Remittance (TOTAL) reacts to COD Fee override --}}
              <th class="px-3 py-2 border-t text-right font-semibold"
                  x-text="money(remittanceEffective)"></th>
            </tr>
          </tfoot>
        </table>
      </div>
    </section>
  </div>

  <script>document.title = 'Remittance • Likha';</script>

  {{-- flatpickr --}}
  <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

  <script>
    function remitUI(startDefault, endDefault){
      return {
        filters: { start_date: startDefault || '', end_date: endDefault || '' },
        dateLabel: 'Select dates',

        ymd(d){ const p = n => String(n).padStart(2,'0'); return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate()); },
        setDateLabel(){
          if (!this.filters.start_date || !this.filters.end_date) { this.dateLabel = 'Select dates'; return; }
          const s = new Date(this.filters.start_date + 'T00:00:00');
          const e = new Date(this.filters.end_date   + 'T00:00:00');
          const M = i => ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][i];
          const same = s.getTime() === e.getTime();
          this.dateLabel = same
            ? `${M(s.getMonth())} ${s.getDate()}, ${s.getFullYear()}`
            : `${M(s.getMonth())} ${s.getDate()}, ${s.getFullYear()} – ${M(e.getMonth())} ${e.getDate()}, ${e.getFullYear()}`;
        },
        go(){
          const params = new URLSearchParams({
            start_date: this.filters.start_date || '',
            end_date:   this.filters.end_date   || ''
          });
          window.location = '{{ route('jnt.remittance') }}?' + params.toString();
        },
        thisMonth(){
          const now = new Date();
          const start = new Date(now.getFullYear(), now.getMonth(), 1);
          this.filters.start_date = this.ymd(start);
          this.filters.end_date   = this.ymd(now);
          this.setDateLabel(); this.go();
        },
        yesterday(){
          const now = new Date(); now.setDate(now.getDate() - 1);
          const y = this.ymd(now);
          this.filters.start_date = y; this.filters.end_date = y;
          this.setDateLabel(); this.go();
        },
        init(){
          this.setDateLabel();
          window.flatpickr('#remitRange', {
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
              this.setDateLabel(); this.go();
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

    // Mini Alpine component for editable COD Fee (TOTAL)
    function codFeeTotals(init){
      return {
        // server totals
        codSum: Number(init.codSum || 0),
        codFeeDefault: Number(init.codFee || 0),
        shipCost: Number(init.shipCost || 0),

        // user override (null = not overridden)
        codFeeOverride: null,

        get codFeeEffective(){ return this.codFeeOverride ?? this.codFeeDefault; },
        get remittanceEffective(){ return +(this.codSum - this.codFeeEffective - this.shipCost).toFixed(2); },

        onInput(e){
          const v = parseFloat(e.target.value);
          this.codFeeOverride = isNaN(v) ? null : v;
        },
        sanitize(){
          if (this.codFeeOverride === null) return; // keep null if empty
          if (!isFinite(this.codFeeOverride)) this.codFeeOverride = this.codFeeDefault;
          if (this.codFeeOverride < 0) this.codFeeOverride = 0;
        },
        reset(){ this.codFeeOverride = null; },
        isOverridden(){ return this.codFeeOverride !== null && this.codFeeOverride.toFixed(2) !== this.codFeeDefault.toFixed(2); },

        money(v){ return '₱' + Number(v||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); },
      }
    }
  </script>
</x-layout>
