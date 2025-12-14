<x-layout>
  <x-slot name="title">J&T Return Inventory • Likha</x-slot>
  <x-slot name="heading"><div class="text-xl font-bold">📦 J&T Return Inventory</div></x-slot>

  <div x-data="invUI('{{ $start }}','{{ $end }}','{{ $mode }}')" x-init="init()" class="space-y-4">
    {{-- Filters --}}
    <section class="bg-white rounded-xl shadow p-4">
      <div class="grid md:grid-cols-3 gap-3 items-end">
        <div class="md:col-span-2">
          <label class="block text-sm font-semibold mb-1">Date range (by <b>scanned_at</b>)</label>
          <input id="dr" type="text" class="w-full border p-2 rounded bg-white cursor-pointer" readonly>
          <div class="text-xs text-gray-500 mt-1" x-text="dateLabel"></div>
        </div>

        <div class="space-y-2">
          <label class="flex items-center gap-2">
            <input type="checkbox" class="w-4 h-4" x-model="raw" @change="apply()">
            <span class="text-sm">Use raw item names</span>
          </label>
          <div class="flex gap-2 md:justify-end">
            <button class="px-3 py-2 rounded border hover:bg-gray-50" @click="thisMonth()">This month</button>
            <button class="px-3 py-2 rounded border hover:bg-gray-50" @click="yesterday()">Yesterday</button>
            <button class="px-3 py-2 rounded border hover:bg-gray-50" @click="apply()">Apply</button>
          </div>
        </div>
      </div>
    </section>

    {{-- Inventory by Item (per day) --}}
    <section class="bg-white rounded-xl shadow p-4">
      {{-- Header + Copy Button --}}
      <div class="flex items-center justify-between gap-2 mb-2">
        <div class="font-semibold">
          Existing by Item (counts parsed from item_name {{ $mode === 'raw' ? 'RAW' : 'MODIFIED' }})
        </div>

        <button
          class="px-3 py-2 rounded border hover:bg-gray-50 text-sm"
          @click="copyInventory()"
          x-text="copied ? 'Copied ✅' : 'Copy (TSV)'"
        ></button>
      </div>

      <div class="overflow-x-auto">
        <table class="min-w-full text-sm border border-gray-200">
          <thead>
            {{-- Row 1: Merged months --}}
            <tr class="bg-gray-50">
              <th class="px-3 py-2 border-b text-left align-bottom" rowspan="2" style="min-width:240px">Item Name</th>
              @foreach($monthSegments as $seg)
                <th class="px-3 py-2 border-b text-center" colspan="{{ $seg['span'] }}">{{ $seg['label'] }}</th>
              @endforeach
              <th class="px-3 py-2 border-b text-right align-bottom" rowspan="2" style="min-width:100px">Total</th>
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
            @forelse($perItem as $label => $rec)
              <tr class="hover:bg-gray-50">
                <td class="px-3 py-2 border-b text-left">{{ $label }}</td>
                @foreach($dates as $d)
                  <td class="px-2 py-2 border-b text-right">
                    {{ $rec['per'][$d] ? number_format($rec['per'][$d]) : '' }}
                  </td>
                @endforeach
                <td class="px-3 py-2 border-b text-right font-semibold">{{ number_format($rec['total']) }}</td>
              </tr>
            @empty
              <tr><td colspan="{{ 2 + count($dates) }}" class="px-3 py-6 text-center text-gray-500">No scanned returns in range.</td></tr>
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
    function invUI(startDefault, endDefault, modeDefault){
      return {
        filters: { start_date: startDefault || '', end_date: endDefault || '' },
        raw: (modeDefault === 'raw'),
        dateLabel: 'Select dates',

        // ✅ for copy function
        dates: @json($dates),
        copied: false,

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

        // ✅ copy table as TSV (paste-ready for Google Sheets)
        copyInventory(){
          // safest: scope to THIS section's table
          const table = document.querySelector('section.bg-white.rounded-xl.shadow.p-4 table');
          if (!table) return;

          const header = ['Item Name', ...this.dates, 'Total'];
          const lines = [header.join('\t')];

          table.querySelectorAll('tbody tr').forEach(tr => {
            const tds = Array.from(tr.querySelectorAll('td'));
            // skip "No scanned returns..." row
            if (tds.length < 2) return;

            const row = tds.map(td => td.textContent.trim().replace(/\s+/g,' '));
            lines.push(row.join('\t'));
          });

          const text = lines.join('\n');

          const done = () => {
            this.copied = true;
            setTimeout(() => this.copied = false, 1200);
          };

          // clipboard with fallback
          if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done).catch(() => {
              const ta = document.createElement('textarea');
              ta.value = text;
              document.body.appendChild(ta);
              ta.select();
              document.execCommand('copy');
              document.body.removeChild(ta);
              done();
            });
          } else {
            const ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            done();
          }
        },

        apply(){
          const params = new URLSearchParams({
            start_date: this.filters.start_date || '',
            end_date:   this.filters.end_date   || '',
            mode:       this.raw ? 'raw' : 'mod'
          });
          window.location = "{{ route('jnt.return.inventory') }}?" + params.toString();
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
          flatpickr('#dr', {
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
