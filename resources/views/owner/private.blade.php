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
      border-right:1px solid #334155;   /* vertical column separator (dark, visible on header) */
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

    tbody td {
      border-bottom:1px solid #f1f5f9;
      border-right:1px solid #f1f5f9;   /* vertical column separator — same 1px as the horizontal row line */
    }
    tbody tr:hover td { background:#f8fafc; }
    tbody tr.editing-row td { background:#eff6ff !important; }

    /* Keep the nested campaign panel (.fb-table) clean — no vertical lines there;
       the column separators are only for the MAIN summary table. */
    .fb-table thead th, .fb-table tbody td { border-right:0; }

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
    .cell-edit-icon {
      font-size:11px; line-height:1; padding:2px 4px; border-radius:3px;
      cursor:pointer; border:1px solid transparent; background:transparent;
      color:#94a3b8; opacity:0.5; transition:opacity 0.15s, color 0.15s, background 0.15s;
      flex-shrink:0;
    }
    .cell-edit-icon:hover { opacity:1; color:#2563eb; background:#eff6ff; border-color:#bfdbfe; }
    tr:hover .cell-edit-icon { opacity:1; }

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
      background: #fff;   /* keep white when expanded (was #f1f5f9 gray) */
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

    /* ── Per-page repeated column header (VIEW ONLY) ──────────────────────
       Shown above each EXPANDED page section so you don't lose track of
       which column is which while scrolling. Display-only labels — walang
       sort/drag (yun lang sa main header). NOT sticky (inline group header
       per page section). */
    tr.page-col-header > th {
      position: static;        /* override the sticky thead th rule */
      background: #334155;      /* slightly lighter than main header (#1e293b) */
      color: #cbd5e1;
      font-size: 10.5px; font-weight: 600;
      text-transform: uppercase; letter-spacing: .05em;
      padding: 7px 10px; white-space: nowrap;
      border-top: 2px solid #2563eb;   /* blue accent to mark the section start */
      border-bottom: 1px solid #1e293b;
      border-right: 1px solid #475569;  /* vertical column separator (matches main header) */
      user-select: none;
      cursor: default;
      z-index: 1;
    }
    /* Edit modal — shared style with /owner/private/breakdown matrix. */
    .ow-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,0.5);z-index:80;
                       display:flex;align-items:center;justify-content:center;padding:1rem;}
    .ow-modal-card{background:#fff;border-radius:8px;box-shadow:0 10px 25px rgba(0,0,0,0.2);
                   max-width:480px;width:100%;max-height:90vh;overflow:auto;font-size:13px;}
    .ow-modal-card label{display:block;font-size:11px;font-weight:700;color:#334155;
                         text-transform:uppercase;letter-spacing:0.04em;margin-bottom:4px;}
    .ow-modal-card input[type=number],
    .ow-modal-card input[type=text]{border:1px solid #cbd5e1;border-radius:6px;
                                    padding:7px 10px;font-size:13px;width:100%;font-family:ui-monospace,monospace;}
    .ow-modal-card input:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,0.12);}
    .ow-modal-card .ow-modal-section{padding:14px 18px;border-bottom:1px solid #f1f5f9;}
    .ow-modal-card .ow-modal-section:last-of-type{border-bottom:0;}
    .ow-modal-card .ow-modal-footer{padding:11px 18px;background:#f8fafc;border-top:1px solid #e2e8f0;
                                    display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;}
    .ow-modal-card .ow-btn{font-weight:700;font-size:12.5px;padding:8px 14px;border-radius:6px;}
    .ow-modal-card .ow-btn-cancel{background:transparent;color:#64748b;}
    .ow-modal-card .ow-btn-cancel:hover{color:#0f172a;}
    .ow-modal-card .ow-btn-apply{background:#f59e0b;color:#fff;}
    .ow-modal-card .ow-btn-apply:hover{background:#d97706;}
    .ow-modal-card .ow-btn-save{background:#2563eb;color:#fff;}
    .ow-modal-card .ow-btn-save:hover{background:#1d4ed8;}
    .ow-modal-card .ow-btn:disabled{opacity:0.5;cursor:not-allowed;}

    /* Creative preview modal — 3-column grid for FB Post / Body+Headline / Messenger.
       Each section keeps its colored background; right/bottom dividers between them.
       Collapses to single column under 900px so mobile/narrow viewports still work. */
    .creative-modal-grid{display:grid;grid-template-columns:1fr 1fr 1fr;align-items:stretch;}
    .creative-modal-grid > .ow-modal-section{border-top:1px solid #e2e8f0;border-right:1px solid #e2e8f0;border-bottom:0;min-width:0;}
    .creative-modal-grid > .ow-modal-section:last-child{border-right:0;}
    @media (max-width: 900px){
      .creative-modal-grid{grid-template-columns:1fr;}
      .creative-modal-grid > .ow-modal-section{border-right:0;border-bottom:1px solid #e2e8f0;}
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
               placeholder="Search item or alias…"
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
            <span x-text="selectedItems.length + selectedAliases.length" style="color:#2563eb;font-weight:700;"></span>
            <span> / </span>
            <span x-text="uniqueItems().length + uniqueAliases().length"></span>
          </span>
        </div>
        <div style="overflow-y:auto;flex:1;padding:4px;">
          <template x-if="visibleAliases().length === 0 && visibleItems().length === 0">
            <div style="text-align:center;color:#94a3b8;font-size:11px;padding:20px;">No items match.</div>
          </template>

          {{-- Alias (family) groups — isang check = LAHAT ng item names sa family --}}
          <template x-for="alias in visibleAliases()" :key="'alias:'+alias">
            <label style="display:flex;align-items:center;gap:10px;padding:6px 10px;cursor:pointer;
                          border-radius:6px;font-size:12px;color:#0f172a;line-height:1.3;
                          transition:background .1s;background:#faf5ff;"
                   onmouseover="this.style.background='#f3e8ff'"
                   onmouseout="this.style.background='#faf5ff'">
              <input type="checkbox"
                     :checked="selectedAliases.includes(alias)"
                     @change="toggleAlias(alias)"
                     style="accent-color:#7c3aed;cursor:pointer;width:14px;height:14px;flex-shrink:0;">
              <span style="flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                <span style="display:inline-block;background:#ede9fe;color:#6d28d9;font-size:9px;
                             font-weight:700;letter-spacing:.3px;padding:1px 5px;border-radius:4px;
                             margin-right:6px;vertical-align:middle;">FAMILY</span>
                <span x-text="alias" style="font-weight:700;color:#6d28d9;"></span>
              </span>
            </label>
          </template>

          {{-- divider between families and individual items --}}
          <template x-if="visibleAliases().length > 0 && visibleItems().length > 0">
            <div style="height:1px;background:#e9d5ff;margin:5px 8px;"></div>
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
              <span style="flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                <span x-text="name" style="font-weight:500;"></span>
                <template x-if="aliasOf(name)">
                  <span x-text="' · ' + aliasOf(name)"
                        style="font-size:10px;color:#94a3b8;font-weight:400;"
                        title="Item alias (family)"></span>
                </template>
              </span>
            </label>
          </template>
        </div>
        <div x-show="selectedItems.length || selectedAliases.length"
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

    {{-- Partial date picker — opt-in 1D column override. Empty by default.
         Use case: spot-check today's (or any specific date's) partial data sa
         1D column while keeping the main range as the reliable historical baseline.
         TCPR for 1D projection uses the aggregated historical average. --}}
    <span style="color:#fbbf24;font-size:11px;font-weight:600;margin-left:6px;" title="Optional — overrides 1D column with this date's data, uses historical TCPR for projection">1D Date</span>
    <input type="date" x-model="partialDate" @change="load()"
           :title="partialDate ? '1D column shows '+partialDate+' data with historical TCPR projection' : 'Leave empty for default behavior (1D = last day of range)'"
           style="background:#0f172a;color:#fde68a;border:1px solid #d97706;
                  border-radius:6px;padding:5px 10px;font-size:13px;outline:none;cursor:pointer;">
    <button type="button" @click="partialDate=''; load();" x-show="partialDate"
            title="Clear partial date — back to default 1D behavior"
            style="background:#0f172a;color:#94a3b8;border:1px solid #475569;
                   border-radius:6px;padding:3px 8px;font-size:12px;cursor:pointer;">✕</button>
    <a :href="'{{ route('owner.private.breakdown') }}?start_date='+startDate+'&end_date='+endDate"
       target="_blank"
       x-show="!isSingleDate"
       style="background:#1e293b;color:#93c5fd;border:1px solid #475569;
              border-radius:6px;padding:5px 10px;font-size:12px;font-weight:700;
              cursor:pointer;text-decoration:none;"
       title="View page × date × item matrix for this range">🧭 Matrix</a>

    @if(($isCEO ?? false) && ($viewAs ?? 'ceo') === 'ceo')
    <a :href="'{{ route('owner.private.daily') }}?start_date='+startDate+'&end_date='+endDate"
       target="_blank"
       style="background:#1e293b;color:#fde68a;border:1px solid #475569;
              border-radius:6px;padding:5px 10px;font-size:12px;font-weight:700;
              cursor:pointer;text-decoration:none;"
       title="Per-day overall summary across all pages (CEO)">📅 Daily</a>
    @endif

    @if(($isCEO ?? false))
    {{-- CEO-only "view as" toggle. Replaces the prior profit-only toggle and
         drives BOTH the visible UI mode AND the profit cogs source:
           CEO       → cogs_ceo for profit + CEO column + modal CEO field visible
           Marketing → cogs for profit + CEO column hidden + modal CEO field hidden
                       (also hides other CEO-only header buttons — Daily, Columns,
                        Excluded, Primary Items — so the UI mirrors Marketing's)
         Toggle itself ALWAYS stays visible to actual CEO so they can switch back. --}}
    <div :title="'View mode: ' + (viewAs === 'ceo' ? 'CEO — full CEO experience' : 'Marketing — previewing what Marketing sees') + '. Click to toggle.'"
         style="display:inline-flex;align-items:center;background:#1e293b;border:1px solid #475569;
                border-radius:6px;padding:3px;margin-left:4px;gap:2px;">
      <span style="font-size:10px;color:#94a3b8;font-weight:600;padding:0 6px;">👁 View:</span>
      <button type="button" @click="viewAs !== 'marketing' && toggleViewAs()"
              :style="viewAs === 'marketing'
                ? 'background:#f59e0b;color:#0f172a;border-radius:4px;padding:3px 9px;font-size:11px;font-weight:700;'
                : 'background:transparent;color:#cbd5e1;border-radius:4px;padding:3px 9px;font-size:11px;font-weight:600;'"
              title="Preview as Marketing — hides CEO-only columns, fields, and buttons; profit uses cogs">Marketing</button>
      <button type="button" @click="viewAs !== 'ceo' && toggleViewAs()"
              :style="viewAs === 'ceo'
                ? 'background:#6366f1;color:#fff;border-radius:4px;padding:3px 9px;font-size:11px;font-weight:700;'
                : 'background:transparent;color:#cbd5e1;border-radius:4px;padding:3px 9px;font-size:11px;font-weight:600;'"
              title="Full CEO view — shows CEO column + modal field; profit uses cogs_ceo">🔒 CEO</button>
    </div>
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

    {{-- Refresh data — available to ALL roles. Bypasses the read cache for
         /owner/private/data + /item-summary, re-runs the heavy aggregations,
         and writes the fresh result back to cache. Use this kapag suspect
         na stale yung values shown. --}}
    <button type="button" @click="forceRefresh()"
            :disabled="loading"
            title="Bypass cache → re-fetch fresh values from database → rewrite cache. Use if you suspect stale data."
            style="background:#1e293b;color:#7dd3fc;border:1px solid #475569;
                   border-radius:6px;padding:5px 10px;font-size:12px;font-weight:700;
                   cursor:pointer;margin-left:4px;">
      <span x-show="!loading">🔄 Refresh</span>
      <span x-show="loading">Refreshing…</span>
    </button>

    {{-- CEO-only chrome — hidden when CEO toggles to Marketing view so the UI
         truly mirrors what Marketing sees. Actual CEO role still has access via
         direct URL; this is a view-toggle gate, not an auth gate. --}}
    @if(!empty($effectiveIsCEO))
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

    {{-- Logs + Primary Items: visible to MOIC always; for actual CEO, only in
         CEO view (Marketing view hides them since plain Marketing doesn't have
         these buttons either). --}}
    @if(!empty($effectiveIsCEO) || !empty($isMarketingOIC))
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

    {{-- 📸 Snapshots — CEO-only freeze + browse archive. --}}
    @if(!empty($effectiveIsCEO))
      <button id="saveSnapshotBtn"
              @click="saveSnapshot()"
              :disabled="savingSnapshot || loading"
              title="Save current /owner/private view as a snapshot (frozen capture, viewable later)"
              style="background:#1e293b;color:#c4b5fd;border:1px solid #475569;
                     border-radius:6px;padding:5px 10px;font-size:12px;font-weight:700;
                     cursor:pointer;margin-left:4px;">
        <span x-show="!savingSnapshot">📸 Save Snapshot</span>
        <span x-show="savingSnapshot">Saving…</span>
      </button>
      <a href="{{ route('owner.private.snapshots.index') }}"
         title="Browse saved snapshots archive"
         style="background:#1e293b;color:#a5b4fc;border:1px solid #475569;
                border-radius:6px;padding:5px 10px;font-size:12px;font-weight:700;
                cursor:pointer;margin-left:4px;text-decoration:none;">📂 Snapshots</a>
    @endif
  </div>

  <!-- Selected item pills -->
  <div x-show="selectedItems.length || selectedAliases.length" x-cloak
       style="background:#f1f5f9;border-bottom:1px solid #e2e8f0;padding:6px 12px;
              display:flex;flex-wrap:wrap;gap:4px;align-items:center;">
    <span style="font-size:11px;color:#475569;font-weight:700;margin-right:4px;">Filter:</span>
    {{-- Alias (family) pills --}}
    <template x-for="alias in selectedAliases" :key="'apill:'+alias">
      <span style="display:inline-flex;align-items:center;gap:4px;background:#ede9fe;
                   color:#6d28d9;border:1px solid #c4b5fd;border-radius:12px;
                   padding:2px 8px;font-size:11px;font-weight:700;">
        <span style="font-size:9px;opacity:.8;">FAMILY</span>
        <span x-text="alias"></span>
        <button type="button" @click="toggleAlias(alias)"
                style="background:none;border:none;color:#6d28d9;cursor:pointer;
                       font-size:14px;line-height:1;padding:0;font-weight:700;"
                title="Remove">×</button>
      </span>
    </template>
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

            {{-- Row-level Actions column removed. Per-cell ✎ edit icons na lang. --}}
          </tr>
        </thead>
        <tbody class="msg-tbody">

          <template x-if="rows.length === 0 && !loading">
            <tr><td :colspan="cols.length + 2" style="text-align:center;padding:48px;color:#94a3b8;font-size:13px;">
              No data for selected date.
            </td></tr>
          </template>

          <template x-if="rows.length === 0 && loading">
            <tr><td :colspan="cols.length + 2" style="text-align:center;padding:48px;color:#94a3b8;font-size:13px;">
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

            {{-- Per-page repeated column header — VIEW ONLY. Shows above each
                 EXPANDED page section so you don't lose track of which column
                 is which while scrolling. Display-only labels: walang sort
                 click / drag (yun lang sa main header). Sumusunod pa rin sa
                 global `cols` order, kaya pag nag-reorder ka sa main header,
                 nag-uupdate din itong display. Hidden when collapsed. --}}
            <tr x-show="(expandedPages[row.page_name] || {}).open" class="page-col-header">
              <th style="text-align:left;min-width:110px;">Page</th>
              <th style="text-align:left;min-width:160px;">Item</th>
              <template x-for="col in cols" :key="'ph-'+row.page_key+'-'+col.id">
                <th :style="'text-align:'+col.align+';min-width:'+col.minw+'px'">
                  <span x-text="col.label"></span>
                </th>
              </template>
            </tr>

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
                    {{-- Back-fill warning — may included date(s) na walang proper
                         setting kaya hiniram ang earliest. Precise label: ilista
                         kung ALIN ang back-filled (RTS / cost / fee). Click →
                         breakdown (red cells doon). --}}
                    <template x-if="row.has_backfill">
                      <div style="cursor:pointer;font-size:10px;color:#dc2626;font-weight:600;line-height:1.3;margin-top:2px;"
                           @click="openBreakdown(row)"
                           :title="'⚠ ' + (row.backfill_dates ? row.backfill_dates.length : 0) + ' date(s) walang proper setting — back-filled earliest. Click para makita sa breakdown (red cells).'"
                           x-text="'⚠ back-filled ' + (row.backfill_fields && row.backfill_fields.length ? row.backfill_fields.map(f => ({rts:'RTS', cost:'cost', fee:'fee'}[f] || f)).join(' + ') : '')">
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
                <td :style="'text-align:'+col.align+';'+(col.id==='rts_set'&&editIdx!==idx&&row.rts_pct===null?'background:#fef2f2;':'')+(col.id==='item_val'&&editIdx!==idx&&row.item_value===null?'background:#fef2f2;':'')+(col.id==='proj_profit'?pbStyle(row.projected_profit,row):'')+(col.id==='proj_prof_1d'?pbStyleN(row.projected_profit_last_day,1):'')+(col.id==='proj_prof_3d'?pbStyleN(row.projected_profit_last_3d,3):'')+(col.id==='proj_prof_7d'?pbStyleN(row.projected_profit_last_7d,7):'')+cellFormatStyle(col.id, cellValueFor(col, row), row)">

                  <!-- adspent -->
                  <template x-if="col.id==='adspent'">
                    <span style="color:#111;font-weight:500;" x-text="money(row.adspent)"></span>
                  </template>

                  <!-- orders -->
                  <template x-if="col.id==='orders'">
                    <span style="color:#111;" x-text="num(row.orders)"></span>
                  </template>

                  <!-- orders_1d — orders count on end_date (last day of range) -->
                  <template x-if="col.id==='orders_1d'">
                    <span style="color:#111;" x-text="num(row.orders_last_day)"></span>
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

                  {{-- NP/O = projected_profit_last_day ÷ orders_last_day. Single-day
                       net-profit-per-order snapshot using end_date metrics. Null when
                       either side missing (no slice on end_date, or missing RTS/cogs). --}}
                  <template x-if="col.id==='np_per_order'">
                    @php /* npo computed inline below */ @endphp
                    <template x-if="row.projected_profit_last_day !== null && row.orders_last_day > 0">
                      <span style="color:#111;font-weight:600;"
                            :style="'color:'+pbColor(row.projected_profit_last_day / row.orders_last_day)"
                            x-text="md(row.projected_profit_last_day / row.orders_last_day)"
                            :title="'1D net profit ₱'+Number(row.projected_profit_last_day||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / orders '+(row.orders_last_day||0)+' (end_date only)'"></span>
                    </template>
                    <template x-if="!(row.projected_profit_last_day !== null && row.orders_last_day > 0)">
                      <span style="color:#cbd5e1;" title="Missing 1D profit or orders for end_date">—</span>
                    </template>
                  </template>

                  {{-- NP/O (3D) = projected_profit_last_3d ÷ orders_last_3d --}}
                  <template x-if="col.id==='np_per_order_3d'">
                    <template x-if="row.projected_profit_last_3d !== null && row.orders_last_3d > 0">
                      <span style="font-weight:600;"
                            :style="'color:'+pbColor(row.projected_profit_last_3d / row.orders_last_3d)"
                            x-text="md(row.projected_profit_last_3d / row.orders_last_3d)"
                            :title="'3D net profit ₱'+Number(row.projected_profit_last_3d||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / orders '+(row.orders_last_3d||0)+' (last 3 days)'"></span>
                    </template>
                    <template x-if="!(row.projected_profit_last_3d !== null && row.orders_last_3d > 0)">
                      <span style="color:#cbd5e1;" title="Missing 3D profit or orders">—</span>
                    </template>
                  </template>

                  {{-- NP/O (7D) = projected_profit_last_7d ÷ orders_last_7d --}}
                  <template x-if="col.id==='np_per_order_7d'">
                    <template x-if="row.projected_profit_last_7d !== null && row.orders_last_7d > 0">
                      <span style="font-weight:600;"
                            :style="'color:'+pbColor(row.projected_profit_last_7d / row.orders_last_7d)"
                            x-text="md(row.projected_profit_last_7d / row.orders_last_7d)"
                            :title="'7D net profit ₱'+Number(row.projected_profit_last_7d||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / orders '+(row.orders_last_7d||0)+' (last 7 days)'"></span>
                    </template>
                    <template x-if="!(row.projected_profit_last_7d !== null && row.orders_last_7d > 0)">
                      <span style="color:#cbd5e1;" title="Missing 7D profit or orders">—</span>
                    </template>
                  </template>

                  {{-- NP/O (1M, entire selected range) = projected_profit ÷ orders --}}
                  <template x-if="col.id==='np_per_order_1m'">
                    <template x-if="row.projected_profit !== null && row.orders > 0">
                      <span style="font-weight:600;"
                            :style="'color:'+pbColor(row.projected_profit / row.orders)"
                            x-text="md(row.projected_profit / row.orders)"
                            :title="'Range net profit ₱'+Number(row.projected_profit||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / orders '+(row.orders||0)+' (entire selected range)'"></span>
                    </template>
                    <template x-if="!(row.projected_profit !== null && row.orders > 0)">
                      <span style="color:#cbd5e1;" title="Missing range profit or orders">—</span>
                    </template>
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

                  <!-- jnt_rdt — combined RTS% / Del% / Transit% (3 stacked rows; % removed, count kept).
                       Per-line: each row shown only kung naka-check sa column-settings (col.members). -->
                  <template x-if="col.id==='jnt_rdt'">
                    <span style="display:inline-block;line-height:1.5;font-size:12px;white-space:nowrap;">
                      <template x-if="col.members.includes('jnt_rts')">
                        <div>
                          <span style="color:#94a3b8;font-size:9px;font-weight:600;">RTS</span>
                          <template x-if="row.jnt_rts_pct !== null"><span style="color:#111;font-weight:700;" x-text="' '+row.jnt_rts_pct.toFixed(1)+'('+row.jnt_rts_cnt+')'"></span></template>
                          <template x-if="row.jnt_rts_pct === null"><span style="color:#cbd5e1;"> —</span></template>
                        </div>
                      </template>
                      <template x-if="col.members.includes('jnt_del')">
                        <div>
                          <span style="color:#94a3b8;font-size:9px;font-weight:600;">DEL</span>
                          <template x-if="row.jnt_del_pct !== null"><span style="color:#111;" x-text="' '+row.jnt_del_pct.toFixed(1)+'('+row.jnt_del_cnt+')'"></span></template>
                          <template x-if="row.jnt_del_pct === null"><span style="color:#cbd5e1;"> —</span></template>
                        </div>
                      </template>
                      <template x-if="col.members.includes('jnt_transit')">
                        <div>
                          <span style="color:#94a3b8;font-size:9px;font-weight:600;">INT</span>
                          <template x-if="row.jnt_transit_pct !== null"><span style="color:#111;" x-text="' '+row.jnt_transit_pct.toFixed(1)+'('+row.jnt_transit_cnt+')'"></span></template>
                          <template x-if="row.jnt_transit_pct === null"><span style="color:#cbd5e1;"> —</span></template>
                        </div>
                      </template>
                    </span>
                  </template>

                  <!-- rts_set — manually set RTS% (read-only here; click ✎ icon to edit via modal) -->
                  <template x-if="col.id==='rts_set'">
                    <span style="display:inline-flex;align-items:flex-start;gap:4px;">
                      <div style="flex:1;">
                        <template x-if="row.rts_pct !== null">
                          <div>
                            <span style="font-weight:700;color:#000;"
                                  x-text="row.rts_pct.toFixed(1)+'%'"></span>
                            <template x-if="row.settings_date">
                              <div style="font-size:9px;color:#94a3b8;margin-top:2px;"
                                   x-text="'from ' + row.settings_date"></div>
                            </template>
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
                      <button type="button" class="cell-edit-icon" @click="openEditModal(row, 'rts')"
                              title="Edit RTS%">✎</button>
                    </span>
                  </template>

                  <!-- promo — per-date inherited; click ✎ to edit via modal -->
                  <template x-if="col.id==='promo'">
                    <span style="display:inline-flex;align-items:center;gap:4px;">
                      <div style="flex:1;">
                        <template x-if="row.promo">
                          <span x-text="(row.promo.toUpperCase()==='NONE' || row.promo==='-') ? '—' : row.promo"></span>
                        </template>
                        <template x-if="!row.promo">
                          <span style="color:#cbd5e1;" title="no promo set yet">—</span>
                        </template>
                      </div>
                      <button type="button" class="cell-edit-icon" @click="openEditModal(row, 'promo')"
                              title="Edit Promo">✎</button>
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

                  <!-- item_val — Marketing's cogs; click ✎ to edit via modal -->
                  <template x-if="col.id==='item_val'">
                    <span style="display:inline-flex;align-items:flex-start;gap:4px;">
                      <div style="flex:1;">
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
                      </div>
                      <button type="button" class="cell-edit-icon" @click="openEditModal(row, 'cogs')"
                              title="Edit Unit Cost (COGS)">✎</button>
                    </span>
                  </template>

                  <!-- item_val_ceo — CEO-only; click ✎ to edit CEO cogs via modal -->
                  <template x-if="col.id==='item_val_ceo'">
                    <span style="display:inline-flex;align-items:center;gap:4px;">
                      <div style="flex:1;">
                        <template x-if="row.item_value_ceo !== null && row.item_value_ceo !== undefined">
                          <span style="color:#111;" x-text="money(row.item_value_ceo)"></span>
                        </template>
                        <template x-if="row.item_value_ceo === null || row.item_value_ceo === undefined">
                          <span style="color:#fca5a5;font-style:italic;font-size:11px;" title="No CEO value set — profit calc shows — for this row.">—</span>
                        </template>
                      </div>
                      <button type="button" class="cell-edit-icon" @click="openEditModal(row, 'cogs_ceo')"
                              title="Edit CEO Unit Cost">✎</button>
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

                  <!-- hold — daily HOLD snapshot (units) as-of end_date.
                       Black font; coloring via conditional formatting (column-settings), manual. -->
                  <template x-if="col.id==='hold'">
                    <span style="color:#111;"
                          :title="row.hold_snap_date ? ('HOLD units as-of '+row.hold_snap_date) : 'no hold snapshot yet'"
                          x-text="(row.hold_units !== null && row.hold_units !== undefined) ? num(row.hold_units) : '—'"></span>
                  </template>

                  <!-- action — per-(page, end_date) note; click ✎ to edit via modal -->
                  {{-- action — truncated by default (huwag auto-expand kahit mahaba);
                       may "more/less" toggle per cell. ✎ → floating edit modal. --}}
                  <template x-if="col.id==='action'">
                    <span style="display:flex;width:100%;align-items:flex-start;justify-content:space-between;gap:6px;">
                      <div style="flex:1;text-align:left;min-width:0;">
                        <template x-if="row.action_comment">
                          <div :title="row.action_comment">
                            <div :style="row._actionOpen
                                          ? 'white-space:normal;max-width:200px;'
                                          : 'white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:150px;'"
                                 style="font-size:11px;color:#0f172a;line-height:1.3;">
                              <span x-text="row.action_comment"></span>
                            </div>
                            {{-- Editor info — LAGING visible (kahit collapsed) --}}
                            <template x-if="row.action_by">
                              <div style="font-size:9px;color:#94a3b8;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px;"
                                   :title="'✎ '+row.action_by + (row.action_at ? (' · '+row.action_at) : '')"
                                   x-text="'✎ '+row.action_by + (row.action_at ? (' · '+row.action_at) : '')"></div>
                            </template>
                            <template x-if="(row.action_comment||'').length > 24">
                              <button type="button" @click="row._actionOpen = !row._actionOpen"
                                      style="background:none;border:none;color:#2563eb;font-size:9px;cursor:pointer;padding:0;font-weight:600;"
                                      x-text="row._actionOpen ? '▾ less' : '▸ more'"></button>
                            </template>
                          </div>
                        </template>
                        <template x-if="!row.action_comment">
                          <span style="color:#cbd5e1;" title="no action logged">—</span>
                        </template>
                      </div>
                      {{-- White chip ✎ — visible sa kahit anong cell bg (white o red CF) --}}
                      <button type="button" @click="openActionModal(row)" title="Edit Action note"
                              style="flex-shrink:0;align-self:flex-start;background:#fff;border:1px solid #cbd5e1;
                                     border-radius:5px;color:#334155;font-size:12px;line-height:1;padding:3px 6px;
                                     cursor:pointer;box-shadow:0 1px 2px rgba(0,0,0,.2);">✎</button>
                    </span>
                  </template>

                </td>
              </template>

              {{-- Row-level Edit button removed. Per-cell ✎ icons sa RTS / Promo /
                   Item Val / Item Val (CEO) columns ang nag-open ng scoped modal
                   for that specific field. --}}
            </tr>

            {{-- Inline expand row — sits directly after THIS page row when
                 expandedPages[page_name].open is true. Hosts the nested
                 campaigns / adsets / ads view from /ads_manager/campaigns/data.
                 Wrapped together with the page row inside a per-iteration
                 <tbody> so they stay interleaved (Alpine x-for needs single
                 root child — <tbody> serves as that root). --}}
            <tr x-show="(expandedPages[row.page_name] || {}).open"
                class="page-expand-row">
              <td :colspan="(cols.length + 2)" style="padding:0;">{{-- (cols.length + 2): page + idx-checkbox + cols --}}
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
                  <template x-if="col.id==='orders_1d'">
                    <span x-text="num(tot().orders_last_day)"></span>
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
                  <template x-if="col.id==='np_per_order'">
                    <span style="color:#111;font-weight:700;" x-text="tot().np_per_order != null ? md(tot().np_per_order) : '—'"
                          :title="tot().projected_profit_last_day != null ? '1D net profit ₱'+Number(tot().projected_profit_last_day||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / orders '+(tot().orders_last_day||0) : ''"></span>
                  </template>
                  <template x-if="col.id==='np_per_order_3d'">
                    <span style="font-weight:700;color:#111;" x-text="tot().np_per_order_3d != null ? md(tot().np_per_order_3d) : '—'"
                          :title="tot().projected_profit_last_3d != null ? '3D net profit ₱'+Number(tot().projected_profit_last_3d||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / orders '+(tot().orders_last_3d||0) : ''"></span>
                  </template>
                  <template x-if="col.id==='np_per_order_7d'">
                    <span style="font-weight:700;color:#111;" x-text="tot().np_per_order_7d != null ? md(tot().np_per_order_7d) : '—'"
                          :title="tot().projected_profit_last_7d != null ? '7D net profit ₱'+Number(tot().projected_profit_last_7d||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / orders '+(tot().orders_last_7d||0) : ''"></span>
                  </template>
                  <template x-if="col.id==='np_per_order_1m'">
                    <span style="font-weight:700;color:#111;" x-text="tot().np_per_order_1m != null ? md(tot().np_per_order_1m) : '—'"
                          :title="tot().projected_profit != null ? 'Range net profit ₱'+Number(tot().projected_profit||0).toLocaleString('en-PH',{maximumFractionDigits:0})+' / orders '+(tot().orders||0)+' (entire range)' : ''"></span>
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
                  <template x-if="!['adspent','orders','orders_1d','cpp','proceed','pcpp','tcpr','breakeven_cpp','proj_profit','per_order','np_per_order','np_per_order_3d','np_per_order_7d','np_per_order_1m','proj_pct','proj_pct_1d','proj_pct_3d','proj_pct_7d','proj_prof_1d','proj_prof_3d','proj_prof_7d'].includes(col.id)">
                    <span></span>
                  </template>
                </td>
              </template>
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

  {{-- Edit modal — 3 independent sections (RTS, Promo, COGS) — each has its
       own editable effective_date and Save button. Modal stays open after each
       save so user can do multiple actions; Close button to exit + refresh. --}}
  {{-- ── Action note modal — floating; SLIM TOP TAB lang ang draggable ──────
       Transparent backdrop (viewable ang table). Auto-grow height; walang
       horizontal scroll (content wraps); capped width + char limit. --}}
  <template x-if="actionModal.open">
    <div style="position:fixed;inset:0;z-index:9999;background:transparent;" @click.self="actionModal.open = false">
      <div class="ow-modal-card"
           :style="`position:fixed;left:${actionModal.x}px;top:${actionModal.y}px;width:min(92vw,440px);max-width:440px;margin:0;overflow-x:hidden;overflow-y:auto;max-height:90vh;`">

        {{-- Drag tab — ITO LANG ang draggable (parang window title bar) --}}
        <div @mousedown.prevent="startActionDrag($event)" title="Drag to move"
             style="cursor:move;user-select:none;background:#0f172a;height:24px;display:flex;align-items:center;justify-content:space-between;padding:0 8px;">
          <span style="color:#64748b;font-size:12px;letter-spacing:3px;line-height:1;">⠿⠿⠿</span>
          <span style="color:#94a3b8;font-size:9px;">drag</span>
          <button type="button" @click="actionModal.open=false" @mousedown.stop
                  style="background:none;border:none;color:#94a3b8;font-size:15px;line-height:1;cursor:pointer;padding:0 2px;" title="Close">✕</button>
        </div>

        <div class="ow-modal-section" style="border-bottom:1px solid #e2e8f0;">
          <div style="font-size:10.5px;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">📝 Action Note</div>
          <div style="font-size:16px;font-weight:700;color:#0f172a;margin-top:4px;word-break:break-word;" x-text="actionModal.page_name"></div>
          <div style="font-size:12px;color:#475569;margin-top:2px;word-break:break-word;">
            <span style="font-family:ui-monospace,monospace;" x-text="actionModal.ts_date"></span>
            <span style="color:#94a3b8;"> · anong aksyon ginawa sa page na ito sa araw na ito</span>
          </div>
        </div>

        <div class="ow-modal-section">
          <label>Action / Comment <span style="color:#94a3b8;font-weight:500;text-transform:none;letter-spacing:0;" x-text="'('+(actionModal.comment||'').length+'/1000)'"></span></label>
          <textarea x-model="actionModal.comment" maxlength="1000"
                    x-init="$nextTick(() => { $el.style.height='auto'; $el.style.height=Math.min($el.scrollHeight,220)+'px'; })"
                    @input="$el.style.height='auto'; $el.style.height=Math.min($el.scrollHeight,220)+'px'"
                    placeholder="hal. 'Tinaasan ang budget', 'Pinause ang adset', 'Bagong creative'…"
                    style="width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:6px;padding:8px;font-size:13px;resize:none;outline:none;min-height:58px;white-space:pre-wrap;overflow-wrap:break-word;overflow-y:auto;"></textarea>

          <div style="display:flex;align-items:center;justify-content:space-between;margin-top:8px;gap:6px;flex-wrap:wrap;">
            <button type="button" @click="loadActionLogs()"
                    style="background:none;border:none;color:#2563eb;font-size:11px;cursor:pointer;font-weight:600;padding:0;">
              <span x-text="actionModal.logsOpen ? '▾ Hide edit history' : '▸ Edit history'"></span>
            </button>
            <template x-if="actionModal.by">
              <span style="font-size:10px;color:#94a3b8;word-break:break-word;"
                    x-text="'last: '+actionModal.by+(actionModal.at?(' · '+actionModal.at):'')"></span>
            </template>
          </div>

          <template x-if="actionModal.logsOpen">
            <div style="margin-top:8px;border-top:1px solid #f1f5f9;padding-top:8px;max-height:160px;overflow-y:auto;overflow-x:hidden;">
              <template x-if="actionModal.logsLoading">
                <div style="font-size:11px;color:#94a3b8;">Loading…</div>
              </template>
              <template x-if="!actionModal.logsLoading && actionModal.logs.length===0">
                <div style="font-size:11px;color:#94a3b8;">Walang edit history pa.</div>
              </template>
              <template x-for="(lg, i) in actionModal.logs" :key="i">
                <div style="font-size:11px;color:#334155;padding:4px 0;border-bottom:1px solid #f8fafc;word-break:break-word;">
                  <div style="color:#94a3b8;font-size:10px;" x-text="(lg.by||'—')+' · '+(lg.at||'')"></div>
                  <div x-text="(lg.new || '(cleared)')"></div>
                </div>
              </template>
            </div>
          </template>
        </div>

        <div class="ow-modal-section" style="border-top:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;gap:8px;">
          <div>
            <template x-if="actionModal.error"><span style="color:#dc2626;font-size:12px;" x-text="actionModal.error"></span></template>
            <template x-if="actionModal.saved"><span style="color:#16a34a;font-size:12px;font-weight:700;" x-text="actionModal.saved"></span></template>
          </div>
          <div style="display:flex;gap:8px;">
            <button type="button" @click="actionModal.open=false"
                    style="background:#fff;border:1px solid #cbd5e1;color:#475569;border-radius:6px;padding:6px 14px;font-size:13px;font-weight:600;cursor:pointer;">Cancel</button>
            <button type="button" @click="saveActionNote()" :disabled="actionModal.saving"
                    style="background:#2563eb;border:1px solid #2563eb;color:#fff;border-radius:6px;padding:6px 16px;font-size:13px;font-weight:700;cursor:pointer;">
              <span x-text="actionModal.saving ? 'Saving…' : 'Save'"></span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </template>

  <template x-if="edit.open">
    <div class="ow-modal-backdrop" @click.self="edit.open = false">
      <div class="ow-modal-card">
        <div class="ow-modal-section" style="border-bottom:1px solid #e2e8f0;">
          <div style="font-size:10.5px;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;">Edit Row</div>
          <div style="font-size:16px;font-weight:700;color:#0f172a;margin-top:4px;" x-text="edit.page_name"></div>
          <div style="font-size:12px;color:#475569;margin-top:2px;">
            <span x-text="edit.item_name"></span>
            <span style="color:#94a3b8;"> · </span>
            <span style="font-family:ui-monospace,monospace;" x-text="edit.date"></span>
          </div>
          <div style="font-size:11px;color:#64748b;margin-top:3px;">
            <span x-text="(edit.orders||0) + ' orders'"></span>
            <template x-if="edit.price">
              <span x-text="' @ ₱' + Number(edit.price).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2})"></span>
            </template>
            <template x-if="edit.anchor_first_date">
              <span style="color:#7c3aed;font-weight:600;margin-left:6px;"
                    x-text="'▸ anchor since ' + edit.anchor_first_date"></span>
            </template>
          </div>
        </div>

        {{-- ━━━ Section 1: RTS% ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ --}}
        <div class="ow-modal-section" style="border-top:1px solid #e2e8f0;background:#fefce8;"
             x-show="!edit.focusScope || edit.focusScope === 'rts'">
          <div style="font-size:10.5px;color:#92400e;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:6px;">📊 RTS%</div>
          <label>RTS% <span style="color:#94a3b8;font-weight:500;text-transform:none;letter-spacing:0;">(per page, item & price · effective for chosen period)</span></label>
          <div style="display:flex;align-items:center;gap:8px;">
            <input type="number" step="0.01" min="0" max="100" x-model="edit.rts_pct" placeholder="e.g. 50">
            <span style="color:#64748b;">%</span>
          </div>
          <template x-if="edit.rts_inherited && edit.rts_eff_date">
            <div style="font-size:11px;color:#b45309;margin-top:4px;">
              ⚠ Current value inherited from <span style="font-family:ui-monospace,monospace;" x-text="edit.rts_eff_date"></span>. Pwede ka mag-set ng bagong period sa baba.
            </div>
          </template>

          <label style="margin-top:10px;">RTS Comment <span style="color:#dc2626;">*</span></label>
          <input type="text" x-model="edit.comment" maxlength="500" placeholder="why is this value different?">

          <label style="margin-top:10px;">Effective from <span style="color:#94a3b8;font-weight:500;text-transform:none;letter-spacing:0;">(start ng period kung saan applicable)</span></label>
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <input type="date" x-model="edit.rts_effective_date">
            <button type="button" class="ow-btn"
                    style="background:#e0e7ff;color:#3730a3;font-size:10.5px;padding:4px 8px;"
                    @click="edit.rts_effective_date = edit.anchor_first_date || edit.date"
                    :disabled="!edit.anchor_first_date">↶ anchor</button>
            <button type="button" class="ow-btn"
                    style="background:#f1f5f9;color:#475569;font-size:10.5px;padding:4px 8px;"
                    @click="edit.rts_effective_date = edit.date">↶ this date</button>
          </div>
          <div style="font-size:11px;color:#64748b;margin-top:3px;">
            Iba't ibang season = iba't ibang period. Pumili ng date kung saan magstart ang bagong RTS value.
            <template x-if="edit.anchor_first_date">
              <span style="color:#7c3aed;font-weight:600;"> · price anchor: <span x-text="edit.anchor_first_date"></span></span>
            </template>
          </div>

          <template x-if="edit.rtsError">
            <div style="background:#fef2f2;color:#991b1b;font-size:12px;padding:6px 10px;border-radius:4px;margin-top:8px;" x-text="edit.rtsError"></div>
          </template>
          <template x-if="edit.rtsSaved">
            <div style="background:#dcfce7;color:#166534;font-size:12px;padding:6px 10px;border-radius:4px;margin-top:8px;" x-text="edit.rtsSaved"></div>
          </template>
          <div style="margin-top:10px;text-align:right;">
            <button type="button" class="ow-btn ow-btn-save" @click="submitScoped('rts')" :disabled="edit.savingRts">
              <span x-text="edit.savingRts ? 'Saving…' : '💾 Save RTS'"></span>
            </button>
          </div>
        </div>

        {{-- ━━━ Section 2: Promo ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ --}}
        <div class="ow-modal-section" style="border-top:1px solid #e2e8f0;background:#fdf4ff;"
             x-show="!edit.focusScope || edit.focusScope === 'promo'">
          <div style="font-size:10.5px;color:#86198f;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:6px;">🏷 Promo</div>
          <label>Promo <span style="color:#dc2626;">*</span> <span style="color:#94a3b8;font-weight:500;text-transform:none;letter-spacing:0;">(type NONE if walang promo)</span></label>
          <input type="text" x-model="edit.promo" maxlength="255" placeholder='e.g. "9.9 Sale", "PAYDAY", or "NONE"'>
          <template x-if="edit.promo_inherited && edit.rts_eff_date">
            <div style="font-size:11px;color:#b45309;margin-top:4px;">
              ⚠ Current value inherited from <span style="font-family:ui-monospace,monospace;" x-text="edit.rts_eff_date"></span>. Pwede ka mag-set ng bagong period sa baba.
            </div>
          </template>

          <label style="margin-top:10px;">Effective from <span style="color:#94a3b8;font-weight:500;text-transform:none;letter-spacing:0;">(start ng promo period)</span></label>
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <input type="date" x-model="edit.promo_effective_date">
            <button type="button" class="ow-btn"
                    style="background:#e0e7ff;color:#3730a3;font-size:10.5px;padding:4px 8px;"
                    @click="edit.promo_effective_date = edit.anchor_first_date || edit.date"
                    :disabled="!edit.anchor_first_date">↶ anchor</button>
            <button type="button" class="ow-btn"
                    style="background:#f1f5f9;color:#475569;font-size:10.5px;padding:4px 8px;"
                    @click="edit.promo_effective_date = edit.date">↶ this date</button>
          </div>
          <div style="font-size:11px;color:#64748b;margin-top:3px;">
            Iba't ibang promo period = pumili ng start date. Promo applies until next change or end of price scope.
          </div>

          <template x-if="edit.promoError">
            <div style="background:#fef2f2;color:#991b1b;font-size:12px;padding:6px 10px;border-radius:4px;margin-top:8px;" x-text="edit.promoError"></div>
          </template>
          <template x-if="edit.promoSaved">
            <div style="background:#dcfce7;color:#166534;font-size:12px;padding:6px 10px;border-radius:4px;margin-top:8px;" x-text="edit.promoSaved"></div>
          </template>
          <div style="margin-top:10px;text-align:right;">
            <button type="button" class="ow-btn ow-btn-save" @click="submitScoped('promo')" :disabled="edit.savingPromo">
              <span x-text="edit.savingPromo ? 'Saving…' : '💾 Save Promo'"></span>
            </button>
          </div>
        </div>

        {{-- ━━━ Section 3: COGS (item-global) ━━━━━━━━━━━━━━━━━━━━━━ --}}
        <div class="ow-modal-section" style="border-top:1px solid #e2e8f0;background:#f0fdf4;"
             x-show="!edit.focusScope || edit.focusScope === 'cogs' || edit.focusScope === 'cogs_ceo'">
          <div style="font-size:10.5px;color:#166534;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:6px;">💰 COGS <span style="color:#94a3b8;font-weight:500;">(item-global)</span></div>
          <label>Unit Cost (Marketing) <span style="color:#94a3b8;font-weight:500;text-transform:none;letter-spacing:0;">— affects ALL pages on this date</span></label>
          <div style="display:flex;align-items:center;gap:8px;">
            <span style="color:#64748b;">₱</span>
            <input type="number" step="0.01" min="0" x-model="edit.unit_cost" placeholder="e.g. 25">
          </div>

          <template x-if="edit.isCeoView">
            <div>
              <label style="margin-top:10px;color:#3730a3;">🔒 CEO Unit Cost <span style="color:#94a3b8;font-weight:500;text-transform:none;letter-spacing:0;">— CEO-only</span></label>
              <div style="display:flex;align-items:center;gap:8px;">
                <span style="color:#64748b;">₱</span>
                <input type="number" step="0.01" min="0" x-model="edit.unit_cost_ceo" placeholder="e.g. 25"
                       style="border-color:#c7d2fe;background:#eef2ff80;">
              </div>
            </div>
          </template>

          <label style="margin-top:10px;">Effective from <span style="color:#94a3b8;font-weight:500;text-transform:none;letter-spacing:0;">(editable)</span></label>
          <div style="display:flex;align-items:center;gap:8px;">
            <input type="date" x-model="edit.cogs_effective_date">
            <button type="button" class="ow-btn"
                    style="background:#f1f5f9;color:#475569;font-size:10.5px;padding:4px 8px;"
                    @click="edit.cogs_effective_date = edit.date">↶ this date</button>
            <button type="button" class="ow-btn"
                    style="background:#dcfce7;color:#166534;font-size:10.5px;padding:4px 8px;"
                    @click="edit.cogs_effective_date = edit.cogs_last_date || edit.date"
                    :disabled="!edit.cogs_last_date">↶ last cogs change</button>
          </div>
          <div style="font-size:11px;color:#64748b;margin-top:3px;">
            <template x-if="edit.cogs_last_date">
              <span>Last COGS change for this item: <span style="font-family:ui-monospace,monospace;" x-text="edit.cogs_last_date"></span></span>
            </template>
            <template x-if="!edit.cogs_last_date">
              <span>No prior COGS set for this item yet.</span>
            </template>
          </div>

          <template x-if="edit.cogsError">
            <div style="background:#fef2f2;color:#991b1b;font-size:12px;padding:6px 10px;border-radius:4px;margin-top:8px;" x-text="edit.cogsError"></div>
          </template>
          <template x-if="edit.cogsSaved">
            <div style="background:#dcfce7;color:#166534;font-size:12px;padding:6px 10px;border-radius:4px;margin-top:8px;" x-text="edit.cogsSaved"></div>
          </template>
          <div style="margin-top:10px;text-align:right;">
            <button type="button" class="ow-btn ow-btn-save" @click="submitScoped('cogs')" :disabled="edit.savingCogs">
              <span x-text="edit.savingCogs ? 'Saving…' : '💾 Save COGS'"></span>
            </button>
          </div>
        </div>

        <div class="ow-modal-footer">
          <button type="button" class="ow-btn ow-btn-cancel" @click="closeEditModal()">Close</button>
        </div>
      </div>
    </div>
  </template>

  {{-- ━━━ Creative Preview Modal ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
       Opens when user clicks a campaign / ad set / ad name sa expanded
       campaigns panel. Lazy-fetches creative data via /ads_manager/creative-
       preview. Three sections:
         1. Facebook post embed (iframe + fallback link)
         2. Primary Text + Headline (backup info)
         3. Messenger preview (Page + welcome msg + quick replies mockup)
  --}}
  <template x-if="creativeModal.open">
    <div class="ow-modal-backdrop" @click.self="creativeModal.open = false" style="z-index:90;">
      <div class="ow-modal-card" style="max-width:1280px;width:96vw;max-height:90vh;overflow-y:auto;">
        <div class="ow-modal-section" style="border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">
          <div style="flex:1;min-width:0;">
            <div style="font-size:10.5px;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;"
                 x-text="(creativeModal.level || '') + ' preview'"></div>
            <div style="font-size:16px;font-weight:700;color:#0f172a;margin-top:4px;line-height:1.3;word-break:break-word;"
                 x-text="creativeModal.data?.entity_name || 'Loading…'"></div>
            <div style="font-size:11px;color:#475569;margin-top:2px;">
              <span x-text="creativeModal.data?.page_name || ''"></span>
              <template x-if="creativeModal.data?.campaign_name && creativeModal.level !== 'campaign'">
                <span> · <span x-text="creativeModal.data.campaign_name"></span></span>
              </template>
              <template x-if="creativeModal.data?.ad_set_name && creativeModal.level === 'ad'">
                <span> / <span x-text="creativeModal.data.ad_set_name"></span></span>
              </template>
            </div>
          </div>
          <button @click="creativeModal.open = false"
                  style="background:transparent;border:none;font-size:18px;color:#64748b;cursor:pointer;padding:4px 8px;border-radius:4px;"
                  onmouseover="this.style.background='#f1f5f9';this.style.color='#0f172a';"
                  onmouseout="this.style.background='transparent';this.style.color='#64748b';"
                  title="Close">✕</button>
        </div>

        <template x-if="creativeModal.loading">
          <div style="padding:30px;text-align:center;color:#94a3b8;font-size:13px;">
            <span class="spin" style="margin-right:8px;"></span>Loading creative…
          </div>
        </template>

        <template x-if="!creativeModal.loading && creativeModal.error">
          <div style="padding:20px;background:#fef2f2;color:#991b1b;font-size:12.5px;border-radius:6px;margin:14px;"
               x-text="'⚠ ' + creativeModal.error"></div>
        </template>

        <template x-if="!creativeModal.loading && !creativeModal.error && creativeModal.data">
          {{-- 3-column grid: FB Post · Body+Headline · Messenger Preview.
               Collapses to single column sa narrow screens (< 900px). --}}
          <div class="creative-modal-grid">
            {{-- ━━━ Section 1: Facebook Post Embed ━━━━━━━━━━━━━━━━━━━━━━ --}}
            <div class="ow-modal-section" style="border-top:1px solid #e2e8f0;background:#f0f2f5;">
              <div style="font-size:10.5px;color:#1e40af;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:8px;">
                📺 Facebook Post
              </div>
              <template x-if="creativeModal.data.creative?.ad_link">
                <div>
                  {{-- iframe embed via FB plugin URL. Works for posts, reels, videos. --}}
                  <div style="background:white;border-radius:8px;overflow:hidden;border:1px solid #dadde1;">
                    {{-- Height balanced: ~65vh — enough para sa landscape (1920x1080)
                         na may post text + reactions visible. Portrait reels may
                         maliit na internal scroll pero acceptable. Capped to keep
                         the modal manageable. --}}
                    <iframe :src="fbEmbedSrc(creativeModal.data.creative.ad_link)"
                            style="width:100%;height:min(780px, max(450px, 65vh));border:none;display:block;"
                            scrolling="yes" frameborder="0"
                            allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share"
                            allowfullscreen="true"></iframe>
                  </div>
                  <div style="text-align:center;margin-top:8px;">
                    <a :href="creativeModal.data.creative.ad_link" target="_blank" rel="noopener"
                       style="display:inline-block;font-size:11.5px;color:#1877f2;text-decoration:none;padding:6px 12px;border:1px solid #dadde1;border-radius:6px;background:white;"
                       onmouseover="this.style.background='#f0f2f5';"
                       onmouseout="this.style.background='white';">
                      🔗 Open on Facebook ↗
                    </a>
                  </div>
                </div>
              </template>
              <template x-if="!creativeModal.data.creative?.ad_link">
                <div style="background:white;border:1px dashed #cbd5e1;border-radius:8px;padding:24px;text-align:center;color:#94a3b8;font-size:12px;">
                  No ad link saved yet.<br>
                  <span style="font-size:10.5px;">Set sa /ads_manager/campaigns/history para mag-preview.</span>
                </div>
              </template>
            </div>

            {{-- ━━━ Section 2: Primary Text + Headline ━━━━━━━━━━━━━━━━━━ --}}
            <div class="ow-modal-section" style="border-top:1px solid #e2e8f0;background:#fefce8;">
              <div style="font-size:10.5px;color:#92400e;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:8px;">
                📝 Primary Text & Headline <span style="color:#a16207;font-weight:500;text-transform:none;letter-spacing:0;">(backup info)</span>
              </div>
              <div style="background:white;border:1px solid #fde68a;border-radius:6px;padding:10px;">
                <div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;">Body / Primary Text</div>
                <div style="font-size:12px;line-height:1.5;color:#0f172a;white-space:pre-wrap;word-break:break-word;"
                     x-text="creativeModal.data.creative?.body || '(no body text)'"
                     :style="creativeModal.data.creative?.body ? '' : 'color:#cbd5e1;font-style:italic;'"></div>
              </div>
              <div style="background:white;border:1px solid #fde68a;border-radius:6px;padding:10px;margin-top:8px;">
                <div style="font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px;">Headline</div>
                <div style="font-size:13px;font-weight:600;color:#0f172a;word-break:break-word;"
                     x-text="creativeModal.data.creative?.headline || '(no headline)'"
                     :style="creativeModal.data.creative?.headline ? '' : 'color:#cbd5e1;font-style:italic;font-weight:400;'"></div>
              </div>
            </div>

            {{-- ━━━ Section 3: Messenger Preview ━━━━━━━━━━━━━━━━━━━━━━━━ --}}
            <div class="ow-modal-section" style="border-top:1px solid #e2e8f0;background:#fdf4ff;">
              <div style="font-size:10.5px;color:#86198f;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:8px;">
                💬 Messenger Preview
              </div>
              {{-- Messenger UI mockup — mimics the FB Messenger business chat layout. --}}
              <div style="background:white;border-radius:12px;border:1px solid #e4e6eb;overflow:hidden;font-family:system-ui,-apple-system,'Segoe UI',sans-serif;">
                {{-- Header: avatar + page name + business chat + call/menu icons --}}
                <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid #e4e6eb;background:white;">
                  <div style="position:relative;flex-shrink:0;">
                    <div :style="'width:38px;height:38px;border-radius:50%;background:'+avatarColor(creativeModal.data.page_name)+';color:white;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px;'"
                         x-text="pageInitial(creativeModal.data.page_name)"></div>
                    <span style="position:absolute;bottom:0;right:0;width:11px;height:11px;background:#31a24c;border:2px solid white;border-radius:50%;"></span>
                  </div>
                  <div style="flex:1;min-width:0;">
                    <div style="font-weight:600;font-size:14px;color:#050505;display:flex;align-items:center;gap:4px;">
                      <span x-text="creativeModal.data.page_name || 'Page'"></span>
                      <span style="color:#0866ff;font-size:11px;" title="Verified business">✓</span>
                    </div>
                    <div style="font-size:11px;color:#65676b;">Business chat</div>
                  </div>
                  <div style="display:flex;gap:14px;color:#0866ff;font-size:18px;">
                    <span title="Call">📞</span>
                    <span title="Menu">🗂</span>
                  </div>
                </div>

                {{-- Conversation area --}}
                <div style="padding:16px 14px;background:white;min-height:220px;">
                  <div style="text-align:center;font-size:11px;color:#65676b;padding:8px 16px;line-height:1.4;margin-bottom:12px;">
                    You opened this conversation through an ad. When you reply,
                    <span x-text="creativeModal.data.page_name || 'this Page'"></span>
                    will be able to see your public info and which ad you clicked.
                  </div>

                  {{-- Welcome message bubble (incoming from page) --}}
                  <template x-if="creativeModal.data.creative?.welcome_message">
                    <div style="display:flex;align-items:flex-end;gap:6px;margin-bottom:10px;">
                      <div :style="'width:24px;height:24px;border-radius:50%;background:'+avatarColor(creativeModal.data.page_name)+';color:white;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;flex-shrink:0;'"
                           x-text="pageInitial(creativeModal.data.page_name)"></div>
                      <div style="background:#f0f0f0;color:#050505;padding:10px 13px;border-radius:18px;font-size:13px;line-height:1.4;max-width:78%;white-space:pre-wrap;word-break:break-word;"
                           x-text="creativeModal.data.creative.welcome_message"></div>
                    </div>
                  </template>
                  <template x-if="!creativeModal.data.creative?.welcome_message">
                    <div style="background:#fef3c7;color:#92400e;border-radius:8px;padding:10px;font-size:11px;text-align:center;">
                      ⚠ No welcome message set
                    </div>
                  </template>

                  {{-- Quick reply pills --}}
                  <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;margin-top:14px;">
                    <template x-for="qr in [creativeModal.data.creative?.quick_reply_1, creativeModal.data.creative?.quick_reply_2, creativeModal.data.creative?.quick_reply_3].filter(Boolean)" :key="qr">
                      <div style="background:white;color:#0866ff;border:1px solid #0866ff;border-radius:18px;padding:8px 16px;font-size:13px;font-weight:600;cursor:pointer;"
                           x-text="qr"></div>
                    </template>
                    <template x-if="!creativeModal.data.creative?.quick_reply_1 && !creativeModal.data.creative?.quick_reply_2 && !creativeModal.data.creative?.quick_reply_3">
                      <div style="color:#cbd5e1;font-size:11px;font-style:italic;width:100%;text-align:center;">(no quick replies set)</div>
                    </template>
                  </div>
                </div>

                {{-- Composer footer --}}
                <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;border-top:1px solid #e4e6eb;background:#f0f2f5;">
                  <span style="color:#0866ff;font-size:16px;">📷</span>
                  <span style="color:#0866ff;font-size:16px;">🖼</span>
                  <span style="color:#0866ff;font-size:16px;">🎤</span>
                  <div style="flex:1;background:white;border-radius:18px;padding:6px 12px;font-size:12px;color:#65676b;">Aa</div>
                  <span style="color:#fbbf24;font-size:16px;">😊</span>
                  <span style="color:#0866ff;font-size:16px;">👍</span>
                </div>
              </div>
            </div>
          </div>
        </template>
      </div>
    </div>
  </template>

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
        // Partial date — optional opt-in 1D override. Empty default.
        const urlPartial = qs.get('partial_date');
        const partialDate = (urlPartial && re.test(urlPartial)) ? urlPartial : '';
        return { startDate: s, endDate: e, partialDate };
      })(),

      rows:[], loading:false, editIdx:-1, editRow:null,
      ev:{ item_value:'', rts_pct:'', comment:'' },
      saving:false, saveMsg:'',

      // Actual CEO role from server — used as the auth gate for the View toggle
      // button visibility and for ANY genuinely-CEO-only behavior (writes, etc.).
      isCeoView: @json(!empty($isCEO ?? false)),

      // CEO "view as" toggle: 'ceo' = full CEO mode (default), 'marketing' = simulate
      // Marketing's UI. Drives cogs source for profit + visibility of CEO column
      // + modal CEO field. Initialized from URL ?view_as= so refresh preserves it.
      viewAs: (function(){
        const p = (new URLSearchParams(window.location.search).get('view_as') || '').toLowerCase();
        return p === 'marketing' ? 'marketing' : 'ceo';
      })(),

      // Derived: true only when actual CEO AND currently in CEO view mode. Used
      // for column + modal field visibility downstream. Computed via Alpine getter.
      get effectiveIsCeo(){ return this.isCeoView && this.viewAs === 'ceo'; },

      // Modal-based edit state. Replaces old inline edit UX. Saves use the
      // same /owner/private/item-setting endpoint as the breakdown matrix
      // modal — including optional `apply_through` for range cascade.
      edit: {
        open:false, saving:false, error:null,
        page_name:'', item_name:'', date:'', orders:0, price:null,
        rts_pct:'', rts_eff_date:null, rts_inherited:false,
        unit_cost:'', unit_cost_ceo:'', comment:'',
        apply_from:null,
        isCeoView:false,
      },
      // Action note modal — per (page, end_date) comment. Floating + draggable.
      actionModal: {
        open:false, saving:false, error:null, saved:null,
        page_key:'', page_name:'', ts_date:'', comment:'',
        by:null, at:null, _row:null,
        logsOpen:false, logsLoading:false, logs:[],
        x:140, y:120, _dragging:false, _dx:0, _dy:0,
      },
      refreshing:false,
      savingSnapshot:false,

      // Creative preview modal — opened by clicking campaign/adset/ad name
      // sa expanded campaigns panel. Lazy-fetches creative content via
      // /ads_manager/creative-preview, then renders 3-section modal:
      //   1. FB post iframe embed (via ad_link)
      //   2. Body + headline (text backup)
      //   3. Messenger UI mockup (page + welcome msg + quick replies)
      creativeModal: {
        open: false,
        loading: false,
        error: null,
        level: null,    // 'campaign' | 'adset' | 'ad'
        id: null,
        data: null,     // server response payload
      },
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
      selectedAliases: [],
      itemFilterOpen: false,
      itemFilterSearch: '',
      uniqueItems() {
        const set = new Set();
        for (const r of this.rows) { if (r.item_name) set.add(r.item_name); }
        return [...set].sort((a,b)=>a.localeCompare(b));
      },
      // item_name → item_alias (canonical family). Built once from rows.
      _itemAliasMap() {
        const m = {};
        for (const r of this.rows) {
          if (r.item_name && r.item_alias) m[r.item_name] = String(r.item_alias);
        }
        return m;
      },
      aliasOf(name) {
        const a = this._itemAliasMap()[name];
        return (a && a.toLowerCase() !== String(name).toLowerCase()) ? a : null;
      },
      visibleItems() {
        const q = (this.itemFilterSearch||'').toLowerCase().trim();
        const items = this.uniqueItems();
        if (!q) return items;
        const am = this._itemAliasMap();
        // Match by item NAME or by item ALIAS (canonical family).
        return items.filter(n => {
          if (n.toLowerCase().includes(q)) return true;
          const a = am[n];
          return a && a.toLowerCase().includes(q);
        });
      },
      toggleItem(name) {
        const i = this.selectedItems.indexOf(name);
        if (i>=0) this.selectedItems.splice(i,1);
        else this.selectedItems.push(name);
      },
      // ── Alias (family) as a checkable group ───────────────────────────────
      // Distinct aliases across rows. One check covers ALL item names sa family.
      uniqueAliases() {
        const set = new Set();
        for (const r of this.rows) { if (r.item_alias) set.add(String(r.item_alias)); }
        return [...set].sort((a,b)=>a.localeCompare(b));
      },
      visibleAliases() {
        const q = (this.itemFilterSearch||'').toLowerCase().trim();
        const aliases = this.uniqueAliases();
        if (!q) return aliases;
        // Match by alias label, OR if any item name sa family matches the query.
        const am = this._itemAliasMap();
        return aliases.filter(a => {
          if (a.toLowerCase().includes(q)) return true;
          for (const n in am) { if (am[n] === a && n.toLowerCase().includes(q)) return true; }
          return false;
        });
      },
      toggleAlias(alias) {
        const i = this.selectedAliases.indexOf(alias);
        if (i>=0) this.selectedAliases.splice(i,1);
        else this.selectedAliases.push(alias);
      },
      clearItems() { this.selectedItems = []; this.selectedAliases = []; },
      filteredRows() {
        if (!this.selectedItems.length && !this.selectedAliases.length) return this.rows;
        const selI = new Set(this.selectedItems);
        const selA = new Set(this.selectedAliases);
        return this.rows.filter(r =>
          selI.has(r.item_name) || (r.item_alias && selA.has(String(r.item_alias)))
        );
      },

      // ── Column definitions ────────────────────────────────────────────────
      defaultCols() {
        return [
          { id:'adspent',    label:'Adspent',    sort:'adspent',              align:'center', minw:90  },
          { id:'orders',     label:'Orders',     sort:'orders',               align:'center', minw:65  },
          { id:'orders_1d',  label:'Orders (1D)',sort:'orders_last_day',      align:'center', minw:80  },
          { id:'cpp',        label:'CPP',        sort:'cpp',                  align:'center', minw:75  },
          { id:'proceed',    label:'Proceed',    sort:'proceed_orders',       align:'center', minw:70  },
          { id:'pcpp',       label:'P.CPP',      sort:'proceed_cpp',          align:'center', minw:75  },
          { id:'tcpr',          label:'TCPR',           sort:'tcpr_computed',               align:'center', minw:65  },
          { id:'breakeven_cpp', label:this._breakevenLabel(), sort:'breakeven_cpp_computed', align:'center', minw:115 },
          { id:'proj_profit',label:'Prof.Profit',sort:'projected_profit',     align:'center', minw:95  },
          { id:'per_order',  label:'/Order',     sort:'proj_profit_per_order',align:'center', minw:75  },
          { id:'np_per_order',    label:'NP/O',     sort:'np_per_order_computed',    align:'center', minw:75  },
          { id:'np_per_order_3d', label:'NP/O(3D)', sort:'np_per_order_3d_computed', align:'center', minw:80  },
          { id:'np_per_order_7d', label:'NP/O(7D)', sort:'np_per_order_7d_computed', align:'center', minw:80  },
          { id:'np_per_order_1m', label:'NP/O(1M)', sort:'np_per_order_1m_computed', align:'center', minw:80  },
          { id:'proj_pct',     label:'Prof.%(1M)',     sort:'proj_pct_computed',           align:'center', minw:75  },
          { id:'proj_pct_1d',  label:'Prof.%(1D)',     sort:'proj_pct_last_day',           align:'center', minw:75  },
          { id:'proj_pct_3d',  label:'Prof.%(3D)',     sort:'proj_pct_last_3d',            align:'center', minw:75  },
          { id:'proj_pct_7d',  label:'Prof.%(7D)',     sort:'proj_pct_last_7d',            align:'center', minw:75  },
          { id:'proj_prof_1d', label:'Prof.Profit(1D)',sort:'projected_profit_last_day',   align:'center', minw:105 },
          { id:'proj_prof_3d', label:'Prof.Profit(3D)',sort:'projected_profit_last_3d',    align:'center', minw:105 },
          { id:'proj_prof_7d', label:'Prof.Profit(7D)',sort:'projected_profit_last_7d',    align:'center', minw:105 },
          { id:'jnt_rts',      label:'RTS%',           sort:'jnt_rts_pct',                 align:'center', minw:100 },
          { id:'jnt_del',    label:'Del%',       sort:'jnt_del_pct',          align:'center', minw:90  },
          { id:'jnt_transit',label:'Transit%',   sort:'jnt_transit_pct',      align:'center', minw:85  },
          { id:'rts_set',    label:'Set RTS%',   sort:'rts_pct',              align:'center', minw:110 },
          { id:'promo',      label:'Promo',      sort:'promo',                align:'center', minw:90  },
          { id:'price',      label:'Price',      sort:'price',                align:'center', minw:85  },
          { id:'item_val',     label:'Item Val.',       sort:'item_value',     align:'center', minw:80  },
          { id:'item_val_ceo', label:'Item Val. (CEO)', sort:'item_value_ceo', align:'center', minw:90  },
          { id:'ship',       label:'Ship',       sort:'shipping_fee',         align:'center', minw:58  },
          { id:'cod_fee',    label:'COD Fee',    sort:'cod_fee',              align:'center', minw:72  },
          { id:'hold',       label:'Hold',       sort:'hold_units',           align:'center', minw:60  },
          { id:'action',     label:'Action',     sort:null,                   align:'left',   minw:160 },
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
        // CEO viewing as Marketing: force-hide the CEO-only column regardless of
        // user prefs (mirrors what Marketing actually sees on their account).
        // Explicit check (not via getter) to avoid any Alpine reactivity timing
        // issues during initCols which runs before any user interaction.
        const isEffectivelyCeo = this.isCeoView && this.viewAs === 'ceo';
        if (!isEffectivelyCeo) hiddenSet.add('item_val_ceo');

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
        this.cols = this.mergeRtsTrio(ordered);
      },

      // Pagsamahin ang RTS% / Del% / Transit% sa ISANG stacked column (jnt_rdt).
      // Per-line toggle preserved: `members` = visible lang sa trio (forced order
      // RTS→DEL→INT). Column-settings catalog = DI nagagalaw (3 toggles pa rin —
      // pag in-uncheck mo ang isa, yung linyang yon lang ang mawawala sa cell).
      mergeRtsTrio(ordered) {
        const trio = ['jnt_rts', 'jnt_del', 'jnt_transit'];
        const present = ordered.filter(c => trio.includes(c.id)).map(c => c.id);
        if (!present.length) return ordered;
        const members  = trio.filter(id => present.includes(id)); // forced RTS,DEL,INT
        const firstIdx = ordered.findIndex(c => trio.includes(c.id));
        const insertAt = ordered.slice(0, firstIdx).filter(c => !trio.includes(c.id)).length;
        const merged   = { id:'jnt_rdt', label:'RTS / DEL / INT', sort:'jnt_rts_pct', align:'center', minw:95, members };
        const out = ordered.filter(c => !trio.includes(c.id));
        out.splice(insertAt, 0, merged);
        return out;
      },

      saveCols() {
        // Expand ang synthetic jnt_rdt pabalik sa real catalog ids (jnt_rts/del/transit)
        // para tugma ang saved order sa column-settings catalog (di masira sa reload).
        const order = this.cols.flatMap(c => c.id === 'jnt_rdt' ? (c.members || []) : [c.id]);
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
      async load(forceRefreshFlag){
        this.loading=true; this.editIdx=-1; this.saveMsg='';
        // Normalize: if start > end, swap before query
        if (this.startDate && this.endDate && this.startDate > this.endDate) {
          const t = this.startDate; this.startDate = this.endDate; this.endDate = t;
        }
        // Persist range + CEO view toggle in URL so refresh restores it.
        // view_as only added when 'marketing' (default 'ceo' for CEO viewers;
        // ignored server-side for non-CEO).
        const qsObj = { start_date: this.startDate, end_date: this.endDate };
        if (this.isCeoView && this.viewAs === 'marketing') qsObj.view_as = 'marketing';
        // partial_date — only added when explicitly set by user (opt-in 1D override)
        if (this.partialDate) qsObj.partial_date = this.partialDate;
        // refresh=1 bypasses the server-side cache for this single request.
        // History-replaced URL does NOT include refresh — kasi nagdadagdag lang
        // siya ng noise sa visible address bar.
        const qs = new URLSearchParams(qsObj);
        history.replaceState(null,'','?'+qs.toString());
        if (forceRefreshFlag) qs.set('refresh', '1');
        try{
          const r = await fetch('{{ route('owner.private.item-summary') }}?'+qs.toString());
          const j = await r.json();
          this.rows = j.rows||[];
          this.skippedCount = j.skipped_count || 0;
          this.skippedPages = j.skipped_pages || [];
          this.isSingleDate = !!j.is_single_date;
          this.rangeDays    = Number(j.range_days || 1);
          // Sync UI toggle with server's echoed mode (CEO only).
          if (j.is_ceo && j.view_as) this.viewAs = j.view_as;
          // Show cache freshness in the save banner kapag may explicit refresh.
          if (forceRefreshFlag) {
            this.saveMsg = j._cache === 'hit' ? '✓ Cache hit (already fresh)' : '✓ Refreshed from database';
            setTimeout(() => { this.saveMsg = ''; }, 4000);
          }
        }catch(e){ console.error(e); }
        finally{ this.loading=false; }
      },

      // ── Force refresh — user-triggered cache bypass + reload ──────────────
      // Used by the "🔄 Refresh" header button. Sends ?refresh=1 sa data fetch
      // so server re-runs the heavy aggregations and rewrites its cache.
      async forceRefresh(){
        if (this.loading) return;
        await this.load(true);
      },

      // CEO toggle — flips view_as, reloads the data, AND does a full page
      // reload so server-rendered chrome (Daily/Columns/Excluded/Logs/Primary
      // Items buttons) re-evaluates with the new $effectiveIsCEO. Non-CEO never
      // sees the toggle in the first place.
      toggleViewAs(){
        if (!this.isCeoView) return;
        const next = this.viewAs === 'ceo' ? 'marketing' : 'ceo';
        const url = new URL(window.location.href);
        if (next === 'marketing') url.searchParams.set('view_as', 'marketing');
        else                      url.searchParams.delete('view_as');
        window.location.href = url.toString();
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

      // ── CEO: save current /owner/private view as a snapshot ──────────────
      // Captures the exact rendered rows + totals + filter state as JSON,
      // stored sa owner_private_snapshots. Viewable later sa
      // /owner/private/snapshots/{id}.
      // ── Creative preview modal helpers ──────────────────────────────────
      // Opens the modal at given level/id, fetches creative data, renders.
      // entityName/pageName are optimistically passed so the header shows
      // immediately instead of waiting for fetch to finish.
      async openCreativePreview(level, id, entityName, pageName){
        if (!level || !id) return;
        this.creativeModal.open    = true;
        this.creativeModal.loading = true;
        this.creativeModal.error   = null;
        this.creativeModal.level   = level;
        this.creativeModal.id      = String(id);
        // Optimistic header (overridden by server response)
        this.creativeModal.data    = { entity_name: entityName || '', page_name: pageName || '', creative: {} };
        try {
          const url = '/ads_manager/creative-preview?level=' + encodeURIComponent(level) + '&id=' + encodeURIComponent(id);
          const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
          const j = await r.json();
          if (!r.ok || !j.ok) { this.creativeModal.error = j.message || 'Fetch failed'; return; }
          this.creativeModal.data = j;
        } catch (e) {
          this.creativeModal.error = 'Network error: ' + e.message;
        } finally {
          this.creativeModal.loading = false;
        }
      },

      // First letter of page name for the avatar circle. Falls back to '?'.
      pageInitial(pageName) {
        const s = (pageName || '').trim();
        return s ? s.charAt(0).toUpperCase() : '?';
      },

      // Deterministic color per page name — same page always gets same avatar
      // color. Uses a small HSL palette for variety.
      avatarColor(pageName) {
        const s = (pageName || 'Page').toString();
        let hash = 0;
        for (let i = 0; i < s.length; i++) hash = ((hash << 5) - hash + s.charCodeAt(i)) | 0;
        const hue = Math.abs(hash) % 360;
        return 'hsl(' + hue + ', 55%, 50%)';
      },

      // Convert an ad_link (FB post / reel / video URL) to the FB plugins
      // iframe src. Falls back to the original URL if it doesn't match a
      // known FB pattern (iframe will likely show an error but the "Open
      // on Facebook" button still works).
      fbEmbedSrc(adLink) {
        if (!adLink) return '';
        // Strip query/hash to normalize
        const clean = adLink.split('#')[0].split('?')[0];
        const encoded = encodeURIComponent(adLink);
        // FB plugin width — narrower (380) para magfit sa 3-col modal layout.
        // Reels + videos use the video plugin; posts + photos use post plugin.
        if (/facebook\.com\/(reel|watch|.*\/videos)/i.test(clean)) {
          return 'https://www.facebook.com/plugins/video.php?href=' + encoded + '&show_text=true&width=380';
        }
        return 'https://www.facebook.com/plugins/post.php?href=' + encoded + '&width=380&show_text=true';
      },

      async saveSnapshot(){
        if (this.savingSnapshot) return;
        if (!this.rows || this.rows.length === 0) {
          if (!confirm('Walang rows na visible ngayon — save anyway as an empty snapshot?')) return;
        }
        if (!confirm('Save snapshot for ' + this.startDate + ' → ' + this.endDate + '?\n\nFreezes the current view ng /owner/private. Viewable later sa /owner/private/snapshots.')) return;

        this.savingSnapshot = true; this.saveMsg = '';
        try {
          const payload = {
            // Mirrors yung itemSummary response shape — keeps the snapshot
            // self-contained at viewable independently of live data.
            rows:          this.rows,
            skipped_count: this.skippedCount,
            skipped_pages: this.skippedPages,
            range_days:    this.rangeDays,
            is_single_date: this.isSingleDate,
            // Save the user's current column order/visibility so the snapshot
            // detail view can render columns sa exact same arrangement.
            cols:          this.cols.map(c => ({
              id: c.id, label: c.label, sort: c.sort,
              align: c.align, minw: c.minw,
            })),
            sort_col:      this.sortCol,
            sort_dir:      this.sortDir,
            start_date:    this.startDate,
            end_date:      this.endDate,
            partial_date:  this.partialDate || null,
            view_as:       this.viewAs || 'ceo',
            breakeven_target_pct: window.__BREAKEVEN_PCT__ ?? 5,
            fees:          window.__FEES__ ?? null,
          };
          const r = await fetch('{{ route('owner.private.snapshots.save') }}', {
            method: 'POST',
            headers: {
              'Content-Type':'application/json',
              'Accept':'application/json',
              'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({
              start_date:    this.startDate,
              end_date:      this.endDate,
              partial_date:  this.partialDate || null,
              view_as:       this.viewAs || 'ceo',
              rows_count:    this.rows.length,
              skipped_count: this.skippedCount,
              payload:       payload,
            }),
          });
          const j = await r.json();
          if (!r.ok || !j.ok) { alert(j.message || 'Snapshot save failed'); return; }
          this.saveMsg = '✓ Snapshot #' + j.id + ' saved!';
          setTimeout(() => this.saveMsg = '', 5000);
          // Optional: open the snapshot detail in new tab
          if (confirm('Snapshot #' + j.id + ' saved! Open detail view?')) {
            window.open(j.view_url, '_blank');
          }
        } catch(e) {
          console.error(e);
          alert('Network error: ' + e.message);
        } finally {
          this.savingSnapshot = false;
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
        const self = this;
        const computedFor = (row, col) => {
          if (col === 'proj_pct_computed') {
            if (row.projected_profit !== null && row.gross_sales > 0) {
              return row.projected_profit / row.gross_sales * 100;
            }
            return null;
          }
          // TCPR + Breakeven CPP: derived sa frontend via Alpine helpers. Sort
          // uses the same value the column shows; null rows fall to bottom (per
          // existing null handling sa sort below).
          if (col === 'tcpr_computed')          return self.tcprFor(row);
          if (col === 'breakeven_cpp_computed') return self.breakevenCppFor(row);
          // NP/O variants — ratio per row using matching profit + orders window.
          if (col === 'np_per_order_computed') {
            return (row.projected_profit_last_day !== null && row.orders_last_day > 0)
              ? (row.projected_profit_last_day / row.orders_last_day) : null;
          }
          if (col === 'np_per_order_3d_computed') {
            return (row.projected_profit_last_3d !== null && row.orders_last_3d > 0)
              ? (row.projected_profit_last_3d / row.orders_last_3d) : null;
          }
          if (col === 'np_per_order_7d_computed') {
            return (row.projected_profit_last_7d !== null && row.orders_last_7d > 0)
              ? (row.projected_profit_last_7d / row.orders_last_7d) : null;
          }
          if (col === 'np_per_order_1m_computed') {
            return (row.projected_profit !== null && row.orders > 0)
              ? (row.projected_profit / row.orders) : null;
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

      // ── Action note modal (per page, end_date) — floating + draggable ────
      openActionModal(row){
        const w = 460;
        const cx = Math.max(20, Math.round((window.innerWidth  - w) / 2));
        const cy = Math.max(20, Math.round((window.innerHeight - 360) / 2));
        this.actionModal = {
          open:true, saving:false, error:null, saved:null,
          page_key:   row.page_key,
          page_name:  row.page_name,
          ts_date:    row.action_date || this.endDate,
          comment:    row.action_comment || '',
          by:         row.action_by || null,
          at:         row.action_at || null,
          _row:       row,
          logsOpen:false, logsLoading:false, logs:[],
          x: cx, y: cy, _dragging:false, _dx:0, _dy:0,
        };
      },
      startActionDrag(e){
        const m = this.actionModal;
        m._dragging = true;
        m._dx = e.clientX - m.x;
        m._dy = e.clientY - m.y;
        const move = (ev) => {
          if (!m._dragging) return;
          m.x = Math.max(0, ev.clientX - m._dx);
          m.y = Math.max(0, ev.clientY - m._dy);
        };
        const up = () => {
          m._dragging = false;
          window.removeEventListener('mousemove', move);
          window.removeEventListener('mouseup', up);
        };
        window.addEventListener('mousemove', move);
        window.addEventListener('mouseup', up);
      },
      async saveActionNote(){
        const m = this.actionModal;
        m.saving = true; m.error = null; m.saved = null;
        try {
          const r = await fetch('{{ route('owner.private.action.save') }}', {
            method:'POST',
            headers:{
              'Content-Type':'application/json',
              'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
              'Accept':'application/json',
            },
            body: JSON.stringify({
              page_key: m.page_key,
              ts_date:  m.ts_date,
              comment:  m.comment || '',
            }),
          });
          const j = await r.json();
          if (!r.ok || !j.ok) throw new Error(j.message || ('HTTP '+r.status));
          // Reflect into the row in place (no full reload).
          if (m._row) {
            m._row.action_comment = j.comment || null;
            m._row.action_by      = j.updated_by || null;
            m._row.action_at      = j.updated_at || null;
          }
          m.saved = '✓ Saved';
          setTimeout(() => { this.actionModal.open = false; }, 500);
        } catch(e) {
          m.error = e.message || 'Save failed';
        } finally {
          m.saving = false;
        }
      },
      async loadActionLogs(){
        const m = this.actionModal;
        m.logsOpen = !m.logsOpen;
        if (!m.logsOpen || m.logs.length) return;
        m.logsLoading = true;
        try {
          const qs = new URLSearchParams({ page_key:m.page_key, ts_date:m.ts_date });
          const r = await fetch('{{ route('owner.private.action.logs') }}?'+qs.toString());
          const j = await r.json();
          if (j.ok) m.logs = j.logs || [];
        } catch(e) { /* non-fatal */ }
        finally { m.logsLoading = false; }
      },

      // ── Modal-based edit — 3-section design (RTS / Promo / COGS) ─────────
      // Each section has its own editable effective_date + Save button. User
      // can save multiple sections per modal open without closing.
      // Default effective_date suggestions:
      //   - RTS, Promo  → anchor_first_date (price+item streak start)
      //   - COGS         → cogs_last_date (latest cogs change for this item)
      //
      // focusScope: null (show all 3 sections — legacy "Edit All" entry) or
      //   'rts' / 'promo' / 'cogs' / 'cogs_ceo' (show only that section,
      //   triggered by per-cell ✎ edit icons).
      openEditModal(row, focusScope = null){
        const settingsDate = row.settings_date || null;
        const isInherited = !!(row.has_settings && settingsDate && settingsDate !== this.endDate);
        const anchorStart = row.anchor_first_date || null;
        const cogsLastDate = row.cogs_last_date || null;

        this.edit = {
          open:true,
          focusScope:   focusScope,  // null = show all, 'rts'|'promo'|'cogs'|'cogs_ceo' = show only that section
          // Per-section save state
          savingRts:false, savingPromo:false, savingCogs:false,
          rtsError:null,   promoError:null,   cogsError:null,
          rtsSaved:null,   promoSaved:null,   cogsSaved:null,

          page_name:    row.page_name,
          item_name:    row.item_name,
          date:         this.endDate,
          orders:       row.orders || 0,
          price:        row.price || null,
          anchor_first_date: anchorStart,
          cogs_last_date:    cogsLastDate,

          // RTS section
          rts_pct:             row.rts_pct != null ? row.rts_pct : '',
          rts_eff_date:        settingsDate,
          rts_inherited:       isInherited,
          // Default = current settings date kung may existing row, else cell date.
          // User can change via picker / ↶ anchor / ↶ this date buttons.
          rts_effective_date:  settingsDate || this.endDate,
          comment:             '',

          // Promo section
          promo:                row.promo || '',
          promo_inherited:      isInherited && !!row.promo,
          promo_effective_date: settingsDate || this.endDate,

          // COGS section
          unit_cost:            row.item_value     != null ? row.item_value     : '',
          unit_cost_ceo:       (row.item_value_ceo != null && row.item_value_ceo !== undefined) ? row.item_value_ceo : '',
          cogs_effective_date:  cogsLastDate || this.endDate,

          isCeoView:   this.effectiveIsCeo,
        };
      },

      // Submit one scope (rts/promo/cogs) — clears other sections' state.
      async submitScoped(scope){
        const e = this.edit;
        // Clear all section feedback first
        e.rtsError = e.promoError = e.cogsError = null;
        e.rtsSaved = e.promoSaved = e.cogsSaved = null;

        // ── Per-scope validation + payload building ─────────────────────────
        const fd = new FormData();
        fd.append('scope',     scope);
        fd.append('page_name', e.page_name);
        fd.append('item_name', e.item_name);

        // Cell's price tag — required para sa RTS+Promo (price-scoped).
        // Cogs-only saves skip this (item-global, no price scope).
        const cellPriceInt = (e.price != null && e.price > 0) ? Math.round(Number(e.price)) : null;

        if (scope === 'rts') {
          const rts = parseFloat(e.rts_pct);
          if (isNaN(rts) || rts < 0 || rts > 100) { e.rtsError = 'RTS% must be 0–100.'; return; }
          const cmt = (e.comment || '').trim();
          if (!cmt) { e.rtsError = 'RTS Comment is required.'; return; }
          if (cellPriceInt === null) { e.rtsError = 'No price detected for this cell. Cannot save.'; return; }
          // Promo NOT sent for scope=rts — server preserves existing promo.
          fd.append('rts_pct',        rts);
          fd.append('comment',        cmt);
          fd.append('mode_cod_int',   cellPriceInt);
          fd.append('effective_date', e.rts_effective_date || e.date);
          if ((e.rts_effective_date || e.date) !== e.date) fd.append('apply_through', e.date);
          e.savingRts = true;
        } else if (scope === 'promo') {
          const promo = (e.promo || '').trim();
          if (!promo) { e.promoError = 'Promo required — type "NONE" if walang promo.'; return; }
          if (cellPriceInt === null) { e.promoError = 'No price detected for this cell. Cannot save.'; return; }
          fd.append('promo',          promo);
          fd.append('mode_cod_int',   cellPriceInt);
          fd.append('effective_date', e.promo_effective_date || e.date);
          if ((e.promo_effective_date || e.date) !== e.date) fd.append('apply_through', e.date);
          e.savingPromo = true;
        } else if (scope === 'cogs') {
          const cost = parseFloat(e.unit_cost);
          if (isNaN(cost) || cost < 0) { e.cogsError = 'Unit cost must be ≥ 0.'; return; }
          fd.append('item_value',     cost);
          if (e.isCeoView && e.unit_cost_ceo !== '' && e.unit_cost_ceo !== null) {
            const costCeo = parseFloat(e.unit_cost_ceo);
            if (!isNaN(costCeo) && costCeo >= 0) fd.append('item_value_ceo', costCeo);
          }
          fd.append('effective_date', e.cogs_effective_date || e.date);
          if ((e.cogs_effective_date || e.date) !== e.date) fd.append('apply_through', e.date);
          e.savingCogs = true;
        } else { return; }

        try {
          const r = await fetch('{{ route('owner.private.item-setting.save') }}', {
            method:'POST',
            headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}', 'Accept':'application/json'},
            body: fd,
          });
          let j;
          try { j = await r.json(); }
          catch { this._setScopeError(scope, 'Server returned non-JSON (HTTP '+r.status+')'); return; }
          if (!r.ok || !j.ok) {
            this._setScopeError(scope, j.message || (j.errors ? Object.values(j.errors).flat().join('\n') : ('HTTP '+r.status)));
            return;
          }
          this._setScopeSuccess(scope, '✓ Saved (effective ' + fd.get('effective_date') + ')');
          // Auto-clear success message after 4s
          setTimeout(() => { this._setScopeSuccess(scope, null); }, 4000);
        } catch (ex) {
          console.error(ex);
          this._setScopeError(scope, 'Network error: ' + ex.message);
        } finally {
          e.savingRts = e.savingPromo = e.savingCogs = false;
        }
      },

      _setScopeError(scope, msg){
        const e = this.edit;
        if (scope === 'rts')   e.rtsError = msg;
        if (scope === 'promo') e.promoError = msg;
        if (scope === 'cogs')  e.cogsError = msg;
      },
      _setScopeSuccess(scope, msg){
        const e = this.edit;
        if (scope === 'rts')   e.rtsSaved = msg;
        if (scope === 'promo') e.promoSaved = msg;
        if (scope === 'cogs')  e.cogsSaved = msg;
      },

      // Closes modal + refreshes table data to reflect any saves done.
      async closeEditModal(){
        this.edit.open = false;
        await this.load(true);  // force cache refresh since user may have saved
      },

      async save(){
        if (!this.isSingleDate) { alert('Switch to single-date mode (From = To) to edit.'); return; }
        const itemVal = parseFloat(this.ev.item_value);
        const rts     = parseFloat(this.ev.rts_pct);
        if(isNaN(itemVal)||itemVal<0)   { alert('Item Value needed (≥ 0). Set both to 0 to delete this date\'s override.'); return; }
        if(isNaN(rts)||rts<0||rts>100) { alert('RTS% needed (0–100). Set both to 0 to delete this date\'s override.'); return; }

        const row = this.editRow;
        // Inline save lacks a promo input — server requires it. Prompt for it,
        // pre-filling with current row promo (or NONE) so user can Enter-through.
        const promoDefault = (row && row.promo) ? row.promo : 'NONE';
        const promo = (prompt('Promo (required — type NONE if walang promo):', promoDefault) || '').trim();
        if (!promo) { alert('Promo is required.'); return; }

        // Comment is required by server too — fallback to short reason if blank.
        const cmt = ((this.ev.comment || '').trim()) || 'inline edit';

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
              comment:              cmt,
              item_value_comment:   this.ev.iv_comment || null,
              promo:                promo,
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
        const t = { adspent:0, orders:0, orders_last_day:0, orders_last_3d:0, orders_last_7d:0, proceed_orders:0, gross_sales:0, projected_profit:null, cpp:null, proceed_cpp:null, proj_profit_per_order:null, proj_pct:null,
                    projected_profit_last_day:null, gross_sales_last_day:0, proj_pct_1d:null,
                    projected_profit_last_3d:null, gross_sales_last_3d:0, proj_pct_3d:null,
                    projected_profit_last_7d:null, gross_sales_last_7d:0, proj_pct_7d:null };
        let hasP=false, hasG=false, hasP1=false, hasP3=false, hasP7=false;
        for (const r of this.filteredRows()) {
          t.adspent         += Number(r.adspent         ||0);
          t.orders          += Number(r.orders          ||0);
          t.orders_last_day += Number(r.orders_last_day ||0);
          t.orders_last_3d  += Number(r.orders_last_3d  ||0);
          t.orders_last_7d  += Number(r.orders_last_7d  ||0);
          t.proceed_orders  += Number(r.proceed_orders  ||0);
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
        // NP/O total = projected net profit ÷ orders across all rows.
        // Sum-of-ratios would skew small-orders rows; ratio-of-sums is correct.
        t.np_per_order        = (t.projected_profit_last_day != null && t.orders_last_day > 0)
                                    ? (t.projected_profit_last_day / t.orders_last_day) : null;
        t.np_per_order_3d     = (t.projected_profit_last_3d != null && t.orders_last_3d > 0)
                                    ? (t.projected_profit_last_3d / t.orders_last_3d) : null;
        t.np_per_order_7d     = (t.projected_profit_last_7d != null && t.orders_last_7d > 0)
                                    ? (t.projected_profit_last_7d / t.orders_last_7d) : null;
        t.np_per_order_1m     = (t.projected_profit != null && t.orders > 0)
                                    ? (t.projected_profit / t.orders) : null;
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
          case 'hold':          return row.hold_units ?? null;
          case 'action':        return row.action_comment ?? null;
          case 'jnt_rts':       return row.jnt_rts_pct;
          case 'jnt_del':       return row.jnt_del_pct;
          case 'jnt_transit':   return row.jnt_transit_pct;
          case 'cpp':           return row.cpp;
          case 'pcpp':          return row.proceed_cpp;
          case 'per_order':     return row.proj_profit_per_order;
          case 'np_per_order':    return (row.projected_profit_last_day != null && row.orders_last_day > 0)
                                     ? (row.projected_profit_last_day / row.orders_last_day) : null;
          case 'np_per_order_3d': return (row.projected_profit_last_3d != null && row.orders_last_3d > 0)
                                     ? (row.projected_profit_last_3d / row.orders_last_3d) : null;
          case 'np_per_order_7d': return (row.projected_profit_last_7d != null && row.orders_last_7d > 0)
                                     ? (row.projected_profit_last_7d / row.orders_last_7d) : null;
          case 'np_per_order_1m': return (row.projected_profit != null && row.orders > 0)
                                     ? (row.projected_profit / row.orders) : null;
          case 'adspent':       return row.adspent;
          case 'orders':        return row.orders;
          case 'orders_1d':     return row.orders_last_day;
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
          // Determine what to evaluate: compare_col (sibling) or self
          let evalRaw = value;
          if (r.compare_col && sameRow && Object.prototype.hasOwnProperty.call(sameRow, r.compare_col)) {
            evalRaw = sameRow[r.compare_col];
          }

          // NULL-CHECK OPS — fire FIRST, before isNaN guard. Strict null scope:
          // null, undefined, or empty string count as "empty".
          let hit = false;
          if (r.op === 'is_null' || r.op === 'is_not_null') {
            const isEmpty = (evalRaw === null || evalRaw === undefined || evalRaw === '');
            hit = (r.op === 'is_null') ? isEmpty : !isEmpty;
          } else {
            const t = this.resolveRuleThreshold(r.value, refRow, sameRow);
            if (isNaN(t)) continue;   // ref/formula couldn't resolve → skip rule
            if (evalRaw == null || isNaN(Number(evalRaw))) continue;
            const v = Number(evalRaw);
            switch (r.op) {
              case '>=': hit = v >= t; break;
              case '>':  hit = v >  t; break;
              case '=':  hit = v == t; break;
              case '<=': hit = v <= t; break;
              case '<':  hit = v <  t; break;
            }
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
          { id:'active_subcount',label:'Inside',           sort:null,             type:'integer',      align:'right', minw:70  },
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
          { id:'reach',          label:'Reach',            sort:'reach',          type:'integer',      align:'right', minw:90  },
          { id:'frequency',      label:'Freq',             sort:'frequency',      type:'number',       align:'right', minw:70  },
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
      // otherwise → expand all visible rows.
      //
      // FAST PATH: single batched HTTP call fetching ALL pages' campaigns at
      // once via /ads_manager/campaigns/batch-data. Brings expand-all from
      // ~minutes (40+ parallel calls) down to ~seconds (1 query).
      //
      // FALLBACK: if batch fails for any reason (network, server error, schema
      // mismatch, etc.), automatically falls back to old per-page parallel
      // method (togglePageExpand). Walang feature loss.
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
          return;
        }
        // Try batched fast path first
        try {
          await this._toggleAllExpandBatch(visible);
        } catch (e) {
          console.warn('[expand-all] Batch path failed, falling back to per-page:', e);
          await this._toggleAllExpandPerPage(visible);
        }
      },

      // FAST PATH — single POST to /ads_manager/campaigns/batch-data with all
      // pages' (name, start_date). Server returns map keyed by page_name.
      async _toggleAllExpandBatch(visible){
        const pages = [];
        // Mark all loading first so spinners show
        for (const r of visible) {
          const st = this.expandedPages[r.page_name];
          if (!st || !st.open) {
            this.expandedPages[r.page_name] = { open: true, loading: true, error: null, campaigns: null };
            const scope = this._pageScopeFor(r.page_name);
            pages.push({ name: r.page_name, start_date: scope.start_date });
          }
        }
        if (pages.length === 0) return;

        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const res = await fetch('{{ route('ads_manager.campaigns.batch-data') }}', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept':       'application/json',
            'X-CSRF-TOKEN': csrf,
          },
          body: JSON.stringify({
            end_date: this.endDate,
            pages,
            only_with_spend: true,
            include_windows: true,
            limit_per_page: 1000,
          }),
        });
        if (!res.ok) throw new Error('Batch HTTP ' + res.status);
        const j = await res.json();
        if (!j || !j.ok || !j.by_page) throw new Error('Invalid batch response');

        // Distribute results back into expandedPages map.
        for (const p of pages) {
          const bucket = j.by_page[p.name] || { rows: [], totals: {} };
          const campaigns = Array.isArray(bucket.rows) ? bucket.rows : [];
          // Enrich profit_pct client-side using parent row's data — same logic
          // as the per-page _fetchCampaignsData() does.
          for (const r of campaigns) {
            r.profit_pct       = this._campaignProfitPctFromCpp(r, r.cpp);
            r.profit_pct_7d    = this._campaignProfitPctFromCpp(r, r.cpp_7d);
            r.profit_pct_3d    = this._campaignProfitPctFromCpp(r, r.cpp_3d);
            r.profit_pct_today = this._campaignProfitPctFromCpp(r, r.cpp_today);
          }
          this._markActiveOffDivider(campaigns);
          this.expandedPages[p.name] = {
            open: true, loading: false, error: null, campaigns
          };
        }
      },

      // FALLBACK PATH — original behavior: N parallel HTTP calls via togglePageExpand.
      async _toggleAllExpandPerPage(visible){
        const tasks = [];
        for (const r of visible) {
          const st = this.expandedPages[r.page_name];
          if (!st || !st.open || st.error) {
            tasks.push(this.togglePageExpand(r.page_name));
          }
        }
        await Promise.allSettled(tasks);
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
