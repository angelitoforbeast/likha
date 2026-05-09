<!doctype html>
<html lang="en" x-data="campaignsUI()" x-cloak>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Ads Manager - Likha</title>

  {{-- Tailwind & Alpine --}}
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>

  {{-- Flatpickr styles --}}
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <style>
    [x-cloak]{display:none!important}
    .flatpickr-calendar{z-index:9999!important}

    /* ── FB Ads Manager-like table styling ────────────────────────────── */
    .fb-table { font-size:13px; border-collapse:separate; border-spacing:0; width:100%; background:white; }
    .fb-table thead th {
      background:#f0f2f5; color:#65676b;
      font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.03em;
      border-bottom:1px solid #ced0d4; padding:10px 12px;
      text-align:left; vertical-align:middle; white-space:nowrap;
      position:sticky; top:0; z-index:10;
    }
    .fb-table thead th.sortable { cursor:pointer; user-select:none; }
    .fb-table thead th.sortable:hover { background:#e4e6eb; }
    .fb-table thead th.text-right { text-align:right; }
    .fb-table thead th.col-checkbox { width:34px; padding:10px 6px 10px 12px; }
    .fb-table tbody td {
      border-bottom:1px solid #e4e6eb; padding:11px 12px;
      color:#1c1e21; vertical-align:top;
    }
    .fb-table tbody tr:hover td { background:#f7f8fa; }
    .fb-table tbody tr.editing-row td { background:#fef3c7 !important; }
    .fb-table tbody tr.cursor-pointer td:not(.col-checkbox) { cursor:pointer; }
    .fb-table .num { text-align:right; font-variant-numeric:tabular-nums; }
    .fb-table .name { color:#1877f2; font-weight:500; }
    .fb-table .name:hover { text-decoration:underline; }
    .fb-table .sub { font-size:11px; color:#65676b; margin-top:1px; }
    .fb-table tr.totals td {
      background:#f5f6f7; border-top:2px solid #ced0d4; font-weight:600; color:#1c1e21;
      position:sticky; bottom:0; z-index:8;
    }
    .fb-pill { display:inline-flex; align-items:center; gap:6px; font-size:12px; line-height:1; }
    .fb-pill::before {
      content:''; width:8px; height:8px; border-radius:50%; display:inline-block;
    }
    .fb-pill.active::before { background:#42b72a; }
    .fb-pill.off::before    { background:#bcc0c4; }
    .fb-pill.active { color:#1c1e21; }
    .fb-pill.off    { color:#65676b; }
    .fb-table .col-checkbox { width:34px; padding:11px 6px 11px 12px; }
    /* Sort arrow */
    .fb-sort-arrow { font-size:9px; color:#1877f2; margin-left:4px; }
  </style>
</head>
<body class="bg-gray-100 text-gray-900">
  <!-- Top bar -->
  <nav class="bg-white border-b sticky top-0 z-40">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="h-16 flex items-center justify-between gap-4">
        <div class="flex items-center gap-2 min-w-0">
          <div class="font-semibold text-lg truncate" x-text="titleForTab()"></div>
          <template x-if="selectedCount() > 0">
            <div class="text-xs flex items-center gap-2 px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 border border-blue-200">
              <span x-text="`${selectedCount()} selected`"></span>
              <button class="underline" @click="clearSelection(tab); reload()">Clear</button>
            </div>
          </template>
          <div class="text-xs text-gray-500 px-2 py-0.5 rounded-full bg-gray-100">Opportunity score: 100</div>
        </div>
        <div class="flex items-center gap-2">
          <button class="inline-flex items-center gap-2 border rounded px-3 py-1.5 text-sm hover:bg-gray-50">
            <span class="i">⏱️</span>
            <span x-text="dateLabel"></span>
          </button>
          <a href="{{ route('ads_manager.campaigns.history') }}"
             class="border rounded px-3 py-1.5 text-sm hover:bg-gray-50 no-underline text-gray-700"
             title="View daily change log (created, turned on/off)">⏱ History</a>
          <button class="border rounded px-3 py-1.5 text-sm hover:bg-gray-50" @click="exportCsv()">Export</button>
        </div>
      </div>
    </div>
  </nav>

  <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-4">
    <!-- Tabs + Search + Right controls -->
    <div class="flex flex-wrap items-end gap-3">
      <div class="flex items-center gap-2">
        <button :class="tab==='campaigns' ? 'bg-white shadow' : 'bg-gray-100'"
                class="px-3 py-2 rounded text-sm"
                @click="switchTab('campaigns')">Campaigns</button>
        <button :class="tab==='adsets' ? 'bg-white shadow' : 'bg-gray-100'"
                class="px-3 py-2 rounded text-sm"
                @click="switchTab('adsets')">Ad sets</button>
        <button :class="tab==='ads' ? 'bg-white shadow' : 'bg-gray-100'"
                class="px-3 py-2 rounded text-sm"
                @click="switchTab('ads')">Ads</button>
      </div>

      <div class="flex-1 min-w-[280px] relative">
        <input type="search" x-model.debounce.350ms="filters.q"
               :placeholder="searchPlaceholder()"
               class="w-full rounded border px-3 py-2 text-sm bg-white pr-9"
               @input.debounce.350ms="reload()" />
        <span x-show="loading" class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400" style="pointer-events:none;">
          ⏳
        </span>
      </div>

      <div class="flex items-center gap-2 flex-wrap">
        {{-- Date preset dropdown — quick ranges. Selecting one updates
             the date range + Flatpickr display + triggers reload. --}}
        <select class="border rounded px-2 py-2 text-sm bg-white"
                x-model="datePreset"
                @change="applyDatePreset($event.target.value)">
          <option value="">Custom range…</option>
          <option value="today">Today</option>
          <option value="yesterday">Yesterday</option>
          <option value="last_3">Last 3 days</option>
          <option value="last_7">Last 7 days</option>
          <option value="this_month">This month</option>
          <option value="last_month">Last month</option>
          <option value="last_and_this">Last + This month</option>
        </select>

        {{-- Unified single input (one day or range) --}}
        <div class="min-w-[220px]">
          <label class="block text-xs font-semibold mb-1 text-gray-500">Date range</label>
          <input id="dateRange" type="text" placeholder="Select date range"
                 class="w-full border border-gray-300 p-2 rounded-md shadow-sm cursor-pointer bg-white text-sm" readonly>
        </div>

        <select class="border rounded px-2 py-2 text-sm" x-model="filters.page_name" @change="reload()">
          <option value="all">All Pages</option>
          @foreach(($pages ?? []) as $p)
            <option value="{{ trim($p) }}">{{ trim($p) }}</option>
          @endforeach
        </select>
      </div>
    </div>

    <!-- Table Card -->
    <section class="relative left-1/2 right-1/2 -mx-[50vw] w-screen bg-white shadow-sm border-y rounded-none">
      <div class="px-2 sm:px-3 lg:px-4">
        <div class="overflow-auto max-h-[70vh]">
          {{-- ─── Cols-driven table (FB Ads Manager-style) ─────────────────────
               Visibility/order ay galing sa /owner/column-settings (CEO)
               via window.__CAMPAIGNS_COLS__. Each col has a `type` na
               nag-dictate ng renderer (status pill, name, date, money, etc.). --}}
          <table class="fb-table">
            <thead>
              <tr>
                <th class="col-checkbox">
                  <input type="checkbox"
                         :checked="allChecked()"
                         @change="toggleCheckAll($event)"
                         class="h-4 w-4 rounded border-gray-300">
                </th>
                <template x-for="col in visibleCols()" :key="'h-'+col.id">
                  <th :class="(col.sort ? 'sortable ' : '') + (col.align==='right' ? 'text-right' : '')"
                      :style="col.minw ? ('min-width:'+col.minw+'px') : ''"
                      @click="col.sort && toggleSort(col.sort)">
                    <span x-text="colLabel(col)"></span>
                    <span class="fb-sort-arrow" x-show="col.sort && sortBy === col.sort"
                          x-text="sortDir==='asc' ? '▲' : '▼'"></span>
                  </th>
                </template>
              </tr>
            </thead>

            <tbody>
              <template x-for="row in rows" :key="rowKey(row)">
                <tr @click="rowClick(row)"
                    :class="{'cursor-pointer': tab!=='ads'}">
                  <td class="col-checkbox" @click.stop>
                    <input type="checkbox"
                           :checked="isChecked(row)"
                           @change="onCheck(row, $event.target.checked)"
                           class="h-4 w-4 rounded border-gray-300">
                  </td>
                  <template x-for="col in visibleCols()" :key="'c-'+col.id">
                    <td :class="(col.align==='right' ? 'num' : '')"
                        :style="(col.id==='days_running' ? 'white-space:nowrap;' : '') + cellFormatStyle(col.id, row[col.id])">
                      {{-- on: status pill --}}
                      <template x-if="col.type==='on'">
                        <span :class="'fb-pill ' + (row.on ? 'active' : 'off')"
                              x-text="row.on ? 'Active' : 'Off'"></span>
                      </template>
                      {{-- name: campaign / adset / ad name (varies per tab) --}}
                      <template x-if="col.type==='name'">
                        <div>
                          <div class="name" x-text="rowName(row)"></div>
                          <template x-if="tab==='ads' && row.item_name">
                            <div class="sub" x-text="row.item_name"></div>
                          </template>
                          <div class="sub" x-text="row.page_name"></div>
                        </div>
                      </template>
                      {{-- first_started / latest_started: date display.
                           When `running_at_start` is true (campaign already
                           had spend on its earliest record), prefix with "≥"
                           because the actual launch happened BEFORE our
                           data window. --}}
                      <template x-if="col.type==='date'">
                        <span>
                          <template x-if="row[col.id]">
                            <span style="white-space:nowrap;"
                                  :title="(col.id==='first_started' && row.running_at_start) ? 'Already running at the earliest record — true launch happened before our data starts' : ''">
                              <template x-if="col.id==='first_started' && row.running_at_start">
                                <span style="color:#64748b;font-weight:600;">≥&nbsp;</span>
                              </template>
                              <span x-text="fmtDate(row[col.id])"></span>
                            </span>
                          </template>
                          <template x-if="!row[col.id]">
                            <span style="color:#bcc0c4;">—</span>
                          </template>
                        </span>
                      </template>
                      {{-- days_running: integer derived from first_started when Active.
                           Same "≥" prefix when running_at_start — count is a lower bound. --}}
                      <template x-if="col.type==='days_running'">
                        <span>
                          <template x-if="row.first_started && row.on">
                            <span :title="row.running_at_start ? 'Already running at our data boundary — at least this many days' : ''">
                              <template x-if="row.running_at_start">
                                <span style="color:#64748b;font-weight:600;">≥&nbsp;</span>
                              </template>
                              <span x-text="daysSince(row.first_started)"></span>
                            </span>
                          </template>
                          <template x-if="!row.first_started || !row.on">
                            <span style="color:#bcc0c4;">—</span>
                          </template>
                        </span>
                      </template>
                      {{-- money: ₱ formatted with em-dash fallback --}}
                      <template x-if="col.type==='money'">
                        <span x-text="row[col.id] != null ? money(row[col.id]) : '—'"></span>
                      </template>
                      {{-- integer: locale-formatted count --}}
                      <template x-if="col.type==='integer'">
                        <span x-text="row[col.id] != null ? num(row[col.id]) : '—'"></span>
                      </template>
                      {{-- account: name + id sub-text. Warning if unmapped. --}}
                      <template x-if="col.type==='account'">
                        <span>
                          <template x-if="row.account_id && row.account_name">
                            <span>
                              <span class="font-medium" x-text="row.account_name"></span>
                              <div style="font-size:10px;color:#94a3b8;font-family:monospace;" x-text="row.account_id"></div>
                            </span>
                          </template>
                          <template x-if="row.account_id && !row.account_name">
                            <span title="Hindi pa naka-register sa /ads_manager/ad_account">
                              <span style="color:#dc2626;font-weight:600;">⚠ unmapped</span>
                              <div style="font-size:10px;color:#94a3b8;font-family:monospace;" x-text="row.account_id"></div>
                            </span>
                          </template>
                          <template x-if="!row.account_id">
                            <span style="color:#cbd5e1;">—</span>
                          </template>
                        </span>
                      </template>
                      {{-- percent: 1 decimal + % suffix, em-dash fallback --}}
                      <template x-if="col.type==='percent'">
                        <span x-text="row[col.id] != null ? (Number(row[col.id]).toFixed(1) + '%') : '—'"></span>
                      </template>
                    </td>
                  </template>
                </tr>
              </template>

              <tr class="totals">
                <td class="col-checkbox"></td>
                <template x-for="col in visibleCols()" :key="'t-'+col.id">
                  <td :class="(col.align==='right' ? 'num' : '')">
                    {{-- Totals show data only for money + integer cols --}}
                    <template x-if="col.type==='name'">
                      <span x-text="`Results from ${rows.length} ${tabLabel()}`"></span>
                    </template>
                    <template x-if="col.type==='money'">
                      <span x-text="totals[col.id] != null ? money(totals[col.id]) : '—'"></span>
                    </template>
                    <template x-if="col.type==='integer'">
                      <span x-text="totals[col.id] != null ? num(totals[col.id]) : '—'"></span>
                    </template>
                    <template x-if="col.type==='percent'">
                      <span x-text="totals[col.id] != null ? (Number(totals[col.id]).toFixed(1) + '%') : '—'"></span>
                    </template>
                    {{-- on, date, days_running: blank in totals --}}
                  </td>
                </template>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </main>

  {{-- Flatpickr script --}}
  <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>

  <script>
    // GLOBAL column visibility/order injected by AdsManagerCampaignsController
    // (CEO-managed via /owner/column-settings). Same shape as the one used
    // inside /owner/private's expand panel.
    window.__CAMPAIGNS_COLS__ = @json($campaignsColsConfig ?? ['order' => [], 'hidden' => []]);
    // Conditional formatting rules for campaigns table. Cross-table refs
    // (e.g. cpp ≥ breakeven_cpp from owner_private) are SKIPPED here since
    // standalone view has no parent page-summary context — only literal rules
    // evaluate. Use /owner/private expand panel for full ref support.
    window.__CAMPAIGNS_COL_FORMAT__ = @json($campaignsColFormatRules ?? new \stdClass());

    function campaignsUI() {
      return {
        tab: 'campaigns',
        currentCampaignId: null,
        currentAdSetId: null,
        rows: [],
        totals: {},
        sortBy: 'default',
        sortDir: 'desc',
        dateLabel: 'This month',
        datePreset: 'this_month',  // bound to the preset dropdown
        loading: false,            // shown next to search input during fetch

        // Search placeholder is level-aware so the user knows what's actually
        // being searched (matches the controller's level-aware search clause).
        searchPlaceholder(){
          return this.tab === 'campaigns'
            ? 'Search Campaign name…'
            : this.tab === 'adsets'
              ? 'Search Ad set name…'
              : 'Search Ad headline / item…';
        },
        filters: {
          start_date: '',
          end_date: '',
          page_name: 'all',
          q: '',
          limit: 200,
        },

        // ── URL persistence helpers ────────────────────────────────────────
        // Read filters from window.location.search on init so refresh +
        // shared links preserve the user's view.
        readFiltersFromUrl(){
          try {
            const q = new URL(window.location.href).searchParams;
            const get = (k) => { const v = q.get(k); return (v === null || v === '') ? null : v; };
            const start = get('start_date');
            const end   = get('end_date');
            const page  = get('page_name');
            const search= get('q');
            const tab   = get('tab');
            const sort  = get('sort_by');
            const dir   = get('sort_dir');
            const preset= get('preset');
            if (start) this.filters.start_date = start;
            if (end)   this.filters.end_date   = end;
            if (page)  this.filters.page_name  = page;
            if (search)this.filters.q          = search;
            if (tab && ['campaigns','adsets','ads'].includes(tab)) this.tab = tab;
            if (sort)  this.sortBy  = sort;
            if (dir && ['asc','desc'].includes(dir)) this.sortDir = dir;
            if (preset !== null) this.datePreset = preset || '';
          } catch(e) { /* ignore — non-fatal */ }
        },
        // Mirror current filters into the URL via replaceState (no history entry).
        syncUrl(){
          try {
            const url = new URL(window.location.href);
            const set = (k, v) => {
              if (v === null || v === undefined || v === '' || v === 'all') url.searchParams.delete(k);
              else url.searchParams.set(k, v);
            };
            set('tab',         this.tab);
            set('start_date',  this.filters.start_date);
            set('end_date',    this.filters.end_date);
            set('page_name',   this.filters.page_name);
            set('q',           this.filters.q);
            set('sort_by',     this.sortBy === 'default' ? null : this.sortBy);
            set('sort_dir',    this.sortDir === 'desc' && this.sortBy === 'default' ? null : this.sortDir);
            set('preset',      this.datePreset);
            window.history.replaceState({}, '', url.toString());
          } catch(e) {}
        },

        // Apply a named date preset to the filter range. Recomputes labels
        // + Flatpickr display + reloads.
        applyDatePreset(preset){
          if (!preset) return;
          const ph    = new Date(new Date().toLocaleString('en-US', {timeZone: 'Asia/Manila'}));
          const today = new Date(ph.getFullYear(), ph.getMonth(), ph.getDate());
          let start, end;
          switch (preset) {
            case 'today':
              start = today; end = today; break;
            case 'yesterday': {
              const y = new Date(today); y.setDate(today.getDate() - 1);
              start = y; end = y; break;
            }
            case 'last_3': {
              const s = new Date(today); s.setDate(today.getDate() - 2);
              start = s; end = today; break;
            }
            case 'last_7': {
              const s = new Date(today); s.setDate(today.getDate() - 6);
              start = s; end = today; break;
            }
            case 'this_month':
              start = new Date(today.getFullYear(), today.getMonth(), 1);
              end   = today; break;
            case 'last_month':
              start = new Date(today.getFullYear(), today.getMonth() - 1, 1);
              end   = new Date(today.getFullYear(), today.getMonth(), 0); break; // last day of prev
            case 'last_and_this':
              start = new Date(today.getFullYear(), today.getMonth() - 1, 1);
              end   = today; break;
            default:
              return;
          }
          this.filters.start_date = this.ymd(start);
          this.filters.end_date   = this.ymd(end);
          this.setDateLabel();
          // Sync Flatpickr display kung naka-init
          if (window._fpInstance && typeof window._fpInstance.setDate === 'function') {
            window._fpInstance.setDate([this.filters.start_date, this.filters.end_date], false);
          }
          this.reload();
        },

        // Column catalog — each col has id (data key on row),
        // sort (server sort key, null = not sortable), type (renderer),
        // align (right for numerics), minw (px hint).
        // Order/visibility filtered at runtime via visibleCols().
        defaultCampaignsCols(){
          return [
            { id:'on',             label:'Off / On',         sort:null,             type:'on',           align:'left',  minw:80  },
            { id:'name',           label:'Name',             sort:null,             type:'name',         align:'left',  minw:200 },
            { id:'account',        label:'Account',          sort:null,             type:'account',      align:'left',  minw:140 },
            { id:'first_started',  label:'First launched',   sort:'first_started',  type:'date',         align:'left',  minw:110 },
            { id:'days_running',   label:'Days running',     sort:'first_started',  type:'days_running', align:'left',  minw:90  },
            { id:'latest_started', label:'Latest start',     sort:'latest_started', type:'date',         align:'left',  minw:110 },
            { id:'spend',          label:'Amount spent',     sort:'spend',          type:'money',        align:'right', minw:110 },
            { id:'cpm_1000',       label:'CPM (per 1,000)',  sort:'cpm_1000',       type:'money',        align:'right', minw:110 },
            { id:'cpm_msg',        label:'Cost per messaging', sort:'cpm_msg',      type:'money',        align:'right', minw:120 },
            { id:'cpr',            label:'Cost per result',  sort:'cpr',            type:'money',        align:'right', minw:120 },
            { id:'cpp',            label:'Cost per purchase',sort:'cpp',            type:'money',        align:'right', minw:130 },
            { id:'profit_pct',     label:'Profit %',         sort:'profit_pct',     type:'percent',      align:'right', minw:90  },
            { id:'impressions',    label:'Impressions',      sort:null,             type:'integer',      align:'right', minw:100 },
            { id:'link_clicks',       label:'Link clicks',         sort:'link_clicks',      type:'integer',      align:'right', minw:90  },
            { id:'welcome_msg_rate',  label:'Welcome Msg Rate (%)',sort:'welcome_msg_rate', type:'percent',      align:'right', minw:130 },
            { id:'messages',       label:'Messages',         sort:null,             type:'integer',      align:'right', minw:90  },
            { id:'conversion_rate',   label:'Conv Rate (%)',       sort:'conversion_rate',  type:'percent',      align:'right', minw:110 },
            { id:'purchases',      label:'Purchases',        sort:null,             type:'integer',      align:'right', minw:100 },
          ];
        },
        // Apply server-injected order + hidden filter.
        visibleCols(){
          const defs = this.defaultCampaignsCols();
          const defMap = Object.fromEntries(defs.map(c => [c.id, c]));
          const cfg = window.__CAMPAIGNS_COLS__ || {};
          const hidden = new Set(Array.isArray(cfg.hidden) ? cfg.hidden : []);
          const order  = Array.isArray(cfg.order) && cfg.order.length ? cfg.order : defs.map(c => c.id);
          const seen = new Set();
          const out = [];
          for (const id of order) {
            if (defMap[id] && !hidden.has(id) && !seen.has(id)) { out.push(defMap[id]); seen.add(id); }
          }
          // Append any catalog cols not in the saved order (for newly-added).
          for (const c of defs) {
            if (!hidden.has(c.id) && !seen.has(c.id)) out.push(c);
          }
          return out;
        },
        // Header label override — "Name" col uses tab-aware label.
        colLabel(col){
          if (col.id === 'name') {
            return this.tab === 'campaigns' ? 'Campaign'
                 : this.tab === 'adsets'    ? 'Ad set'
                 : 'Ad';
          }
          return col.label;
        },
        // Row name based on tab.
        rowName(row){
          if (this.tab === 'campaigns') return row.campaign_name || ('Campaign ' + row.campaign_id);
          if (this.tab === 'adsets')    return row.ad_set_name   || ('Ad set '   + row.ad_set_id);
          return row.headline || ('Ad ' + row.ad_id);
        },

        // Conditional formatting for campaigns table cells. Standalone view
        // has no /owner/private parent context, so cross-table refs (e.g.
        // `cpp ≥ owner_private:breakeven_cpp`) cannot resolve and are skipped
        // (the rule iteration falls through to the next rule).
        cellFormatStyle(colId, value){
          const rules = (window.__CAMPAIGNS_COL_FORMAT__ || {})[colId];
          if (!Array.isArray(rules) || rules.length === 0) return '';
          if (value == null || isNaN(Number(value))) return '';
          const v = Number(value);
          for (const r of rules) {
            // Cross-table ref → skip (no parent context here).
            if (r.value && typeof r.value === 'object' && r.value.type === 'ref') continue;
            const t = Number(r.value);
            if (isNaN(t)) continue;
            let hit = false;
            switch (r.op) {
              case '>=': hit = v >= t; break;
              case '>':  hit = v >  t; break;
              case '=':  hit = v == t; break;
              case '<=': hit = v <= t; break;
              case '<':  hit = v <  t; break;
            }
            if (hit) {
              const bg  = r.bg || '#fee2e2';
              // Default text = black. Override only when rule sets a color.
              const txt = (r.color && /^#[0-9a-f]{6}$/i.test(r.color)) ? r.color : '#111827';
              return 'background:' + bg + ';color:' + txt + ';' + (r.bold ? 'font-weight:700;' : '');
            }
          }
          return '';
        },

        // NEW: selections
        selectedCampaignIds: new Set(),
        selectedAdSetIds: new Set(),

        // Helpers
        titleForTab() {
          if (this.tab === 'campaigns') return 'Campaigns';
          if (this.tab === 'adsets') {
            if (this.selectedCampaignIds.size > 0) {
              const n = this.selectedCampaignIds.size;
              return `Ad sets for ${n} Campaign${n>1?'s':''}`;
            }
            if (this.currentCampaignId) return 'Ad sets for 1 Campaign';
            return 'Ad sets';
          }
          if (this.tab === 'ads') {
            if (this.selectedAdSetIds.size > 0) {
              const n = this.selectedAdSetIds.size;
              return `Ads for ${n} Ad set${n>1?'s':''}`;
            }
            if (this.currentAdSetId) return 'Ads for 1 Ad set';
            return 'Ads';
          }
          return 'Ads Manager';
        },
        tabLabel() { return this.tab === 'ads' ? 'ads' : (this.tab === 'adsets' ? 'ad sets' : 'campaigns'); },
        rowKey(r) { return (r.ad_id || r.ad_set_id || r.campaign_id); },
        money(v){ return `₱${Number(v||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2})}`; },
        num(v){ return Number(v||0).toLocaleString('en-PH'); },
        ymd(d){ const p=n=>String(n).padStart(2,'0'); return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate()); },

        // ── Started-date display helpers ─────────────────────────────────
        // fmtDate('2026-04-23') → 'Apr 23, 2026'
        fmtDate(s){
          if (!s) return '';
          // s may be 'YYYY-MM-DD' or 'YYYY-MM-DD HH:MM:SS' — parse safely.
          const d = new Date((s+'').slice(0,10) + 'T00:00:00');
          if (isNaN(d.getTime())) return s;
          return d.toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'});
        },
        // daysAgo('2026-04-23') → '3d ago' / 'today' / 'in 5d' (future = scheduled)
        daysAgo(s){
          if (!s) return '';
          const d = new Date((s+'').slice(0,10) + 'T00:00:00');
          if (isNaN(d.getTime())) return '';
          const ph = new Date(new Date().toLocaleString('en-US', {timeZone: 'Asia/Manila'}));
          const phMid = new Date(ph.getFullYear(), ph.getMonth(), ph.getDate());
          const diffDays = Math.round((phMid - d) / 86400000);
          if (diffDays === 0)  return 'today';
          if (diffDays === 1)  return '1d ago';
          if (diffDays > 1)    return diffDays + 'd ago';
          if (diffDays === -1) return 'tomorrow';
          return 'in ' + Math.abs(diffDays) + 'd';
        },
        // daysSince('2026-04-23') → '3' (raw number only — for the "Days running" column)
        daysSince(s){
          if (!s) return '';
          const d = new Date((s+'').slice(0,10) + 'T00:00:00');
          if (isNaN(d.getTime())) return '';
          const ph = new Date(new Date().toLocaleString('en-US', {timeZone: 'Asia/Manila'}));
          const phMid = new Date(ph.getFullYear(), ph.getMonth(), ph.getDate());
          return String(Math.max(0, Math.round((phMid - d) / 86400000)));
        },
        monthName(i){ return ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][i]; },
        setDateLabel(){
          if (!this.filters.start_date || !this.filters.end_date) { this.dateLabel = 'Select dates'; return; }
          const s = new Date(this.filters.start_date+'T00:00:00');
          const e = new Date(this.filters.end_date+'T00:00:00');
          const sameDay = s.getTime() === e.getTime();
          const sM = this.monthName(s.getMonth()), eM = this.monthName(e.getMonth());
          if (sameDay) {
            this.dateLabel = `${sM} ${s.getDate()}, ${s.getFullYear()}`;
          } else if (s.getFullYear() === e.getFullYear() && s.getMonth() === e.getMonth()) {
            this.dateLabel = `${sM} ${s.getDate()}–${e.getDate()}, ${s.getFullYear()}`;
          } else {
            this.dateLabel = `${sM} ${s.getDate()}, ${s.getFullYear()} – ${eM} ${e.getDate()}, ${e.getFullYear()}`;
          }
        },

        // NEW: selection helpers
        selectedCount(){
          return this.tab === 'campaigns'
            ? this.selectedCampaignIds.size
            : this.tab === 'adsets'
              ? this.selectedAdSetIds.size
              : 0;
        },
        clearSelection(which = this.tab){
          if (which === 'campaigns') this.selectedCampaignIds = new Set();
          if (which === 'adsets') this.selectedAdSetIds = new Set();
        },
        isChecked(row){
          if (this.tab==='campaigns') return this.selectedCampaignIds.has(row.campaign_id);
          if (this.tab==='adsets')    return this.selectedAdSetIds.has(row.ad_set_id);
          return false;
        },
        onCheck(row, checked){
          const set = this.tab==='campaigns' ? this.selectedCampaignIds : this.selectedAdSetIds;
          const key = this.tab==='campaigns' ? row.campaign_id : row.ad_set_id;
          if (checked) set.add(key); else set.delete(key);
        },
        allChecked(){
          if (!this.rows.length) return false;
          if (this.tab==='campaigns') return this.rows.every(r => this.selectedCampaignIds.has(r.campaign_id));
          if (this.tab==='adsets')    return this.rows.every(r => this.selectedAdSetIds.has(r.ad_set_id));
          return false;
        },
        toggleCheckAll(e){
          const checked = e.target.checked;
          if (this.tab==='campaigns') {
            if (checked) this.rows.forEach(r => this.selectedCampaignIds.add(r.campaign_id));
            else         this.rows.forEach(r => this.selectedCampaignIds.delete(r.campaign_id));
          } else if (this.tab==='adsets') {
            if (checked) this.rows.forEach(r => this.selectedAdSetIds.add(r.ad_set_id));
            else         this.rows.forEach(r => this.selectedAdSetIds.delete(r.ad_set_id));
          }
        },

        // Sorting
        toggleSort(k){
          if (this.sortBy === k) {
            this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
          } else {
            this.sortBy = k;
            this.sortDir = (k === 'spend') ? 'desc' : 'asc';
          }
          this.reload();
        },

        // Tab switching (respect selections)
        switchTab(t){
          this.tab = t;

          // if going to Ad sets & there are selected campaigns, ignore single-drill id
          if (t==='adsets' && this.selectedCampaignIds.size > 0) {
            this.currentCampaignId = null;
          }
          // if going to Ads & there are selected ad sets, ignore single-drill id
          if (t==='ads' && this.selectedAdSetIds.size > 0) {
            this.currentAdSetId = null;
          }
          this.reload();
        },

        // Row click drilldown
        rowClick(row){
          if (this.tab === 'campaigns') {
            // if there’s a selection, just go to Ad sets for selection
            if (this.selectedCampaignIds.size > 0) {
              this.currentCampaignId = null;
              this.tab = 'adsets';
              this.reload();
            } else {
              this.currentCampaignId = row.campaign_id;
              this.tab = 'adsets';
              this.reload();
            }
          } else if (this.tab === 'adsets') {
            if (this.selectedAdSetIds.size > 0) {
              this.currentAdSetId = null;
              this.tab = 'ads';
              this.reload();
            } else {
              this.currentAdSetId = row.ad_set_id;
              this.tab = 'ads';
              this.reload();
            }
          }
        },

        // Data loading
        async reload(){
          const level = this.tab === 'campaigns' ? 'campaigns' : (this.tab === 'adsets' ? 'adsets' : 'ads');
          const params = new URLSearchParams({
            level,
            start_date: this.filters.start_date || '',
            end_date: this.filters.end_date || '',
            page_name: this.filters.page_name || 'all',
            q: this.filters.q || '',
            sort_by: this.sortBy,
            sort_dir: this.sortDir,
            limit: this.filters.limit
          });

          // single-drill ids (only when no multi-select)
          if (this.currentCampaignId && level !== 'campaigns' && this.selectedCampaignIds.size === 0) {
            params.set('campaign_id', this.currentCampaignId);
          }
          if (this.currentAdSetId && level === 'ads' && this.selectedAdSetIds.size === 0) {
            params.set('ad_set_id', this.currentAdSetId);
          }

          // multi-select filters (affect child tabs)
          if (level === 'adsets' && this.selectedCampaignIds.size > 0) {
            params.set('campaign_ids', Array.from(this.selectedCampaignIds).join(','));
          }
          if (level === 'ads') {
            if (this.selectedAdSetIds.size > 0) {
              params.set('ad_set_ids', Array.from(this.selectedAdSetIds).join(','));
            } else if (this.selectedCampaignIds.size > 0) {
              // allow jumping Campaigns → Ads directly if needed
              params.set('campaign_ids', Array.from(this.selectedCampaignIds).join(','));
            }
          }

          // Mirror to URL before fetch so a refresh mid-load preserves intent.
          this.syncUrl();
          this.loading = true;

          try {
            const res  = await fetch('{{ route('ads_manager.campaigns.data') }}?'+params.toString());
            const json = await res.json();
            this.rows   = json.rows || [];
            this.totals = json.totals || {};
          } catch (err) {
            console.error('reload failed', err);
          } finally {
            this.loading = false;
          }
        },

        exportCsv(){
          const level = this.tab === 'campaigns' ? 'campaigns' : (this.tab === 'adsets' ? 'adsets' : 'ads');
          const params = new URLSearchParams({
            level,
            start_date: this.filters.start_date || '',
            end_date: this.filters.end_date || '',
            page_name: this.filters.page_name || 'all',
            q: this.filters.q || '',
            sort_by: this.sortBy,
            sort_dir: this.sortDir,
            limit: this.filters.limit
          });

          if (this.currentCampaignId && level !== 'campaigns' && this.selectedCampaignIds.size === 0) params.set('campaign_id', this.currentCampaignId);
          if (this.currentAdSetId && level === 'ads' && this.selectedAdSetIds.size === 0) params.set('ad_set_id', this.currentAdSetId);

          if (level === 'adsets' && this.selectedCampaignIds.size > 0) {
            params.set('campaign_ids', Array.from(this.selectedCampaignIds).join(','));
          }
          if (level === 'ads') {
            if (this.selectedAdSetIds.size > 0) {
              params.set('ad_set_ids', Array.from(this.selectedAdSetIds).join(','));
            } else if (this.selectedCampaignIds.size > 0) {
              params.set('campaign_ids', Array.from(this.selectedCampaignIds).join(','));
            }
          }

          params.set('export', 'csv');
          window.location = '{{ route('ads_manager.campaigns.data') }}?'+params.toString();
        },

        async init(){
          // Default to this month (from 1st day to today)
          const now   = new Date();
          const start = new Date(now.getFullYear(), now.getMonth(), 1);
          this.filters.start_date = this.ymd(start);
          this.filters.end_date   = this.ymd(now);
          // Read URL params after defaults so URL overrides win.
          this.readFiltersFromUrl();
          this.setDateLabel();

          // Init Flatpickr after Alpine renders
          this.$nextTick(() => {
            const fp = window.flatpickr || null;
            if (!fp) return;
            const inst = fp('#dateRange', {
              mode: 'range',
              dateFormat: 'Y-m-d',
              defaultDate: [this.filters.start_date, this.filters.end_date],
              onClose: (selectedDates, dateStr, instance) => {
                if (selectedDates.length === 2) {
                  const [from, to] = selectedDates;
                  this.filters.start_date = this.ymd(from);
                  this.filters.end_date   = this.ymd(to);
                  // User picked a custom range → clear preset selection.
                  this.datePreset = '';
                } else if (selectedDates.length === 1) {
                  const d = selectedDates[0];
                  this.filters.start_date = this.ymd(d);
                  this.filters.end_date   = this.ymd(d);
                } else {
                  return;
                }
                this.setDateLabel();
                this.reload();
              },
              onReady: (selectedDates, dateStr, instance) => {
                instance.input.value = `${this.filters.start_date} to ${this.filters.end_date}`;
                // Cache instance globally so applyDatePreset() can update display.
                window._fpInstance = instance;
              }
            });
            window._fpInstance = inst;
          });

          await this.reload();
        }
      }
    }
  </script>
</body>
</html>
