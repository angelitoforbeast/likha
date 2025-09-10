<!doctype html>
<html lang="en" x-data="overallUI()" x-init="init()" x-cloak>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Overall Summary • Likha</title>

  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <style>
    [x-cloak]{display:none!important}
    .flatpickr-calendar{z-index:9999!important}
    body { overflow-x: hidden; }
  </style>
</head>
<body class="bg-gray-100 text-gray-900">
  <!-- Top bar -->
  <nav class="bg-white border-b sticky top-0 z-40">
    <div class="w-full mx-auto px-2 sm:px-3 lg:px-4">
      <div class="h-16 flex items-center justify-between">
        <div class="font-semibold text-lg">Overall Summary</div>
        <div class="text-sm text-gray-500" x-text="dateLabel"></div>
      </div>
    </div>
  </nav>

  <main class="w-full mx-auto px-2 sm:px-3 lg:px-4 py-4 space-y-4">
    <!-- Filters -->
    <section class="bg-white rounded-xl shadow p-3">
      <div class="grid md:grid-cols-4 gap-3 items-end">
        <div class="md:col-span-2">
          <label class="block text-sm font-semibold mb-1">Page</label>
          <select class="w-full border rounded px-3 py-2" x-model="filters.page_name" @change="reload()">
            <option value="all">All Pages</option>
            @foreach(($pages ?? []) as $p)
              <option value="{{ trim($p) }}">{{ trim($p) }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label class="block text-sm font-semibold mb-1">Date range</label>
          <input id="dateRange" type="text" placeholder="Select date range"
                 class="w-full border border-gray-300 p-2 rounded-md shadow-sm cursor-pointer bg-white" readonly>
        </div>
        <div class="flex gap-2">
          <button class="px-3 py-2 rounded border hover:bg-gray-50" @click="resetToThisMonth()">This month</button>
          <button class="px-3 py-2 rounded border hover:bg-gray-50" @click="reload()">Refresh</button>
        </div>
      </div>
    </section>

    <!-- Table -->
    <section class="bg-white rounded-xl shadow p-3">
      <div class="flex items-center justify-between mb-2">
        <div class="font-semibold">Daily Ad Spend</div>
        <div class="text-xs text-gray-500">
          Source: ads_manager_reports + macro_output + from_jnts
          (Adspent, Orders, Proceed, Cannot Proceed, ODZ, Shipped,
          <b>Delivered</b>, <b>Gross Sales</b>, <b>Returned</b>, <b>For Return</b>, <b>In Transit</b>,
          <b>CPP</b>, <b>Proceed CPP</b>, <b>RTS%</b>, <b>In Transit%</b>, TCPR)
        </div>
      </div>
      <div class="overflow-x-visible">
        <table class="min-w-full w-full text-xs table-fixed">
          <thead class="bg-gray-50">
            <tr class="text-left text-gray-600">
              <th class="px-2 py-2">Date</th>
              <th class="px-2 py-2">Page</th>
              <th class="px-2 py-2 text-right">Adspent</th>
              <th class="px-2 py-2 text-right">Orders</th>
              <th class="px-2 py-2 text-right">Proceed</th>
              <th class="px-2 py-2 text-right">Cannot Proceed</th>
              <th class="px-2 py-2 text-right">ODZ</th>
              <th class="px-2 py-2 text-right">Shipped</th>
              <th class="px-2 py-2 text-right">Delivered</th>
              <th class="px-2 py-2 text-right">Gross Sales</th><!-- NEW -->
              <th class="px-2 py-2 text-right">Returned</th>
              <th class="px-2 py-2 text-right">For Return</th>
              <th class="px-2 py-2 text-right">In Transit</th>
              <th class="px-2 py-2 text-right">CPP</th>
              <th class="px-2 py-2 text-right">Proceed CPP</th>
              <th class="px-2 py-2 text-right">RTS%</th>
              <th class="px-2 py-2 text-right">In Transit%</th>
              <th class="px-2 py-2 text-right">TCPR</th>
            </tr>
          </thead>
          <tbody>
            <template x-if="!data.ads_daily || data.ads_daily.length===0">
              <tr class="border-t">
                <td class="px-3 py-3 text-gray-500" colspan="18">No data for selected filters.</td>
              </tr>
            </template>

            <template x-for="row in data.ads_daily" :key="(row.date ?? '') + '|' + (row.page ?? '') + '|' + (row.is_total?'1':'0')">
              <tr class="border-t" :class="row.is_total ? 'bg-gray-50 font-semibold' : 'hover:bg-gray-50'">
                <td class="px-2 py-2" x-text="row.date"></td>
                <td class="px-2 py-2" x-text="row.page ?? '—'"></td>
                <td class="px-2 py-2 text-right" x-text="money(row.adspent)"></td>
                <td class="px-2 py-2 text-right" x-text="num(row.orders)"></td>
                <td class="px-2 py-2 text-right" x-text="num(row.proceed)"></td>
                <td class="px-2 py-2 text-right" x-text="num(row.cannot_proceed)"></td>
                <td class="px-2 py-2 text-right" x-text="num(row.odz)"></td>
                <td class="px-2 py-2 text-right" x-text="num(row.shipped)"></td>
                <td class="px-2 py-2 text-right" x-text="num(row.delivered)"></td>
                <td class="px-2 py-2 text-right" x-text="money(row.gross_sales)"></td><!-- NEW -->
                <td class="px-2 py-2 text-right" x-text="num(row.returned)"></td>
                <td class="px-2 py-2 text-right" x-text="num(row.for_return)"></td>
                <td class="px-2 py-2 text-right" x-text="num(row.in_transit)"></td>
                <td class="px-2 py-2 text-right" x-text="moneyOrDash(row.cpp)"></td>
                <td class="px-2 py-2 text-right" x-text="moneyOrDash(row.proceed_cpp)"></td>
                <td class="px-2 py-2 text-right">
                  <span class="px-2 py-0.5 rounded text-[10px]"
                        :class="rtsClass(row.rts_pct)"
                        x-text="percent(row.rts_pct)">
                  </span>
                </td>
                <td class="px-2 py-2 text-right" x-text="percent(row.in_transit_pct)"></td>
                <td class="px-2 py-2 text-right">
                  <span class="px-2 py-0.5 rounded text-[10px]"
                        :class="tcprClass(row.tcpr)"
                        x-text="percent(row.tcpr)">
                  </span>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </section>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
  <script>
    function overallUI(){
      return {
        data: { ads_daily: [] },
        filters: { page_name: 'all', start_date: '', end_date: '' },
        dateLabel: 'Select dates',

        money(v){ return `₱${Number(v||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2})}`; },
        moneyOrDash(v){ return (v==null || isNaN(v)) ? '—' : this.money(v); },
        num(v){ return Number(v||0).toLocaleString('en-PH'); },
        percent(v){ return (v==null || isNaN(v)) ? '—' : (Number(v).toFixed(2) + '%'); },
        ymd(d){ const p=n=>String(n).padStart(2,'0'); return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate()); },

        tcprClass(pct){
          if (pct == null || isNaN(pct)) return '';
          if (pct > 7) return 'bg-red-100 text-red-800';
          if (pct > 5) return 'bg-orange-100 text-orange-800';
          if (pct > 3) return 'bg-yellow-100 text-yellow-800';
          return '';
        },
        // RTS% conditional formatting: >25% yellow, >30% orange, >35% red
        rtsClass(pct){
          if (pct == null || isNaN(pct)) return '';
          if (pct > 35) return 'bg-red-100 text-red-800';
          if (pct > 30) return 'bg-orange-100 text-orange-800';
          if (pct > 25) return 'bg-yellow-100 text-yellow-800';
          return '';
        },

        setDateLabel(){
          if (!this.filters.start_date || !this.filters.end_date) { this.dateLabel = 'Select dates'; return; }
          const s = new Date(this.filters.start_date+'T00:00:00');
          const e = new Date(this.filters.end_date+'T00:00:00');
          const same = s.getTime()===e.getTime();
          const M = i => ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][i];
          this.dateLabel = same
            ? `${M(s.getMonth())} ${s.getDate()}, ${s.getFullYear()}`
            : `${M(s.getMonth())} ${s.getDate()}, ${s.getFullYear()} – ${M(e.getMonth())} ${e.getDate()}, ${e.getFullYear()}`;
        },

        async reload(){
          const params = new URLSearchParams({
            page_name: this.filters.page_name || 'all',
            start_date: this.filters.start_date || '',
            end_date: this.filters.end_date || ''
          });
          const res  = await fetch('{{ route('summary.overall.data') }}?'+params.toString());
          const json = await res.json();
          this.data = json;
        },

        resetToThisMonth(){
          const now = new Date();
          const start = new Date(now.getFullYear(), now.getMonth(), 1);
          this.filters.start_date = this.ymd(start);
          this.filters.end_date   = this.ymd(now);
          this.setDateLabel();
          this.reload();
        },

        async init(){
          const now   = new Date();
          const start = new Date(now.getFullYear(), now.getMonth(), 1);
          this.filters.start_date = this.ymd(start);
          this.filters.end_date   = this.ymd(now);
          this.setDateLabel();

          this.$nextTick(() => {
            window.flatpickr('#dateRange', {
              mode: 'range',
              dateFormat: 'Y-m-d',
              defaultDate: [this.filters.start_date, this.filters.end_date],
              onClose: (sel) => {
                if (sel.length === 2) {
                  this.filters.start_date = this.ymd(sel[0]);
                  this.filters.end_date   = this.ymd(sel[1]);
                } else if (sel.length === 1) {
                  this.filters.start_date = this.ymd(sel[0]);
                  this.filters.end_date   = this.ymd(sel[0]);
                } else { return; }
                this.setDateLabel();
                this.reload();
              },
              onReady: (_sd, _ds, inst) => {
                inst.input.value = `${this.filters.start_date} to ${this.filters.end_date}`;
              }
            });
          });

          await this.reload();
        }
      }
    }
  </script>
</body>
</html>
