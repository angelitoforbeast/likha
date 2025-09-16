<x-layout>
  <x-slot name="title">J&T Return Reconciliation • Likha</x-slot>
  <x-slot name="heading"><div class="text-xl font-bold">📦 J&T Return Reconciliation</div></x-slot>

  <div x-data="reconUI('{{ $start }}','{{ $end }}', {{ $perDate ? 'true' : 'false' }})" x-init="init()" class="space-y-4">
    {{-- Filters --}}
    <section class="bg-white rounded-xl shadow p-4">
      <div class="grid md:grid-cols-3 gap-3 items-end">
        <div class="md:col-span-2">
          <label class="block text-sm font-semibold mb-1">Date range (by signingtime)</label>
          <input id="filterRange" type="text" class="w-full border p-2 rounded bg-white cursor-pointer" readonly>
          <div class="text-xs text-gray-500 mt-1" x-text="dateLabel"></div>
        </div>

        <div class="space-y-2">
          <label class="flex items-center gap-2">
            <input type="checkbox" class="w-4 h-4" x-model="perDate">
            <span class="text-sm">Per date (expandable)</span>
          </label>

          <div class="flex gap-2 md:justify-end">
            <button class="px-3 py-2 rounded border hover:bg-gray-50" @click="thisMonth()">This month</button>
            <button class="px-3 py-2 rounded border hover:bg-gray-50" @click="yesterday()">Yesterday</button>
            <button class="px-3 py-2 rounded border hover:bg-gray-50" @click="apply()">Apply</button>
          </div>
        </div>
      </div>
    </section>

    {{-- Missing --}}
    <section class="bg-white rounded-xl shadow p-4">
      <div class="flex items-center justify-between mb-2">
        <div class="font-semibold">Missing</div>
        <div class="text-xs text-gray-500">Returned in from_jnts but NOT found in jnt_return_scanned</div>
      </div>

      {{-- Flat view: per-waybill --}}
      <div class="overflow-x-auto" x-show="!perDate">
        <table class="min-w-full text-sm border border-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-3 py-2 border-b text-left">Waybill</th>
              <th class="px-3 py-2 border-b text-left">Signing Time</th>
            </tr>
          </thead>
          <tbody>
            @forelse($missing as $r)
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

      {{-- Per-date expandable --}}
      <div class="overflow-x-auto" x-show="perDate">
        <table class="min-w-full text-sm border border-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-3 py-2 border-b text-left">Date</th>
              <th class="px-3 py-2 border-b text-right">Count</th>
              <th class="px-3 py-2 border-b"></th>
            </tr>
          </thead>
          <tbody>
            @php $dates = $byDate['dates'] ?? []; @endphp
            @forelse($dates as $d)
              @php $cnt = $byDate['counts']['missing'][$d] ?? 0; @endphp
              <tr x-data="{open:false}" class="border-b">
                <td class="px-3 py-2">{{ $d }}</td>
                <td class="px-3 py-2 text-right font-semibold">{{ number_format($cnt) }}</td>
                <td class="px-3 py-2 text-right">
                  <button class="text-xs px-2 py-1 border rounded hover:bg-gray-100" @click="open=!open" x-text="open ? 'Hide' : 'Show'"></button>
                </td>
              </tr>
              <tr x-show="open">
                <td class="px-0 py-0" colspan="3">
                  <div class="p-2 border-t bg-gray-50">
                    <table class="min-w-full text-xs">
                      <thead>
                        <tr>
                          <th class="px-3 py-2 text-left">Waybill</th>
                          <th class="px-3 py-2 text-left">Signing Time</th>
                        </tr>
                      </thead>
                      <tbody>
                        @foreach(($byDate['missing'][$d] ?? []) as $r)
                          <tr class="hover:bg-gray-100">
                            <td class="px-3 py-1">{{ $r['waybill'] }}</td>
                            <td class="px-3 py-1">{{ $r['signingtime'] }}</td>
                          </tr>
                        @endforeach
                      </tbody>
                    </table>
                  </div>
                </td>
              </tr>
            @empty
              <tr><td colspan="3" class="px-3 py-6 text-center text-gray-500">No missing items in range.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </section>

    {{-- Existing --}}
    <section class="bg-white rounded-xl shadow p-4">
      <div class="flex items-center justify-between mb-2">
        <div class="font-semibold">Existing</div>
        <div class="text-xs text-gray-500">Returned in from_jnts and found in jnt_return_scanned</div>
      </div>

      {{-- Flat view: per-waybill --}}
      <div class="overflow-x-auto" x-show="!perDate">
        <table class="min-w-full text-sm border border-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-3 py-2 border-b text-left">Waybill</th>
              <th class="px-3 py-2 border-b text-left">Signing Time</th>
              <th class="px-3 py-2 border-b text-left">Scanned At</th>
              <th class="px-3 py-2 border-b text-left">Scanned By</th>
            </tr>
          </thead>
          <tbody>
            @forelse($existing as $r)
              <tr class="hover:bg-gray-50">
                <td class="px-3 py-2 border-b">{{ $r['waybill'] }}</td>
                <td class="px-3 py-2 border-b">{{ $r['signingtime'] }}</td>
                <td class="px-3 py-2 border-b">{{ $r['scanned_at'] ?? '—' }}</td>
                <td class="px-3 py-2 border-b">{{ $r['scanned_by'] ?? '—' }}</td>
              </tr>
            @empty
              <tr><td colspan="4" class="px-3 py-6 text-center text-gray-500">No existing waybills in range.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>

      {{-- Per-date expandable --}}
      <div class="overflow-x-auto" x-show="perDate">
        <table class="min-w-full text-sm border border-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-3 py-2 border-b text-left">Date</th>
              <th class="px-3 py-2 border-b text-right">Count</th>
              <th class="px-3 py-2 border-b"></th>
            </tr>
          </thead>
          <tbody>
            @php $dates = $byDate['dates'] ?? []; @endphp
            @forelse($dates as $d)
              @php $cnt = $byDate['counts']['existing'][$d] ?? 0; @endphp
              <tr x-data="{open:false}" class="border-b">
                <td class="px-3 py-2">{{ $d }}</td>
                <td class="px-3 py-2 text-right font-semibold">{{ number_format($cnt) }}</td>
                <td class="px-3 py-2 text-right">
                  <button class="text-xs px-2 py-1 border rounded hover:bg-gray-100" @click="open=!open" x-text="open ? 'Hide' : 'Show'"></button>
                </td>
              </tr>
              <tr x-show="open">
                <td class="px-0 py-0" colspan="3">
                  <div class="p-2 border-t bg-gray-50">
                    <table class="min-w-full text-xs">
                      <thead>
                        <tr>
                          <th class="px-3 py-2 text-left">Waybill</th>
                          <th class="px-3 py-2 text-left">Signing Time</th>
                          <th class="px-3 py-2 text-left">Scanned At</th>
                          <th class="px-3 py-2 text-left">Scanned By</th>
                        </tr>
                      </thead>
                      <tbody>
                        @foreach(($byDate['existing'][$d] ?? []) as $r)
                          <tr class="hover:bg-gray-100">
                            <td class="px-3 py-1">{{ $r['waybill'] }}</td>
                            <td class="px-3 py-1">{{ $r['signingtime'] }}</td>
                            <td class="px-3 py-1">{{ $r['scanned_at'] ?? '—' }}</td>
                            <td class="px-3 py-1">{{ $r['scanned_by'] ?? '—' }}</td>
                          </tr>
                        @endforeach
                      </tbody>
                    </table>
                  </div>
                </td>
              </tr>
            @empty
              <tr><td colspan="3" class="px-3 py-6 text-center text-gray-500">No existing items in range.</td></tr>
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
    function reconUI(startDefault, endDefault, perDateDefault){
      return {
        filters: { start_date: startDefault || '', end_date: endDefault || '' },
        perDate: !!perDateDefault,
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
            end_date:   this.filters.end_date   || '',
            per_date:   this.perDate ? '1' : '0'
          });
          window.location = "{{ route('jnt.return.reconciliation') }}?" + params.toString();
        },
        thisMonth(){
          const now = new Date();
          const start = new Date(now.getFullYear(), now.getMonth(), 1);
          this.filters.start_date = this.ymd(start);
          this.filters.end_date   = this.ymd(now);
          this.setDateLabel();
          this.apply();
        },
        yesterday(){
          const now = new Date(); now.setDate(now.getDate() - 1);
          const y = this.ymd(now);
          this.filters.start_date = y; this.filters.end_date = y;
          this.setDateLabel();
          this.apply();
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
              this.setDateLabel();
              this.apply();
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
