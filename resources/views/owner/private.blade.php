<!doctype html>
<html lang="en" x-data="privateUI()" x-init="init()" x-cloak>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="csrf-token" content="{{ csrf_token() }}" />
  <title>Daily Summary • Private</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <style>
    [x-cloak] { display:none !important; }
    * { box-sizing:border-box; margin:0; padding:0; }

    html, body { height:100%; background:#f1f5f9; }
    body { display:flex; flex-direction:column; overflow:hidden; }

    #nav {
      flex-shrink:0; height:52px; background:#1e293b;
      border-bottom:1px solid #334155;
      display:flex; align-items:center; padding:0 18px; gap:10px;
      position:relative; z-index:100;
    }

    #scroll { flex:1; overflow:auto; padding:0 16px; min-width:0; }

    .card {
      background:#fff; border-radius:10px;
      box-shadow:0 1px 4px rgba(0,0,0,.09);
      min-width:900px; margin:14px 0;
    }

    table { width:100%; border-collapse:separate; border-spacing:0; }

    thead th {
      position:sticky; top:0; z-index:30;
      background:#1e293b; color:#94a3b8;
      font-size:11px; font-weight:600;
      text-transform:uppercase; letter-spacing:.05em;
      padding:9px 10px; white-space:nowrap;
      border-bottom:2px solid #0f172a;
      user-select:none;
    }
    thead th:first-child { border-radius:10px 0 0 0; }
    thead th:last-child  { border-radius:0 10px 0 0; }
    thead th.sortable { cursor:pointer; }
    thead th.sortable:hover { background:#263347; color:#e2e8f0; }
    thead th.col-active { color:#60a5fa; }
    thead th[draggable="true"] { cursor:grab; }
    thead th[draggable="true"]:active { cursor:grabbing; }
    thead th.drag-over { box-shadow:inset 2px 0 0 #60a5fa; }

    tr.total-row td {
      position:sticky; bottom:0; z-index:20;
      font-weight:700; color:#0f172a;
      background:#f1f5f9; border-top:2px solid #cbd5e1;
    }

    tbody td { border-bottom:1px solid #f1f5f9; }
    tbody tr:hover td { background:#f8fafc; }
    tbody tr.editing-row td { background:#eff6ff !important; }

    td {
      font-size:12.5px; color:#374151;
      padding:7px 10px; white-space:nowrap;
      vertical-align:middle;
    }

    .ii {
      border:1.5px solid #93c5fd; border-radius:6px;
      padding:4px 7px; font-size:12px;
      text-align:right; outline:none; background:#fff;
    }
    .ii:focus { border-color:#3b82f6; box-shadow:0 0 0 2px rgba(59,130,246,.15); }
    .ii-comment {
      border:1.5px solid #93c5fd; border-radius:6px;
      padding:4px 7px; font-size:11px; outline:none; background:#fff; width:100%;
    }
    .ii-comment:focus { border-color:#3b82f6; }

    .badge { display:inline-block; padding:2px 8px; border-radius:9999px; font-size:11px; font-weight:700; }
    .bg  { background:#dcfce7; color:#15803d; }
    .by  { background:#fef9c3; color:#a16207; }
    .bo  { background:#ffedd5; color:#c2410c; }
    .br  { background:#fee2e2; color:#b91c1c; }
    .bb  { background:#dbeafe; color:#1d4ed8; }
    .bx  { background:#f1f5f9; color:#94a3b8; }

    .null-warn { background:#fef2f2 !important; }

    .spin {
      display:inline-block; width:15px; height:15px;
      border:2px solid #475569; border-top-color:#60a5fa;
      border-radius:50%; animation:rot .7s linear infinite; vertical-align:middle;
    }
    @keyframes rot { to { transform:rotate(360deg); } }

    .btn-refresh {
      display:flex; align-items:center; justify-content:center;
      width:32px; height:32px; border-radius:6px; border:1px solid #475569;
      background:#0f172a; color:#94a3b8; cursor:pointer; font-size:16px;
      transition:color .15s, border-color .15s;
    }
    .btn-refresh:hover { color:#e2e8f0; border-color:#94a3b8; }
    .btn-refresh.spinning svg { animation:rot .7s linear infinite; }

    .btn-save   { font-size:11px; padding:3px 10px; border-radius:5px; cursor:pointer; border:none; background:#16a34a; color:#fff; font-weight:700; }
    .btn-save:disabled { opacity:.6; cursor:not-allowed; }
    .btn-cancel { font-size:11px; padding:3px 8px; border-radius:5px; cursor:pointer; border:1.5px solid #e2e8f0; background:#f1f5f9; color:#475569; }
    .btn-set    { font-size:11px; padding:3px 9px; border-radius:5px; cursor:pointer; border:1.5px solid #e2e8f0; background:#f8fafc; color:#64748b; }
    .btn-set:hover { border-color:#93c5fd; color:#2563eb; background:#eff6ff; }

    /* ── Inline campaigns expand: simple chevron toggle ───────────────── */
    /* The Page cell uses a fixed-width "gutter" so the chevron sits at the
       same x position on every row regardless of the page name length.
       Inner content (name + optional warnings) flows naturally. */
    .page-cell { display:flex; align-items:flex-start; gap:6px; text-align:left; }
    .page-cell-body { flex:1; min-width:0; }
    .expand-chev {
      flex-shrink:0;
      width:22px; height:22px;
      display:inline-flex; align-items:center; justify-content:center;
      background:none; border:1px solid transparent; border-radius:4px;
      cursor:pointer; color:#64748b; font-size:16px; line-height:1;
      padding:0; margin-top:1px;
      transition:color .12s, background .12s, border-color .12s, transform .15s;
      user-select:none;
    }
    .expand-chev:hover { color:#0f172a; background:#f1f5f9; border-color:#cbd5e1; }
    .expand-chev.active { color:#0f172a; background:#dbeafe; border-color:#93c5fd; transform:rotate(90deg); }

    /* FB Ads Manager-like table for the inline expand panel. */
    .fb-table { font-size:12px; border-collapse:separate; border-spacing:0; width:100%; background:white; }
    .fb-table thead th {
      background:#f0f2f5; color:#65676b;
      font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.04em;
      border-bottom:1px solid #ced0d4; padding:7px 10px;
      text-align:left; vertical-align:middle; white-space:nowrap;
    }
    .fb-table thead th.sortable { cursor:pointer; user-select:none; }
    .fb-table thead th.sortable:hover { background:#e4e6eb; }
    .fb-table thead th.text-right { text-align:right; }
    /* Force center alignment para sa lahat ng numeric cells + headers,
       including yung mga in-line styled via Alpine col.align bindings. */
    .fb-table thead th[style*="text-align:right"],
    .fb-table tbody td[style*="text-align:right"] { text-align:center !important; }
    .fb-table tbody td {
      border-bottom:1px solid #e4e6eb; padding:8px 10px;
      color:#1c1e21; vertical-align:top; font-size:12px;
    }
    .fb-table tbody tr:hover td { background:#f7f8fa; }
    .fb-table .num { text-align:center; font-variant-numeric:tabular-nums; }
    /* Center-align all numeric cells (.num) sa /owner/private. Header td/th
       inline `text-align:right` overrides ay overridden via this rule too:
       higher specificity .fb-table td.num beats default plain `td`. */
    .fb-table td.num, .fb-table th.num { text-align:center !important; }
    .fb-table .name { color:#1877f2; font-weight:500; }
    .fb-table .name:hover { text-decoration:underline; }
    .fb-table .sub { font-size:10px; color:#65676b; margin-top:1px; }
    .fb-pill { display:inline-flex; align-items:center; gap:5px; font-size:11px; line-height:1; }
    .fb-pill::before { content:''; width:7px; height:7px; border-radius:50%; display:inline-block; }
    .fb-pill.active::before { background:#42b72a; }
    .fb-pill.off::before    { background:#bcc0c4; }
    .fb-pill.active { color:#1c1e21; }
    .fb-pill.off    { color:#65676b; }

    /* Expand-row container — drop the row's outer cell padding so the
       inner panel can fill edge-to-edge cleanly. */
    tr.page-expand-row > td { padding:0; }
    .expand-panel { background:#f8fafc; border-top:1px solid #cbd5e1; border-bottom:1px solid #cbd5e1; padding:12px 16px; }
    .expand-panel .expand-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; }
    .expand-panel .expand-title  { font-size:12px; color:#475569; }
    .expand-panel .expand-title b { color:#0f172a; }
    .expand-panel .expand-close  { font-size:11px; color:#64748b; cursor:pointer; padding:2px 8px; border-radius:5px; border:1px solid #cbd5e1; background:white; }
    .expand-panel .expand-close:hover { background:#f1f5f9; }
    .expand-wrap { background:white; border:2px solid #64748b; border-radius:6px; overflow-x:auto; max-width:100%; }

    /* Visual divider between the last "Active" campaign and the first "Off"
       campaign in the expanded list. Backend sorts is_on DESC by default so
       the transition happens once. JS post-process marks the boundary
       tbody with `c._divider_top` after fetch. */
    .fb-table tbody.campaign-active-off-divider { border-top:3px solid #475569; }
    .fb-table tbody.campaign-active-off-divider > tr:first-child > td { border-top:3px solid #475569; }

    /* Nested expand backgrounds (visual hierarchy). */
    .expand-nest-1 td.nest-host { background:#eef2f7; padding:10px 14px; }
    .expand-nest-2 td.nest-host { background:#dde4ed; padding:10px 14px; }

    /* ── Expanded page-section grouping ───────────────────────────────────
       Goal: when a page row is expanded, it forms a clear "card" with its
       inline campaigns panel so the eye doesn't blur the boundary between
       this page and the next one. Achieved via:
         1) thick bottom border on the expand-row (4px slate-700)
         2) tinted left accent bar (3px blue) running through both the
            page row + the expand panel
         3) subtle box-shadow under the expand panel for "lifted" feel
       Non-expanded sections stay clean (1px row separator only). */
    tbody.page-section-expanded > tr.page-row-expanded > td:first-child {
      box-shadow: inset 3px 0 0 #2563eb;
    }
    tbody.page-section-expanded > tr.page-row-expanded > td {
      background: #f1f5f9;
    }
    tbody.page-section-expanded > tr.page-expand-row > td {
      border-bottom: 4px solid #334155;
      box-shadow: inset 3px 0 0 #2563eb, 0 2px 0 rgba(15,23,42,0.06);
    }
    /* Visual fallback for non-collapsing-border tables: ensure the row
       above the next page-row has a subtle bottom rule. */
    tbody.page-section-expanded + tbody > tr:first-child > td {
      border-top: 0;
    }
  </style>
</head>
<body>

  <!-- Nav -->
  <div id="nav">
    <span style="color:#f1f5f9;font-weight:700;font-size:14px;">Daily Summary</span>

    <!-- Inline item search + checkbox list (left side, beside title) -->
    <div style="position:relative;margin-left:10px;" @click.away="itemFilterOpen=false"
         @keydown.escape.window="itemFilterOpen=false">
      <div style="position:relative;display:flex;align-items:center;">
        <span style="position:absolute;left:10px;font-size:12px;color:#94a3b8;pointer-events:none;">🔎</span>
        <input type="text" x-model="itemFilterSearch"
               @focus="itemFilterOpen=true"
               @click="itemFilterOpen=true"
               placeholder="Search item…"
               style="background:#0f172a;color:#e2e8f0;border:1px solid #475569;
                      border-radius:6px;padding:5px 28px 5px 28px;font-size:12px;
                      outline:none;width:240px;transition:border-color .15s;"
               onfocus="this.style.borderColor='#2563eb';"
               onblur="this.style.borderColor='#475569';">
        <button x-show="itemFilterSearch" type="button"
                @click="itemFilterSearch=''"
                style="position:absolute;right:6px;background:none;border:none;
                       color:#94a3b8;cursor:pointer;font-size:14px;line-height:1;
                       padding:2px 6px;border-radius:4px;"
                title="Clear search">×</button>
      </div>
      <div x-show="itemFilterOpen" x-transition.opacity.duration.150ms x-cloak
           style="position:absolute;top:calc(100% + 6px);left:0;z-index:9999;
                  background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;
                  width:300px;max-height:340px;display:flex;flex-direction:column;
                  box-shadow:0 20px 40px -8px rgba(15,23,42,.35), 0 4px 12px rgba(15,23,42,.12);
                  overflow:hidden;">
        <div style="padding:8px 10px;border-bottom:1px solid #f1f5f9;
                    display:flex;justify-content:space-between;align-items:center;
                    background:#f8fafc;">
          <span style="font-size:10px;font-weight:700;color:#64748b;letter-spacing:.5px;text-transform:uppercase;">
            Filter by item
          </span>
          <span style="font-size:10px;color:#94a3b8;">
            <span x-text="selectedItems.length" style="color:#2563eb;font-weight:700;"></span>
            <span> / </span>
            <span x-text="uniqueItems().length"></span>
          </span>
        </div>
        <div style="overflow-y:auto;flex:1;padding:4px;">
          <template x-if="visibleItems().length === 0">
            <div style="text-align:center;color:#94a3b8;font-size:11px;padding:20px;">No items match.</div>
          </template>
          <template x-for="name in visibleItems()" :key="name">
            <label style="display:flex;align-items:center;gap:10px;padding:6px 10px;cursor:pointer;
                          border-radius:6px;font-size:12px;color:#0f172a;line-height:1.3;
                          transition:background .1s;"
                   onmouseover="this.style.background='#eff6ff'"
                   onmouseout="this.style.background=''">
              <input type="checkbox"
                     :checked="selectedItems.includes(name)"
                     @change="toggleItem(name)"
                     style="accent-color:#2563eb;cursor:pointer;width:14px;height:14px;flex-shrink:0;">
              <span x-text="name"
                    style="flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:500;"></span>
            </label>
          </template>
        </div>
        <div x-show="selectedItems.length"
             style="border-top:1px solid #f1f5f9;padding:6px 10px;background:#f8fafc;
                    display:flex;justify-content:flex-end;">
          <button type="button" @click="clearItems()"
                  style="background:none;border:none;color:#ef4444;cursor:pointer;
                         font-size:11px;font-weight:700;padding:2px 6px;border-radius:4px;"
                  onmouseover="this.style.background='#fef2f2'"
                  onmouseout="this.style.background=''">Clear all</button>
        </div>
      </div>
    </div>

    <div style="flex:1"></div>
    <span x-show="saveMsg" x-transition style="color:#4ade80;font-size:13px;font-weight:700;" x-text="saveMsg"></span>
    <span style="color:#94a3b8;font-size:11px;font-weight:600;">From</span>
    <input type="date" x-model="startDate" @change="load()"
           style="background:#0f172a;color:#e2e8f0;border:1px solid #475569;
                  border-radius:6px;padding:5px 10px;font-size:13px;outline:none;cursor:pointer;">
    <span style="color:#94a3b8;font-size:11px;font-weight:600;">To</span>
    <input type="date" x-model="endDate" @change="load()"
           style="background:#0f172a;color:#e2e8f0;border:1px solid #475569;
                  border-radius:6px;padding:5px 10px;font-size:13px;outline:none;cursor:pointer;">
    <a :href="'{{ route('owner.private.breakdown') }}?start_date='+startDate+'&end_date='+endDate"
       target="_blank"
       x-show="!isSingleDate"
       style="background:#1e293b;color:#93c5fd;border:1px solid #475569;
              border-radius:6px;padding:5px 10px;font-size:12px;font-weight:700;
              cursor:pointer;text-decoration:none;"
       title="View page × date × item matrix for this range">🧭 Matrix</a>

    @if(($isCEO ?? false))
    <a :href="'{{ route('owner.private.daily') }}?start_date='+startDate+'&end_date='+endDate"
       target="_blank"
       style="background:#1e293b;color:#fde68a;border:1px solid #475569;
              border-radius:6px;padding:5px 10px;font-size:12px;font-weight:700;
              cursor:pointer;text-decoration:none;"
       title="Per-day overall summary across all pages (CEO)">📅 Daily</a>
    @endif

    <button class="btn-refresh" :class="loading ? 'spinning' : ''" @click="load()" title="Refresh">
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"
           fill="none" stroke="currentColor" stroke-width="2.2"
           stroke-linecap="round" stroke-linejoin="round">
        <path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/>
        <path d="M21 3v5h-5"/>
        <path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/>
        <path d="M3 21v-5h5"/>
      </svg>
    </button>

    {{-- Expand-all / Hide-all toggle for the per-page campaigns expand.
         One button — label flips based on current state:
           - Some/all rows collapsed (or no rows expanded) → "▶ Expand all"
           - All visible rows expanded → "▼ Hide all" --}}
    <button type="button" @click="toggleAllExpand()"
            :title="anyExpanded() ? 'Collapse all campaigns panels' : 'Expand campaigns for every page row'"
            style="background:#1e293b;color:#86efac;border:1px solid #475569;
                   border-radius:6px;padding:5px 10px;font-size:12px;font-weight:700;
                   cursor:pointer;margin-left:4px;">
      <span x-text="anyExpanded() ? '▼ Hide all' : '▶ Expand all'"></span>
    </button>

    @if(!empty($isCEO))
      <a href="{{ route('owner.column-settings') }}" target="_blank"
         title="Configure column visibility / order globally for /owner/private and the campaigns expand panel"
         style="background:#1e293b;color:#a5b4fc;border:1px solid #475569;
                border-radius:6px;padding:5px 10px;font-size:12px;font-weight:700;
                cursor:pointer;margin-left:4px;text-decoration:none;">⚙ Columns</a>
      <a href="{{ route('jnt.supply.excluded.index') }}" target="_blank"
         title="Manage excluded pages (affects /owner/private + /jnt/supply)"
         style="background:#1e293b;color:#f87171;border:1px solid #475569;
                border-radius:6px;padding:5px 10px;font-size:12px;font-weight:700;
                cursor:pointer;margin-left:4px;text-decoration:none;">🚫 Excluded</a>
    @endif

    @if(!empty($isCEO) || !empty($isMarketingOIC))
      <a href="{{ route('owner.private.edit-logs') }}"
         title="View RTS / COGS edit history"
         style="background:#1e293b;color:#86efac;border:1px solid #475569;
                border-radius:6px;padding:5px 10px;font-size:12px;font-weight:700;
                cursor:pointer;margin-left:4px;text-decoration:none;">📜 Logs</a>
      <button id="refreshPrimaryBtn"
              @click="refreshPrimary()"
              :disabled="refreshing"
              title="Recompute daily_page_primary_item (last 90 days)"
              style="background:#1e293b;color:#fbbf24;border:1px solid #475569;
                     border-radius:6px;padding:5px 10px;font-size:12px;font-weight:700;
                     cursor:pointer;margin-left:4px;">
        <span x-show="!refreshing">🔄 Primary Items</span>
        <span x-show="refreshing">Recomputing…</span>
      </button>
    @endif
  </div>

  <!-- Selected item pills -->
  <div x-show="selectedItems.length" x-cloak
       style="background:#f1f5f9;border-bottom:1px solid #e2e8f0;padding:6px 12px;
              display:flex;flex-wrap:wrap;gap:4px;align-items:center;">
    <span style="font-size:11px;color:#475569;font-weight:700;margin-right:4px;">Filter:</span>
    <template x-for="name in selectedItems" :key="name">
      <span style="display:inline-flex;align-items:center;gap:4px;background:#dbeafe;
                   color:#1e3a8a;border:1px solid #93c5fd;border-radius:12px;
                   padding:2px 8px;font-size:11px;font-weight:600;">
        <span x-text="name"></span>
        <button type="button" @click="toggleItem(name)"
                style="background:none;border:none;color:#1e3a8a;cursor:pointer;
                       font-size:14px;line-height:1;padding:0;font-weight:700;"
                title="Remove">×</button>
      </span>
    </template>
    <button type="button" @click="clearItems()"
            style="background:none;border:none;color:#ef4444;cursor:pointer;
                   font-size:11px;font-weight:700;margin-left:4px;">Clear all</button>
  </div>

  <!-- Scroll area -->
  <div id="scroll">
    <div class="card">
      <table>
        <thead>
          <tr>
            <!-- Fixed: Page (sortable) -->
            <th
              :class="['sortable', ac('page_name') ? 'col-active' : '']"
              style="text-align:left;min-width:110px;"
              @click="sb('page_name')"
            >
              <span>Page</span>
              <span x-text="arr('page_name')" style="font-size:10px;"></span>
            </th>
            <!-- Fixed: Item (sortable) -->
            <th
              :class="['sortable', ac('item_name') ? 'col-active' : '']"
              style="text-align:left;min-width:160px;"
              @click="sb('item_name')"
            >
              <span>Item</span>
              <span x-text="arr('item_name')" style="font-size:10px;"></span>
            </th>

            <!-- Draggable/reorderable columns -->
            <template x-for="col in cols" :key="col.id">
              <th
                draggable="true"
                :class="[col.sort ? 'sortable' : '', col.sort && ac(col.sort) ? 'col-active' : '', dragOver===col.id ? 'drag-over' : '']"
                :style="'text-align:'+col.align+';min-width:'+col.minw+'px'"
                @click="col.sort && sb(col.sort)"
                @dragstart="colDragStart($event, col.id)"
                @dragend="colDragEnd($event)"
                @dragover.prevent="dragOver=col.id"
                @dragleave="dragOver=null"
                @drop.prevent="colDrop($event, col.id)"
              >
                <span x-text="col.label"></span>
                <template x-if="col.sort">
                  <span x-text="arr(col.sort)" style="font-size:10px;"></span>
                </template>
              </th>
            </template>

            <!-- Fixed: Actions -->
            <th style="text-align:center;min-width:90px;"></th>
          </tr>
        </thead>
        <tbody class="msg-tbody">

          <template x-if="rows.length === 0 && !loading">
            <tr><td :colspan="cols.length + 3" style="text-align:center;padding:48px;color:#94a3b8;font-size:13px;">
              No data for selected date.
            </td></tr>
          </template>

          <template x-if="rows.length === 0 && loading">
            <tr><td :colspan="cols.length + 3" style="text-align:center;padding:48px;color:#94a3b8;font-size:13px;">
              <span class="spin" style="margin-right:6px;"></span>Loading…
            </td></tr>
          </template>

        </tbody>

          {{-- Per-row: <tbody> wraps both the page row AND its inline expand
               row so they stay interleaved. HTML allows multiple <tbody>
               per <table>; using one per iteration is the canonical Alpine
               pattern when an x-for needs to produce multiple <tr>s. --}}
          <template x-for="(row, idx) in sortedRows()" :key="row.page_key">
          <tbody :class="'page-row-tbody' + ((expandedPages[row.page_name] || {}).open ? ' page-section-expanded' : '')">
            <tr :class="(editIdx === idx ? 'editing-row ' : '') + ((expandedPages[row.page_name] || {}).open ? 'page-row-expanded' : '')">

              <!-- Fixed: Page -->
              <td>
                {{-- Page cell layout: chevron in a fixed-width gutter so it
                     vertically aligns to the FIRST LINE of the page name
                     across every row (regardless of multi-line warnings). --}}
                <div class="page-cell">
                  {{-- Inline expand chevron — fetches & shows this page's
                       campaigns/adsets/ads via /ads_manager/campaigns/data.
                       Right arrow rotates down when open. --}}
                  <button class="expand-chev"
                          :class="(expandedPages[row.page_name] || {}).open ? 'active' : ''"
                          @click.stop="togglePageExpand(row.page_name)"
                          :title="(expandedPages[row.page_name] || {}).open ? 'Hide campaigns' : 'Show campaigns'">›</button>
                  <div class="page-cell-body">
                    <template x-if="row.is_range">
                      <a href="#" @click.prevent="openBreakdown(row)"
                         style="font-weight:600;color:#0f172a;white-space:normal;line-height:1.35;
                                text-decoration:underline;text-decoration-color:#cbd5e1;
                                text-underline-offset:2px;cursor:pointer;"
                         onmouseover="this.style.color='#2563eb';this.style.textDecorationColor='#2563eb';"
                         onmouseout="this.style.color='#0f172a';this.style.textDecorationColor='#cbd5e1';"
                         title="View per-date primary item breakdown"
                         x-text="row.page_name"></a>
                    </template>
                    <template x-if="!row.is_range">
                      <span style="font-weight:600;color:#0f172a;white-space:normal;line-height:1.35;"
                            x-text="row.page_name"></span>
                    </template>
                    <template x-if="row.mixed_primary">
                      <div style="cursor:pointer;" @click="openBreakdown(row)"
                           :title="row.distinct_items_in_range + ' distinct primary items across ' + row.range_days + '-day range. Click to see breakdown.'">
                        <div style="font-size:10px;color:#b45309;font-weight:600;line-height:1.3;margin-top:2px;">
                          ⚠ mixed primary · <span x-text="row.included_days + '/' + row.range_days + ' d'"></span>
                        </div>
                        <template x-if="row.anchor_first_date">
                          <div style="font-size:9px;color:#64748b;line-height:1.2;">
                            computed since <span x-text="fmtMD(row.anchor_first_date)"></span>
                          </div>
                        </template>
                      </div>
                    </template>
                  </div>
                </div>
              </td>

              <!-- Fixed: Item -->
              <td style="text-align:center;">
                <div style="font-weight:600;color:#1e293b;white-space:normal;line-height:1.35;"
                     x-text="sq(row.item_name)"></div>
                <template x-for="s in (row.secondary_items||[])" :key="s.item_name">
                  <div style="font-size:10px;color:#94a3b8;line-height:1.4;">
                    <span x-text="sq(s.item_name)+' ('+s.total_orders+')'"></span>
                    <template x-if="s.price && s.price !== row.price">
                      <span style="color:#cbd5e1;" x-text="' · '+money(s.price)"></span>
                    </template>
                  </div>
                </template>
              </td>

              <!-- Dynamic columns -->
              <template x-for="col in cols" :key="col.id">
                <td :style="'text-align:'+col.align+';'+(col.id==='rts_set'&&editIdx!==idx&&row.rts_pct===null?'background:#fef2f2;':'')+(col.id==='item_val'&&editIdx!==idx&&row.item_value===null?'background:#fef2f2;':'')+(col.id==='proj_profit'?pbStyle(row.projected_profit,row):'')+(col.id==='proj_pct'&&row.projected_profit!==null&&row.gross_sales>0?rppStyle(row.projected_profit/row.gross_sales*100):'')+(col.id==='proj_pct_1d'&&row.proj_pct_last_day!==null?rppStyle(row.proj_pct_last_day):'')+(col.id==='proj_pct_3d'&&row.proj_pct_last_3d!==null?rppStyle(row.proj_pct_last_3d):'')+(col.id==='proj_pct_7d'&&row.proj_pct_last_7d!==null?rppStyle(row.proj_pct_last_7d):'')+(col.id==='proj_prof_1d'?pbStyleN(row.projected_profit_last_day,1):'')+(col.id==='proj_prof_3d'?pbStyleN(row.projected_profit_last_3d,3):'')+(col.id==='proj_prof_7d'?pbStyleN(row.projected_profit_last_7d,7):'')+cellFormatStyle(col.id, cellValueFor(col, row), row)">

                  <!-- adspent -->
                  <template x-if="col.id==='adspent'">
                    <span style="color:#111;font-weight:500;" x-text="money(row.adspent)"></span>
                  </template>

                  <!-- orders -->
                  <template x-if="col.id==='orders'">
                    <span style="color:#111;" x-text="num(row.orders)"></span>
                  </template>

                  <!-- cpp -->
                  <template x-if="col.id==='cpp'">
                    <span style="color:#111;" x-text="md(row.cpp)"></span>
                  </template>

                  <!-- proceed -->
                  <template x-if="col.id==='proceed'">
                    <span style="color:#111;font-weight:600;" x-text="num(row.proceed_orders)"></span>
                  </template>

                  <!-- pcpp -->
                  <template x-if="col.id==='pcpp'">
                    <span style="color:#111;" x-text="md(row.proceed_cpp)"></span>
                  </template>

                  <!-- TCPR (pending rate) — (1 − proceed/orders) × 100 -->
                  <template x-if="col.id==='tcpr'">
                    <span x-text="tcprFor(row) === null ? '—' : tcprFor(row).toFixed(1) + '%'"></span>
                  </template>

                  <!-- Breakeven CPP — derived from existing profit math at the
                       global target Proj.% (configurable via /owner/column-settings). -->
                  <template x-if="col.id==='breakeven_cpp'">
                    <span :title="breakevenCppFor(row) === null ? 'Missing rts / item_value / price / orders' : ('Target ' + (window.__BREAKEVEN_PCT__ ?? 5) + '% Proj.% · actual CPP ' + md(row.cpp))"
                          x-text="breakevenCppFor(row) === null ? '—' : md(breakevenCppFor(row))"></span>
                  </template>

                  <!-- proj_profit — cell background handles color; bold text -->
                  <template x-if="col.id==='proj_profit'">
                    <span style="font-weight:700;" :style="'color:'+pbColor(row.projected_profit)"
                          x-text="md(row.projected_profit)"></span>
                  </template>

                  <!-- per_order -->
                  <template x-if="col.id==='per_order'">
                    <span style="color:#111;" x-text="md(row.proj_profit_per_order)"></span>
                  </template>

                  <!-- proj_pct = projected_profit ÷ gross_sales × 100 (net margin) -->
                  <template x-if="col.id==='proj_pct'">
                    <span>
                      <template x-if="row.projected_profit !== null && row.gross_sales > 0">
                        <span style="font-weight:700;"
                              x-text="(row.projected_profit/row.gross_sales*100).toFixed(1)+'%'"
                              :title="'profit ₱'+Number(row.projected_profit||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / gross ₱'+Number(row.gross_sales).toLocaleString('en-PH',{maximumFractionDigits:0})"></span>
                      </template>
                      <template x-if="!(row.projected_profit !== null && row.gross_sales > 0)">
                        <span style="color:#cbd5e1;">—</span>
                      </template>
                    </span>
                  </template>

                  <!-- proj_pct_1d = same formula, but only the slice on end_date.
                       Strict end_date — null when end_date has no slice for this page+item. -->
                  <template x-if="col.id==='proj_pct_1d'">
                    <span>
                      <template x-if="row.proj_pct_last_day !== null">
                        <span style="font-weight:700;"
                              x-text="row.proj_pct_last_day.toFixed(1)+'%'"
                              :title="'1D profit ₱'+Number(row.projected_profit_last_day||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / gross ₱'+Number(row.gross_sales_last_day||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' · orders '+(row.orders_last_day||0)+' · proceed '+(row.proceed_last_day||0)+' (end_date only)'"></span>
                      </template>
                      <template x-if="row.proj_pct_last_day === null">
                        <span style="color:#cbd5e1;" title="No slice on end_date for this page+item, or RTS/item_value missing">—</span>
                      </template>
                    </span>
                  </template>

                  <!-- proj_pct_3d / proj_pct_7d — last 3/7 days ending at end_date. -->
                  <template x-if="col.id==='proj_pct_3d'">
                    <span>
                      <template x-if="row.proj_pct_last_3d !== null">
                        <span style="font-weight:700;"
                              x-text="row.proj_pct_last_3d.toFixed(1)+'%'"
                              :title="'3D profit ₱'+Number(row.projected_profit_last_3d||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / gross ₱'+Number(row.gross_sales_last_3d||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' · orders '+(row.orders_last_3d||0)+' · proceed '+(row.proceed_last_3d||0)"></span>
                      </template>
                      <template x-if="row.proj_pct_last_3d === null">
                        <span style="color:#cbd5e1;" title="No slice in last 3 days, or RTS/item_value missing">—</span>
                      </template>
                    </span>
                  </template>
                  <template x-if="col.id==='proj_pct_7d'">
                    <span>
                      <template x-if="row.proj_pct_last_7d !== null">
                        <span style="font-weight:700;"
                              x-text="row.proj_pct_last_7d.toFixed(1)+'%'"
                              :title="'7D profit ₱'+Number(row.projected_profit_last_7d||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / gross ₱'+Number(row.gross_sales_last_7d||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' · orders '+(row.orders_last_7d||0)+' · proceed '+(row.proceed_last_7d||0)"></span>
                      </template>
                      <template x-if="row.proj_pct_last_7d === null">
                        <span style="color:#cbd5e1;" title="No slice in last 7 days, or RTS/item_value missing">—</span>
                      </template>
                    </span>
                  </template>

                  <!-- proj_prof_1d / 3d / 7d — peso totals, color-coded via pbColor like the range Proj.Profit. -->
                  <template x-if="col.id==='proj_prof_1d'">
                    <span style="font-weight:700;" :style="'color:'+pbColor(row.projected_profit_last_day)"
                          x-text="md(row.projected_profit_last_day)"
                          :title="row.projected_profit_last_day!==null ? '1D profit (end_date only) · orders '+(row.orders_last_day||0)+' · proceed '+(row.proceed_last_day||0) : 'No slice on end_date'"></span>
                  </template>
                  <template x-if="col.id==='proj_prof_3d'">
                    <span style="font-weight:700;" :style="'color:'+pbColor(row.projected_profit_last_3d)"
                          x-text="md(row.projected_profit_last_3d)"
                          :title="row.projected_profit_last_3d!==null ? '3D profit (last 3 days) · orders '+(row.orders_last_3d||0)+' · proceed '+(row.proceed_last_3d||0) : 'No slice in last 3 days'"></span>
                  </template>
                  <template x-if="col.id==='proj_prof_7d'">
                    <span style="font-weight:700;" :style="'color:'+pbColor(row.projected_profit_last_7d)"
                          x-text="md(row.projected_profit_last_7d)"
                          :title="row.projected_profit_last_7d!==null ? '7D profit (last 7 days) · orders '+(row.orders_last_7d||0)+' · proceed '+(row.proceed_last_7d||0) : 'No slice in last 7 days'"></span>
                  </template>

                  <!-- jnt_rts — actual RTS% from JNT (90-day window) -->
                  <template x-if="col.id==='jnt_rts'">
                    <span>
                      <template x-if="row.jnt_rts_pct !== null">
                        <span style="color:#111;font-weight:700;font-size:12px;"
                              x-text="row.jnt_rts_pct.toFixed(1)+'%('+row.jnt_rts_cnt+')'"></span>
                      </template>
                      <template x-if="row.jnt_rts_pct === null">
                        <span style="color:#cbd5e1;font-size:11px;">—</span>
                      </template>
                    </span>
                  </template>

                  <!-- jnt_del — actual Delivered% from JNT -->
                  <template x-if="col.id==='jnt_del'">
                    <span>
                      <template x-if="row.jnt_del_pct !== null">
                        <span style="color:#111;font-size:12px;"
                              x-text="row.jnt_del_pct.toFixed(1)+'%('+row.jnt_del_cnt+')'"></span>
                      </template>
                      <template x-if="row.jnt_del_pct === null">
                        <span style="color:#cbd5e1;font-size:11px;">—</span>
                      </template>
                    </span>
                  </template>

                  <!-- jnt_transit — actual In-transit% from JNT -->
                  <template x-if="col.id==='jnt_transit'">
                    <span>
                      <template x-if="row.jnt_transit_pct !== null">
                        <span style="color:#111;font-size:12px;"
                              x-text="row.jnt_transit_pct.toFixed(1)+'%('+row.jnt_transit_cnt+')'"></span>
                      </template>
                      <template x-if="row.jnt_transit_pct === null">
                        <span style="color:#cbd5e1;font-size:11px;">—</span>
                      </template>
                    </span>
                  </template>

                  <!-- rts_set — manually set RTS% (editable) + comment display -->
                  <template x-if="col.id==='rts_set'">
                    <span>
                      <template x-if="editIdx === idx">
                        <div style="display:flex;flex-direction:column;gap:4px;min-width:140px;">
                          <input class="ii" type="number" step="0.1" min="0" max="100"
                                 x-model="ev.rts_pct" placeholder="RTS%"
                                 @keydown.enter="save()" @keydown.escape="cancel()"
                                 style="width:70px;">
                          <input class="ii-comment" type="text" maxlength="500"
                                 x-model="ev.comment" placeholder="Comment (optional)"
                                 @keydown.enter="save()" @keydown.escape="cancel()">
                          <div style="font-size:9px;color:#94a3b8;">Both 0 = delete override</div>
                        </div>
                      </template>
                      <template x-if="editIdx !== idx">
                        <div>
                          <template x-if="row.rts_pct !== null">
                            <div>
                              <span style="font-weight:700;color:#000;"
                                    x-text="row.rts_pct.toFixed(1)+'%'"></span>
                              <div style="font-size:9px;color:#94a3b8;margin-top:2px;"
                                   x-text="'from ' + row.settings_date"></div>
                              <template x-if="row.rts_comment">
                                <div style="font-size:9px;color:#64748b;margin-top:1px;font-style:italic;white-space:normal;max-width:120px;"
                                     x-text="'💬 '+row.rts_comment"></div>
                              </template>
                            </div>
                          </template>
                          <template x-if="row.rts_pct === null">
                            <span style="color:#fca5a5;font-style:italic;font-size:11px;">—</span>
                          </template>
                        </div>
                      </template>
                    </span>
                  </template>

                  <!-- price — mode COD, read-only -->
                  <template x-if="col.id==='price'">
                    <span>
                      <template x-if="row.price !== null">
                        <div>
                          <span style="color:#374151;" x-text="money(row.price)"></span>
                          <template x-if="row.price_min !== null">
                            <div style="font-size:9px;color:#94a3b8;"
                                 x-text="'↓ ' + money(row.price_min)"></div>
                          </template>
                          <template x-if="row.price_max !== null">
                            <div style="font-size:9px;color:#94a3b8;"
                                 x-text="'↑ ' + money(row.price_max)"></div>
                          </template>
                        </div>
                      </template>
                      <template x-if="row.price === null">
                        <span style="color:#94a3b8;font-size:11px;">—</span>
                      </template>
                    </span>
                  </template>

                  <!-- item_val — editable inline -->
                  <template x-if="col.id==='item_val'">
                    <span>
                      <template x-if="editIdx === idx">
                        <div style="display:flex;flex-direction:column;gap:4px;min-width:140px;">
                          <input class="ii" type="number" step="1" min="0"
                                 x-model="ev.item_value" placeholder="Item Val."
                                 @keydown.enter="save()" @keydown.escape="cancel()"
                                 style="width:78px;">
                          <input class="ii-comment" type="text" maxlength="500"
                                 x-model="ev.iv_comment" placeholder="Comment (optional)"
                                 @keydown.enter="save()" @keydown.escape="cancel()">
                        </div>
                      </template>
                      <template x-if="editIdx !== idx">
                        <span>
                          <template x-if="row.item_value !== null">
                            <div>
                              <span style="color:#111;" x-text="money(row.item_value)"></span>
                              <template x-if="row.item_value_source === 'cogs'">
                                <div style="font-size:9px;color:#cbd5e1;">cogs</div>
                              </template>
                              <template x-if="row.item_value_source === 'manual' && row.settings_date">
                                <div style="font-size:9px;color:#94a3b8;margin-top:2px;"
                                     x-text="'from ' + row.settings_date"></div>
                              </template>
                              <template x-if="row.item_value_comment && row.item_value_source === 'manual'">
                                <div style="font-size:9px;color:#64748b;margin-top:1px;font-style:italic;white-space:normal;max-width:110px;"
                                     x-text="'💬 '+row.item_value_comment"></div>
                              </template>
                            </div>
                          </template>
                          <template x-if="row.item_value === null">
                            <span style="color:#fca5a5;font-style:italic;font-size:11px;">—</span>
                          </template>
                        </span>
                      </template>
                    </span>
                  </template>

                  <!-- ship -->
                  <template x-if="col.id==='ship'">
                    <span style="color:#111;"
                          x-text="row.shipping_fee !== null ? money(row.shipping_fee) : '—'"></span>
                  </template>

                  <!-- cod_fee -->
                  <template x-if="col.id==='cod_fee'">
                    <span style="color:#111;"
                          x-text="row.cod_fee !== null ? money(row.cod_fee) : '—'"></span>
                  </template>

                </td>
              </template>

              <!-- Fixed: Actions -->
              <td style="text-align:center;">
                <template x-if="editIdx === idx">
                  <span style="display:inline-flex;gap:5px;align-items:center;">
                    <button class="btn-save" @click="save()" :disabled="saving"
                            x-text="saving ? '…' : 'Save'"></button>
                    <button class="btn-cancel" @click="cancel()">✕</button>
                  </span>
                </template>
                <template x-if="editIdx !== idx && row.is_single_date">
                  <button class="btn-set" @click="startEdit(idx, row)"
                          x-text="row.has_settings ? 'Edit' : '+ Set'"></button>
                </template>
                <template x-if="editIdx !== idx && !row.is_single_date">
                  <span style="font-size:10px;color:#cbd5e1;" title="Switch From = To to edit">—</span>
                </template>
              </td>
            </tr>

            {{-- Inline expand row — sits directly after THIS page row when
                 expandedPages[page_name].open is true. Hosts the nested
                 campaigns / adsets / ads view from /ads_manager/campaigns/data.
                 Wrapped together with the page row inside a per-iteration
                 <tbody> so they stay interleaved (Alpine x-for needs single
                 root child — <tbody> serves as that root). --}}
            <tr x-show="(expandedPages[row.page_name] || {}).open"
                class="page-expand-row">
              <td :colspan="(cols.length + 3)" style="padding:0;">
                @include('owner._private_expand_inline')
              </td>
            </tr>
          </tbody>
          </template>

          <tbody class="total-tbody">
          <!-- Total row -->
          <template x-if="rows.length > 0">
            <tr class="total-row">
              <td>TOTAL</td>
              <td></td>
              <template x-for="col in cols" :key="col.id">
                <td :style="'text-align:'+col.align+';'+(col.id==='proj_profit'?pbStyle(tot().projected_profit,{included_days:rangeDays,range_days:rangeDays}):'')+(col.id==='proj_prof_1d'?pbStyleN(tot().projected_profit_last_day,1):'')+(col.id==='proj_prof_3d'?pbStyleN(tot().projected_profit_last_3d,3):'')+(col.id==='proj_prof_7d'?pbStyleN(tot().projected_profit_last_7d,7):'')">
                  <template x-if="col.id==='adspent'">
                    <span x-text="money(tot().adspent)"></span>
                  </template>
                  <template x-if="col.id==='orders'">
                    <span x-text="num(tot().orders)"></span>
                  </template>
                  <template x-if="col.id==='cpp'">
                    <span style="color:#475569;" x-text="md(tot().cpp)"></span>
                  </template>
                  <template x-if="col.id==='proceed'">
                    <span x-text="num(tot().proceed_orders)"></span>
                  </template>
                  <template x-if="col.id==='pcpp'">
                    <span style="color:#475569;" x-text="md(tot().proceed_cpp)"></span>
                  </template>
                  <template x-if="col.id==='tcpr'">
                    <span x-text="(tot().orders > 0) ? ((1 - tot().proceed_orders / tot().orders) * 100).toFixed(1) + '%' : '—'"></span>
                  </template>
                  <template x-if="col.id==='breakeven_cpp'">
                    <span style="color:#cbd5e1;">—</span>
                  </template>
                  <template x-if="col.id==='proj_profit'">
                    <span style="font-weight:700;" x-text="md(tot().projected_profit)"></span>
                  </template>
                  <template x-if="col.id==='per_order'">
                    <span style="color:#111;" x-text="md(tot().proj_profit_per_order)"></span>
                  </template>
                  <template x-if="col.id==='proj_pct'">
                    <span style="font-weight:700;color:#111;"
                          x-text="tot().proj_pct!=null ? tot().proj_pct.toFixed(1)+'%' : '—'"
                          :title="tot().gross_sales!=null ? 'profit ₱'+Number(tot().projected_profit||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / gross ₱'+Number(tot().gross_sales).toLocaleString('en-PH',{maximumFractionDigits:0}) : ''"></span>
                  </template>
                  <template x-if="col.id==='proj_pct_1d'">
                    <span style="font-weight:700;color:#111;"
                          x-text="tot().proj_pct_1d!=null ? tot().proj_pct_1d.toFixed(1)+'%' : '—'"
                          :title="tot().gross_sales_last_day ? '1D profit ₱'+Number(tot().projected_profit_last_day||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / gross ₱'+Number(tot().gross_sales_last_day||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' (end_date only)' : ''"></span>
                  </template>
                  <template x-if="col.id==='proj_pct_3d'">
                    <span style="font-weight:700;color:#111;"
                          x-text="tot().proj_pct_3d!=null ? tot().proj_pct_3d.toFixed(1)+'%' : '—'"
                          :title="tot().gross_sales_last_3d ? '3D profit ₱'+Number(tot().projected_profit_last_3d||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / gross ₱'+Number(tot().gross_sales_last_3d||0).toLocaleString('en-PH',{maximumFractionDigits:0}) : ''"></span>
                  </template>
                  <template x-if="col.id==='proj_pct_7d'">
                    <span style="font-weight:700;color:#111;"
                          x-text="tot().proj_pct_7d!=null ? tot().proj_pct_7d.toFixed(1)+'%' : '—'"
                          :title="tot().gross_sales_last_7d ? '7D profit ₱'+Number(tot().projected_profit_last_7d||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / gross ₱'+Number(tot().gross_sales_last_7d||0).toLocaleString('en-PH',{maximumFractionDigits:0}) : ''"></span>
                  </template>
                  <template x-if="col.id==='proj_prof_1d'">
                    <span style="font-weight:700;" x-text="md(tot().projected_profit_last_day)"></span>
                  </template>
                  <template x-if="col.id==='proj_prof_3d'">
                    <span style="font-weight:700;" x-text="md(tot().projected_profit_last_3d)"></span>
                  </template>
                  <template x-if="col.id==='proj_prof_7d'">
                    <span style="font-weight:700;" x-text="md(tot().projected_profit_last_7d)"></span>
                  </template>
                  <template x-if="!['adspent','orders','cpp','proceed','pcpp','tcpr','breakeven_cpp','proj_profit','per_order','proj_pct','proj_pct_1d','proj_pct_3d','proj_pct_7d','proj_prof_1d','proj_prof_3d','proj_prof_7d'].includes(col.id)">
                    <span></span>
                  </template>
                </td>
              </template>
              <td></td>
            </tr>
          </template>

        </tbody>
      </table>
      <div style="padding:7px 12px;font-size:10px;color:#94a3b8;border-top:1px solid #f1f5f9;">
        One row per page · Price = mode COD · Ship/proceed · COD Fee=Price×rate×(1+VAT)/delivered · Proj.%=/Order÷Price · RTS/Del/Transit% = JNT 90-day · Drag headers to reorder
        <template x-if="skippedCount > 0">
          <span style="color:#b45309;font-weight:600;margin-left:8px;"
                :title="'Pages excluded: '+skippedPages.join(', ')">
            ⚠ <span x-text="skippedCount"></span> page(s) skipped (tied primary or unresolved — hover for list)
          </span>
        </template>
      </div>
    </div>
  </div>

  <script>
  // GLOBAL column settings injected by OwnerPrivateController (managed via
  // CEO-only /owner/column-settings). Read in initCols() and the Alpine
  // expand panel so /owner/private + the inline campaigns expand share the
  // same visibility/order as /ads_manager/campaigns.
  window.__OWNER_PRIVATE_COLS__ = @json($ownerPrivateColsConfig ?? ['order' => [], 'hidden' => []]);
  window.__CAMPAIGNS_COLS__     = @json($campaignsColsConfig    ?? ['order' => [], 'hidden' => []]);
  // Computation + formatting settings.
  window.__BREAKEVEN_PCT__      = {{ $breakevenTargetPct ?? 5 }};
  window.__COL_FORMAT__         = @json($colFormatRules ?? new \stdClass());
  // Campaigns table rules — used by the inline expand panel cells.
  // Cross-table ref values (e.g. `cpp ≥ breakeven_cpp`) resolved by looking
  // up the parent page-summary row via row.page_name.
  window.__CAMPAIGNS_COL_FORMAT__ = @json($campaignsColFormatRules ?? new \stdClass());
  // Fee settings — passed from server (FeeSetting::getRate at index time).
  // Used by breakevenCppFor() + campaignProfitPct() instead of hardcoded.
  // SF = shipping_fee_per_order, F = cod_fee_rate × (1 + cod_fee_vat_rate).
  window.__FEES__ = {
    SF:       {{ is_numeric($feeShipping) ? $feeShipping : 'null' }},
    COD_RATE: {{ is_numeric($feeCodRate)  ? $feeCodRate  : 'null' }},
    VAT_RATE: {{ is_numeric($feeVatRate)  ? $feeVatRate  : 'null' }},
    F:        {{ (is_numeric($feeCodRate) && is_numeric($feeVatRate)) ? ($feeCodRate * (1 + $feeVatRate)) : 'null' }},
  };

  function privateUI() {
    return {
      ...(function(){
        // URL precedence: ?start_date + ?end_date > legacy ?date= > default 30-day range ending yesterday PH
        const qs = new URLSearchParams(window.location.search);
        const re = /^\d{4}-\d{2}-\d{2}$/;
        const urlStart = qs.get('start_date');
        const urlEnd   = qs.get('end_date');
        const urlDate  = qs.get('date');
        const ph = new Date(new Date().toLocaleString('en-US',{timeZone:'Asia/Manila'}));
        ph.setDate(ph.getDate()-1);  // yesterday
        const p = n => String(n).padStart(2,'0');
        const fmt = d => d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate());
        const yesterday = fmt(ph);
        // Default range = last 30 days ending yesterday (inclusive). 30 days = 29 day step back
        // so [yesterday-29d, yesterday] is exactly 30 calendar days.
        const monthAgo = new Date(ph);
        monthAgo.setDate(monthAgo.getDate() - 29);
        const defaultStart = fmt(monthAgo);
        let s, e;
        if (urlStart && re.test(urlStart) && urlEnd && re.test(urlEnd)) { s = urlStart; e = urlEnd; }
        else if (urlDate && re.test(urlDate)) { s = urlDate; e = urlDate; }
        else { s = defaultStart; e = yesterday; }
        if (s > e) { const t = s; s = e; e = t; }
        return { startDate: s, endDate: e };
      })(),

      rows:[], loading:false, editIdx:-1, editRow:null,
      ev:{ item_value:'', rts_pct:'', comment:'' },
      saving:false, saveMsg:'',
      refreshing:false,
      skippedCount:0, skippedPages:[],
      isSingleDate:true, rangeDays:1,
      sortCol:'', sortDir:'desc',
      dragSrc:null, dragOver:null,
      cols:[],

      // ── Inline campaigns/adsets/ads expand (per-page) ─────────────────────
      // Each map keyed by entity id holds { open, loading, error, <list> }.
      // Cached so collapse/re-expand doesn't re-fetch.
      expandedPages: {},      // page_name → { open, loading, error, campaigns:[] }
      expandedCampaigns: {},  // campaign_id → { open, loading, error, adsets:[] }
      expandedAdSets: {},     // ad_set_id   → { open, loading, error, ads:[] }

      // ── Item filter (multi-select) ───────────────────────────────────────
      selectedItems: [],
      itemFilterOpen: false,
      itemFilterSearch: '',
      uniqueItems() {
        const set = new Set();
        for (const r of this.rows) { if (r.item_name) set.add(r.item_name); }
        return [...set].sort((a,b)=>a.localeCompare(b));
      },
      visibleItems() {
        const q = (this.itemFilterSearch||'').toLowerCase().trim();
        const items = this.uniqueItems();
        if (!q) return items;
        return items.filter(n => n.toLowerCase().includes(q));
      },
      toggleItem(name) {
        const i = this.selectedItems.indexOf(name);
        if (i>=0) this.selectedItems.splice(i,1);
        else this.selectedItems.push(name);
      },
      clearItems() { this.selectedItems = []; },
      filteredRows() {
        if (!this.selectedItems.length) return this.rows;
        const sel = new Set(this.selectedItems);
        return this.rows.filter(r => sel.has(r.item_name));
      },

      // ── Column definitions ────────────────────────────────────────────────
      defaultCols() {
        return [
          { id:'adspent',    label:'Adspent',    sort:'adspent',              align:'center', minw:90  },
          { id:'orders',     label:'Orders',     sort:'orders',               align:'center', minw:65  },
          { id:'cpp',        label:'CPP',        sort:'cpp',                  align:'center', minw:75  },
          { id:'proceed',    label:'Proceed',    sort:'proceed_orders',       align:'center', minw:70  },
          { id:'pcpp',       label:'P.CPP',      sort:'proceed_cpp',          align:'center', minw:75  },
          { id:'tcpr',          label:'TCPR',           sort:null,                          align:'center', minw:65  },
          { id:'breakeven_cpp', label:this._breakevenLabel(), sort:null,                    align:'center', minw:115 },
          { id:'proj_profit',label:'Prof.Profit',sort:'projected_profit',     align:'center', minw:95  },
          { id:'per_order',  label:'/Order',     sort:'proj_profit_per_order',align:'center', minw:75  },
          { id:'proj_pct',     label:'Prof.%(1M)',     sort:'proj_pct_computed',           align:'center', minw:75  },
          { id:'proj_pct_1d',  label:'Prof.%(1D)',     sort:'proj_pct_last_day',           align:'center', minw:75  },
          { id:'proj_pct_3d',  label:'Prof.%(3D)',     sort:'proj_pct_last_3d',            align:'center', minw:75  },
          { id:'proj_pct_7d',  label:'Prof.%(7D)',     sort:'proj_pct_last_7d',            align:'center', minw:75  },
          { id:'proj_prof_1d', label:'Prof.Profit(1D)',sort:'projected_profit_last_day',   align:'center', minw:105 },
          { id:'proj_prof_3d', label:'Prof.Profit(3D)',sort:'projected_profit_last_3d',    align:'center', minw:105 },
          { id:'proj_prof_7d', label:'Prof.Profit(7D)',sort:'projected_profit_last_7d',    align:'center', minw:105 },
          { id:'jnt_rts',      label:'RTS%',           sort:null,                          align:'center', minw:100 },
          { id:'jnt_del',    label:'Del%',       sort:null,                   align:'center', minw:90  },
          { id:'jnt_transit',label:'Transit%',   sort:null,                   align:'center', minw:85  },
          { id:'rts_set',    label:'Set RTS%',   sort:'rts_pct',              align:'center', minw:110 },
          { id:'price',      label:'Price',      sort:null,                   align:'center', minw:85  },
          { id:'item_val',   label:'Item Val.',  sort:null,                   align:'center', minw:80  },
          { id:'ship',       label:'Ship',       sort:null,                   align:'center', minw:58  },
          { id:'cod_fee',    label:'COD Fee',    sort:null,                   align:'center', minw:72  },
        ];
      },

      initCols() {
        const defs = this.defaultCols();
        const defMap = Object.fromEntries(defs.map(c => [c.id, c]));

        // Server-injected GLOBAL config (CEO-managed via /owner/column-settings)
        // takes precedence over localStorage. The hidden array filters out
        // entire columns; the order array sets the visual sequence.
        const serverCfg = window.__OWNER_PRIVATE_COLS__ || {};
        const hiddenSet = new Set(Array.isArray(serverCfg.hidden) ? serverCfg.hidden : []);

        let orderedIds;
        if (Array.isArray(serverCfg.order) && serverCfg.order.length) {
          orderedIds = serverCfg.order.slice();
        } else {
          // Fallback: localStorage (legacy per-browser preference) → defaults.
          const saved = localStorage.getItem('private_col_order_v1');
          if (saved) {
            try { orderedIds = JSON.parse(saved); } catch(e) { orderedIds = null; }
          }
          if (!orderedIds) orderedIds = defs.map(c => c.id);
        }

        // Resolve to col definitions, drop unknown ids, exclude hidden,
        // then append any catalog cols not in the saved order.
        const seen = new Set();
        const ordered = [];
        for (const id of orderedIds) {
          if (defMap[id] && !hiddenSet.has(id) && !seen.has(id)) {
            ordered.push(defMap[id]);
            seen.add(id);
          }
        }
        for (const c of defs) {
          if (!hiddenSet.has(c.id) && !seen.has(c.id)) ordered.push(c);
        }
        this.cols = ordered;
      },

      saveCols() {
        const order = this.cols.map(c => c.id);
        localStorage.setItem('private_col_order_v1', JSON.stringify(order));
        // Persist to DB (partial update — only `order` sent, so `hidden` and
        // `visible_by_role` saved sa /owner/column-settings are preserved).
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        fetch('{{ route('owner.column-settings.save') }}', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
          body: JSON.stringify({ table: 'owner_private', order }),
        }).catch(e => console.warn('saveCols DB sync failed', e));
      },

      // ── Column drag-and-drop ──────────────────────────────────────────────
      colDragStart(e, colId) {
        this.dragSrc = colId;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', colId);
      },
      colDragEnd(e) {
        this.dragSrc  = null;
        this.dragOver = null;
      },
      colDrop(e, targetId) {
        if (!this.dragSrc || this.dragSrc === targetId) { this.dragOver=null; return; }
        const from = this.cols.findIndex(c => c.id === this.dragSrc);
        const to   = this.cols.findIndex(c => c.id === targetId);
        if (from < 0 || to < 0) { this.dragOver=null; return; }
        const [moved] = this.cols.splice(from, 1);
        this.cols.splice(to, 0, moved);
        this.saveCols();
        this.dragSrc = this.dragOver = null;
      },

      // ── Load ─────────────────────────────────────────────────────────────
      async load(){
        this.loading=true; this.editIdx=-1; this.saveMsg='';
        // Normalize: if start > end, swap before query
        if (this.startDate && this.endDate && this.startDate > this.endDate) {
          const t = this.startDate; this.startDate = this.endDate; this.endDate = t;
        }
        // Persist range in URL so refresh restores it
        const qs = new URLSearchParams({ start_date: this.startDate, end_date: this.endDate });
        history.replaceState(null,'','?'+qs.toString());
        try{
          const r = await fetch('{{ route('owner.private.item-summary') }}?'+qs.toString());
          const j = await r.json();
          this.rows = j.rows||[];
          this.skippedCount = j.skipped_count || 0;
          this.skippedPages = j.skipped_pages || [];
          this.isSingleDate = !!j.is_single_date;
          this.rangeDays    = Number(j.range_days || 1);
        }catch(e){ console.error(e); }
        finally{ this.loading=false; }
      },

      // ── Page breakdown (navigate to separate route) ───────────────────
      openBreakdown(row){
        const qs = new URLSearchParams({
          page_key:   row.page_key,
          start_date: this.startDate,
          end_date:   this.endDate,
        });
        window.open('{{ route('owner.private.breakdown') }}?'+qs.toString(), '_blank');
      },

      // ── CEO: manual recompute of daily_page_primary_item ────────────────
      async refreshPrimary(){
        if (this.refreshing) return;
        if (!confirm('Recompute primary-item table for the last 90 days?\nThis rebuilds the cached per-page/date primary item used by /owner/private and /jnt/supply.')) return;
        this.refreshing = true; this.saveMsg = '';
        try {
          const r = await fetch('{{ route('owner.private.refresh-primary-items') }}', {
            method: 'POST',
            headers: {
              'Content-Type':'application/json',
              'Accept':'application/json',
              'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({}),
          });
          const j = await r.json();
          if (j.ok && j.summary) {
            const s = j.summary;
            this.saveMsg = `✓ ${s.rows_upserted} rows · ${s.ties_skipped} ties skipped (${s.from}→${s.to}, ${s.elapsed_s}s)`;
            setTimeout(() => this.saveMsg = '', 6000);
            await this.load();
          } else {
            alert(j.message || 'Refresh failed');
          }
        } catch(e) {
          console.error(e);
          alert('Network error');
        } finally {
          this.refreshing = false;
        }
      },

      // ── Sort ─────────────────────────────────────────────────────────────
      sb(col){
        if(this.sortCol===col){ this.sortDir = this.sortDir==='asc'?'desc':'asc'; }
        else{ this.sortCol=col; this.sortDir='desc'; }
      },
      arr(col){ return this.sortCol!==col?'':(this.sortDir==='asc'?' ↑':' ↓'); },
      ac(col) { return this.sortCol===col?'col-active':''; },
      sortedRows(){
        const base = this.filteredRows();
        if(!this.sortCol) return base;
        const c=this.sortCol, d=this.sortDir==='asc'?1:-1;

        // Computed-value sort handler — for columns na hindi naka-stored as direct field
        // (e.g., proj_pct = projected_profit / gross_sales × 100, computed inline sa view).
        const computedFor = (row, col) => {
          if (col === 'proj_pct_computed') {
            if (row.projected_profit !== null && row.gross_sales > 0) {
              return row.projected_profit / row.gross_sales * 100;
            }
            return null;
          }
          return row[col];
        };

        return [...base].sort((a,b)=>{
          let va = computedFor(a, c);
          let vb = computedFor(b, c);
          if(va==null) va=typeof vb==='string'?'':-Infinity;
          if(vb==null) vb=typeof va==='string'?'':-Infinity;
          if(typeof va==='string') return d*va.localeCompare(vb);
          return d*(Number(va)-Number(vb));
        });
      },

      // ── Edit ─────────────────────────────────────────────────────────────
      startEdit(idx, row){
        this.editIdx=idx; this.editRow=row; this.saveMsg='';
        this.ev = {
          item_value: row.item_value !== null ? row.item_value : '',
          rts_pct:    row.rts_pct   !== null ? row.rts_pct   : '',
          comment:    row.rts_comment || '',
          iv_comment: row.item_value_comment || '',
        };
      },
      cancel(){ this.editIdx=-1; this.editRow=null; this.ev={item_value:'',rts_pct:'',comment:'',iv_comment:''}; },

      async save(){
        if (!this.isSingleDate) { alert('Switch to single-date mode (From = To) to edit.'); return; }
        const itemVal = parseFloat(this.ev.item_value);
        const rts     = parseFloat(this.ev.rts_pct);
        if(isNaN(itemVal)||itemVal<0)   { alert('Item Value needed (≥ 0). Set both to 0 to delete this date\'s override.'); return; }
        if(isNaN(rts)||rts<0||rts>100) { alert('RTS% needed (0–100). Set both to 0 to delete this date\'s override.'); return; }

        const row = this.editRow;
        this.saving = true;
        try {
          const r = await fetch('{{ route('owner.private.item-setting.save') }}', {
            method:  'POST',
            headers: { 'Content-Type':'application/json', 'X-CSRF-TOKEN':'{{ csrf_token() }}' },
            body:    JSON.stringify({
              page_name:      row.page_name,
              item_name:      row.item_name,
              item_value:     itemVal,
              rts_pct:        rts,
              effective_date: this.endDate,
              comment:              this.ev.comment    || null,
              item_value_comment:   this.ev.iv_comment || null,
            }),
          });
          let j;
          try { j = await r.json(); }
          catch { alert('Save failed: server returned non-JSON (HTTP '+r.status+')'); return; }
          if (!r.ok) {
            const msg = j.message||(j.errors?Object.values(j.errors).flat().join('\n'):'HTTP '+r.status);
            alert('Save failed:\n'+msg); return;
          }
          if (j.ok) {
            this.cancel();
            await this.load();
            this.saveMsg = j.deleted ? '🗑 Deleted!' : '✓ Saved!';
            setTimeout(()=>{ this.saveMsg=''; }, 2500);
          }
        } catch(e) {
          console.error(e); alert('Save failed: '+e.message);
        } finally {
          this.saving=false;
        }
      },

      // ── Totals ────────────────────────────────────────────────────────────
      tot() {
        const t = { adspent:0, orders:0, proceed_orders:0, gross_sales:0, projected_profit:null, cpp:null, proceed_cpp:null, proj_profit_per_order:null, proj_pct:null,
                    projected_profit_last_day:null, gross_sales_last_day:0, proj_pct_1d:null,
                    projected_profit_last_3d:null, gross_sales_last_3d:0, proj_pct_3d:null,
                    projected_profit_last_7d:null, gross_sales_last_7d:0, proj_pct_7d:null };
        let hasP=false, hasG=false, hasP1=false, hasP3=false, hasP7=false;
        for (const r of this.filteredRows()) {
          t.adspent        += Number(r.adspent        ||0);
          t.orders         += Number(r.orders         ||0);
          t.proceed_orders += Number(r.proceed_orders ||0);
          if (r.gross_sales!=null){ t.gross_sales += Number(r.gross_sales); hasG=true; }
          if (r.projected_profit!=null){ t.projected_profit=(t.projected_profit||0)+r.projected_profit; hasP=true; }
          // Trailing-window totals — only sum rows that had any slice in that window.
          if (r.projected_profit_last_day!=null){ t.projected_profit_last_day=(t.projected_profit_last_day||0)+Number(r.projected_profit_last_day); hasP1=true; }
          if (r.gross_sales_last_day!=null) t.gross_sales_last_day += Number(r.gross_sales_last_day);
          if (r.projected_profit_last_3d!=null){ t.projected_profit_last_3d=(t.projected_profit_last_3d||0)+Number(r.projected_profit_last_3d); hasP3=true; }
          if (r.gross_sales_last_3d!=null)  t.gross_sales_last_3d += Number(r.gross_sales_last_3d);
          if (r.projected_profit_last_7d!=null){ t.projected_profit_last_7d=(t.projected_profit_last_7d||0)+Number(r.projected_profit_last_7d); hasP7=true; }
          if (r.gross_sales_last_7d!=null)  t.gross_sales_last_7d += Number(r.gross_sales_last_7d);
        }
        if(!hasP) t.projected_profit=null;
        if(!hasG) t.gross_sales=null;
        if(!hasP1) t.projected_profit_last_day=null;
        if(!hasP3) t.projected_profit_last_3d=null;
        if(!hasP7) t.projected_profit_last_7d=null;
        t.cpp                  = t.orders>0         ? t.adspent/t.orders         : null;
        t.proceed_cpp          = t.proceed_orders>0  ? t.adspent/t.proceed_orders : null;
        t.proj_profit_per_order= (t.orders>0&&t.projected_profit!=null) ? t.projected_profit/t.orders : null;
        t.proj_pct             = (t.projected_profit!=null && t.gross_sales && t.gross_sales>0)
                                    ? (t.projected_profit/t.gross_sales*100) : null;
        t.proj_pct_1d          = (t.projected_profit_last_day!=null && t.gross_sales_last_day>0)
                                    ? (t.projected_profit_last_day/t.gross_sales_last_day*100) : null;
        t.proj_pct_3d          = (t.projected_profit_last_3d!=null && t.gross_sales_last_3d>0)
                                    ? (t.projected_profit_last_3d/t.gross_sales_last_3d*100) : null;
        t.proj_pct_7d          = (t.projected_profit_last_7d!=null && t.gross_sales_last_7d>0)
                                    ? (t.projected_profit_last_7d/t.gross_sales_last_7d*100) : null;
        return t;
      },

      // ── Helpers ───────────────────────────────────────────────────────────
      sq(n){ return n||''; },
      // Projected Profit threshold is PER-DAY. If the row has a limited included-day window
      // (mixed primary), divide by included_days. Otherwise divide by range_days.
      ppd(v, row){
        if (v==null || isNaN(Number(v))) return null;
        let days = 1;
        if (row) {
          days = Number(row.included_days) > 0 ? Number(row.included_days)
               : (Number(row.range_days) > 0 ? Number(row.range_days) : 1);
        }
        return Number(v) / days;
      },
      pb(v, row){
        const p = this.ppd(v, row);
        if (p==null) return 'bx';
        return p<0 ? 'br' : p>=3000 ? 'bg' : 'bx';
      },
      pbStyle(v, row){
        const p = this.ppd(v, row);
        if (p==null) return '';
        return p<0 ? 'background:#ff0000;' : p>=3000 ? 'background:#00ff00;' : '';
      },
      // Color profit cells for fixed N-day windows (1D/3D/7D). Threshold is the
      // SAME per-day rule used by the range Proj.Profit: average daily profit.
      // Window is clamped to rangeDays so a 5-day range gets a 5-day "7D" window.
      pbStyleN(v, n){
        if (v==null || isNaN(Number(v))) return '';
        const days = Math.max(1, Math.min(Number(n)||1, Number(this.rangeDays)||1));
        const avg = Number(v) / days;
        return avg<0 ? 'background:#ff0000;' : avg>=3000 ? 'background:#00ff00;' : '';
      },
      pbColor(v){ return '#000'; },
      rppStyle(v){ if(v==null||isNaN(Number(v))) return ''; if(v<5) return 'background:#ff0000;'; if(v<10) return 'background:#ff6600;'; if(v<20) return 'background:#00ffff;'; return 'background:#00ff00;'; },
      rppColor(v){ return '#000'; },
      // Set RTS%: ≤25 cyan, ≤30 green, ≤40 yellow, ≤50 orange, >50 red
      rbStyle(v){ if(v==null||isNaN(Number(v))) return ''; if(v<=25) return 'background:#00ffff;'; if(v<=30) return 'background:#00ff00;'; if(v<=40) return 'background:#ffff00;'; if(v<=50) return 'background:#ff6600;'; return 'background:#ff0000;'; },
      rb(v){ if(v==null||isNaN(v)) return 'bx'; return v>45?'br':v>35?'bo':v>25?'by':'bg'; },
      dlb(v){ if(v==null||isNaN(v)) return 'bx'; return v>=80?'bg':v>=60?'by':v>=40?'bo':'br'; },
      rpp(v){ if(v==null||isNaN(v)) return 'bx'; if(v<5) return 'br'; if(v<10) return 'bo'; if(v<15) return 'by'; if(v<20) return 'bb'; return 'bg'; },
      money(v){ return '₱'+Number(v||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2}); },
      md(v)   { return (v==null||isNaN(Number(v)))?'—':this.money(v); },
      num(v)  { return Number(v||0).toLocaleString('en-PH'); },
      fmtMD(dstr){
        if(!dstr) return '';
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(dstr); if(!m) return dstr;
        const months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return months[parseInt(m[2],10)-1]+' '+parseInt(m[3],10);
      },

      // ── Date helpers (mirrors /ads_manager/campaigns Alpine helpers) ──────
      // 'YYYY-MM-DD' (or 'YYYY-MM-DD HH:MM:SS') → 'Apr 23, 2026'
      fmtDate(s){
        if(!s) return '';
        const d = new Date((s+'').slice(0,10) + 'T00:00:00');
        if(isNaN(d.getTime())) return s;
        return d.toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'});
      },
      // 'YYYY-MM-DD' → '57' (raw integer days since, PH timezone reference).
      daysSince(s){
        if(!s) return '';
        const d = new Date((s+'').slice(0,10) + 'T00:00:00');
        if(isNaN(d.getTime())) return '';
        const ph = new Date(new Date().toLocaleString('en-US', {timeZone: 'Asia/Manila'}));
        const phMid = new Date(ph.getFullYear(), ph.getMonth(), ph.getDate());
        return String(Math.max(0, Math.round((phMid - d) / 86400000)));
      },

      // ── Computed columns: TCPR + Breakeven CPP ────────────────────────────
      // Target % is global (managed via /owner/column-settings, default 5).
      _breakevenLabel(){ return 'Breakeven CPP (' + (window.__BREAKEVEN_PCT__ ?? 5) + '%)'; },

      // TCPR = (1 - proceed/orders) × 100. Returns number or null when den=0.
      tcprFor(row){
        const o = Number(row.orders ?? 0);
        const p = Number(row.proceed_orders ?? 0);
        if (o <= 0) return null;
        return (1 - p / o) * 100;
      },

      // Breakeven CPP at the global target %. Formula derived from the
      // existing /owner/private profit math:
      //   (proceed/orders) × [(1 − rts) × (0.9832 × price − item_value) − 37]
      //   − (target/100) × price
      // Returns null when any required input is missing/invalid.
      breakevenCppFor(row){
        const o     = Number(row.orders ?? 0);
        const p     = Number(row.proceed_orders ?? 0);
        const price = Number(row.price ?? 0);
        const iv    = row.item_value;
        const rts   = row.rts_pct;
        if (o <= 0 || price <= 0 || iv == null || rts == null) return null;
        const procRate = p / o;
        const df       = 1 - (Number(rts) / 100);
        const target   = (Number(window.__BREAKEVEN_PCT__ ?? 5)) / 100;
        const F  = Number(window.__FEES__?.F  ?? 0.0168); // cod_fee × (1+VAT)
        const SF = Number(window.__FEES__?.SF ?? 37);     // shipping_fee_per_order
        return procRate * (df * (price * (1 - F) - Number(iv)) - SF) - target * price;
      },

      // Resolve the numeric value the conditional-formatting rules should
      // evaluate against, per column type. Centralized so the inline :style
      // expression stays simple (parens-balanced).
      cellValueFor(col, row){
        if (!col || !row) return null;
        switch (col.id) {
          case 'tcpr':          return this.tcprFor(row);
          case 'breakeven_cpp': return this.breakevenCppFor(row);
          case 'proj_pct':
            return (row.gross_sales > 0 && row.projected_profit != null)
              ? (row.projected_profit / row.gross_sales) * 100 : null;
          case 'proj_pct_1d':   return row.proj_pct_last_day;
          case 'proj_pct_3d':   return row.proj_pct_last_3d;
          case 'proj_pct_7d':   return row.proj_pct_last_7d;
          case 'proj_profit':   return row.projected_profit;
          case 'proj_prof_1d':  return row.projected_profit_last_day;
          case 'proj_prof_3d':  return row.projected_profit_last_3d;
          case 'proj_prof_7d':  return row.projected_profit_last_7d;
          case 'rts_set':       return row.rts_pct;
          case 'jnt_rts':       return row.jnt_rts_pct;
          case 'jnt_del':       return row.jnt_del_pct;
          case 'jnt_transit':   return row.jnt_transit_pct;
          case 'cpp':           return row.cpp;
          case 'pcpp':          return row.proceed_cpp;
          case 'per_order':     return row.proj_profit_per_order;
          case 'adspent':       return row.adspent;
          case 'orders':        return row.orders;
          case 'proceed':       return row.proceed_orders;
          case 'price':         return row.price;
          case 'item_val':      return row.item_value;
          case 'ship':          return row.shipping_fee;
          case 'cod_fee':       return row.cod_fee;
          default:
            return col.sort && row[col.sort] !== undefined ? row[col.sort] : row[col.id];
        }
      },

      // Resolve a rule's threshold value. Numbers pass through. Refs are
      // looked up via cellValueFor() on the supplied refRow. Formula expressions
      // (e.g. type=formula, expr="[[cpp]] + [[op:breakeven_cpp]] - 1") are
      // evaluated against the cell's own row + parent row. Returns NaN when
      // anything can't be resolved.
      resolveRuleThreshold(threshold, refRow, sameRow){
        if (threshold == null) return NaN;
        if (typeof threshold === 'number') return threshold;
        if (typeof threshold === 'object' && threshold.type === 'ref') {
          if (!refRow) return NaN;
          const fakeCol = { id: threshold.col, sort: null };
          const v = this.cellValueFor(fakeCol, refRow);
          return (v == null || isNaN(Number(v))) ? NaN : Number(v);
        }
        if (typeof threshold === 'object' && threshold.type === 'formula') {
          return this._evalFormulaExpr(String(threshold.expr || ''), sameRow, refRow);
        }
        const n = Number(threshold);
        return isNaN(n) ? NaN : n;
      },

      // Safe formula evaluator. Tokens use [[col]] (same-table) or
      // [[op:col]] (cross-ref to owner_private). Replace each token with the
      // row's numeric value, validate remaining string contains only
      // digits/operators (no eval injection), then evaluate via Function.
      // Returns NaN if any token resolves to null/missing or formula invalid.
      _evalFormulaExpr(expr, sameRow, refRow){
        if (!expr) return NaN;
        // Match [[token]] patterns
        const tokens = [...expr.matchAll(/\[\[\s*([a-z0-9_:]+)\s*\]\]/gi)];
        let resolved = expr;
        for (const m of tokens) {
          const tok = m[1];
          let val;
          if (tok.startsWith('op:')) {
            // Cross-ref to owner_private (parent page row)
            if (!refRow) return NaN;
            const colId = tok.substring(3);
            const fakeCol = { id: colId, sort: null };
            val = this.cellValueFor(fakeCol, refRow);
          } else {
            // Same-table — use cellValueFor para naka-map sa proper data field
            // (e.g., col.id 'jnt_rts' → row.jnt_rts_pct via the switch sa cellValueFor)
            if (!sameRow) return NaN;
            const fakeCol = { id: tok, sort: null };
            val = this.cellValueFor(fakeCol, sameRow);
          }
          if (val == null || isNaN(Number(val))) return NaN;
          // Replace ALL occurrences of this token (regex-escape the original)
          const escaped = m[0].replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
          resolved = resolved.replace(new RegExp(escaped, 'g'), '(' + Number(val) + ')');
        }
        // Strip whitespace, then validate: only digits, dot, +, -, *, /, %, parens
        const cleaned = resolved.replace(/\s+/g, '');
        if (!/^[\d.+\-*/%()]*$/.test(cleaned)) return NaN;
        if (cleaned === '') return NaN;
        try {
          const result = Function('"use strict";return (' + cleaned + ');')();
          return (typeof result === 'number' && isFinite(result)) ? result : NaN;
        } catch (e) {
          return NaN;
        }
      },

      // Generic rule evaluator. Pass:
      //   rules    — array of {op, value, bg, bold, label, compare_col}
      //   value    — cell's own numeric value
      //   refRow   — optional row to use when threshold is a ref (page summary
      //              row in the campaigns expand context; null in standalone)
      //   sameRow  — optional row of the cell being evaluated. When rule has
      //              compare_col set, fetch that column's value sa same row
      //              instead of cell's own value.
      _evalRules(rules, value, refRow, sameRow){
        if (!Array.isArray(rules) || rules.length === 0) return '';
        for (const r of rules) {
          // Active-state filter: skip rule kung mismatch sa row's `on` field.
          // For rows w/o `on` field (e.g., owner_private page rows) — rule applies regardless.
          const activeState = r.active_state || 'active';
          if (activeState !== 'any' && sameRow && Object.prototype.hasOwnProperty.call(sameRow, 'on')) {
            const isOn = !!sameRow.on;
            if (activeState === 'active' && !isOn) continue;
            if (activeState === 'off'    &&  isOn) continue;
          }
          const t = this.resolveRuleThreshold(r.value, refRow, sameRow);
          if (isNaN(t)) continue;   // ref/formula couldn't resolve → skip rule
          // Determine what to evaluate: compare_col (sibling) or self
          let evalRaw = value;
          if (r.compare_col && sameRow && Object.prototype.hasOwnProperty.call(sameRow, r.compare_col)) {
            evalRaw = sameRow[r.compare_col];
          }
          if (evalRaw == null || isNaN(Number(evalRaw))) continue;
          const v = Number(evalRaw);
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
            const txt = (r.color && /^#[0-9a-f]{6}$/i.test(r.color)) ? r.color : '#111827';
            return 'background:' + bg + ';color:' + txt + ';' + (r.bold ? 'font-weight:700;' : '');
          }
        }
        return '';
      },

      // Conditional formatting for owner_private rows. Refs (rare here) use
      // the row itself as the ref source.
      cellFormatStyle(colId, value, row){
        const rules = (window.__COL_FORMAT__ || {})[colId];
        // For owner_private cells the same row is both the ref source AND the
        // sibling-col source for compare_col. Pass row to _evalRules.
        const same = row || this._currentOwnerRefRow || null;
        return this._evalRules(rules, value, same, same);
      },

      // Look up a page-summary row by page_name (used by campaigns rules).
      pageRowFor(pageName){
        if (!pageName || !Array.isArray(this.rows)) return null;
        return this.rows.find(r => r.page_name === pageName) || null;
      },

      // Conditional formatting for inline expand rows (campaign / adset / ad).
      // Resolves the value via cellValueFor against the campaign-level row's
      // own data — but uses the parent page summary row as the ref source so
      // rules like "cpp ≥ breakeven_cpp" work.
      campaignsCellFormatStyle(colId, campaignRow, value){
        const rules = (window.__CAMPAIGNS_COL_FORMAT__ || {})[colId];
        if (!Array.isArray(rules) || rules.length === 0) return '';
        const refRow = this.pageRowFor(campaignRow?.page_name);
        // Pass campaignRow as sameRow para sa compare_col (sibling column)
        return this._evalRules(rules, value, refRow, campaignRow);
      },

      // ── Campaigns column catalog (mirrors the one in /ads_manager/campaigns)
      // Used by the inline expand panel rendering. Visibility/order via the
      // CEO-managed config injected into window.__CAMPAIGNS_COLS__.
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
          { id:'cpm_msg',        label:'Cost per messaging',sort:'cpm_msg',       type:'money',        align:'right', minw:120 },
          { id:'cpr',            label:'Cost per result',  sort:'cpr',            type:'money',        align:'right', minw:120 },
          { id:'cpp',            label:'Cost per purchase',sort:'cpp',            type:'money',        align:'right', minw:130 },
          { id:'profit_pct',     label:'Profit % (1M)',    sort:'profit_pct',     type:'percent',      align:'right', minw:90  },
          { id:'profit_pct_7d',  label:'Profit % (7D)',    sort:'profit_pct_7d',  type:'percent',      align:'right', minw:90  },
          { id:'profit_pct_3d',  label:'Profit % (3D)',    sort:'profit_pct_3d',  type:'percent',      align:'right', minw:90  },
          { id:'profit_pct_today',label:'Profit % (Today)',sort:'profit_pct_today',type:'percent',     align:'right', minw:100 },
          { id:'cpp_today',      label:'CPP (today)',      sort:'cpp_today',      type:'money',        align:'right', minw:110 },
          { id:'cpp_3d',         label:'CPP (3d)',         sort:'cpp_3d',         type:'money',        align:'right', minw:100 },
          { id:'cpp_7d',         label:'CPP (7d)',         sort:'cpp_7d',         type:'money',        align:'right', minw:100 },
          { id:'impressions',    label:'Impressions',      sort:null,             type:'integer',      align:'right', minw:100 },
          { id:'link_clicks',       label:'Link clicks',         sort:'link_clicks',      type:'integer',      align:'right', minw:90  },
          { id:'welcome_msg_rate',  label:'Welcome Msg Rate (%)',sort:'welcome_msg_rate', type:'percent',      align:'right', minw:130 },
          { id:'messages',       label:'Messages',         sort:null,             type:'integer',      align:'right', minw:90  },
          { id:'conversion_rate',   label:'Conv Rate (%)',       sort:'conversion_rate',  type:'percent',      align:'right', minw:110 },
          { id:'purchases',      label:'Purchases',        sort:null,             type:'integer',      align:'right', minw:100 },
        ];
      },
      visibleCampaignsCols(){
        const defs = this.defaultCampaignsCols();
        const defMap = Object.fromEntries(defs.map(c => [c.id, c]));
        const cfg = window.__CAMPAIGNS_COLS__ || {};
        const hidden = new Set(Array.isArray(cfg.hidden) ? cfg.hidden : []);
        const order  = Array.isArray(cfg.order) && cfg.order.length ? cfg.order : defs.map(c => c.id);
        const seen = new Set(); const out = [];
        for (const id of order) {
          if (defMap[id] && !hidden.has(id) && !seen.has(id)) { out.push(defMap[id]); seen.add(id); }
        }
        for (const c of defs) { if (!hidden.has(c.id) && !seen.has(c.id)) out.push(c); }
        return out;
      },
      // Header label per col, with name col tab-aware.
      campLabel(col, level){
        if (col.id === 'name') {
          return level === 'campaigns' ? 'Campaign'
               : level === 'adsets'    ? 'Ad set'
               : 'Ad';
        }
        return col.label;
      },
      // Display name from row by level.
      campRowName(row, level){
        if (level === 'campaigns') return row.campaign_name || ('Campaign ' + row.campaign_id);
        if (level === 'adsets')    return row.ad_set_name   || ('Ad set '   + row.ad_set_id);
        return row.headline || ('Ad ' + row.ad_id);
      },

      // ── Inline expand: campaigns / adsets / ads ───────────────────────────
      // Date range = `[row.anchor_first_date .. /owner/private end_date]` so
      // we only see ad activity from when the page's CURRENT primary item
      // first became primary. Item filter = exact qty-variant match
      // (e.g. "2 x MINI FLASHLIGHT"). only_with_spend=1 hides idle entities.
      // Falls back to this-month range if anchor missing (legacy single-date).

      // Strip leading qty prefix (e.g. "2 x MINI FLASHLIGHT" → "MINI FLASHLIGHT")
      // so the value matches `ads_manager_reports.item_name` which usually
      // stores the base item name (FB Ads Manager doesn't track qty variants
      // in its item_name field).
      _stripQty(name){
        if (!name) return '';
        const s = String(name).trim();
        // Match patterns like "1 x ", "2 X ", "3x ", "10 x" at start.
        const m = /^\d+\s*[xX×]\s*(.+)$/.exec(s);
        return m ? m[1].trim() : s;
      },

      // Resolve {start_date, end_date} scope per page row.
      // The breakdown matrix tells us WHEN this page's current primary item
      // first appeared (anchor_first_date). We just use that date range to
      // pull all the page's campaigns/adsets/ads that had spend in that
      // window — no item-name filter (ads_manager_reports.item_name is
      // unreliable / often empty / different formatting; matching on it
      // hides legit ad activity).
      _pageScopeFor(pageName){
        const row = (this.rows || []).find(r => r.page_name === pageName);
        const fallbackEnd = this.endDate || '';
        const fallbackStart = this.startDate || fallbackEnd;
        if (!row) return { start_date: fallbackStart, end_date: fallbackEnd };
        const start = row.anchor_first_date || fallbackStart;
        const end   = this.endDate || fallbackEnd;
        return { start_date: start, end_date: end };
      },

      // Fetch campaigns + enrich profit_pct (4 windows) client-side using
      // PARENT PAGE ROW's already-loaded data (price, rts_pct, item_value).
      // Walang dagdag na DB query — yung data is already sa browser when
      // /owner/private renders the page summary.
      async _fetchCampaignsData(params){
        const qs = new URLSearchParams(Object.assign({
          limit:           1000,
          sort_by:         'default',
          sort_dir:        'desc',
          only_with_spend: '1',
          include_windows: '1',
        }, params));
        const res = await fetch('{{ route('ads_manager.campaigns.data') }}?' + qs.toString());
        if (!res.ok) throw new Error('HTTP '+res.status);
        const j = await res.json();

        if (Array.isArray(j.rows)) {
          for (const r of j.rows) {
            r.profit_pct       = this._campaignProfitPctFromCpp(r, r.cpp);
            r.profit_pct_7d    = this._campaignProfitPctFromCpp(r, r.cpp_7d);
            r.profit_pct_3d    = this._campaignProfitPctFromCpp(r, r.cpp_3d);
            r.profit_pct_today = this._campaignProfitPctFromCpp(r, r.cpp_today);
          }
        }
        return j;
      },

      // Compute profit% per campaign using parent page row's data.
      // Inputs: campaign's cpp + parent.price/rts_pct/item_value + window.__FEES__
      // Formula:
      //   df               = 1 − rts_pct/100
      //   profit_per_order = df × (price − item_value − price × F) − SF − cpp
      //   profit_pct       = profit_per_order ÷ price × 100
      _campaignProfitPctFromCpp(row, cppVal){
        const cpp = Number(cppVal ?? NaN);
        if (isNaN(cpp)) return null;
        const parent = this.pageRowFor(row?.page_name);
        if (!parent) return null;
        const price = Number(parent.price ?? 0);
        const iv    = parent.item_value;
        const rts   = parent.rts_pct;
        if (price <= 0 || iv == null || rts == null) return null;
        const df = 1 - (Number(rts) / 100);
        const F  = Number(window.__FEES__?.F  ?? 0.0168);
        const SF = Number(window.__FEES__?.SF ?? 37);
        const profitPerOrder = df * (price - Number(iv) - price * F) - SF - cpp;
        return Math.round((profitPerOrder / price) * 100 * 100) / 100;
      },

      // Are any visible page rows currently expanded? Used by the
      // "Expand all / Hide all" toggle button to choose its label + action.
      anyExpanded(){
        const visible = (this.sortedRows ? this.sortedRows() : (this.rows || []));
        for (const r of visible) {
          const st = this.expandedPages[r.page_name];
          if (st && st.open) return true;
        }
        return false;
      },

      // Single-button toggle: if any row is open → collapse all visible rows;
      // otherwise → expand all visible rows (fires N parallel fetches).
      // Uses the existing togglePageExpand so cache + load semantics match.
      async toggleAllExpand(){
        const visible = (this.sortedRows ? this.sortedRows() : (this.rows || []));
        if (this.anyExpanded()) {
          // Collapse: just flip open=false on already-loaded entries (no fetch).
          for (const r of visible) {
            const st = this.expandedPages[r.page_name];
            if (st && st.open) {
              this.expandedPages[r.page_name] = Object.assign({}, st, { open: false });
            }
          }
        } else {
          // Expand all: kick off togglePageExpand for any not-yet-expanded row.
          // Run in parallel — let each panel resolve independently.
          const tasks = [];
          for (const r of visible) {
            const st = this.expandedPages[r.page_name];
            if (!st || !st.open) tasks.push(this.togglePageExpand(r.page_name));
          }
          await Promise.allSettled(tasks);
        }
      },

      // Toggle the per-page campaigns expand. First open fetches; subsequent
      // toggles just flip `open` without re-fetching. Scope = the page's
      // current primary item run window.
      async togglePageExpand(pageName){
        const cur = this.expandedPages[pageName];
        if (cur && cur.campaigns) {
          this.expandedPages[pageName] = Object.assign({}, cur, { open: !cur.open });
          return;
        }
        this.expandedPages[pageName] = { open: true, loading: true, error: null, campaigns: null };
        try {
          const scope = this._pageScopeFor(pageName);
          const j = await this._fetchCampaignsData(Object.assign({
            level: 'campaigns', page_name: pageName,
          }, scope));
          const campaigns = j.rows || [];
          this._markActiveOffDivider(campaigns);
          this.expandedPages[pageName] = { open: true, loading: false, error: null, campaigns };
        } catch (e) {
          this.expandedPages[pageName] = { open: true, loading: false, error: e.message || 'Failed to load', campaigns: [] };
        }
      },

      // Tag the first off-after-active campaign with `_divider_top = true` so
      // the template can render a thicker top border separating the Active
      // group from the Off group. Backend sorts is_on DESC by default, so the
      // active→off transition is unique. No-op if the list is all-active or
      // all-off.
      _markActiveOffDivider(list){
        let seenActive = false;
        for (const c of (list || [])) {
          if (c.on) {
            seenActive = true;
          } else if (seenActive) {
            c._divider_top = true;
            return;
          }
        }
      },

      // Toggle a campaign's adsets expand inside an already-expanded page.
      // Scope (date range + item filter) inherits from the parent page's row.
      async toggleCampaignExpand(campaignId, pageName){
        const cur = this.expandedCampaigns[campaignId];
        if (cur && cur.adsets) {
          this.expandedCampaigns[campaignId] = Object.assign({}, cur, { open: !cur.open });
          return;
        }
        this.expandedCampaigns[campaignId] = { open: true, loading: true, error: null, adsets: null };
        try {
          const scope = this._pageScopeFor(pageName);
          const j = await this._fetchCampaignsData(Object.assign({
            level: 'adsets', page_name: pageName, campaign_id: campaignId,
          }, scope));
          this.expandedCampaigns[campaignId] = { open: true, loading: false, error: null, adsets: j.rows || [] };
        } catch (e) {
          this.expandedCampaigns[campaignId] = { open: true, loading: false, error: e.message || 'Failed to load', adsets: [] };
        }
      },

      // Toggle an ad set's ads expand inside an already-expanded campaign.
      async toggleAdSetExpand(adSetId, campaignId, pageName){
        const cur = this.expandedAdSets[adSetId];
        if (cur && cur.ads) {
          this.expandedAdSets[adSetId] = Object.assign({}, cur, { open: !cur.open });
          return;
        }
        this.expandedAdSets[adSetId] = { open: true, loading: true, error: null, ads: null };
        try {
          const scope = this._pageScopeFor(pageName);
          const j = await this._fetchCampaignsData(Object.assign({
            level: 'ads', page_name: pageName, ad_set_id: adSetId,
          }, scope));
          this.expandedAdSets[adSetId] = { open: true, loading: false, error: null, ads: j.rows || [] };
        } catch (e) {
          this.expandedAdSets[adSetId] = { open: true, loading: false, error: e.message || 'Failed to load', ads: [] };
        }
      },

      async init(){
        this.initCols();
        await this.load();
      },
    };
  }
  </script>
</body>
</html>
