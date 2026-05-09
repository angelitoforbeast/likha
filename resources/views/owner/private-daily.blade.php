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
    table.daily-tbl thead th { background:#1f2937; color:#fff; font-weight:600; font-size:10px; text-transform:uppercase; letter-spacing:0.04em; position:sticky; top:0; z-index:2; cursor:grab; user-select:none; }
    table.daily-tbl thead th:active { cursor:grabbing; }
    table.daily-tbl thead th.drag-over { box-shadow: inset 3px 0 0 0 #fbbf24; }
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
      <div class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
        <div>
          <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Preset</label>
          <select x-model="presetSel" @change="applyPreset()"
                  class="w-full border border-gray-300 rounded px-3 py-2 text-sm bg-white">
            <option value="custom">Custom range</option>
            <option value="last30">Last 30 days (excl today)</option>
            <option value="last7">Last 7 days (excl today)</option>
            <option value="thisMonth">This month (excl today)</option>
            <option value="lastMonth">Last month (full)</option>
            <option value="yesterday">Yesterday only</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">From</label>
          <input type="date" x-model="filters.start_date" @change="presetSel='custom';reload()"
                 class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">To</label>
          <input type="date" x-model="filters.end_date" @change="presetSel='custom';reload()"
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
        <div class="p-6 text-red-600 text-sm">
          <div class="font-semibold mb-2">Error:</div>
          <pre class="text-left text-[11px] bg-red-50 border border-red-200 rounded p-3 overflow-auto whitespace-pre-wrap" x-text="error"></pre>
        </div>
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
              <template x-for="c in visibleCols()" :key="c.id">
                <th :class="(c.align === 'left' ? 'text-left' : 'num') + (dragOverId === c.id ? ' drag-over' : '')"
                    draggable="true"
                    :title="'Drag to reorder · ' + c.label"
                    @dragstart="colDragStart($event, c.id)"
                    @dragend="dragOverId=null"
                    @dragover.prevent="dragOverId = c.id"
                    @dragleave="dragOverId = null"
                    @drop.prevent="colDrop($event, c.id)"
                    x-text="c.label"></th>
              </template>
            </tr>
          </thead>
          <tbody>
            <template x-for="r in rows" :key="r.date">
              <tr>
                <template x-for="c in visibleCols()" :key="c.id">
                  <td :class="cellClass(c, r)"
                      :style="cellStyle(c.id, rawVal(c, r), r)"
                      x-html="cellText(c, r)"></td>
                </template>
              </tr>
            </template>
          </tbody>
          <tfoot x-show="rows.length > 0">
            <tr>
              <template x-for="(c, idx) in visibleCols()" :key="c.id">
                <td :class="c.align === 'left' ? 'text-left' : 'num'"
                    :style="cellStyle(c.id, rawVal(c, totals), totals)"
                    x-html="idx === 0 ? 'TOTAL' : totalText(c)"></td>
              </template>
            </tr>
          </tfoot>
        </table>
      </template>
    </div>

    <div class="text-[11px] text-gray-500 px-1">
      <strong>Proj. Net Profit</strong> per day = sum of (proceed × mode_cod × deliverFactor − shipping − COGS − adspent − COD fee)
      across pages, using each page's JNT 60-day RTS rate.
      Same formula as <code>/owner/private</code>'s itemSummary projected_profit.
      Default range = last 30 days excl today. JNT stats cached 1 hour.
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
        presetSel: 'last30',
        filters: {
          start_date: '{{ $defaultStartDate }}',
          end_date:   '{{ $defaultEndDate }}',
        },
        // Column drag-and-drop state
        dragSrcId: null,
        dragOverId: null,
        // Local order override (when user drags). Falls back to COLS_CONFIG.order.
        localOrder: null,
        init() { this.reload(); },

        // ── Column drag-and-drop ──────────────────────────────────────────
        colDragStart(e, colId) {
          this.dragSrcId = colId;
          e.dataTransfer.effectAllowed = 'move';
          e.dataTransfer.setData('text/plain', colId);
        },
        colDrop(e, targetId) {
          this.dragOverId = null;
          if (!this.dragSrcId || this.dragSrcId === targetId) { this.dragSrcId = null; return; }
          const cur = this.visibleCols().map(c => c.id);
          const from = cur.indexOf(this.dragSrcId);
          const to   = cur.indexOf(targetId);
          if (from < 0 || to < 0) { this.dragSrcId = null; return; }
          const [moved] = cur.splice(from, 1);
          cur.splice(to, 0, moved);
          // Append any cols we didn't see (hidden ones, etc.) in original order
          const all = this.ALL_COLS.map(c => c.id);
          const seen = new Set(cur);
          for (const id of all) if (!seen.has(id)) cur.push(id);
          this.localOrder = cur;
          this.saveColOrder(cur);
          this.dragSrcId = null;
        },
        async saveColOrder(order) {
          const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
          try {
            await fetch('{{ route('owner.column-settings.save') }}', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
              body: JSON.stringify({ table: 'daily_summary', order }),
            });
          } catch (e) { console.warn('saveColOrder failed', e); }
        },
        // PH timezone "today" derived locally — same logic as backend
        phToday() {
          const d = new Date(new Date().toLocaleString('en-US', { timeZone: 'Asia/Manila' }));
          return d;
        },
        fmt(d) {
          const p = n => String(n).padStart(2, '0');
          return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
        },
        applyPreset() {
          const today = this.phToday();
          const yesterday = new Date(today); yesterday.setDate(today.getDate() - 1);
          let s, e;
          switch (this.presetSel) {
            case 'last30':
              e = yesterday;
              s = new Date(today); s.setDate(today.getDate() - 30);
              break;
            case 'last7':
              e = yesterday;
              s = new Date(today); s.setDate(today.getDate() - 7);
              break;
            case 'thisMonth':
              s = new Date(today.getFullYear(), today.getMonth(), 1);
              e = yesterday;
              if (e < s) e = s; // edge: today is the 1st
              break;
            case 'lastMonth':
              s = new Date(today.getFullYear(), today.getMonth() - 1, 1);
              e = new Date(today.getFullYear(), today.getMonth(), 0); // last day of prev month
              break;
            case 'yesterday':
              s = yesterday; e = yesterday;
              break;
            default: return;
          }
          this.filters.start_date = this.fmt(s);
          this.filters.end_date   = this.fmt(e);
          this.reload();
        },
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
              if (j.error) {
                this.error = j.error + '\n' + (j.file || '') + '\n' + (j.trace ? j.trace.join('\n') : '');
              }
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

        // ── Column metadata ─────────────────────────────────────────────
        // Defines render functions per column id. Used by visibleCols() +
        // cell/total renderers. raw() returns numeric value para sa
        // conditional formatting.
        ALL_COLS: [
          { id:'date',            label:'Date',           align:'left',
            cell:(r,h)=>'<span class="font-medium">'+h.fmtDay(r.date)+'</span>',
            total:()=>'TOTAL', raw:(r)=>null },
          { id:'adspent',         label:'Ad Spend',       align:'num', cell:(r,h)=>h.peso(r.adspent),    total:(t,h)=>h.peso(t.adspent),    raw:(r)=>r.adspent },
          { id:'messages',        label:'Msgs',           align:'num', cell:(r,h)=>h.num(r.messages),    total:(t,h)=>h.num(t.messages),    raw:(r)=>r.messages },
          { id:'orders',          label:'Orders',         align:'num', cell:(r,h)=>h.num(r.orders),      total:(t,h)=>h.num(t.orders),      raw:(r)=>r.orders },
          { id:'proceed',         label:'Proceed',        align:'num', cell:(r,h)=>h.num(r.proceed),     total:(t,h)=>h.num(t.proceed),     raw:(r)=>r.proceed },
          { id:'cannot',          label:'Cannot',         align:'num', cell:(r,h)=>'<span class="muted">'+h.num(r.cannot)+'</span>', total:(t,h)=>'<span class="muted">'+h.num(t.cannot)+'</span>', raw:(r)=>r.cannot },
          { id:'odz',             label:'ODZ',            align:'num', cell:(r,h)=>'<span class="muted">'+h.num(r.odz)+'</span>',    total:(t,h)=>'<span class="muted">'+h.num(t.odz)+'</span>',    raw:(r)=>r.odz },
          { id:'shipped',         label:'Shipped',        align:'num', cell:(r,h)=>h.num(r.shipped),     total:(t,h)=>h.num(t.shipped),     raw:(r)=>r.shipped },
          { id:'delivered',       label:'Delivered',      align:'num', cell:(r,h)=>h.num(r.delivered),   total:(t,h)=>h.num(t.delivered),   raw:(r)=>r.delivered },
          { id:'in_transit',      label:'In Transit',     align:'num', cell:(r,h)=>'<span class="muted">'+h.num(r.in_transit)+'</span>', total:(t,h)=>'<span class="muted">'+h.num(t.in_transit)+'</span>', raw:(r)=>r.in_transit },
          { id:'rts',             label:'RTS',            align:'num', cell:(r,h)=>h.num((r.returned||0)+(r.for_return||0)), total:(t,h)=>h.num((t.returned||0)+(t.for_return||0)), raw:(r)=>(r.returned||0)+(r.for_return||0) },
          { id:'cpp',             label:'CPP',            align:'num', cell:(r,h)=>h.peso(r.cpp),         total:(t,h)=>h.peso(t.cpp),         raw:(r)=>r.cpp },
          { id:'proceed_cpp',     label:'Proc CPP',       align:'num', cell:(r,h)=>h.peso(r.proceed_cpp), total:(t,h)=>h.peso(t.proceed_cpp), raw:(r)=>r.proceed_cpp },
          { id:'cpm',             label:'CPM',            align:'num', cell:(r,h)=>h.peso(r.cpm),         total:(t,h)=>h.peso(t.cpm),         raw:(r)=>r.cpm },
          { id:'tcpr_pct',        label:'TCPR%',          align:'num', cell:(r,h)=>h.pct(r.tcpr_pct),     total:(t,h)=>h.pct(t.tcpr_pct),     raw:(r)=>r.tcpr_pct },
          { id:'proj_gross',      label:'Proj. Gross',    align:'num', cell:(r,h)=>h.peso(r.proj_gross),     total:(t,h)=>h.peso(t.proj_gross),     raw:(r)=>r.proj_gross },
          { id:'proj_shipping',   label:'Proj. Shipping', align:'num', cell:(r,h)=>h.peso(r.proj_shipping),  total:(t,h)=>h.peso(t.proj_shipping),  raw:(r)=>r.proj_shipping },
          { id:'proj_cogs',       label:'Proj. COGS',     align:'num', cell:(r,h)=>h.peso(r.proj_cogs),      total:(t,h)=>h.peso(t.proj_cogs),      raw:(r)=>r.proj_cogs },
          { id:'proj_net_profit', label:'Proj. Net Profit', align:'num',
            cell:(r,h)=>'<span class="'+(r.proj_net_profit>=0?'pos':'neg')+'">'+h.peso(r.proj_net_profit)+'</span>',
            total:(t,h)=>'<span class="'+(t.proj_net_profit>=0?'pos':'neg')+'">'+h.peso(t.proj_net_profit)+'</span>',
            raw:(r)=>r.proj_net_profit },
          { id:'proj_net_pct',    label:'Proj. Net %',    align:'num',
            cell:(r,h)=>r.proj_net_pct==null?'—':'<span class="'+(r.proj_net_pct>=0?'pos':'neg')+'">'+h.pct(r.proj_net_pct)+'</span>',
            total:(t,h)=>t.proj_net_pct==null?'—':'<span class="'+(t.proj_net_pct>=0?'pos':'neg')+'">'+h.pct(t.proj_net_pct)+'</span>',
            raw:(r)=>r.proj_net_pct },
        ],

        // Visibility + ordering from /owner/column-settings
        COLS_CONFIG:    @json($colsConfig),
        COL_FORMAT:     @json($colFormatRules),

        visibleCols() {
          const cfg     = this.COLS_CONFIG || {};
          // localOrder (set by drag-reorder) takes precedence over saved config
          const order   = this.localOrder
                            ? this.localOrder
                            : (Array.isArray(cfg.order) ? cfg.order : []);
          const hidden  = new Set(Array.isArray(cfg.hidden) ? cfg.hidden : []);
          const all     = this.ALL_COLS;
          const allMap  = Object.fromEntries(all.map(c => [c.id, c]));
          const seen    = new Set();
          const result  = [];
          for (const id of order) {
            if (allMap[id] && !hidden.has(id) && !seen.has(id)) {
              result.push(allMap[id]);
              seen.add(id);
            }
          }
          for (const c of all) {
            if (!seen.has(c.id) && !hidden.has(c.id)) result.push(c);
          }
          return result;
        },

        cellClass(c, r) {
          return c.align === 'left' ? 'text-left' : 'num';
        },
        rawVal(c, r) { return c.raw ? c.raw(r) : null; },
        cellText(c, r) { return c.cell ? c.cell(r, this) : ''; },
        totalText(c) { return c.total ? c.total(this.totals, this) : ''; },

        // Conditional formatting — applies bg color based on rules.
        // Supports compare_col (rule fires based on sibling column's value).
        cellStyle(colId, value, row) {
          const rules = (this.COL_FORMAT || {})[colId] || [];
          for (const r of rules) {
            const op = r.op;
            const v  = (typeof r.value === 'number') ? r.value : null;
            if (v == null) continue;
            // Pick eval value: compare_col (sibling) or self
            let evalRaw = value;
            if (r.compare_col && row && Object.prototype.hasOwnProperty.call(row, r.compare_col)) {
              evalRaw = row[r.compare_col];
            }
            if (evalRaw == null || isNaN(Number(evalRaw))) continue;
            const v0 = Number(evalRaw);
            let match = false;
            if (op === '>')  match = v0 >  v;
            if (op === '>=') match = v0 >= v;
            if (op === '=')  match = v0 == v;
            if (op === '<=') match = v0 <= v;
            if (op === '<')  match = v0 <  v;
            if (match) {
              const bg    = r.bg    || '#fee2e2';
              const color = r.color || '';
              return `background-color:${bg};` + (color ? `color:${color};` : '');
            }
          }
          return '';
        },
      }
    }
  </script>
</body>
</html>
