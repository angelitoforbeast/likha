<!DOCTYPE html>
<html lang="en" x-data="dailyUI()" x-cloak>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Daily Summary — CEO View</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <style>
    [x-cloak]{display:none!important}
    body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background:#f0f2f5; }
    .num { text-align:right; font-variant-numeric:tabular-nums; }
    table.daily-tbl { width:100%; border-collapse:collapse; font-size:11.5px; }
    table.daily-tbl th, table.daily-tbl td { padding:6px 8px; border-bottom:1px solid #e4e6eb; white-space:nowrap; }
    table.daily-tbl thead th { background:#1f2937; color:#fff; font-weight:600; font-size:10px; text-transform:uppercase; letter-spacing:0.04em; position:sticky; top:0; z-index:2; }
    table.daily-tbl tbody tr:hover { background:#f8fafc; }
    table.daily-tbl tfoot td { background:#fef3c7; font-weight:700; border-top:2px solid #f59e0b; }
    .pos { color:#15803d; font-weight:600; }
    .neg { color:#b91c1c; font-weight:600; }
    .muted { color:#9ca3af; }
    .pill { display:inline-block; padding:1px 6px; border-radius:9999px; font-size:10px; font-weight:600; }
    .pill.green { background:#dcfce7; color:#166534; }
    .pill.red   { background:#fee2e2; color:#b91c1c; }
  </style>
</head>
<body>
  <nav class="bg-white border-b sticky top-0 z-30 shadow-sm">
    <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <a href="{{ route('owner.private') }}" class="text-sm text-blue-600 hover:underline">← Back to Daily Summary</a>
        <span class="text-gray-300">·</span>
        <h1 class="font-semibold text-base">📅 Per-Day Summary <span class="text-xs font-normal text-gray-500">CEO view</span></h1>
      </div>
      <div class="text-xs text-gray-500">
        Aggregated across all pages, one row per date
      </div>
    </div>
  </nav>

  <main class="px-4 py-4 space-y-4" style="max-width:none;">

    <div class="bg-white rounded-lg shadow border border-gray-200 p-4">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
        <div>
          <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">From</label>
          <input type="date" x-model="filters.start_date" @change="reload()"
                 class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">To</label>
          <input type="date" x-model="filters.end_date" @change="reload()"
                 class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
        </div>
        <div class="flex items-center gap-2">
          <button @click="reload()" :disabled="loading"
                  class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-4 py-2 rounded disabled:opacity-50">
            <span x-show="!loading">🔍 Refresh</span>
            <span x-show="loading">Loading…</span>
          </button>
          <span class="text-xs text-gray-500" x-show="rows.length > 0"
                x-text="rows.length + ' day(s)'"></span>
        </div>
      </div>
    </div>

    <div class="bg-white rounded-lg shadow border border-gray-200 overflow-auto" style="max-height:calc(100vh - 220px);">
      <template x-if="loading">
        <div class="p-10 text-center text-gray-500 text-sm">Loading…</div>
      </template>
      <template x-if="!loading && error">
        <div class="p-10 text-center text-red-600 text-sm" x-text="error"></div>
      </template>
      <template x-if="!loading && !error && rows.length === 0">
        <div class="p-10 text-center text-gray-500 text-sm">
          <div>No data for selected range.</div>
          <pre x-show="debug" class="mt-4 text-left text-[11px] bg-gray-50 border border-gray-200 rounded p-3 overflow-auto" x-text="JSON.stringify(debug, null, 2)"></pre>
        </div>
      </template>
      <template x-if="!loading && !error && rows.length > 0">
        <table class="daily-tbl">
          <thead>
            <tr>
              <th class="text-left">Date</th>
              <th class="num">Ad Spend</th>
              <th class="num">Msgs</th>
              <th class="num">Orders</th>
              <th class="num">Proceed</th>
              <th class="num">Cannot</th>
              <th class="num">ODZ</th>
              <th class="num">Shipped</th>
              <th class="num">Delivered</th>
              <th class="num">In Transit</th>
              <th class="num">RTS</th>
              <th class="num">CPP</th>
              <th class="num">Proc CPP</th>
              <th class="num">CPM</th>
              <th class="num">TCPR%</th>
              <th class="num">Proj. Gross</th>
              <th class="num">Proj. Shipping</th>
              <th class="num">Proj. COGS</th>
              <th class="num">Proj. Net Profit</th>
              <th class="num">Proj. Net %</th>
            </tr>
          </thead>
          <tbody>
            <template x-for="r in rows" :key="r.date">
              <tr>
                <td class="text-left font-medium" x-text="fmtDay(r.date)"></td>
                <td class="num" x-text="peso(r.adspent)"></td>
                <td class="num" x-text="num(r.messages)"></td>
                <td class="num" x-text="num(r.orders)"></td>
                <td class="num" x-text="num(r.proceed)"></td>
                <td class="num muted" x-text="num(r.cannot)"></td>
                <td class="num muted" x-text="num(r.odz)"></td>
                <td class="num" x-text="num(r.shipped)"></td>
                <td class="num" x-text="num(r.delivered)"></td>
                <td class="num muted" x-text="num(r.in_transit)"></td>
                <td class="num" x-text="num(r.returned + r.for_return)"></td>
                <td class="num" x-text="peso(r.cpp)"></td>
                <td class="num" x-text="peso(r.proceed_cpp)"></td>
                <td class="num" x-text="peso(r.cpm)"></td>
                <td class="num" x-text="pct(r.tcpr_pct)"></td>
                <td class="num" x-text="peso(r.proj_gross)"></td>
                <td class="num" x-text="peso(r.proj_shipping)"></td>
                <td class="num" x-text="peso(r.proj_cogs)"></td>
                <td :class="'num ' + (r.proj_net_profit >= 0 ? 'pos' : 'neg')" x-text="peso(r.proj_net_profit)"></td>
                <td :class="'num ' + (r.proj_net_pct == null ? '' : (r.proj_net_pct >= 0 ? 'pos' : 'neg'))"
                    x-text="pct(r.proj_net_pct)"></td>
              </tr>
            </template>
          </tbody>
          <tfoot x-show="rows.length > 0">
            <tr>
              <td class="text-left">TOTAL</td>
              <td class="num" x-text="peso(totals.adspent)"></td>
              <td class="num" x-text="num(totals.messages)"></td>
              <td class="num" x-text="num(totals.orders)"></td>
              <td class="num" x-text="num(totals.proceed)"></td>
              <td class="num muted" x-text="num(totals.cannot)"></td>
              <td class="num muted" x-text="num(totals.odz)"></td>
              <td class="num" x-text="num(totals.shipped)"></td>
              <td class="num" x-text="num(totals.delivered)"></td>
              <td class="num muted" x-text="num(totals.in_transit)"></td>
              <td class="num" x-text="num(totals.returned + totals.for_return)"></td>
              <td class="num" x-text="peso(totals.cpp)"></td>
              <td class="num" x-text="peso(totals.proceed_cpp)"></td>
              <td class="num" x-text="peso(totals.cpm)"></td>
              <td class="num" x-text="pct(totals.tcpr_pct)"></td>
              <td class="num" x-text="peso(totals.proj_gross)"></td>
              <td class="num" x-text="peso(totals.proj_shipping)"></td>
              <td class="num" x-text="peso(totals.proj_cogs)"></td>
              <td :class="'num ' + (totals.proj_net_profit >= 0 ? 'pos' : 'neg')" x-text="peso(totals.proj_net_profit)"></td>
              <td :class="'num ' + (totals.proj_net_pct == null ? '' : (totals.proj_net_pct >= 0 ? 'pos' : 'neg'))"
                  x-text="pct(totals.proj_net_pct)"></td>
            </tr>
          </tfoot>
        </table>
      </template>
    </div>

    <div class="text-[11px] text-gray-500 px-1">
      Each row = TOTAL ng <code>/owner/private</code> kapag fina-filter sa single date.
      <strong>Proj. Net Profit</strong> = sum of per-page projected net profit (per-page RTS rate applied),
      same value mong nakikita sa <code>/owner/private</code>'s TOTAL row.
    </div>
  </main>

  <script>
    function dailyUI() {
      return {
        loading: false,
        error: '',
        rows: [],
        totals: {},
        debug: null,
        filters: {
          start_date: '{{ $defaultStartDate }}',
          end_date:   '{{ $defaultEndDate }}',
        },
        init() { this.reload(); },
        async reload() {
          this.loading = true;
          this.error = '';
          this.debug = null;
          try {
            const qs = new URLSearchParams({
              start_date: this.filters.start_date,
              end_date:   this.filters.end_date,
            });
            const res = await fetch('{{ route('owner.private.daily.data') }}?' + qs.toString());
            if (!res.ok) {
              const t = await res.text();
              this.error = `HTTP ${res.status}: ${t.slice(0, 300)}`;
              this.rows = []; this.totals = {};
            } else {
              const j = await res.json();
              this.rows   = j.rows   || [];
              this.totals = j.totals || {};
              this.debug  = j.debug  || null;
            }
          } catch (e) {
            this.error = String(e);
            this.rows = []; this.totals = {};
          } finally {
            this.loading = false;
          }
        },
        peso(v) {
          if (v == null || isNaN(v)) return '—';
          const n = Number(v);
          return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
        num(v) {
          if (v == null || isNaN(v)) return '—';
          return Number(v).toLocaleString('en-PH');
        },
        pct(v) {
          if (v == null || isNaN(v)) return '—';
          return Number(v).toFixed(1) + '%';
        },
        fmtDay(s) {
          if (!s) return '';
          const dt = new Date(s + 'T00:00:00');
          if (isNaN(dt.getTime())) return s;
          return dt.toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric' }) + ` (${s})`;
        },
      }
    }
  </script>
</body>
</html>
