<!DOCTYPE html>
<html lang="en" x-data="matrixUI()" x-init="init()" x-cloak>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Breakdown Matrix · {{ $startDate }} → {{ $endDate }}</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
  <style>
    [x-cloak]{display:none!important}
    body{background:#f8fafc;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;}
    /* border-separate so sticky cells keep their borders */
    table.matrix{border-collapse:separate;border-spacing:0;}
    .cell{font-size:10px;line-height:1.2;padding:4px 6px;border:1px solid #e2e8f0;
          white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px;
          position:relative; cursor:pointer; color:#1e293b;}
    .cell:hover{outline:2px solid #2563eb; outline-offset:-2px; z-index:2;}
    .cell-anchor{font-weight:600;}
    .cell-empty{background:#f1f5f9 !important;color:#cbd5e1;text-align:center;cursor:default;}
    .cell-empty:hover{outline:none;}
    .cell-end{border-right:3px solid #2563eb;}
    .cell-item-change{border-left:4px solid #dc2626 !important; padding-left:4px;}
    .cell-price-change{box-shadow: inset 3px 0 0 #a855f7;}
    /* "1 ITEM ONLY" filter: cells that do NOT match anchor item + anchor COD
       get neutralized — same visual as empty. Children hidden via x-show. */
    .cell-filtered{background:#f1f5f9 !important; color:#cbd5e1 !important;
                   border:1px solid #e2e8f0 !important; box-shadow:none !important;
                   border-left:1px solid #e2e8f0 !important; cursor:default !important;}
    .badge-new{position:absolute;top:1px;right:2px;background:#dc2626;color:#fff;
               font-size:8px;font-weight:700;padding:0 3px;border-radius:3px;letter-spacing:0.3px;}
    .badge-price{display:inline-block;font-size:9px;font-weight:700;padding:0 3px;border-radius:3px;
                 margin-left:2px;}
    .badge-price-up{background:#10b981;color:#fff;}
    .badge-price-down{background:#ef4444;color:#fff;}
    .badge-rts{display:inline-block;font-size:9px;font-weight:700;padding:1px 4px;border-radius:3px;
               background:#e0e7ff;color:#3730a3;margin-top:1px;}
    .badge-rts.inherited{background:#f1f5f9;color:#64748b;font-weight:500;}
    .badge-rts.missing{background:#fee2e2;color:#991b1b;}
    .badge-cogs{display:inline-block;font-size:9px;font-weight:600;padding:0 3px;border-radius:3px;
                background:#f0fdf4;color:#166534;margin-top:1px;margin-left:2px;}
    .badge-cogs.missing{background:#fef2f2;color:#991b1b;}
    .badge-count{display:inline-block;font-size:9px;font-weight:600;padding:0 3px;border-radius:3px;
                 background:#fef9c3;color:#713f12;margin-top:1px;margin-left:2px;font-family:monospace;}
    th.date-col{font-size:10px;padding:4px 6px;background:#0f172a;color:#fff;
                border:1px solid #1e293b;white-space:nowrap;font-family:monospace;}
    th.page-col,td.page-col{position:sticky;left:0;z-index:5;background:#fff;text-align:left;
                padding:6px 10px;border:1px solid #e2e8f0;font-size:12px;max-width:200px;
                white-space:normal;line-height:1.3;font-weight:600;
                box-shadow:2px 0 0 #e2e8f0;}
    /* thead cells must outrank tbody sticky-col so the header row always sits on top. */
    thead th{position:sticky;top:0;z-index:10;}
    thead th.page-col{z-index:11;background:#0f172a;color:#fff;}
    /* Top scrollbar that mirrors the main one */
    .top-scroll{overflow-x:auto;overflow-y:hidden;}
    .top-scroll > div{height:1px;}
    /* Bounded height so the container (not the page) scrolls vertically —
       required for position:sticky on thead to actually stick. */
    .main-scroll{overflow:auto; max-height:calc(100vh - 180px);}
    .toggle-bar{display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center;}
    .toggle-bar label{display:inline-flex;align-items:center;gap:0.35rem;cursor:pointer;
                      padding:0.25rem 0.6rem;border:1px solid #cbd5e1;border-radius:6px;
                      background:#fff;font-size:11px;font-weight:600;color:#334155;user-select:none;}
    .toggle-bar label.active{background:#2563eb;border-color:#2563eb;color:#fff;}
    .toggle-bar label input{accent-color:#fff;}
    /* Modal */
    .modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,0.5);z-index:50;
                    display:flex;align-items:center;justify-content:center;padding:1rem;}
    .modal-card{background:#fff;border-radius:8px;box-shadow:0 10px 25px rgba(0,0,0,0.2);
                max-width:480px;width:100%;max-height:90vh;overflow:auto;}
  </style>
</head>
<body class="min-h-screen">

  <!-- Header -->
  <div class="bg-slate-900 text-slate-100 px-4 py-3 flex items-center gap-3 shadow flex-wrap">
    <a href="{{ route('owner.private') }}?start_date={{ $startDate }}&end_date={{ $endDate }}"
       class="text-slate-400 hover:text-white text-sm">← Back to Daily Summary</a>
    <span class="text-sm font-bold ml-3">Breakdown Matrix</span>

    <form method="GET" action="{{ route('owner.private.breakdown') }}" id="matrixFilterForm"
          class="flex items-center gap-2 ml-4 flex-wrap">
      <label class="text-xs text-slate-400 font-semibold">From</label>
      <input type="date" name="start_date" value="{{ $startDate }}"
             class="bg-slate-800 border border-slate-600 rounded px-2 py-1 text-sm">
      <label class="text-xs text-slate-400 font-semibold">To</label>
      <input type="date" name="end_date" value="{{ $endDate }}"
             class="bg-slate-800 border border-slate-600 rounded px-2 py-1 text-sm">

      {{-- Inline item search + checkbox list (same pattern as /owner/private) --}}
      <div style="position:relative;" @click.away="itemFilterOpen=false"
           @keydown.escape.window="itemFilterOpen=false">
        <div style="position:relative;display:flex;align-items:center;">
          <span style="position:absolute;left:10px;font-size:12px;color:#94a3b8;pointer-events:none;">🔎</span>
          <input type="text" x-model="itemFilterSearch"
                 @focus="itemFilterOpen=true"
                 @click="itemFilterOpen=true"
                 placeholder="Search item…"
                 style="background:#0f172a;color:#e2e8f0;border:1px solid #475569;
                        border-radius:6px;padding:5px 28px 5px 28px;font-size:12px;
                        outline:none;width:220px;">
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
                    width:320px;max-height:360px;display:flex;flex-direction:column;
                    box-shadow:0 20px 40px -8px rgba(15,23,42,.35), 0 4px 12px rgba(15,23,42,.12);
                    overflow:hidden;">
          <div style="padding:8px 10px;border-bottom:1px solid #f1f5f9;
                      display:flex;justify-content:space-between;align-items:center;
                      background:#f8fafc;">
            <span style="font-size:10px;font-weight:700;color:#64748b;letter-spacing:.5px;text-transform:uppercase;">
              Filter by item (page with ≥ 1 matching date)
            </span>
            <span style="font-size:10px;color:#94a3b8;">
              <span x-text="selectedItems.length" style="color:#2563eb;font-weight:700;"></span>
              <span> / </span>
              <span x-text="allItems.length"></span>
            </span>
          </div>
          <div style="overflow-y:auto;flex:1;padding:4px;">
            <template x-if="visibleItems().length === 0">
              <div style="text-align:center;color:#94a3b8;font-size:11px;padding:20px;">No items match.</div>
            </template>
            <template x-for="name in visibleItems()" :key="name">
              <label style="display:flex;align-items:center;gap:10px;padding:6px 10px;cursor:pointer;
                            border-radius:6px;font-size:12px;color:#0f172a;line-height:1.3;"
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
          <div style="border-top:1px solid #f1f5f9;padding:6px 10px;background:#f8fafc;
                      display:flex;justify-content:space-between;align-items:center;gap:6px;">
            <button type="button" @click="clearItems()"
                    x-show="selectedItems.length"
                    style="background:none;border:none;color:#ef4444;cursor:pointer;
                           font-size:11px;font-weight:700;padding:2px 6px;border-radius:4px;">Clear all</button>
            <div style="flex:1"></div>
            <button type="button" @click="applyFilter()"
                    style="background:#2563eb;color:#fff;border:none;border-radius:6px;
                           padding:5px 12px;font-size:11px;font-weight:700;cursor:pointer;">Apply</button>
          </div>
        </div>
      </div>

      {{-- Hidden item inputs (re-rendered by Alpine as selection changes) --}}
      <template x-for="name in selectedItems" :key="'hi-'+name">
        <input type="hidden" name="items[]" :value="name">
      </template>

      <button type="submit"
              class="bg-blue-600 hover:bg-blue-500 text-white rounded px-3 py-1 text-xs font-bold">Go</button>
      <a href="{{ route('owner.private.breakdown') }}"
         class="text-xs text-slate-400 hover:text-white ml-1"
         title="Reset to this month">↺ this month</a>
    </form>

    <div class="flex-1"></div>
    <span class="text-xs text-slate-400">{{ $startDate }} → {{ $endDate }} ({{ count($dates) }} days · {{ count($pages) }} pages)</span>
  </div>

  {{-- Selected item pills --}}
  <div x-show="selectedItems.length" x-cloak
       style="background:#f1f5f9;border-bottom:1px solid #e2e8f0;padding:6px 12px;
              display:flex;flex-wrap:wrap;gap:4px;align-items:center;">
    <span style="font-size:11px;color:#475569;font-weight:700;margin-right:4px;">Filter:</span>
    <template x-for="name in selectedItems" :key="'pill-'+name">
      <span style="display:inline-flex;align-items:center;gap:4px;background:#dbeafe;
                   color:#1e3a8a;border:1px solid #93c5fd;border-radius:12px;
                   padding:2px 8px;font-size:11px;font-weight:600;">
        <span x-text="name"></span>
        <button type="button" @click="toggleItem(name); applyFilter()"
                style="background:none;border:none;color:#1e3a8a;cursor:pointer;
                       font-size:14px;line-height:1;padding:0;font-weight:700;"
                title="Remove">×</button>
      </span>
    </template>
    <button type="button" @click="clearItems(); applyFilter()"
            style="background:none;border:none;color:#ef4444;cursor:pointer;
                   font-size:11px;font-weight:700;margin-left:4px;">Clear all</button>
  </div>

  <!-- Field Toggles (persisted per route via localStorage) -->
  <div class="max-w-7xl mx-auto px-4 pt-4 pb-2">
    <div class="toggle-bar">
      <span class="text-xs text-slate-500 font-bold uppercase tracking-wide">Show:</span>
      <label :class="vis.item_name && 'active'">
        <input type="checkbox" x-model="vis.item_name" @change="persist()"> ITEM NAME
      </label>
      <label :class="vis.show_alias && 'active'" class="!border-violet-400"
             title="Display the canonical alias-family name instead of the per-day variant. No effect for items without an alias mapping.">
        <input type="checkbox" x-model="vis.show_alias" @change="persist()"> SHOW ALIAS
      </label>
      <label :class="vis.count && 'active'">
        <input type="checkbox" x-model="vis.count" @change="persist()"> COUNT
      </label>
      <label :class="vis.cod && 'active'">
        <input type="checkbox" x-model="vis.cod" @change="persist()"> COD
      </label>
      <label :class="vis.rts && 'active'">
        <input type="checkbox" x-model="vis.rts" @change="persist()"> RTS
      </label>
      <label :class="vis.cogs && 'active'">
        <input type="checkbox" x-model="vis.cogs" @change="persist()"> COGS
      </label>
      <label :class="vis.promo && 'active'" class="!border-fuchsia-400"
             title="Per-date promo tag (inherits forward like RTS). Required on save.">
        <input type="checkbox" x-model="vis.promo" @change="persist()"> PROMO
      </label>
      <label :class="vis.proj_pct && 'active'"
             title="Projected profit% per cell (revenue − shipping − cogs − ads − cod fee) / (orders × mode COD)">
        <input type="checkbox" x-model="vis.proj_pct" @change="persist()"> PROJ%
      </label>
      <label :class="vis.one_item_only && 'active'" class="!border-amber-400"
             title="Show only cells that match the anchor item AND same COD/SRP as the end-date (reference).">
        <input type="checkbox" x-model="vis.one_item_only" @change="persist()"> 1 ITEM ONLY
      </label>
      <label :class="vis.only_missing && 'active'" class="!border-red-400"
             title="Hide pages whose cells all have both RTS% and unit cost set.">
        <input type="checkbox" x-model="vis.only_missing" @change="persist()"> ONLY MISSING
      </label>
      <span class="ml-4 text-[11px] text-slate-500">Click any cell to edit RTS / unit cost. Settings saved per-browser.</span>

      <button type="button" @click="recomputeRange()" :disabled="recomputing"
              class="ml-3 px-3 py-1 rounded border text-[11px] font-semibold disabled:opacity-50"
              :class="recomputing ? 'bg-slate-200 border-slate-300 text-slate-500'
                                  : 'bg-amber-500 border-amber-600 text-white hover:bg-amber-600'"
              :title="'Recompute daily_page_primary_item for ' + '{{ $startDate }}' + ' → ' + '{{ $endDate }}'">
        <span x-show="!recomputing">↻ Recompute visible range</span>
        <span x-show="recomputing">Recomputing…</span>
      </button>
      <span class="text-[11px] text-emerald-700 font-semibold" x-show="recomputeMsg" x-text="recomputeMsg"></span>
    </div>
  </div>

  <!-- Matrix: top scrollbar mirrors the main (bottom) scrollbar -->
  <div class="px-4 pb-8">
    @if(count($pages) === 0)
      <div class="bg-white rounded shadow p-8 text-center text-slate-400 text-sm">No data in this range.</div>
    @else
      <div class="top-scroll" x-ref="topScroll" @scroll="syncFromTop()">
        <div x-ref="topSpacer"></div>
      </div>
      <div class="main-scroll bg-white rounded shadow" x-ref="mainScroll" @scroll="syncFromMain()">
        <table class="matrix">

          <thead>
            <tr>
              <th class="page-col" style="min-width:180px;">Page</th>
              @foreach($dates as $d)
                <th class="date-col {{ $d === $endDate ? 'cell-end' : '' }}">
                  {{ \Carbon\Carbon::parse($d)->format('M d') }}
                </th>
              @endforeach
            </tr>
          </thead>
          <tbody>
            @foreach($pages as $p)
              <tr x-show="!vis.only_missing || {{ !empty($p['has_missing']) ? 'true' : 'false' }}">
                <th class="page-col">
                  <a href="{{ route('owner.private.breakdown') }}?page_key={{ urlencode($p['page_key']) }}&start_date={{ $startDate }}&end_date={{ $endDate }}"
                     class="text-slate-900 hover:text-blue-600"
                     target="_blank">{{ $p['page_label'] }}</a>
                </th>
                @foreach($dates as $d)
                  @php
                    $cell = $p['cells'][$d] ?? null;
                    $isAnchorCell = $cell && $p['anchor_item_key'] && $cell['item_key'] === $p['anchor_item_key'];
                    $class = 'cell';
                    if ($d === $endDate) $class .= ' cell-end';
                    if (!$cell) $class .= ' cell-empty';
                    elseif ($isAnchorCell) $class .= ' cell-anchor';
                    if ($cell && !empty($cell['item_changed']))  $class .= ' cell-item-change';
                    if ($cell && !empty($cell['price_changed'])) $class .= ' cell-price-change';
                    // Missing-data flag: cells without an RTS% or unit_cost need a red
                    // warning bg (#ff0000) so they can be spotted at a glance. Overrides
                    // the per-item pastel.
                    $missingValue = $cell && ($cell['unit_cost'] === null || $cell['rts_pct'] === null);
                    // Per-item pastel bg so same-item cells in the row visually cluster.
                    // Hue from crc32 of normalized item key → deterministic across reloads.
                    $cellStyle = '';
                    if ($cell) {
                        if ($missingValue) {
                            $cellStyle = 'background:#ff0000;color:#fff;';
                        } else {
                            $hue = crc32((string)$cell['item_key']) % 360;
                            if ($hue < 0) $hue += 360;
                            // Anchor slightly darker for emphasis; others softer.
                            $light = $isAnchorCell ? 78 : 90;
                            $cellStyle = "background:hsl({$hue},72%,{$light}%);";
                        }
                    }
                    $tip = 'no data / tied';
                    if ($cell) {
                        $tip = $cell['item_name'].' · '.$cell['orders'].' orders'
                             . ' · '.$cell['count_same'].'/'.$cell['count_total'].' days'
                             . ($cell['mode_cod'] ? ' · ₱'.number_format($cell['mode_cod'],2) : '')
                             . ' · RTS '.($cell['rts_pct'] !== null ? $cell['rts_pct'].'%' : '—')
                             . ' · COGS '.($cell['unit_cost'] !== null ? '₱'.number_format($cell['unit_cost'],2) : '—');
                        if (!empty($cell['item_changed']))  $tip .= ' · NEW ITEM vs previous day';
                        if (!empty($cell['price_changed'])) $tip .= ' · price Δ '.($cell['price_delta']>=0?'+':'').round($cell['price_delta']);
                        if (!empty($cell['rts_inherited'])) $tip .= ' · RTS inherited from '.$cell['rts_eff_date'];
                    }
                    // Anchor match: same item_key AND same mode_cod as the end-date reference.
                    // "1 ITEM ONLY" filter uses this to reveal when the current mode started
                    // at its current COD/SRP — cells with a different COD get filtered out even
                    // if the item name matches.
                    $matchesAnchor = false;
                    if ($cell && $p['anchor_item_key'] && $cell['item_key'] === $p['anchor_item_key']) {
                        $anchorCod = $p['anchor_mode_cod'] ?? null;
                        $cellCod   = $cell['mode_cod'];
                        if ($anchorCod === null && $cellCod === null) {
                            $matchesAnchor = true;
                        } elseif ($anchorCod !== null && $cellCod !== null) {
                            // Same ±₱1 tolerance as Fix A's resolveAnchorStreakStart —
                            // small rounding diff (₱199.49 vs ₱199.99) treated as same.
                            $matchesAnchor = abs((int)round((float)$anchorCod) - (int)round((float)$cellCod)) <= 1;
                        }
                    }
                    // Earliest date in the current range where THIS page has the SAME item.
                    // Used by the "Apply to earliest" button — setting effective_date there
                    // lets the RTS cascade forward to every matching day (via getFor's
                    // `effective_date <= date` resolution) without N separate writes.
                    $earliestSame = null;
                    if ($cell) {
                        foreach ($p['cells'] as $dk => $c2) {
                            if (($c2['item_key'] ?? null) === $cell['item_key']) {
                                if ($earliestSame === null || $dk < $earliestSame) $earliestSame = $dk;
                            }
                        }
                    }
                    $cellPayload = $cell ? [
                        'page_key'    => $p['page_key'],
                        'page_label'  => $p['page_label'],
                        'date'        => $d,
                        'item_name'   => $cell['item_name'],
                        'orders'      => $cell['orders'],
                        'mode_cod'    => $cell['mode_cod'],
                        'rts_pct'     => $cell['rts_pct'],
                        'rts_eff_date'=> $cell['rts_eff_date'],
                        'rts_inherited' => !empty($cell['rts_inherited']),
                        'unit_cost'   => $cell['unit_cost'],
                        'unit_cost_ceo' => $cell['unit_cost_ceo'] ?? null,
                        'promo'         => $cell['promo'] ?? '',
                        'promo_inherited' => !empty($cell['promo_inherited']),
                        // Per-cell anchor start (price+item-aware, walks back from THIS cell).
                        'anchor_first_date' => $cell['anchor_first_date'] ?? $d,
                        // Last date COGS was set for this item ≤ cell date.
                        'cogs_last_date'    => $cell['cogs_last_date'] ?? null,
                        'earliest_same_date' => $earliestSame,
                    ] : null;
                  @endphp
                  <td class="{{ $class }}"
                      :class="vis.one_item_only && !{{ $matchesAnchor ? 'true' : 'false' }} ? 'cell-filtered' : ''"
                      :style="(vis.one_item_only && !{{ $matchesAnchor ? 'true' : 'false' }}) ? '' : '{{ $cellStyle }}'"
                      title="{{ $tip }}"
                      @if($cell) @click="(vis.one_item_only && !{{ $matchesAnchor ? 'true' : 'false' }}) ? null : openEdit(@js($cellPayload))" @endif>
                    @if($cell)
                      <template x-if="!vis.one_item_only || {{ $matchesAnchor ? 'true' : 'false' }}">
                        <div>
                      @if(!empty($cell['item_changed']) || !empty($cell['price_changed']))
                        @php
                          // Single NEW badge for ANY anchor break (item OR price change).
                          // Tooltip distinguishes which kind so user can hover for context.
                          $newReason = !empty($cell['item_changed'])
                              ? 'NEW item vs previous day'
                              : 'NEW price vs previous day (Δ '
                                  . ($cell['price_delta']>=0?'+':'')
                                  . round($cell['price_delta']) . ')';
                        @endphp
                        <span class="badge-new" title="{{ $newReason }}">NEW</span>
                      @endif
                      <div x-show="vis.item_name && !vis.show_alias" style="font-weight:600;">{{ $cell['item_name'] }}</div>
                      <div x-show="vis.item_name && vis.show_alias" style="font-weight:600;color:#6d28d9;">{{ $cell['item_alias_label'] }}</div>
                      <div x-show="vis.cod" style="font-size:9px;color:#64748b;font-weight:400;">
                        {{ $cell['orders'] }}{{ $cell['mode_cod'] ? ' @ '.number_format($cell['mode_cod'],0) : '' }}
                        @if(!empty($cell['price_changed']) && $cell['price_delta'] !== null)
                          <span class="badge-price {{ $cell['price_delta'] >= 0 ? 'badge-price-up' : 'badge-price-down' }}">{{ $cell['price_delta']>=0 ? '▲ +' : '▼ ' }}{{ round($cell['price_delta']) }}</span>
                        @endif
                      </div>
                      <div>
                        <span x-show="vis.count" class="badge-count"
                              title="orders for this item on this date">{{ $cell['orders'] }}</span>
                        @if($cell['rts_pct'] !== null)
                          <span x-show="vis.rts" class="badge-rts {{ $cell['rts_inherited'] ? 'inherited' : '' }}"
                                title="{{ $cell['rts_inherited'] ? 'inherited from '.$cell['rts_eff_date'] : 'set on this date' }}">{{ rtrim(rtrim(number_format($cell['rts_pct'],2), '0'),'.') }}%</span>
                        @endif
                        @if($cell['unit_cost'] !== null)
                          <span x-show="vis.cogs" class="badge-cogs" title="unit cost (cogs)">₱{{ rtrim(rtrim(number_format($cell['unit_cost'],2), '0'),'.') }}</span>
                        @endif
                        @if(!empty($cell['promo']))
                          @php
                            $promoStr = (string)$cell['promo'];
                            $isNone   = strcasecmp($promoStr, 'NONE') === 0 || $promoStr === '-';
                            $promoStyle = $isNone
                                ? 'background:#f1f5f9;color:#94a3b8;font-weight:500;'
                                : 'background:#fae8ff;color:#86198f;font-weight:700;';
                            if (!empty($cell['promo_inherited'])) $promoStyle .= 'opacity:0.7;';
                            $promoTitle = ($cell['promo_inherited'] ? 'inherited from '.$cell['rts_eff_date'] : 'set on this date');
                            $promoTitle .= ' · '.$promoStr;
                          @endphp
                          <span x-show="vis.promo"
                                style="display:inline-block;padding:1px 4px;border-radius:3px;font-size:9px;margin-top:1px;margin-left:2px;{{ $promoStyle }}"
                                title="{{ $promoTitle }}">{{ $isNone ? '—' : $promoStr }}</span>
                        @endif
                        @if(!empty($isCeoView) && isset($cell['unit_cost_ceo']) && $cell['unit_cost_ceo'] !== null)
                          {{-- CEO-only badge — separate from cogs badge, uses indigo tint to distinguish.
                               Only rendered when isCeoView is true (server-side check) AND cogs_ceo has value. --}}
                          <span x-show="vis.cogs" class="badge-cogs-ceo"
                                title="CEO unit cost (cogs_ceo) — used for profit calc when CEO viewing"
                                style="display:inline-block;padding:1px 4px;border-radius:4px;font-size:9px;font-weight:700;background:#e0e7ff;color:#3730a3;margin-left:2px;">
                            CEO ₱{{ rtrim(rtrim(number_format($cell['unit_cost_ceo'],2), '0'),'.') }}
                          </span>
                        @endif
                        @if($cell['profit_pct'] !== null)
                          @php
                            $pp = (float)$cell['profit_pct'];
                            $ppColor = $pp >= 15 ? '#16a34a' : ($pp >= 5 ? '#ca8a04' : ($pp >= 0 ? '#ea580c' : '#dc2626'));
                          @endphp
                          <span x-show="vis.proj_pct"
                                title="projected profit% · proceed {{ (int)($cell['proceed'] ?? 0) }} · ads ₱{{ number_format((float)($cell['ads'] ?? 0),2) }}"
                                style="display:inline-block;padding:1px 4px;border-radius:4px;font-size:9px;font-weight:700;background:{{ $ppColor }};color:#fff;">{{ $pp >= 0 ? '+' : '' }}{{ rtrim(rtrim(number_format($pp,1),'0'),'.') }}%</span>
                        @elseif($cell)
                          <span x-show="vis.proj_pct"
                                title="missing RTS, COGS, or mode COD — cannot compute"
                                style="display:inline-block;padding:1px 4px;border-radius:4px;font-size:9px;font-weight:700;background:#e5e7eb;color:#6b7280;">—</span>
                        @endif
                      </div>
                        </div>
                      </template>
                      <template x-if="vis.one_item_only && !{{ $matchesAnchor ? 'true' : 'false' }}">
                        <span style="color:#cbd5e1;">—</span>
                      </template>
                    @else
                      —
                    @endif
                  </td>
                @endforeach
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>

  <!-- Edit Modal — 3-section design (RTS / Promo / COGS), each with own editable
       effective_date + scoped Save button. Mirrors /owner/private's Edit Row. -->
  <template x-if="edit.open">
    <div class="modal-backdrop" @click.self="edit.open = false">
      <div class="modal-card" style="max-width:520px;">
        <div class="px-5 py-4 border-b border-slate-200">
          <div class="text-xs text-slate-500 uppercase tracking-wide font-bold">Edit Cell</div>
          <div class="text-lg font-bold text-slate-900 mt-1" x-text="edit.page_label"></div>
          <div class="text-sm text-slate-600 mt-0.5">
            <span x-text="edit.item_name"></span>
            <span class="text-slate-400"> · </span>
            <span class="font-mono" x-text="edit.date"></span>
          </div>
          <div class="text-xs text-slate-500 mt-1">
            <span x-text="edit.orders + ' orders'"></span>
            <template x-if="edit.mode_cod">
              <span x-text="' @ ₱' + Number(edit.mode_cod).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2})"></span>
            </template>
            <template x-if="edit.anchor_first_date">
              <span class="text-violet-700 font-semibold ml-2"
                    x-text="'▸ anchor since ' + edit.anchor_first_date"></span>
            </template>
          </div>
        </div>

        {{-- ━━━ Section 1: RTS% ━━━━━━━━━━━━━━━━━━━━━━━━━━ --}}
        <div class="px-5 py-4 border-t border-slate-200" style="background:#fefce8;">
          <div class="text-[11px] font-bold text-amber-800 uppercase tracking-wide mb-2">📊 RTS%</div>
          <label class="block text-xs font-bold text-slate-700 uppercase mb-1">RTS% <span class="text-slate-400 font-normal normal-case">(per page, item & price reference)</span></label>
          <div class="flex items-center gap-2">
            <input type="number" step="0.01" min="0" max="100"
                   x-model="edit.rts_pct"
                   class="flex-1 border border-slate-300 rounded px-3 py-2 text-sm font-mono"
                   placeholder="e.g. 50">
            <span class="text-slate-500 text-sm">%</span>
          </div>

          <label class="block text-xs font-bold text-slate-700 uppercase mb-1 mt-3">RTS Comment <span class="text-red-600">*</span></label>
          <input type="text" x-model="edit.comment" maxlength="500"
                 class="w-full border border-slate-300 rounded px-3 py-2 text-sm"
                 placeholder="why is this value different?">

          <div class="text-[11px] text-slate-500 mt-2">
            Scope: <strong x-text="edit.page_name"></strong> · <span x-text="edit.item_name"></span>
            <template x-if="edit.anchor_first_date">
              <span class="text-violet-600 font-semibold"> · anchored since <span x-text="edit.anchor_first_date"></span></span>
            </template>
          </div>

          <template x-if="edit.rtsError">
            <div class="bg-red-50 text-red-700 text-xs rounded px-2 py-1 mt-2" x-text="edit.rtsError"></div>
          </template>
          <template x-if="edit.rtsSaved">
            <div class="bg-green-50 text-green-700 text-xs rounded px-2 py-1 mt-2" x-text="edit.rtsSaved"></div>
          </template>
          <div class="text-right mt-2">
            <button type="button" @click="submitScoped('rts')" :disabled="edit.savingRts"
                    class="bg-blue-600 hover:bg-blue-500 text-white rounded px-3 py-1.5 text-xs font-bold disabled:opacity-50">
              <span x-text="edit.savingRts ? 'Saving…' : '💾 Save RTS'"></span>
            </button>
          </div>
        </div>

        {{-- ━━━ Section 2: Promo ━━━━━━━━━━━━━━━━━━━━━━━━━ --}}
        <div class="px-5 py-4 border-t border-slate-200" style="background:#fdf4ff;">
          <div class="text-[11px] font-bold text-fuchsia-800 uppercase tracking-wide mb-2">🏷 Promo</div>
          <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Promo <span class="text-red-600">*</span> <span class="text-slate-400 font-normal normal-case">(type NONE if walang promo)</span></label>
          <input type="text" x-model="edit.promo" maxlength="255"
                 class="w-full border border-slate-300 rounded px-3 py-2 text-sm font-mono"
                 placeholder='e.g. "9.9 Sale", "PAYDAY", or "NONE"'>

          <div class="text-[11px] text-slate-500 mt-2">
            Scope: <strong x-text="edit.page_name"></strong> · <span x-text="edit.item_name"></span>
            <template x-if="edit.anchor_first_date">
              <span class="text-violet-600 font-semibold"> · anchored since <span x-text="edit.anchor_first_date"></span></span>
            </template>
          </div>

          <template x-if="edit.promoError">
            <div class="bg-red-50 text-red-700 text-xs rounded px-2 py-1 mt-2" x-text="edit.promoError"></div>
          </template>
          <template x-if="edit.promoSaved">
            <div class="bg-green-50 text-green-700 text-xs rounded px-2 py-1 mt-2" x-text="edit.promoSaved"></div>
          </template>
          <div class="text-right mt-2">
            <button type="button" @click="submitScoped('promo')" :disabled="edit.savingPromo"
                    class="bg-blue-600 hover:bg-blue-500 text-white rounded px-3 py-1.5 text-xs font-bold disabled:opacity-50">
              <span x-text="edit.savingPromo ? 'Saving…' : '💾 Save Promo'"></span>
            </button>
          </div>
        </div>

        {{-- ━━━ Section 3: COGS (item-global) ━━━━━━━━━━━━━ --}}
        <div class="px-5 py-4 border-t border-slate-200" style="background:#f0fdf4;">
          <div class="text-[11px] font-bold text-emerald-800 uppercase tracking-wide mb-2">💰 COGS <span class="text-slate-400 font-normal">(item-global)</span></div>
          <label class="block text-xs font-bold text-slate-700 uppercase mb-1">Unit Cost (Marketing) <span class="text-slate-400 font-normal normal-case">— affects ALL pages on this date</span></label>
          <div class="flex items-center gap-2">
            <span class="text-slate-500 text-sm">₱</span>
            <input type="number" step="0.01" min="0"
                   x-model="edit.unit_cost"
                   class="flex-1 border border-slate-300 rounded px-3 py-2 text-sm font-mono"
                   placeholder="e.g. 25">
          </div>

          @if(!empty($isCeoView))
            <label class="block text-xs font-bold text-indigo-700 uppercase mb-1 mt-3">🔒 CEO Unit Cost <span class="text-slate-400 font-normal normal-case">— CEO-only</span></label>
            <div class="flex items-center gap-2">
              <span class="text-slate-500 text-sm">₱</span>
              <input type="number" step="0.01" min="0"
                     x-model="edit.unit_cost_ceo"
                     class="flex-1 border border-indigo-300 bg-indigo-50/40 rounded px-3 py-2 text-sm font-mono"
                     placeholder="e.g. 25">
            </div>
          @endif

          <label class="block text-xs font-bold text-slate-700 uppercase mb-1 mt-3">Effective from</label>
          <div class="flex items-center gap-2 flex-wrap">
            <input type="date" x-model="edit.cogs_effective_date"
                   class="border border-slate-300 rounded px-2 py-1 text-sm font-mono">
            <button type="button" class="bg-slate-100 text-slate-600 rounded px-2 py-1 text-[11px] font-bold"
                    @click="edit.cogs_effective_date = edit.date">↶ this date</button>
            <button type="button" class="bg-emerald-100 text-emerald-800 rounded px-2 py-1 text-[11px] font-bold disabled:opacity-50"
                    @click="edit.cogs_effective_date = edit.cogs_last_date || edit.date"
                    :disabled="!edit.cogs_last_date">↶ last cogs change</button>
          </div>
          <div class="text-[11px] text-slate-500 mt-1">
            <template x-if="edit.cogs_last_date">
              <span>Last COGS change for this item: <span class="font-mono" x-text="edit.cogs_last_date"></span></span>
            </template>
            <template x-if="!edit.cogs_last_date">
              <span>No prior COGS set for this item yet.</span>
            </template>
          </div>

          <template x-if="edit.cogsError">
            <div class="bg-red-50 text-red-700 text-xs rounded px-2 py-1 mt-2" x-text="edit.cogsError"></div>
          </template>
          <template x-if="edit.cogsSaved">
            <div class="bg-green-50 text-green-700 text-xs rounded px-2 py-1 mt-2" x-text="edit.cogsSaved"></div>
          </template>
          <div class="text-right mt-2">
            <button type="button" @click="submitScoped('cogs')" :disabled="edit.savingCogs"
                    class="bg-blue-600 hover:bg-blue-500 text-white rounded px-3 py-1.5 text-xs font-bold disabled:opacity-50">
              <span x-text="edit.savingCogs ? 'Saving…' : '💾 Save COGS'"></span>
            </button>
          </div>
        </div>

        <div class="px-5 py-3 bg-slate-50 border-t border-slate-200 flex justify-end">
          <button type="button" @click="closeEditModal()"
                  class="px-4 py-2 text-sm font-semibold text-slate-600 hover:text-slate-900">Close</button>
        </div>
      </div>
    </div>
  </template>

  <script>
  function matrixUI(){
    return {
      // Visibility toggles — persisted per route in localStorage.
      // Key includes pathname so ibang page may sariling preference.
      vis: { item_name:true, count:true, cod:true, rts:true, cogs:true, promo:true, proj_pct:false, one_item_only:false, only_missing:false, show_alias:false },
      storageKey: 'matrix_vis:' + location.pathname,

      // Item-filter state (server-side; triggers form submit via applyFilter())
      allItems:       @json($allItems ?? []),
      selectedItems:  @json($selectedItems ?? []),
      itemFilterOpen: false,
      itemFilterSearch: '',

      edit: {
        open:false,
        savingRts:false, savingPromo:false, savingCogs:false,
        rtsError:null,   promoError:null,   cogsError:null,
        rtsSaved:null,   promoSaved:null,   cogsSaved:null,
        page_key:'', page_label:'', page_name:'', date:'', item_name:'',
        orders:0, mode_cod:null,
        anchor_first_date:null, cogs_last_date:null,
        rts_pct:'', comment:'',
        promo:'',
        unit_cost:'', unit_cost_ceo:'', cogs_effective_date:'',
      },

      isCeoView: @json(!empty($isCeoView)),

      _syncing:false,
      recomputing:false,
      recomputeMsg:'',

      async recomputeRange(){
        if (this.recomputing) return;
        const from = '{{ $startDate }}';
        const to   = '{{ $endDate }}';
        if (!confirm('Recompute daily_page_primary_item for ' + from + ' → ' + to + '?\nThis rebuilds primary-item cells from macro_output for those dates.')) return;
        this.recomputing = true; this.recomputeMsg = '';
        try {
          const r = await fetch('{{ route('owner.private.refresh-primary-items') }}', {
            method:'POST',
            headers:{
              'Content-Type':'application/json',
              'Accept':'application/json',
              'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
            },
            body: JSON.stringify({ start_date: from, end_date: to }),
          });
          const j = await r.json();
          if (!j.ok) { alert(j.message || 'Recompute failed'); return; }
          const s = j.summary || {};
          this.recomputeMsg = `✓ ${s.rows_upserted||0} rows · ${s.ties_skipped||0} ties skipped (${s.elapsed_s||0}s)`;
          await this.refreshMatrix();
          setTimeout(() => this.recomputeMsg = '', 8000);
        } catch(e) {
          console.error(e); alert('Network error');
        } finally {
          this.recomputing = false;
        }
      },

      init(){
        try {
          const raw = localStorage.getItem(this.storageKey);
          if (raw) {
            const saved = JSON.parse(raw);
            Object.keys(this.vis).forEach(k => {
              if (typeof saved[k] === 'boolean') this.vis[k] = saved[k];
            });
          }
        } catch(e) { console.warn('Failed to load toggle prefs', e); }
        // Mirror main scroll width into the top-scrollbar's spacer so both
        // scrollbars have identical travel.
        this.$nextTick(() => this.resizeTopSpacer());
        window.addEventListener('resize', () => this.resizeTopSpacer());
      },
      resizeTopSpacer(){
        const m = this.$refs.mainScroll, s = this.$refs.topSpacer;
        if (!m || !s) return;
        s.style.width = m.scrollWidth + 'px';
      },
      syncFromTop(){
        if (this._syncing) return;
        this._syncing = true;
        this.$refs.mainScroll.scrollLeft = this.$refs.topScroll.scrollLeft;
        this._syncing = false;
      },
      syncFromMain(){
        if (this._syncing) return;
        this._syncing = true;
        this.$refs.topScroll.scrollLeft = this.$refs.mainScroll.scrollLeft;
        this._syncing = false;
      },
      persist(){
        try { localStorage.setItem(this.storageKey, JSON.stringify(this.vis)); }
        catch(e) { console.warn('Failed to save toggle prefs', e); }
      },

      // --- Item filter helpers (dropdown + submit) ---
      visibleItems(){
        const q = (this.itemFilterSearch || '').trim().toLowerCase();
        if (!q) return this.allItems;
        return this.allItems.filter(n => n.toLowerCase().includes(q));
      },
      toggleItem(name){
        const i = this.selectedItems.indexOf(name);
        if (i >= 0) this.selectedItems.splice(i, 1);
        else        this.selectedItems.push(name);
      },
      clearItems(){ this.selectedItems = []; },
      applyFilter(){
        // Submit the form so the server can re-render with the items[] filter.
        this.$nextTick(() => {
          const f = document.getElementById('matrixFilterForm');
          if (f) f.submit();
        });
      },

      openEdit(cell){
        const anchorStart  = cell.anchor_first_date || cell.date;
        const cogsLastDate = cell.cogs_last_date || null;
        this.edit = {
          open:true,
          savingRts:false, savingPromo:false, savingCogs:false,
          rtsError:null,   promoError:null,   cogsError:null,
          rtsSaved:null,   promoSaved:null,   cogsSaved:null,

          page_key:     cell.page_key,
          page_label:   cell.page_label,
          page_name:    cell.page_label, // alias for shared modal copy
          date:         cell.date,
          item_name:    cell.item_name,
          orders:       cell.orders,
          mode_cod:     cell.mode_cod,
          anchor_first_date: anchorStart,
          cogs_last_date:    cogsLastDate,

          // RTS section — eff_date auto-anchored sa backend (no user picker)
          rts_pct:             cell.rts_pct !== null ? cell.rts_pct : '',
          comment:             '',

          // Promo section — eff_date auto-anchored sa backend (no user picker)
          promo:                cell.promo || '',

          // COGS section
          unit_cost:            cell.unit_cost !== null ? cell.unit_cost : '',
          unit_cost_ceo:       (cell.unit_cost_ceo !== undefined && cell.unit_cost_ceo !== null) ? cell.unit_cost_ceo : '',
          cogs_effective_date:  cogsLastDate || cell.date,
        };
      },

      // Submit one scope (rts/promo/cogs) — same pattern as /owner/private modal.
      async submitScoped(scope){
        const e = this.edit;
        e.rtsError = e.promoError = e.cogsError = null;
        e.rtsSaved = e.promoSaved = e.cogsSaved = null;

        const fd = new FormData();
        fd.append('scope',     scope);
        fd.append('page_name', e.page_label);
        fd.append('item_name', e.item_name);

        // Cell's price tag — required para sa RTS+Promo (price-scoped).
        // Matrix cells expose price via mode_cod (set sa openEdit from cell payload).
        const cellPriceInt = (e.mode_cod != null && e.mode_cod > 0) ? Math.round(Number(e.mode_cod)) : null;

        // Auto-anchor: effective_date = anchor_first_date (start ng price streak).
        // apply_through = cell.date so cascade updates lahat ng intermediate
        // rows sa scope; user's value reflects sa current view kahit may legacy rows.
        const anchorEff = e.anchor_first_date || e.date;

        if (scope === 'rts') {
          const rts = parseFloat(e.rts_pct);
          if (isNaN(rts) || rts < 0 || rts > 100) { e.rtsError = 'RTS% must be 0–100.'; return; }
          const cmt = (e.comment || '').trim();
          if (!cmt) { e.rtsError = 'RTS Comment is required.'; return; }
          if (cellPriceInt === null) { e.rtsError = 'No price detected for this cell. Cannot save.'; return; }
          fd.append('rts_pct',        rts);
          fd.append('comment',        cmt);
          fd.append('mode_cod_int',   cellPriceInt);
          fd.append('effective_date', anchorEff);
          if (anchorEff !== e.date) fd.append('apply_through', e.date);
          e.savingRts = true;
        } else if (scope === 'promo') {
          const promo = (e.promo || '').trim();
          if (!promo) { e.promoError = 'Promo required — type "NONE" if walang promo.'; return; }
          if (cellPriceInt === null) { e.promoError = 'No price detected for this cell. Cannot save.'; return; }
          fd.append('promo',          promo);
          fd.append('mode_cod_int',   cellPriceInt);
          fd.append('effective_date', anchorEff);
          if (anchorEff !== e.date) fd.append('apply_through', e.date);
          e.savingPromo = true;
        } else if (scope === 'cogs') {
          const cost = parseFloat(e.unit_cost);
          if (isNaN(cost) || cost < 0) { e.cogsError = 'Unit cost must be ≥ 0.'; return; }
          fd.append('item_value', cost);
          if (this.isCeoView && e.unit_cost_ceo !== '' && e.unit_cost_ceo !== null) {
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
            headers:{'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept':'application/json'},
            body: fd,
          });
          let j;
          try { j = await r.json(); }
          catch { this._setScopeError(scope, 'Server returned non-JSON (HTTP '+r.status+')'); return; }
          if (!r.ok || !j.ok) {
            this._setScopeError(scope, j.message || (j.errors ? Object.values(j.errors).flat().join('\n') : ('HTTP '+r.status)));
            return;
          }
          this._setScopeSuccess(scope, '✓ Saved (anchored: ' + fd.get('effective_date') + ')');
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

      async closeEditModal(){
        this.edit.open = false;
        await this.refreshMatrix();
      },
      async refreshMatrix(){
        try {
          const resp = await fetch(window.location.href, { headers: {'X-Requested-With':'XMLHttpRequest'} });
          const html = await resp.text();
          const doc  = new DOMParser().parseFromString(html, 'text/html');
          const fresh = doc.querySelector('.main-scroll');
          const here  = this.$refs.mainScroll;
          if (fresh && here) {
            here.innerHTML = fresh.innerHTML;
            if (window.Alpine && typeof window.Alpine.initTree === 'function') {
              window.Alpine.initTree(here);
            }
            this.$nextTick(() => this.resizeTopSpacer());
          }
        } catch(e) { console.warn('Matrix refresh failed', e); }
      },
    };
  }
  </script>

</body>
</html>
