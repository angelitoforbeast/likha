<!doctype html>
<html lang="en" x-data="overallUI({{ ($isCEO ?? false) ? 'true' : 'false' }}, {{ ($isMarketingOIC ?? false) ? 'true' : 'false' }})" x-init="init()" x-cloak>
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
      <div class="grid md:grid-cols-6 gap-3 items-end">
        <div class="md:col-span-2">
          <label class="block text-sm font-semibold mb-1">Page</label>
          <select class="w-full border rounded px-3 py-2" x-model="filters.page_name" @change="reload()">
            <option value="all">All Pages</option>
            @foreach(($pages ?? []) as $p)
              <option value="{{ trim($p) }}">{{ trim($p) }}</option>
            @endforeach
          </select>
        </div>

        <div class="md:col-span-2">
          <label class="block text-sm font-semibold mb-1">Date range</label>
          <input id="dateRange" type="text" placeholder="Select date range"
                 class="w-full border border-gray-300 p-2 rounded-md shadow-sm cursor-pointer bg-white" readonly>
        </div>

        <template x-if="isCEO">
          <div class="flex items-center gap-2 mt-6 md:mt-0">
            <input id="showAllColumns" type="checkbox" class="w-4 h-4" x-model="showAllColumns">
            <label for="showAllColumns" class="text-sm">Show all columns</label>
          </div>
        </template>

        <div class="flex items-center gap-2 mt-6 md:mt-0">
          <input id="showAllRows" type="checkbox" class="w-4 h-4" x-model="showAllRows">
          <label for="showAllRows" class="text-sm">Show all rows</label>
        </div>

        <div class="flex gap-2 md:col-span-6">
          <button class="px-3 py-2 rounded border hover:bg-gray-50" @click="resetToThisMonth()">This month</button>
          <button class="px-3 py-2 rounded border hover:bg-gray-50" @click="reload()">Refresh</button>
        </div>
      </div>
    </section>

    <!-- ===== NEW: Page Summary (visible only when Page != 'All') ===== -->
    <section class="bg-white rounded-xl shadow p-3" x-show="filters.page_name !== 'all'">
      <div class="flex items-center justify-between mb-2">
        <div class="font-semibold">Page Summary</div>
        <div class="text-xs text-gray-500">Computed on the client from the daily rows</div>
      </div>

      <div class="overflow-x-auto">
        <table class="min-w-full text-xs table-fixed">
          <thead class="bg-gray-50">
            <tr class="text-left text-gray-600">
              <th class="px-2 py-2 w-[200px]">Date Range</th>
              <th class="px-2 py-2">Page</th>
              <th class="px-2 py-2 text-right">Adspent</th>
              <th class="px-2 py-2 text-right">Proceed CPP</th>
              <th class="px-2 py-2 text-right">RTS</th>
              <th class="px-2 py-2 text-right">Projected Net Profit(%)</th>
            </tr>
          </thead>
          <tbody>
            <template x-for="row in topSummaryRows()" :key="row.key">
              <tr class="border-t hover:bg-gray-50">
                <td class="px-2 py-2" x-text="row.rangeLabel"></td>
                <td class="px-2 py-2" x-text="filters.page_name"></td>
                <td class="px-2 py-2 text-right" x-text="moneyOrDash(row.adspent)"></td>
                <td class="px-2 py-2 text-right" x-text="moneyOrDash(row.proceed_cpp)"></td>
                <td class="px-2 py-2 text-right" x-text="percent(data.actual_rts_pct)"></td>
                <td class="px-2 py-2 text-right">
                  <span class="px-2 py-0.5 rounded font-bold"
                        :class="netClass(row.pn_pct)"
                        :style="netStyle(row.pn_pct)"
                        x-text="percent(row.pn_pct)"></span>
                </td>
              </tr>
            </template>
            <template x-if="topSummaryRows().length===0">
              <tr class="border-t"><td class="px-2 py-2 text-gray-500" colspan="6">No data.</td></tr>
            </template>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Daily Table -->
    <section class="bg-white rounded-xl shadow p-3">
      <div class="flex items-center justify-between mb-2">
        <div class="font-semibold">Daily Ad Spend</div>
        <div class="text-xs text-gray-500">
          Source: ads_manager_reports + macro_output + from_jnts + cogs
        </div>
      </div>

      <div class="overflow-x-visible">
        <!-- LIMITED COLUMNS (everyone sees this) -->
        <table class="min-w-full w-full text-xs table-fixed" x-show="!isCEO || (isCEO && !showAllColumns)">
          <thead class="bg-gray-50 sticky top-16 z-20">
            <tr class="text-left text-gray-600">
              <th class="px-2 py-2">Date</th>
              <th class="px-2 py-2">Page</th>
              <th class="px-2 py-2">Items</th>
              <th class="px-2 py-2 text-right">Unit Cost</th>
              <th class="px-2 py-2 text-right">Adspent</th>
              <th class="px-2 py-2 text-right">Orders</th>
              <th class="px-2 py-2 text-right">Proceed CPP</th>
              <th class="px-2 py-2 text-right">Shipped</th>
              <th class="px-2 py-2 text-right">Delay</th>
              <th class="px-2 py-2 text-right">Hold</th>
              <th class="px-2 py-2 text-right">RTS%</th>
              <th class="px-2 py-2 text-right">In Transit%</th>
              <th class="px-2 py-2 text-right">TCPR</th>
              <th class="px-2 py-2 text-right">Net Profit(%)</th>
              <th class="px-2 py-2 text-right">Projected Net Profit(%)</th>
            </tr>
          </thead>
          <tbody>
            <template x-if="rowsForDisplay(data.ads_daily).length===0">
              <tr class="border-t">
                <td class="px-3 py-3 text-gray-500" colspan="15">No data for selected filters.</td>
              </tr>
            </template>

            <template x-for="row in rowsForDisplay(data.ads_daily)" :key="(row.date ?? '') + '|' + (row.page ?? '') + '|' + (row.is_total?'1':'0')">
              <tr class="border-t" :class="row.is_total ? 'bg-gray-50 font-semibold' : 'hover:bg-gray-50'">
                <td class="px-2 py-2" x-text="row.date"></td>
                <td class="px-2 py-2" x-text="row.page ?? '—'"></td>
                <td class="px-2 py-2"><span x-text="row.items_display || '—'"></span></td>
                <td class="px-2 py-2 text-right"><span x-text="moneyList(row.unit_costs)"></span></td>
                <td class="px-2 py-2 text-right" x-text="money(row.adspent)"></td>
                <td class="px-2 py-2 text-right" x-text="num(row.orders)"></td>
                <td class="px-2 py-2 text-right" x-text="moneyOrDash(row.proceed_cpp)"></td>
                <td class="px-2 py-2 text-right" x-text="num(row.shipped)"></td>
                <td class="px-2 py-2 text-right" x-text="days(row.avg_delay_days)"></td>
                <td class="px-2 py-2 text-right" x-text="num(row.hold)"></td>
                <td class="px-2 py-2 text-right"><span class="px-2 py-0.5 rounded" :class="rtsClass(row.rts_pct)" x-text="percent(row.rts_pct)"></span></td>
                <td class="px-2 py-2 text-right" x-text="percent(row.in_transit_pct)"></td>
                <td class="px-2 py-2 text-right"><span class="px-2 py-0.5 rounded" :class="tcprClass(row.tcpr)" x-text="percent(row.tcpr)"></span></td>
                <td class="px-2 py-2 text-right">
                  <span class="px-2 py-0.5 rounded font-bold"
                        :class="netClass(row.net_profit_pct)"
                        :style="netStyle(row.net_profit_pct)"
                        x-text="percent(row.net_profit_pct)"></span>
                </td>
                <td class="px-2 py-2 text-right">
                  <span class="px-2 py-0.5 rounded font-bold"
                        :class="netClass(projectedPct(row))"
                        :style="netStyle(projectedPct(row))"
                        x-text="percent(projectedPct(row))"></span>
                </td>
              </tr>
            </template>
          </tbody>
        </table>

        <!-- FULL COLUMNS (CEO) -->
        <table class="min-w-full w-full text-xs table-fixed" x-show="isCEO && showAllColumns">
          <thead class="bg-gray-50 sticky top-16 z-20">
            <tr class="text-left text-gray-600">
              <th class="px-2 py-2">Date</th>
              <th class="px-2 py-2">Page</th>
              <th class="px-2 py-2">Items</th>
              <th class="px-2 py-2 text-right">Unit Cost</th>
              <th class="px-2 py-2 text-right">Adspent</th>
              <th class="px-2 py-2 text-right">Orders</th>
              <th class="px-2 py-2 text-right">Proceed CPP</th>
              <th class="px-2 py-2 text-right">Shipped</th>
              <th class="px-2 py-2 text-right">Delay</th>
              <th class="px-2 py-2 text-right">Hold</th>
              <th class="px-2 py-2 text-right">RTS%</th>
              <th class="px-2 py-2 text-right">In Transit%</th>
              <th class="px-2 py-2 text-right">TCPR</th>
              <th class="px-2 py-2 text-right">Net Profit(%)</th>
              <th class="px-2 py-2 text-right">Proceed</th>
              <th class="px-2 py-2 text-right">Cannot Proceed</th>
              <th class="px-2 py-2 text-right">ODZ</th>
              <th class="px-2 py-2 text-right">Delivered</th>
              <th class="px-2 py-2 text-right">Gross Sales</th>
              <th class="px-2 py-2 text-right">Shipping Fee</th>
              <th class="px-2 py-2 text-right">COGS</th>
              <th class="px-2 py-2 text-right">Net Profit</th>
              <th class="px-2 py-2 text-right">Returned</th>
              <th class="px-2 py-2 text-right">For Return</th>
              <th class="px-2 py-2 text-right">In Transit</th>
              <th class="px-2 py-2 text-right">CPP</th>
              <th class="px-2 py-2 text-right">Projected Net Profit</th>
              <th class="px-2 py-2 text-right">Projected Net Profit(%)</th>
            </tr>
          </thead>
          <tbody>
            <template x-if="rowsForDisplay(data.ads_daily).length===0">
              <tr class="border-t">
                <td class="px-3 py-3 text-gray-500" colspan="28">No data for selected filters.</td>
              </tr>
            </template>

            <template x-for="row in rowsForDisplay(data.ads_daily)" :key="(row.date ?? '') + '|' + (row.page ?? '') + '|' + (row.is_total?'1':'0')">
              <tr class="border-t" :class="row.is_total ? 'bg-gray-50 font-semibold' : 'hover:bg-gray-50'">
                <td class="px-2 py-2" x-text="row.date"></td>
                <td class="px-2 py-2" x-text="row.page ?? '—'"></td>
                <td class="px-2 py-2"><span x-text="row.items_display || '—'"></span></td>
                <td class="px-2 py-2 text-right"><span x-text="moneyList(row.unit_costs)"></span></td>
                <td class="px-2 py-2 text-right" x-text="money(row.adspent)"></td>
                <td class="px-2 py-2 text-right" x-text="num(row.orders)"></td>
                <td class="px-2 py-2 text-right" x-text="moneyOrDash(row.proceed_cpp)"></td>
                <td class="px-2 py-2 text-right" x-text="num(row.shipped)"></td>
                <td class="px-2 py-2 text-right" x-text="days(row.avg_delay_days)"></td>
                <td class="px-2 py-2 text-right" x-text="num(row.hold)"></td>
                <td class="px-2 py-2 text-right"><span class="px-2 py-0.5 rounded" :class="rtsClass(row.rts_pct)" x-text="percent(row.rts_pct)"></span></td>
                <td class="px-2 py-2 text-right" x-text="percent(row.in_transit_pct)"></td>
                <td class="px-2 py-2 text-right"><span class="px-2 py-0.5 rounded" :class="tcprClass(row.tcpr)" x-text="percent(row.tcpr)"></span></td>
                <td class="px-2 py-2 text-right">
                  <span class="px-2 py-0.5 rounded font-bold"
                        :class="netClass(row.net_profit_pct)"
                        :style="netStyle(row.net_profit_pct)"
                        x-text="percent(row.net_profit_pct)"></span>
                </td>

                <td class="px-2 py-2 text-right" x-text="num(row.proceed)"></td>
                <td class="px-2 py-2 text-right" x-text="num(row.cannot_proceed)"></td>
                <td class="px-2 py-2 text-right" x-text="num(row.odz)"></td>
                <td class="px-2 py-2 text-right" x-text="num(row.delivered)"></td>
                <td class="px-2 py-2 text-right" x-text="money(row.gross_sales)"></td>
                <td class="px-2 py-2 text-right" x-text="money(row.shipping_fee)"></td>
                <td class="px-2 py-2 text-right" x-text="money(row.cogs)"></td>
                <td class="px-2 py-2 text-right" x-text="money(row.net_profit)"></td>
                <td class="px-2 py-2 text-right" x-text="num(row.returned)"></td>
                <td class="px-2 py-2 text-right" x-text="num(row.for_return)"></td>
                <td class="px-2 py-2 text-right" x-text="num(row.in_transit)"></td>
                <td class="px-2 py-2 text-right" x-text="moneyOrDash(row.cpp)"></td>
                <td class="px-2 py-2 text-right" x-text="(filters.page_name !== 'all') ? moneyOrDash(row.projected_net_profit) : '—'"></td>

                <td class="px-2 py-2 text-right">
                  <template x-if="filters.page_name !== 'all'">
                    <span class="px-2 py-0.5 rounded font-bold"
                          :class="netClass(projectedPct(row))"
                          :style="netStyle(projectedPct(row))"
                          x-text="percent(projectedPct(row))"></span>
                  </template>
                  <template x-if="filters.page_name === 'all'">—</template>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Actual RTS box (only when page != 'all') -->
    <section class="bg-white rounded-xl shadow p-4" x-show="filters.page_name !== 'all'">
      <div class="flex items-center justify-between">
        <div>
          <div class="font-semibold">Actual RTS</div>
          <div class="text-xs text-gray-500">Computed only on dates with &lt; 3% In Transit</div>
        </div>
        <div class="text-2xl md:text-3xl font-extrabold" x-text="percent(data.actual_rts_pct)"></div>
      </div>
    </section>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
  <script>
    function overallUI(isCEO=false, isMarketingOIC=false){
      return {
        // role/flags
        isCEO,
        isMarketingOIC,

        // toggles
        showAllColumns: false,
        showAllRows: false,

        // data / filters
        data: { ads_daily: [], actual_rts_pct: null },
        filters: { page_name: 'all', start_date: '', end_date: '' },
        dateLabel: 'Select dates',

        // formatting helpers
        money(v){ return `₱${Number(v||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2})}`; },
        moneyOrDash(v){ return (v==null || isNaN(v)) ? '—' : this.money(v); },
        num(v){ return Number(v||0).toLocaleString('en-PH'); },
        percent(v){ return (v==null || isNaN(v)) ? '—' : (Number(v).toFixed(2) + '%'); },
        ymd(d){ const p=n=>String(n).padStart(2,'0'); return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate()); },
        days(v){ return (v==null || isNaN(v)) ? '—' : Number(v).toFixed(2); },
        moneyList(list){
          if (!Array.isArray(list) || list.length===0) return '—';
          return list.map(v => this.money(v)).join(', ');
        },

        // === Daily Projected Net Profit (%) for the row ===
        // Use server-provided pct if present; else derive from available numerators/denominators.
        projectedPct(row){
          const pre = row?.projected_net_profit_pct;
          if (pre != null && !isNaN(pre)) return Number(pre);

          const npProj = row?.projected_net_profit;
          if (npProj != null && !isNaN(npProj)) {
            // Prefer a matching denominator for "projected" if available on the row:
            if (row?.proceed_cod != null && !isNaN(row.proceed_cod) && Number(row.proceed_cod) !== 0) {
              return (Number(npProj) / Number(row.proceed_cod)) * 100.0;
            }
            // Fallback: if we have a displayed percentage on the row (rare), keep consistency:
            if (row?.net_profit_pct != null && !isNaN(row.net_profit_pct)) {
              // If only actual NP% exists, we can't mix with projected NP directly; skip.
            }
          }

          // Last resort: use actual NP ratio if projected not available,
          // to avoid blanks (still consistent for weighted agg below).
          const npActual = row?.net_profit;
          if (npActual != null && !isNaN(npActual)) {
            if (row?.all_cod != null && !isNaN(row.all_cod) && Number(row.all_cod) !== 0) {
              return (Number(npActual) / Number(row.all_cod)) * 100.0;
            }
          }
          return null;
        },

        // ===== NEW: TOP SUMMARY (client-side only) =====
        topSummaryRows(){
          if (this.filters.page_name === 'all') return [];
          const rows = (this.data?.ads_daily ?? []).filter(r => !r.is_total);

          if (!rows.length) return [];

          // Collect unique dates and sort asc
          const dates = Array.from(new Set(rows.map(r => r.date))).sort();
          const lastDate = dates[dates.length - 1];

          // Helpers to pick subsets
          const lastNDates = (n) => dates.slice(-n);
          const rowsOnDates = (dset) => rows.filter(r => dset.includes(r.date));
          const allRows = rows;

          // Labels
          const fmt = (s) => {
            const d = new Date(s+'T00:00:00');
            const M = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][d.getMonth()];
            return `${M} ${d.getDate()}`;
          };
          const isToday = (() => {
            const t = new Date();
            const ld = new Date(lastDate+'T00:00:00');
            return t.getFullYear()===ld.getFullYear() && t.getMonth()===ld.getMonth() && t.getDate()===ld.getDate();
          })();

          const filteredLabel = this.dateLabel; // already like “Aug 14, 2024 – Sep 13, 2024”
          const last7Label = 'Last 7 Days';
          const last3Label = 'Last 3 Days';
          const last1Label = isToday ? 'Today' : `Last Day (${fmt(lastDate)})`;

          // Aggregator: sums + weighted PN% using consistent weights
          const aggregate = (subset) => {
            if (!subset.length) return { adspent: null, proceed_cpp: null, pn_pct: null };

            // sums
            const adSum = subset.reduce((s,r)=>s + (Number(r.adspent)||0), 0);
            const procSum = subset.reduce((s,r)=>s + (Number(r.proceed)||0), 0);
            const proceedCPP = (procSum>0) ? (adSum / procSum) : null;

            // Weighted PN%
            // numerator: use projected NP if present, else actual NP (to avoid blanks)
            // weight: prefer proceed_cod; else implied denom from row-level % shown; else implied from actual
            let num = 0, den = 0;
            subset.forEach(r => {
              const np = (r.projected_net_profit!=null && !isNaN(r.projected_net_profit))
                         ? Number(r.projected_net_profit)
                         : (r.net_profit!=null && !isNaN(r.net_profit) ? Number(r.net_profit) : null);
              if (np==null) return;

              // Weight detection
              let w = null;
              if (r.proceed_cod!=null && !isNaN(r.proceed_cod) && Number(r.proceed_cod)>0) {
                w = Number(r.proceed_cod);
              } else {
                // Try implied denom from projected % shown in UI (consistent with daily)
                const p = this.projectedPct(r);
                if (p!=null && !isNaN(p) && Number(p)!==0) {
                  w = np / (Number(p)/100.0);
                } else if (r.net_profit_pct!=null && !isNaN(r.net_profit_pct) && Number(r.net_profit_pct)!==0) {
                  w = Number(r.net_profit) / (Number(r.net_profit_pct)/100.0);
                }
              }
              if (w!=null && !isNaN(w) && w>0) { num += np; den += w; }
            });
            const pnPct = (den>0) ? (num/den)*100.0 : null;

            return { adspent: adSum, proceed_cpp: proceedCPP, pn_pct: pnPct };
          };

          const rowsLast7 = rowsOnDates(lastNDates(7));
          const rowsLast3 = rowsOnDates(lastNDates(3));
          const rowsLast1 = rowsOnDates([lastDate]);

          return [
            { key:'filtered', rangeLabel: filteredLabel.replace(/, \d{4}/g,'').replaceAll(',', ''), ...aggregate(allRows) },
            { key:'last7',   rangeLabel: last7Label, ...aggregate(rowsLast7) },
            { key:'last3',   rangeLabel: last3Label, ...aggregate(rowsLast3) },
            { key:'last1',   rangeLabel: last1Label, ...aggregate(rowsLast1) },
          ];
        },

        // conditional formatting
        tcprClass(pct){
          if (pct == null || isNaN(pct)) return '';
          if (pct > 7) return 'bg-red-100 text-red-800';
          if (pct > 5) return 'bg-orange-100 text-orange-800';
          if (pct > 3) return 'bg-yellow-100 text-yellow-800';
          return '';
        },
        rtsClass(pct){
          if (pct == null || isNaN(pct)) return '';
          if (pct > 35) return 'bg-red-100 text-red-800';
          if (pct > 30) return 'bg-orange-100 text-orange-800';
          if (pct > 25) return 'bg-yellow-100 text-yellow-800';
          return '';
        },
        netClass(pct){
          if (pct == null || isNaN(pct)) return '';
          if (pct < 0)  return 'bg-red-100 text-red-800';
          if (pct < 5)  return 'bg-orange-100 text-orange-800';
          if (pct < 10) return 'bg-yellow-100 text-yellow-800';
          if (pct < 15) return 'bg-blue-100 text-blue-800';
          return '';
        },
        netStyle(pct){
          if (pct == null || isNaN(pct)) return {};
          if (pct >= 15) {
            return { backgroundColor: '#00ff00', color: '#052e16' };
          }
          return {};
        },

        rowsForDisplay(rows){
          if (!Array.isArray(rows)) return [];
          return this.showAllRows ? rows : rows.filter(r => r.is_total);
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
            end_date: this.filters.end_date   || ''
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
          // Default to last 30 days (including today)
          const now = new Date();
          const start = new Date(now);
          start.setDate(now.getDate() - 29); // last 30 days inclusive
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
