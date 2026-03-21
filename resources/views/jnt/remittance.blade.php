{{-- resources/views/jnt/remittance.blade.php --}}
<x-layout>
  <x-slot name="title">Remittance • Likha</x-slot>

  <x-slot name="heading">
    <div class="text-xl font-bold">📦 J&T Remittance</div>
  </x-slot>

  <style>
    input.no-spin::-webkit-outer-spin-button,
    input.no-spin::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    input.no-spin[type=number] { -moz-appearance: textfield; appearance: textfield; }
  </style>

  <div x-data="remitUI('{{ $start }}','{{ $end }}')" x-init="init()">
    <!-- Filters -->
    <section class="bg-white rounded-xl shadow p-3">
      <div class="grid md:grid-cols-3 gap-3 items-end">
        <div class="md:col-span-2">
          <label class="block text-sm font-semibold mb-1">Date range</label>
          <input id="remitRange" type="text" placeholder="Select date range"
                 class="w-full border border-gray-300 p-2 rounded-md shadow-sm cursor-pointer bg-white" readonly>
          <div class="text-xs text-gray-500 mt-1" x-text="dateLabel"></div>
        </div>

        <div class="flex gap-2 md:justify-end flex-wrap">
          <button class="px-3 py-2 rounded border hover:bg-gray-50" @click="thisMonth()">This month</button>
          <button class="px-3 py-2 rounded border hover:bg-gray-50" @click="yesterday()">Yesterday</button>
          <a href="{{ route('fee-settings.index') }}" class="px-3 py-2 rounded border bg-gray-100 hover:bg-gray-200 text-sm flex items-center gap-1">
            ⚙️ Fee Settings
          </a>
        </div>
      </div>
    </section>

    {{-- SF Anomaly Alert --}}
    @if(($totals['sf_anomaly_count'] ?? 0) > 0)
      <section class="bg-red-50 border border-red-300 rounded-xl shadow p-3 mt-3">
        <div class="flex items-start gap-2">
          <span class="text-red-600 text-lg">⚠️</span>
          <div>
            <div class="font-semibold text-red-800">Shipping Fee Anomaly Detected</div>
            <div class="text-sm text-red-700 mt-1">
              <strong>{{ $totals['sf_anomaly_count'] }}</strong> order(s) have a shipping fee that does not match the expected rate
              @if($rates['expected_ship_fee'] !== null)
                of <strong>₱{{ number_format($rates['expected_ship_fee'], 2) }}</strong>
              @endif
              . Check the rows highlighted in red below.
            </div>
          </div>
        </div>
      </section>
    @elseif($rates['expected_ship_fee'] === null)
      <section class="bg-yellow-50 border border-yellow-300 rounded-xl shadow p-3 mt-3">
        <div class="flex items-start gap-2">
          <span class="text-yellow-600 text-lg">⚠️</span>
          <div>
            <div class="font-semibold text-yellow-800">No Shipping Fee Setting Configured</div>
            <div class="text-sm text-yellow-700 mt-1">
              Cannot validate shipping fees. Please <a href="{{ route('fee-settings.index') }}" class="underline font-semibold">set the Shipping Fee per Order</a> in Fee Settings.
            </div>
          </div>
        </div>
      </section>
    @endif

    <!-- Table -->
    <section class="bg-white rounded-xl shadow p-3 mt-3">
      <div class="flex items-center justify-between mb-2">
        <div class="font-semibold">Summary</div>
        <div class="text-xs text-gray-500">
          Source: from_jnts — Delivered by <em>signingtime</em>; Pickups by <em>submission_time</em>
          — Shipping cost: <strong>actual from DB</strong>
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="min-w-full border border-gray-200 bg-white text-xs">
          <thead class="bg-gray-50">
            <tr class="text-left">
              <th class="px-3 py-2 border-b">Date</th>
              <th class="px-3 py-2 border-b text-right">Number of Delivered</th>
              <th class="px-3 py-2 border-b text-right">COD Sum</th>
              <th class="px-3 py-2 border-b text-right">COD Fee</th>
              <th class="px-3 py-2 border-b text-right">COD Fee VAT<br><span class="text-[10px] font-normal">({{ $rates['cod_vat_rate'] * 100 }}% x COD Fee)</span></th>
              <th class="px-3 py-2 border-b text-right">Parcels Picked up</th>
              <th class="px-3 py-2 border-b text-right">Total Shipping Cost</th>
              <th class="px-3 py-2 border-b text-right">SF Status</th>
              <th class="px-3 py-2 border-b text-right">Remittance</th>
            </tr>
          </thead>
          <tbody>
            @forelse ($rows as $r)
              @php
                $hasAnomaly = ($r['sf_anomaly_count'] ?? 0) > 0;
              @endphp
              <tr class="{{ $hasAnomaly ? 'bg-red-50 hover:bg-red-100' : 'hover:bg-gray-50' }}">
                <td class="px-3 py-2 border-b whitespace-nowrap">{{ $r['date'] }}</td>
                <td class="px-3 py-2 border-b text-right">{{ number_format($r['delivered']) }}</td>
                <td class="px-3 py-2 border-b text-right">₱{{ number_format($r['cod_sum'], 2) }}</td>
                <td class="px-3 py-2 border-b text-right">₱{{ number_format($r['cod_fee'], 2) }}</td>
                <td class="px-3 py-2 border-b text-right">₱{{ number_format($r['cod_fee_vat'], 2) }}</td>
                <td class="px-3 py-2 border-b text-right">{{ number_format($r['picked']) }}</td>
                <td class="px-3 py-2 border-b text-right {{ $hasAnomaly ? 'text-red-700 font-semibold' : '' }}">
                  ₱{{ number_format($r['ship_cost'], 2) }}
                </td>
                <td class="px-3 py-2 border-b text-right text-[11px]">
                  @if($hasAnomaly)
                    <div class="text-red-700 font-semibold">
                      ⚠️ {{ $r['sf_anomaly_count'] }} wrong
                    </div>
                    <div class="text-red-600 mt-0.5">
                      @foreach($r['sf_anomalies'] as $a)
                        <span class="inline-block bg-red-100 px-1 rounded">₱{{ number_format($a['sf_value'], 2) }} x{{ $a['count'] }}</span>
                      @endforeach
                    </div>
                  @elseif($rates['expected_ship_fee'] !== null)
                    <span class="text-green-700">✅ OK</span>
                  @else
                    <span class="text-gray-400">—</span>
                  @endif
                </td>
                <td class="px-3 py-2 border-b text-right font-semibold">₱{{ number_format($r['remittance'], 2) }}</td>
              </tr>
            @empty
              <tr>
                <td class="px-3 py-6 text-center text-gray-500" colspan="9">No data for the selected date(s).</td>
              </tr>
            @endforelse
          </tbody>

          {{-- TOTALS --}}
          <tfoot class="bg-gray-50"
                 x-data="codFeeTotals({
                   codSum: {{ json_encode($totals['cod_sum']) }},
                   codFee: {{ json_encode($totals['cod_fee']) }},
                   codFeeVat: {{ json_encode($totals['cod_fee_vat']) }},
                   shipCost: {{ json_encode($totals['ship_cost']) }}
                 })"
                 x-init="init()">
            <tr>
              <th class="px-3 py-2 border-t text-right">TOTAL</th>
              <th class="px-3 py-2 border-t text-right">{{ number_format($totals['delivered']) }}</th>
              <th class="px-3 py-2 border-t text-right">₱{{ number_format($totals['cod_sum'], 2) }}</th>

              {{-- Editable TOTAL COD Fee --}}
              <th class="px-3 py-2 border-t text-right">
                <div class="flex items-center justify-end gap-2">
                  <input
                    type="text" inputmode="decimal" pattern="[0-9]*[.,]?[0-9]*"
                    class="no-spin w-32 border rounded px-2 py-1 text-right"
                    x-model="codFeeInput"
                    @blur="formatFee()"
                    @keydown.enter.prevent="formatFee()">
                  <button type="button" class="text-xs px-2 py-1 border rounded hover:bg-gray-100" @click="resetFee()">Reset</button>
                </div>
                <div class="text-[10px] text-gray-500 mt-1" x-show="isFeeOverridden()">
                  overridden (was <span x-text="money(codFeeDefault)"></span>)
                </div>
              </th>

              {{-- Editable TOTAL COD Fee VAT --}}
              <th class="px-3 py-2 border-t text-right">
                <div class="flex items-center justify-end gap-2">
                  <input
                    type="text" inputmode="decimal" pattern="[0-9]*[.,]?[0-9]*"
                    class="no-spin w-32 border rounded px-2 py-1 text-right"
                    x-model="codFeeVatInput"
                    @blur="formatVat()"
                    @keydown.enter.prevent="formatVat()">
                  <button type="button" class="text-xs px-2 py-1 border rounded hover:bg-gray-100" @click="resetVat()">Reset</button>
                </div>
                <div class="text-[10px] text-gray-500 mt-1" x-show="isVatOverridden()">
                  overridden (was <span x-text="money(codFeeVatDefault)"></span>)
                </div>
              </th>

              <th class="px-3 py-2 border-t text-right">{{ number_format($totals['picked']) }}</th>
              <th class="px-3 py-2 border-t text-right {{ ($totals['sf_anomaly_count'] ?? 0) > 0 ? 'text-red-700' : '' }}">
                ₱{{ number_format($totals['ship_cost'], 2) }}
              </th>
              <th class="px-3 py-2 border-t text-right text-[11px]">
                @if(($totals['sf_anomaly_count'] ?? 0) > 0)
                  <span class="text-red-700 font-semibold">⚠️ {{ $totals['sf_anomaly_count'] }} total</span>
                @elseif($rates['expected_ship_fee'] !== null)
                  <span class="text-green-700">✅ All OK</span>
                @else
                  <span class="text-gray-400">—</span>
                @endif
              </th>

              {{-- TOTAL Remittance reacts to both overrides --}}
              <th class="px-3 py-2 border-t text-right font-semibold"
                  x-text="money(remittanceEffective)"></th>
            </tr>
          </tfoot>
        </table>
      </div>

      <div class="text-[11px] text-gray-500 mt-3">
        <span class="font-semibold">Formulas:</span>
        COD Fee = <code>{{ $rates['cod_fee_rate'] * 100 }}% x COD sum</code> •
        COD Fee VAT = <code>{{ $rates['cod_vat_rate'] * 100 }}% x COD Fee</code> •
        Shipping = <code>actual total_shipping_cost from DB</code> •
        Remittance = <code>COD sum - COD Fee - COD Fee VAT - Shipping</code>
        @if($rates['expected_ship_fee'] !== null)
          • Expected SF per order: <code>₱{{ number_format($rates['expected_ship_fee'], 2) }}</code>
        @endif
      </div>
    </section>
  </div>

  {{-- Fallback in case layout doesn't use the title slot --}}
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

    function codFeeTotals(init){
      return {
        codSum: Number(init.codSum || 0),
        codFeeDefault: Number(init.codFee || 0),
        codFeeVatDefault: Number(init.codFeeVat || 0),
        shipCost: Number(init.shipCost || 0),

        codFeeOverride: null,
        codFeeVatOverride: null,
        codFeeInput: '',
        codFeeVatInput: '',

        init(){
          this.codFeeInput    = this.toFixed2(this.codFeeDefault);
          this.codFeeVatInput = this.toFixed2(this.codFeeVatDefault);
        },

        get codFeeEffective(){ return this.codFeeOverride ?? this.codFeeDefault; },
        get codFeeVatEffective(){ return this.codFeeVatOverride ?? this.codFeeVatDefault; },
        get remittanceEffective(){
          return +(this.codSum - this.codFeeEffective - this.codFeeVatEffective - this.shipCost).toFixed(2);
        },

        toFixed2(v){ return (Number(v||0)).toFixed(2); },
        money(v){ return '₱' + Number(v||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); },
        parseNum(s){
          if (s == null) return null;
          let str = String(s).trim().replace(/₱/g,'').replace(/\s/g,'');
          if (!str) return null;
          if (str.includes(',') && !str.includes('.')) {
            str = str.replace(/,/g, '.');
          } else {
            str = str.replace(/,/g, '');
          }
          const v = parseFloat(str);
          return isNaN(v) ? null : v;
        },

        formatFee(){
          const v = this.parseNum(this.codFeeInput);
          if (v === null || !isFinite(v) || v < 0) {
            this.codFeeOverride = null;
            this.codFeeInput = this.toFixed2(this.codFeeDefault);
            return;
          }
          const eps = 0.005;
          this.codFeeOverride = (Math.abs(v - this.codFeeDefault) > eps) ? +v.toFixed(2) : null;
          this.codFeeInput = this.toFixed2(this.codFeeOverride ?? this.codFeeDefault);
        },

        formatVat(){
          const v = this.parseNum(this.codFeeVatInput);
          if (v === null || !isFinite(v) || v < 0) {
            this.codFeeVatOverride = null;
            this.codFeeVatInput = this.toFixed2(this.codFeeVatDefault);
            return;
          }
          const eps = 0.005;
          this.codFeeVatOverride = (Math.abs(v - this.codFeeVatDefault) > eps) ? +v.toFixed(2) : null;
          this.codFeeVatInput = this.toFixed2(this.codFeeVatOverride ?? this.codFeeVatDefault);
        },

        resetFee(){
          this.codFeeOverride = null;
          this.codFeeInput = this.toFixed2(this.codFeeDefault);
        },
        resetVat(){
          this.codFeeVatOverride = null;
          this.codFeeVatInput = this.toFixed2(this.codFeeVatDefault);
        },

        isFeeOverridden(){ return this.codFeeOverride !== null; },
        isVatOverridden(){ return this.codFeeVatOverride !== null; },
      }
    }
  </script>
</x-layout>
