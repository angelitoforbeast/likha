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
    /* Loading spinner */
    .spinner { border: 3px solid #e5e7eb; border-top: 3px solid #3b82f6; border-radius: 50%; width: 24px; height: 24px; animation: spin 0.8s linear infinite; display: inline-block; }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    /* Loading spinner inline */
    .spinner-inline { border: 2px solid #e5e7eb; border-top: 2px solid #3b82f6; border-radius: 50%; width: 18px; height: 18px; animation: spin 0.8s linear infinite; display: inline-block; vertical-align: middle; }
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

        <!-- Quick Date Filter Buttons -->
        <div class="flex flex-wrap gap-2 md:col-span-6">
          <button
            :class="activePreset === 'today' ? 'bg-blue-600 text-white ring-2 ring-blue-300' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
            class="px-3 py-2 rounded text-sm font-medium transition-colors"
            @click="setPreset('today')">Today</button>
          <button
            :class="activePreset === 'yesterday' ? 'bg-blue-600 text-white ring-2 ring-blue-300' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
            class="px-3 py-2 rounded text-sm font-medium transition-colors"
            @click="setPreset('yesterday')">Yesterday</button>
          <button
            :class="activePreset === 'this_week' ? 'bg-blue-600 text-white ring-2 ring-blue-300' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
            class="px-3 py-2 rounded text-sm font-medium transition-colors"
            @click="setPreset('this_week')">This Week</button>
          <button
            :class="activePreset === 'last_7_days' ? 'bg-blue-600 text-white ring-2 ring-blue-300' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
            class="px-3 py-2 rounded text-sm font-medium transition-colors"
            @click="setPreset('last_7_days')">Last 7 Days</button>
          <button
            :class="activePreset === 'this_month' ? 'bg-blue-600 text-white ring-2 ring-blue-300' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
            class="px-3 py-2 rounded text-sm font-medium transition-colors"
            @click="setPreset('this_month')">This Month</button>
          <button
            :class="activePreset === 'last_month' ? 'bg-blue-600 text-white ring-2 ring-blue-300' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'"
            class="px-3 py-2 rounded text-sm font-medium transition-colors"
            @click="setPreset('last_month')">Last Month</button>
          <button class="px-3 py-2 rounded border hover:bg-gray-50 text-sm" @click="reload()">Refresh</button>
          <span x-show="isLoading" x-transition.opacity class="inline-flex items-center gap-1">
            <span class="spinner-inline"></span>
            <span class="text-sm text-gray-500">Loading...</span>
          </span>
        </div>
      </div>
    </section>

    <!-- KPI row: Actual RTS + Target CPP + Breakeven CPP (top, single line) -->
    <section class="grid grid-cols-1 md:grid-cols-3 gap-3" x-show="filters.page_name !== 'all'">
      <!-- Actual RTS -->
      <div class="bg-white rounded-xl shadow p-4 flex items-center justify-between">
        <div>
          <div class="font-semibold">Actual RTS</div>
          <div class="text-xs text-gray-500">Computed only on dates with &lt; 3% In Transit</div>
        </div>
        <div class="text-2xl md:text-3xl font-extrabold" x-text="percent(data.actual_rts_pct)"></div>
      </div>
      <!-- Target CPP -->
      <div class="bg-white rounded-xl shadow p-4 flex items-center justify-between">
        <div>
          <div class="font-semibold">Target CPP</div>
          <div class="text-xs text-gray-500">(1-RTS)·(0.985·COD - Unit) - 0.2·COD - 37</div>
        </div>
        <div class="text-2xl md:text-3xl font-extrabold" x-text="moneyOrDash(data.target_cpp)"></div>
      </div>
      <!-- Breakeven CPP -->
      <div class="bg-white rounded-xl shadow p-4 flex items-center justify-between">
        <div>
          <div class="font-semibold">Breakeven CPP</div>
          <div class="text-xs text-gray-500">(1-RTS)·(0.985·COD - Unit) - 0.05·COD - 37</div>
        </div>
        <div class="text-2xl md:text-3xl font-extrabold" x-text="moneyOrDash(data.breakeven_cpp)"></div>
      </div>
    </section>

    <!-- ===== Page Summary (server-side) ===== -->
    <section class="bg-white rounded-xl shadow p-3" x-show="filters.page_name !== 'all'">
      <div class="flex items-center justify-between mb-2">
        <div class="font-semibold">Page Summary</div>
        <div class="text-xs text-gray-500">Computed on the server</div>
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
            <template x-for="row in (data.top_summary || [])" :key="row.key">
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
            <template x-if="!(data.top_summary && data.top_summary.length)">
              <tr class="border-t"><td class="px-2 py-2 text-gray-500" colspan="6">No data.</td></tr>
            </template>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Daily Table -->
    <section class="bg-white rounded-xl shadow p-3 relative">

      <div class="flex items-center justify-between mb-2">
        <div class="font-semibold">Daily Ad Spend</div>
        <div class="text-xs text-gray-500">
          Source: ads_manager_reports + macro_output + from_jnts + cogs
        </div>
      </div>

      <div class="overflow-x-visible">
        <!-- LIMITED COLUMNS -->
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
              <th class="px-2 py-2 text-right">Actual RTS%</th>
              <th class="px-2 py-2 text-right">In Transit%</th>
              <th class="px-2 py-2 text-right">TCPR</th>
              <th class="px-2 py-2 text-right">Net Profit(%)</th>
              <th class="px-2 py-2 text-right">Projected Net Profit(%)</th>
            </tr>
          </thead>
          <tbody>
            <template x-if="rowsForDisplay(data.ads_daily).length===0">
              <tr class="border-t">
                <td class="px-3 py-3 text-gray-500" colspan="16">No data for selected filters.</td>
              </tr>
            </template>

            <template x-for="row in rowsForDisplay(data.ads_daily)" :key="(row.date ?? '') + '|' + (row.page ?? '') + '|' + (row.is_total?'1':'0')">
              <tr class="border-t"
                  :class="row.is_total ? 'bg-blue-50 font-bold border-t-2 border-blue-300' : 'hover:bg-gray-50'">
                <td class="px-2 py-2" x-text="fmtDate(row.date)"></td>
                <td class="px-2 py-2" x-text="row.page ?? '—'"></td>
                <td class="px-2 py-2"><span x-html="fmtItems(row.items_display)"></span></td>
                <td class="px-2 py-2 text-right"><span x-text="moneyList(row.unit_costs)"></span></td>
                <td class="px-2 py-2 text-right" x-text="money(row.adspent)"></td>
                <td class="px-2 py-2 text-right" x-text="num(row.orders)"></td>
                <td class="px-2 py-2 text-right" x-text="moneyOrDash(row.proceed_cpp)"></td>
                <td class="px-2 py-2 text-right" x-text="num(row.shipped)"></td>
                <td class="px-2 py-2 text-right" x-text="days(row.avg_delay_days)"></td>
                <td class="px-2 py-2 text-right" x-text="num(row.hold)"></td>
                <td class="px-2 py-2 text-right"><span class="px-2 py-0.5 rounded" :class="rtsClass(row.rts_pct)" x-text="percent(row.rts_pct)"></span></td>
                <td class="px-2 py-2 text-right"><span class="px-2 py-0.5 rounded" :class="rtsClass(row.actual_rts_pct)" x-text="percent(row.actual_rts_pct)"></span></td>
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
              <th class="px-2 py-2 text-right">Actual RTS%</th>
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
                <td class="px-3 py-3 text-gray-500" colspan="29">No data for selected filters.</td>
              </tr>
            </template>

            <template x-for="row in rowsForDisplay(data.ads_daily)" :key="(row.date ?? '') + '|' + (row.page ?? '') + '|' + (row.is_total?'1':'0')">
              <tr class="border-t"
                  :class="row.is_total ? 'bg-blue-50 font-bold border-t-2 border-blue-300' : 'hover:bg-gray-50'">
                <td class="px-2 py-2" x-text="fmtDate(row.date)"></td>
                <td class="px-2 py-2" x-text="row.page ?? '—'"></td>
                <td class="px-2 py-2"><span x-html="fmtItems(row.items_display)"></span></td>
                <td class="px-2 py-2 text-right"><span x-text="moneyList(row.unit_costs)"></span></td>
                <td class="px-2 py-2 text-right" x-text="money(row.adspent)"></td>
                <td class="px-2 py-2 text-right" x-text="num(row.orders)"></td>
                <td class="px-2 py-2 text-right" x-text="moneyOrDash(row.proceed_cpp)"></td>
                <td class="px-2 py-2 text-right" x-text="num(row.shipped)"></td>
                <td class="px-2 py-2 text-right" x-text="days(row.avg_delay_days)"></td>
                <td class="px-2 py-2 text-right" x-text="num(row.hold)"></td>
                <td class="px-2 py-2 text-right"><span class="px-2 py-0.5 rounded" :class="rtsClass(row.rts_pct)" x-text="percent(row.rts_pct)"></span></td>
                <td class="px-2 py-2 text-right"><span class="px-2 py-0.5 rounded" :class="rtsClass(row.actual_rts_pct)" x-text="percent(row.actual_rts_pct)"></span></td>
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
                <td class="px-2 py-2 text-right" x-text="moneyOrDash(row.projected_net_profit)"></td>

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
        showAllRows: true,
        isLoading: false,
        activePreset: '',

        // data / filters
        data: { ads_daily: [], actual_rts_pct: null, top_summary: [], target_cpp: null, breakeven_cpp: null },
        filters: { page_name: 'all', start_date: '', end_date: '' },
        dateLabel: 'Select dates',

        // formatting helpers
        money(v){ return `₱${Number(v||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2})}`; },
        moneyOrDash(v){ return (v==null || isNaN(v)) ? '—' : this.money(v); },
        num(v){ return Number(v||0).toLocaleString('en-PH'); },
        percent(v){ return (v==null || isNaN(v)) ? '—' : (Number(v).toFixed(2) + '%'); },
        ymd(d){ const p=n=>String(n).padStart(2,'0'); return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate()); },
        days(v){ return (v==null || isNaN(v)) ? '—' : Number(v).toFixed(2); },
        fmtItems(s){ if (!s || s === '—') return '—'; return s.split(' / ').map(i => i.trim()).join('<br>'); },
        moneyList(list){
          if (!Array.isArray(list) || list.length===0) return '—';
          return list.map(v => this.money(v)).join(', ');
        },

        // Format date from YYYY-MM-DD or date range to abbreviated month format
        fmtDate(dateStr){
          if (!dateStr) return '—';
          // Handle "TOTAL" or similar labels
          if (dateStr.toUpperCase() === 'TOTAL' || dateStr.toUpperCase() === 'TOTALS') return dateStr;
          // Handle date range "YYYY-MM-DD – YYYY-MM-DD"
          if (dateStr.includes('–') || dateStr.includes(' - ')) {
            const sep = dateStr.includes('–') ? '–' : '-';
            const parts = dateStr.split(sep).map(s => s.trim());
            if (parts.length === 2) return this.fmtSingleDate(parts[0]) + ' – ' + this.fmtSingleDate(parts[1]);
          }
          return this.fmtSingleDate(dateStr);
        },
        fmtSingleDate(s){
          if (!s) return '—';
          const M = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
          const d = new Date(s + 'T00:00:00');
          if (isNaN(d.getTime())) return s;
          return M[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
        },

        // Projected % now purely from server
        projectedPct(row){
          const pre = row?.projected_net_profit_pct;
          return (pre==null || isNaN(pre)) ? null : Number(pre);
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

        // Detect which preset matches the current date range
        detectPreset(){
          const today = new Date();
          const todayStr = this.ymd(today);

          const yesterday = new Date(today);
          yesterday.setDate(today.getDate() - 1);
          const yesterdayStr = this.ymd(yesterday);

          // Monday of this week
          const dow = today.getDay();
          const mondayOffset = dow === 0 ? 6 : dow - 1;
          const monday = new Date(today);
          monday.setDate(today.getDate() - mondayOffset);
          const mondayStr = this.ymd(monday);

          // Last 7 days
          const last7 = new Date(today);
          last7.setDate(today.getDate() - 6);
          const last7Str = this.ymd(last7);

          // This month
          const monthStart = new Date(today.getFullYear(), today.getMonth(), 1);
          const monthStartStr = this.ymd(monthStart);

          // Last month
          const lastMonthStart = new Date(today.getFullYear(), today.getMonth() - 1, 1);
          const lastMonthEnd = new Date(today.getFullYear(), today.getMonth(), 0);
          const lastMonthStartStr = this.ymd(lastMonthStart);
          const lastMonthEndStr = this.ymd(lastMonthEnd);

          const s = this.filters.start_date;
          const e = this.filters.end_date;

          if (s === todayStr && e === todayStr) return 'today';
          if (s === yesterdayStr && e === yesterdayStr) return 'yesterday';
          if (s === mondayStr && e === todayStr) return 'this_week';
          if (s === last7Str && e === todayStr) return 'last_7_days';
          if (s === monthStartStr && e === todayStr) return 'this_month';
          if (s === lastMonthStartStr && e === lastMonthEndStr) return 'last_month';
          return '';
        },

        setPreset(preset){
          const today = new Date();
          let startDate, endDate;

          switch(preset){
            case 'today':
              startDate = endDate = today;
              break;
            case 'yesterday':
              const y = new Date(today);
              y.setDate(today.getDate() - 1);
              startDate = endDate = y;
              break;
            case 'this_week':
              const dow = today.getDay();
              const mondayOffset = dow === 0 ? 6 : dow - 1;
              startDate = new Date(today);
              startDate.setDate(today.getDate() - mondayOffset);
              endDate = today;
              break;
            case 'last_7_days':
              startDate = new Date(today);
              startDate.setDate(today.getDate() - 6);
              endDate = today;
              break;
            case 'this_month':
              startDate = new Date(today.getFullYear(), today.getMonth(), 1);
              endDate = today;
              break;
            case 'last_month':
              startDate = new Date(today.getFullYear(), today.getMonth() - 1, 1);
              endDate = new Date(today.getFullYear(), today.getMonth(), 0);
              break;
          }

          this.filters.start_date = this.ymd(startDate);
          this.filters.end_date = this.ymd(endDate);
          this.activePreset = preset;
          this.setDateLabel();

          // Update flatpickr
          const fp = document.querySelector('#dateRange')._flatpickr;
          if (fp) {
            fp.setDate([this.filters.start_date, this.filters.end_date], false);
            fp.input.value = `${this.filters.start_date} to ${this.filters.end_date}`;
          }

          this.reload();
        },

        async reload(){
          this.isLoading = true;
          try {
            const params = new URLSearchParams({
              page_name: this.filters.page_name || 'all',
              start_date: this.filters.start_date || '',
              end_date: this.filters.end_date   || ''
            });
            const res  = await fetch('{{ route('summary.overall.data') }}?'+params.toString());
            const json = await res.json();
            this.data = json;
            this.activePreset = this.detectPreset();
          } catch(e) {
            console.error('Reload error:', e);
          } finally {
            this.isLoading = false;
          }
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
