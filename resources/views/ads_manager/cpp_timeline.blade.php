<x-layout>
  <x-slot name="title">CPP Timeline</x-slot>
  <x-slot name="heading">CPP Snapshot Timeline</x-slot>

  <style>
    /* ── Timeline grid — clean, centered, mostly black text ──────────── */
    .tl-grid-wrap {
      overflow-x: auto;
      display: flex;
      justify-content: center;
    }
    .tl-grid {
      border-collapse: separate;
      border-spacing: 0;
      font-size: 13px;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      color: #000;
      margin: 0 auto;
    }
    .tl-grid th, .tl-grid td {
      border: 1px solid #e5e7eb;
      padding: 10px 14px;
      vertical-align: middle;
      background: white;
      min-width: 170px;
      text-align: center;
    }
    .tl-grid thead th {
      background: #f9fafb;
      font-weight: 700;
      color: #000;
      position: sticky;
      top: 0;
      z-index: 2;
      letter-spacing: 0.04em;
      font-size: 12px;
      text-transform: uppercase;
    }
    .tl-grid th.tl-date-col {
      background: #f9fafb;
      color: #000;
      font-weight: 700;
      position: sticky;
      left: 0;
      z-index: 3;
      min-width: 140px;
      text-align: center;
      letter-spacing: 0.02em;
    }
    .tl-grid thead th.tl-corner {
      left: 0;
      z-index: 4;
      background: #f3f4f6;
    }
    /* Cell base — clickable */
    .tl-cell {
      cursor: pointer;
      transition: background .15s;
      line-height: 1.5;
    }
    .tl-cell:hover { filter: brightness(0.97); }
    /* Empty / no-data */
    .tl-cell-empty {
      background: white;
      color: #d1d5db;
      cursor: default;
    }
    .tl-cell-empty:hover { background: white; }
    /* Inferred (past) — very light amber, still mostly black text */
    /* The cell's state is a thin inner border (the fill is the CPP colour): amber = inferred, dashed blue = estimate. */
    .tl-cell-inferred { box-shadow: inset 0 0 0 2px #f59e0b; }
    /* Estimate (future today) — very light blue, still mostly black text */
    .tl-cell-estimate { outline: 2px dashed #60a5fa; outline-offset: -3px; cursor: default; }
    /* Badges — small, low-contrast, informational only */
    .tl-cell .est-badge {
      display: inline-block;
      font-size: 10px;
      color: #6b7280;
      background: transparent;
      padding: 0;
      margin-top: 6px;
      letter-spacing: 0.02em;
      font-style: italic;
    }
    .tl-cell .inferred-badge {
      display: inline-block;
      font-size: 10px;
      color: #6b7280;
      background: transparent;
      padding: 0;
      margin-top: 6px;
      letter-spacing: 0.02em;
      font-style: italic;
    }
    /* Labels (Adspent / Orders / CPP) — black bold */
    .tl-cell .label {
      font-size: 11px;
      color: #000;
      font-weight: 600;
      letter-spacing: 0.02em;
    }
    /* Values — black, slightly heavier */
    .tl-cell .val {
      color: #000;
      font-weight: 600;
      font-family: 'SFMono-Regular', Consolas, monospace;
    }
    /* Saved timestamp footer — muted gray */
    .tl-cell .saved {
      font-size: 10px;
      color: #9ca3af;
      margin-top: 6px;
      letter-spacing: 0.02em;
    }

    /* CPP colours by amount (the team's CPP report cut-offs): green below ₱30, cyan ₱30 to under ₱50, orange ₱50 to
       under ₱70, red ₱70 and up; grey = no orders yet. Used by both views. */
    .tl-grid td.cpp-g, .tl-hour td.cpp-g, .swatch.cpp-g { background: #bbf7d0; }
    .tl-grid td.cpp-c, .tl-hour td.cpp-c, .swatch.cpp-c { background: #a5f3fc; }
    .tl-grid td.cpp-o, .tl-hour td.cpp-o, .swatch.cpp-o { background: #fed7aa; }
    .tl-grid td.cpp-r, .tl-hour td.cpp-r, .swatch.cpp-r { background: #fecaca; }
    .tl-grid td.cpp-n, .tl-hour td.cpp-n, .swatch.cpp-n { background: #f3f4f6; }

    /* Hourly view: 24 hour columns always fit the box. table-layout: fixed with width 100% sizes the columns from the
       <colgroup> only (page 128 px, the hours share the rest, about 44 px each at a 1280 px window), never from the
       content, so the table cannot be wider than its box; long text is cut with an ellipsis and shown in the tooltip.
       The wrapper has no overflow-x, so there is no horizontal scroll bar. */
    .tl-hour-wrap { width: 100%; overflow-x: hidden; }
    .tl-hour { table-layout: fixed; width: 100%; border-collapse: collapse; font-size: 11px; color: #000;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
    .tl-hour col.tl-hour-page { width: 128px; }
    .tl-hour th, .tl-hour td { border: 1px solid #e5e7eb; padding: 3px 1px; text-align: center; overflow: hidden;
      text-overflow: ellipsis; white-space: nowrap; }
    .tl-hour thead th { background: #f9fafb; font-weight: 700; font-size: 10px; }
    .tl-hour th.tl-hour-name { text-align: left; padding-left: 4px; font-weight: 600; background: #f9fafb; }
    .tl-hour td { font-family: 'SFMono-Regular', Consolas, monospace; font-size: 10px; cursor: default; }
    .tl-hour td.tl-hour-orders { box-shadow: inset 0 0 0 1px #f59e0b; }
    .tl-hour tr.tl-hour-total th, .tl-hour tr.tl-hour-total td { font-weight: 700; border-top: 2px solid #9ca3af; }
    .tl-hour td.tl-hour-empty { color: #d1d5db; background: #fff; }

    /* Hourly totals view (spec 291): rows = date, 24 hour columns, three stacked figures per cell. The same fixed layout
       as the hourly table (column widths from the <colgroup> only, never from the content). At a 1280 px window the box
       is about 1167 px (1280 − scroll bar − 2×32 page padding − 2×16 card padding − border): the date column takes
       76 px and each hour about 45 px, and a figure is at most 5 characters of 10 px monospace (about 28 px). The
       table's min-width (76 + 24 × 41 = 1060 px) is wider than the box only below about a 1190 px window, and then
       only the wrapper scrolls, never the page. */
    .tl-tot-wrap { width: 100%; overflow-x: auto; }
    /* Spec 292: the labels Adspent / Orders / CPP are written once per row in their own column instead of in every
       cell, and the figures are full numbers (42,101 / 1,144 / 36.80). To pay for the label column the date goes on two
       lines ("Nov 18" over "Wed"): date 46 px + labels 44 px leave 1165 − 90 = 1075 px, 44.8 px per hour, and with no
       side padding and the 1 px border a cell keeps 43.8 px, room for a figure of 7 characters (at most 7 × 6 px = 42 px
       of 10 px monospace). A longer figure falls back to the compact form (1.02M). min-width 46 + 44 + 24 × 44 = 1146 px
       is still inside the 1165 px box. */
    /* Spec 297: the Hourly totals view uses the whole window width (a 16 px side gutter) instead of the 1280 px box, so
       the 24 columns share the extra width. Done here, not in the layout: while the view is shown the page root gets
       tl-wide, and the layout's content box (main > div.max-w-7xl, the same with or without the sidebar of spec 295)
       drops its max-width. Buckets and Hourly keep the box. min-width 46 + 44 + 24 × 43 = 1122 px: a cell keeps
       43 − 1 border = 42 px, the 7 figures × 6 px of spec 292, and the table fits a 1280 px window with the sidebar
       collapsed to its rail (1280 − 17 scroll bar − 64 rail − 2×16 gutter − 2×16 card padding − 2 border = 1133 px).
       With the full sidebar open (248 px) the box is 949 px and only the wrapper scrolls, never the page. */
    #tl-root.tl-wide { max-width: none; }
    main > div:has(> #tl-root.tl-wide) { max-width: none; padding-left: 16px; padding-right: 16px; }
    .tl-tot { min-width: 1122px; }
    .tl-tot col.tl-tot-date { width: 46px; }
    .tl-tot col.tl-tot-lbl { width: 44px; }
    .tl-tot th.tl-hour-name { font-size: 11px; padding-left: 3px; line-height: 1.25; vertical-align: top; }
    .tl-tot th.tl-tot-lbls { font-size: 10px; font-weight: 600; color: #4b5563; text-align: left; padding: 2px 1px;
      line-height: 1.2; vertical-align: top; background: #f9fafb; }
    .tl-tot td { padding: 2px 0; line-height: 1.2; vertical-align: top; }
    .tl-tot td > div > div { overflow: hidden; text-overflow: ellipsis; }
    .tl-tot td.tl-tot-last { border-right: 2px solid #374151; }
    .tl-tot td.tl-tot-click { cursor: pointer; }
    .tl-tot td.tl-tot-click:hover { outline: 2px solid #2563eb; outline-offset: -2px; }
    /* Modal */
    .tl-modal-backdrop {
      position: fixed; inset: 0; background: rgba(15, 23, 42, 0.55);
      z-index: 100; display: flex; align-items: flex-start; justify-content: center;
      padding: 40px 16px; overflow-y: auto;
    }
    .tl-modal {
      background: white; border-radius: 12px; max-width: 1100px; width: 100%;
      box-shadow: 0 20px 60px rgba(0,0,0,0.25); display: flex; flex-direction: column;
      max-height: calc(100vh - 80px);
    }
    .tl-modal-head {
      display: flex; align-items: center; justify-content: space-between;
      padding: 16px 20px; border-bottom: 1px solid #e5e7eb;
    }
    .tl-modal-title { font-size: 16px; font-weight: 700; color: #0f172a; }
    .tl-modal-sub { font-size: 12px; color: #64748b; }
    .tl-modal-body { padding: 16px 20px; overflow: auto; flex: 1; }
    .tl-modal-close {
      cursor: pointer; padding: 6px 12px; border-radius: 6px; border: 1px solid #cbd5e1;
      background: white; color: #475569; font-size: 13px;
    }
    .tl-modal-close:hover { background: #f1f5f9; }
    .tl-detail-table { width: 100%; border-collapse: collapse; font-size: 12px; }
    .tl-detail-table th, .tl-detail-table td { border: 1px solid #e5e7eb; padding: 6px 8px; }
    .tl-detail-table thead th { background: #f1f5f9; color: #334155; text-align: left; font-weight: 600; }
    .tl-detail-table tbody tr:nth-child(even) td { background: #fafafa; }
    .tl-detail-table tfoot td { background: #f1f5f9; font-weight: 700; }
    .tl-detail-table thead th { cursor: pointer; }
    /* Ang naka-sort na column (public/js/cpp-sort.js): ▼ pinakamataas muna, ▲ pataas. Galing sa aria-sort, kaya hindi
       kasama sa text na kinokopya ng Copy. */
    th[aria-sort="descending"]::after { content: " ▼"; }
    th[aria-sort="ascending"]::after { content: " ▲"; }
    .num { text-align: right; font-family: 'SFMono-Regular', Consolas, monospace; }

    .tl-legend {
      display: inline-flex; align-items: center; gap: 14px;
      font-size: 11px; color: #64748b;
    }
    .tl-legend .swatch {
      display: inline-block; width: 12px; height: 12px; border-radius: 2px;
      border: 1px solid #e5e7eb; vertical-align: middle; margin-right: 4px;
    }
  </style>

  <div class="max-w-7xl mx-auto" id="tl-root" :class="view === 'totals' ? 'tl-wide' : ''" x-data="cppTimeline()" x-init="init()">

    {{-- Spec 288: Buckets (many days, the 4 snapshot times) or Hourly (one day, every hour).
         Spec 291: Hourly totals (many days, every hour, the day's total). --}}
    <div class="flex items-center gap-2 mb-3" id="tl-view-toggle">
      <span class="text-xs font-semibold text-gray-600">View</span>
      <button type="button" @click="setView('buckets')" class="text-sm px-3 py-1 rounded border"
              :class="view === 'buckets' ? 'bg-gray-800 text-white border-gray-800' : 'bg-white text-gray-700 border-gray-300'">Buckets</button>
      <button type="button" @click="setView('hourly')" class="text-sm px-3 py-1 rounded border"
              :class="view === 'hourly' ? 'bg-gray-800 text-white border-gray-800' : 'bg-white text-gray-700 border-gray-300'">Hourly</button>
      <button type="button" @click="setView('totals')" class="text-sm px-3 py-1 rounded border"
              :class="view === 'totals' ? 'bg-gray-800 text-white border-gray-800' : 'bg-white text-gray-700 border-gray-300'">Hourly totals</button>
      <span class="tl-legend ml-auto">
        <span><span class="swatch cpp-g"></span> CPP below ₱30</span>
        <span><span class="swatch cpp-c"></span> ₱30–49</span>
        <span><span class="swatch cpp-o"></span> ₱50–69</span>
        <span><span class="swatch cpp-r"></span> ₱70 and up</span>
        <span><span class="swatch cpp-n"></span> No orders yet</span>
      </span>
    </div>

    {{-- ── Hourly view: rows = page, columns = hour 00–23 (Manila) ── --}}
    <div x-show="view === 'hourly'" x-cloak class="bg-white border border-gray-200 rounded-lg p-4 mb-4" id="tl-hourly">
      <div class="flex flex-wrap items-end gap-3 mb-2">
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1">Day</label>
          <input type="date" x-model="hourDate" @change="loadHourly()" class="border border-gray-300 rounded px-2 py-1 text-sm">
        </div>
        <div class="text-xs text-gray-600">
          Each cell is CPP from 00:00 to the end of that hour (cumulative, so 15 matches the 3PM bucket). Hover for spend,
          orders, CPI, CPM and what the hour added. Amber edge = orders but no hourly spend stored yet.
          <template x-if="hourly.stored_at"><span> · Meta hourly spend as of <span x-text="fmtSavedAt(hourly.stored_at)"></span></span></template>
        </div>
        <div class="text-xs text-gray-500" x-show="hourLoading">⏳ Loading…</div>
      </div>
      <div class="tl-hour-wrap">
        <table class="tl-hour" id="tl-hour-table">
          <colgroup>
            <col class="tl-hour-page">
            @for ($h = 0; $h < 24; $h++)<col class="tl-hour-col">@endfor
          </colgroup>
          <thead>
            <tr>
              <th class="tl-hour-name">Page</th>
              @for ($h = 0; $h < 24; $h++)<th class="tl-hour-h" title="{{ sprintf('%02d:00–%02d:59', $h, $h) }}">{{ sprintf('%02d', $h) }}</th>@endfor
            </tr>
          </thead>
          <tbody>
            <template x-for="row in hourly.pages" :key="row.page">
              <tr>
                <th class="tl-hour-name" x-text="row.page" :title="row.page"></th>
                <template x-for="(c, h) in row.cells" :key="row.page + '|' + h">
                  <td :class="hourClass(c)" :title="hourTip(row.page, c, h)" x-text="c && c.cpp != null ? Math.round(c.cpp) : (c ? '·' : '')"></td>
                </template>
              </tr>
            </template>
            <template x-if="hourly.pages.length">
              <tr class="tl-hour-total">
                <th class="tl-hour-name">Total</th>
                <template x-for="(c, h) in hourly.total" :key="'total|' + h">
                  <td :class="hourClass(c)" :title="hourTip('Total', c, h)" x-text="c && c.cpp != null ? Math.round(c.cpp) : (c ? '·' : '')"></td>
                </template>
              </tr>
            </template>
            <template x-if="!hourLoading && !hourly.pages.length">
              <tr><td colspan="25" class="text-center text-gray-400 py-6">Walang data sa araw na ito.</td></tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>

    {{-- ── Hourly totals view: rows = date, columns = as of 1AM … 12AM (Manila), each cell the day's total as of that time ── --}}
    <div x-show="view === 'totals'" x-cloak class="bg-white border border-gray-200 rounded-lg p-4 mb-4" id="tl-totals">
      <div class="flex flex-wrap items-end gap-3 mb-2">
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1">Start Date</label>
          <input type="date" x-model="start" @change="preset='custom'" class="border border-gray-300 rounded px-2 py-1 text-sm">
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1">End Date</label>
          <input type="date" x-model="end" @change="preset='custom'" class="border border-gray-300 rounded px-2 py-1 text-sm">
        </div>
        <button type="button" @click="loadTotals()" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-1.5 rounded">
          Apply
        </button>
        <div class="text-xs text-gray-600 flex-1 min-w-0">
          Each cell: Adspent (₱), Orders and CPP (₱) of all pages with ad spend, as of that time (cumulative from 00:00,
          so 3PM matches the 3PM bucket; 12AM = end of day). Today a column fills after the hourly sync that covers its
          full hour (about 10 minutes past). Hover for the exact figures; click a cell for the pages.
          Amber edge = orders but no hourly spend stored. Refreshes every 5 minutes.
          <template x-if="totals.stored_at"><span> · Meta hourly spend as of <span x-text="fmtSavedAt(totals.stored_at)"></span></span></template>
        </div>
        <div class="text-xs text-gray-500" x-show="totalsLoading">⏳ Loading…</div>
      </div>
      <div class="text-xs text-amber-900 bg-amber-50 border border-amber-300 rounded px-2 py-1 mb-2" x-show="totals.stale" x-text="staleNote()" id="tl-tot-stale"></div>
      <div class="text-xs text-gray-600 mb-2" x-show="totals.clamped" x-text="`Showing the ${totals.max_days} days ending ${fmtDate(totals.end)} (the longest range this view shows).`"></div>
      <div class="text-xs text-red-700 mb-2" x-show="totalsError" x-text="totalsError"></div>
      <div class="tl-tot-wrap">
        <table class="tl-hour tl-tot" id="tl-tot-table">
          <colgroup>
            <col class="tl-tot-date">
            <col class="tl-tot-lbl">
            @for ($h = 0; $h < 24; $h++)<col class="tl-tot-col">@endfor
          </colgroup>
          <thead>
            <tr>
              <th class="tl-hour-name">Date</th>
              <th class="tl-tot-lbls" title="Per cell, top to bottom: Adspent (₱), Orders, CPP (₱)"></th>
              {{-- Spec 297: column h is the day as of (h + 1):00, 1AM … 12AM (the end of the day). --}}
              @for ($h = 0; $h < 24; $h++)<th class="tl-tot-h" title="{{ sprintf('As of %s: 00:00 – %d:59 %s%s', \Carbon\Carbon::createFromTime(($h + 1) % 24)->format('gA'), $h % 12 === 0 ? 12 : $h % 12, $h < 12 ? 'AM' : 'PM', $h === 23 ? ' (end of day)' : '') }}">{{ \Carbon\Carbon::createFromTime(($h + 1) % 24)->format('gA') }}</th>@endfor
            </tr>
          </thead>
          <tbody>
            <template x-for="row in totals.days" :key="row.date">
              <tr>
                <th class="tl-hour-name" :title="row.date + ' (' + row.label + ')'"><div x-text="dayLines(row.label)[0]"></div><div x-text="dayLines(row.label)[1]"></div></th>
                <th class="tl-tot-lbls"><div title="Adspent (₱)">Adspent</div><div>Orders</div><div title="CPP (₱)">CPP</div></th>
                <template x-for="(c, h) in totCells(row)" :key="row.date + '|' + h">
                  <td :class="totClass(row, c, h) + (c ? ' tl-tot-click' : '')" :title="totTip(row.label, c, h)" @click="c && openHour(row, h)">
                    <template x-if="c">
                      <div>
                        <div x-text="figSpend(c)"></div>
                        <div x-text="figOrders(c)"></div>
                        <div x-text="figCpp(c)"></div>
                      </div>
                    </template>
                  </td>
                </template>
              </tr>
            </template>
            <template x-if="!totalsLoading && !totals.days.length">
              <tr><td colspan="26" class="text-center text-gray-400 py-6">Walang data sa selected range.</td></tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>

    <div x-show="view === 'buckets'">

    {{-- ── Filter bar ──────────────────────────────────────────────── --}}
    <div class="bg-white border border-gray-200 rounded-lg p-4 mb-4">
      <div class="flex flex-wrap items-end gap-3">
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1">Preset</label>
          <select x-model="preset" @change="applyPreset()" class="border border-gray-300 rounded px-2 py-1 text-sm w-44">
            <option value="last7">Last 7 Days</option>
            <option value="last14">Last 14 Days</option>
            <option value="last30">Last 30 Days</option>
            <option value="this_month">This Month</option>
            <option value="last_month">Last Month</option>
            <option value="last_month_to_date">Last Month-to-Date</option>
            <option value="this_year">This Year</option>
            <option value="custom">Custom</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1">Start Date</label>
          <input type="date" x-model="start" @change="preset='custom'" class="border border-gray-300 rounded px-2 py-1 text-sm">
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-600 mb-1">End Date</label>
          <input type="date" x-model="end" @change="preset='custom'" class="border border-gray-300 rounded px-2 py-1 text-sm">
        </div>

        <div>
          {{-- Cutoff mode affects how inferred orders are counted in cells na
               walang saved snapshot. Saved cells are unaffected. --}}
          <label class="block text-xs font-semibold text-gray-600 mb-1"
                 title="Affects only inferred cells (yellow). Saved cells are immutable.">
            Inferred Cutoff
          </label>
          <select x-model="cutoffMode" @change="reload()" class="border border-gray-300 rounded px-2 py-1 text-sm w-52">
            <option value="upload">Upload time (ads upload moment)</option>
            <option value="clock">Clock time (10:00 / 15:00 / 19:00)</option>
          </select>
        </div>

        <div>
          {{-- Show/hide the "⚠ inferred · as of HH:MM" badge sa inferred cells.
               Default Hide para hindi messy yung grid. Cell background tint
               still shows kung saved or inferred yung cell. --}}
          <label class="block text-xs font-semibold text-gray-600 mb-1"
                 title="Show/hide the cutoff time badge sa inferred cells.">
            Inferred Badge
          </label>
          <select x-model="showInferredBadge" class="border border-gray-300 rounded px-2 py-1 text-sm w-28">
            <option value="hide">Hide</option>
            <option value="show">Show</option>
          </select>
        </div>

        <button @click="reload()" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium px-4 py-1.5 rounded">
          Apply
        </button>
        <span class="text-xs text-gray-500 ml-auto">
          Snapshots auto-save sa <a href="{{ route('ads_manager.cpp') }}" class="text-blue-600 hover:underline">/cpp</a> every Copy Table click.
        </span>
      </div>
      <div class="mt-3 tl-legend">
        <span><span class="swatch" style="background:#ffffff;"></span> Saved snapshot — Adspent + Orders + CPP (no edge)</span>
        <span><span class="swatch" style="box-shadow: inset 0 0 0 2px #f59e0b;"></span> Inferred — Orders only (from macro_output)</span>
        <span><span class="swatch" style="outline: 2px dashed #60a5fa; outline-offset: -3px;"></span> Estimate — projected from earlier-today × historical ratio</span>
        <span><span class="swatch" style="background:#ffffff;border-color:#e5e7eb;"></span> No data</span>
      </div>
    </div>

    {{-- ── Grid (transposed: rows=date, cols=bucket) ──────────────── --}}
    <div class="bg-white border border-gray-200 rounded-lg p-4">
      <div class="flex items-center justify-between mb-2">
        <div class="text-sm text-gray-600">
          Rows = date · Columns = snapshot bucket · Click a cell for per-page detail (saved snapshots only).
        </div>
        <div class="text-xs text-gray-500" x-show="loading">⏳ Loading…</div>
      </div>

      <div class="tl-grid-wrap">
        <table class="tl-grid">
          <thead>
            <tr>
              <th class="tl-corner tl-date-col">Date</th>
              <template x-for="b in buckets" :key="b">
                <th x-text="b"></th>
              </template>
            </tr>
          </thead>
          <tbody>
            <template x-for="d in dates" :key="d">
              <tr>
                <th class="tl-date-col" x-text="fmtDate(d)"></th>
                <template x-for="b in buckets" :key="d + '|' + b">
                  <td x-bind:class="cellClass(b, d)" :title="cellState(b, d)"
                      @click="cellOf(b, d) && !cellOf(b, d).inferred && !cellOf(b, d).is_estimate && openDetail(d, b)">
                    <template x-if="cellOf(b, d)">
                      <div>
                        <template x-if="cellOf(b, d).spent != null">
                          <div><span class="label">Adspent</span> <span class="val" x-text="money(cellOf(b, d).spent)"></span></div>
                        </template>
                        <div>
                          <span class="label" x-text="cellOf(b, d).is_estimate ? 'Est. Orders' : 'Orders'"></span>
                          <span class="val" x-text="num(cellOf(b, d).orders)"></span>
                        </div>
                        <template x-if="cellOf(b, d).cpp != null">
                          <div><span class="label">CPP</span> <span class="val" x-text="money(cellOf(b, d).cpp)"></span></div>
                        </template>
                        {{-- Estimate label — italic muted, always shown for projections --}}
                        <template x-if="cellOf(b, d).is_estimate">
                          <div class="est-badge"
                               :title="'Projection — ' + (cellOf(b, d).estimate_source || '')">
                            projected
                          </div>
                        </template>
                        <template x-if="cellOf(b, d).inferred && showInferredBadge === 'show'">
                          <div class="inferred-badge"
                               x-text="inferredBadgeText(cellOf(b, d))"
                               :title="cellOf(b, d).cutoff_src === 'clock_fallback' ? 'No ads upload found for this bucket — fell back to clock cutoff' : ''">
                          </div>
                        </template>
                        <template x-if="!cellOf(b, d).inferred && !cellOf(b, d).is_estimate && cellOf(b, d).saved_at">
                          <div class="saved">
                            <div x-text="'saved ' + fmtSavedAt(cellOf(b, d).saved_at)"></div>
                            <template x-if="cellOf(b, d).ads_at">
                              <div x-text="'ads upload at ' + cellOf(b, d).ads_at"></div>
                            </template>
                          </div>
                        </template>
                      </div>
                    </template>
                    <template x-if="!cellOf(b, d)">
                      <div>—</div>
                    </template>
                  </td>
                </template>
              </tr>
            </template>
            <template x-if="!loading && dates.length === 0">
              <tr><td colspan="100" class="text-center text-gray-400 py-6">Walang data sa selected range.</td></tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>

    </div>{{-- /bucket view --}}

    {{-- ── Detail Modal ───────────────────────────────────────────── --}}
    <template x-if="modal.open">
      <div class="tl-modal-backdrop" @click.self="closeDetail()">
        <div class="tl-modal">
          <div class="tl-modal-head">
            <div>
              <div class="tl-modal-title">
                <span x-text="fmtDate(modal.date)"></span> · <span x-text="modal.bucket"></span> Snapshot
              </div>
              <div class="tl-modal-sub">
                <span x-text="modalVisibleRows().length"></span> ad-spending pages
                <template x-if="modal.zeroSpentCount > 0 && modal.hideZeroSpent">
                  <span> · <span x-text="modal.zeroSpentCount"></span> hidden (0 adspent)</span>
                </template>
                <template x-if="modal.savedAt"><span> · saved <span x-text="fmtSavedAt(modal.savedAt)"></span></span></template>
                <template x-if="modal.savedBy.length"><span> · by <span x-text="modal.savedBy.join(', ')"></span></span></template>
              </div>
            </div>
            <div class="flex items-center gap-2">
              <template x-if="modal.zeroSpentCount > 0">
                <button @click="modal.hideZeroSpent = !modal.hideZeroSpent"
                        class="text-sm px-3 py-1.5 rounded border"
                        :class="modal.hideZeroSpent ? 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' : 'bg-amber-100 text-amber-900 border-amber-300 hover:bg-amber-200'"
                        :title="modal.hideZeroSpent ? 'Pull up the '+modal.zeroSpentCount+' page(s) with 0 adspent' : 'Hide the 0-adspent rows back (default view)'">
                  <span x-show="modal.hideZeroSpent">👁 Show 0-adspent (<span x-text="modal.zeroSpentCount"></span>)</span>
                  <span x-show="!modal.hideZeroSpent">🙈 Hide 0-adspent</span>
                </button>
              </template>
              <button @click="copyDetail()" class="bg-blue-600 hover:bg-blue-700 text-white text-sm px-3 py-1.5 rounded">📋 Copy</button>
              <button @click="closeDetail()" class="tl-modal-close">✕ Close</button>
            </div>
          </div>
          <div class="tl-modal-body">
            <template x-if="modal.loading">
              <div class="text-center text-gray-400 py-8">⏳ Loading detail…</div>
            </template>
            <template x-if="!modal.loading">
              <table class="tl-detail-table" id="tlDetailTable">
                <thead>
                  <tr>
                    <th tabindex="0" :aria-sort="modalAria(0)" @click="sortModal(0)" @keydown.enter.prevent="sortModal(0)" @keydown.space.prevent="sortModal(0)">Page Name</th>
                    <th tabindex="0" :aria-sort="modalAria(1)" @click="sortModal(1)" @keydown.enter.prevent="sortModal(1)" @keydown.space.prevent="sortModal(1)">Item Names</th>
                    <th class="num" tabindex="0" :aria-sort="modalAria(2)" @click="sortModal(2)" @keydown.enter.prevent="sortModal(2)" @keydown.space.prevent="sortModal(2)">Amount Spent</th>
                    <th class="num" tabindex="0" :aria-sort="modalAria(3)" @click="sortModal(3)" @keydown.enter.prevent="sortModal(3)" @keydown.space.prevent="sortModal(3)">Orders</th>
                    <th class="num" tabindex="0" :aria-sort="modalAria(4)" @click="sortModal(4)" @keydown.enter.prevent="sortModal(4)" @keydown.space.prevent="sortModal(4)">Proceed</th>
                    <th class="num" tabindex="0" :aria-sort="modalAria(5)" @click="sortModal(5)" @keydown.enter.prevent="sortModal(5)" @keydown.space.prevent="sortModal(5)">CPP</th>
                    <th class="num" tabindex="0" :aria-sort="modalAria(6)" @click="sortModal(6)" @keydown.enter.prevent="sortModal(6)" @keydown.space.prevent="sortModal(6)">CPI</th>
                    <th class="num" tabindex="0" :aria-sort="modalAria(7)" @click="sortModal(7)" @keydown.enter.prevent="sortModal(7)" @keydown.space.prevent="sortModal(7)">CPM</th>
                    <th class="num" tabindex="0" :aria-sort="modalAria(8)" @click="sortModal(8)" @keydown.enter.prevent="sortModal(8)" @keydown.space.prevent="sortModal(8)">TCPR%</th>
                  </tr>
                </thead>
                <tbody>
                  <template x-for="r in modalVisibleRows()" :key="r.page_name">
                    <tr :class="Number(r.amount_spent ?? 0) === 0 ? 'opacity-60 italic' : ''"
                        :title="Number(r.amount_spent ?? 0) === 0 ? 'Excluded from CPP totals (0 adspent)' : ''">
                      <td x-text="r.page_name"></td>
                      <td x-text="r.item_names || '—'"></td>
                      <td class="num" x-text="money(r.amount_spent)"></td>
                      <td class="num" x-text="num(r.orders)"></td>
                      <td class="num" x-text="num(r.proceed_orders)"></td>
                      <td class="num" x-text="r.cpp != null ? money(r.cpp) : '—'"></td>
                      <td class="num" x-text="r.cpi != null ? money(r.cpi) : '—'"></td>
                      <td class="num" x-text="r.cpm != null ? money(r.cpm) : '—'"></td>
                      <td class="num" x-text="r.tcpr_pct != null ? (Number(r.tcpr_pct).toFixed(1) + '%') : '—'"></td>
                    </tr>
                  </template>
                  <template x-if="modalVisibleRows().length === 0">
                    <tr><td colspan="9" class="text-center text-gray-400 py-6">
                      <template x-if="modal.rows.length === 0">
                        <span>Walang rows sa snapshot na ito.</span>
                      </template>
                      <template x-if="modal.rows.length > 0">
                        <span>Lahat ng <span x-text="modal.zeroSpentCount"></span> rows ay walang adspent — click "Show 0-adspent" sa taas para makita.</span>
                      </template>
                    </td></tr>
                  </template>
                </tbody>
                <tfoot>
                  <tr>
                    <td>TOTAL</td>
                    <td></td>
                    <td class="num" x-text="money(modal.totals.amount_spent)"></td>
                    <td class="num" x-text="num(modal.totals.orders)"></td>
                    <td class="num" x-text="num(modal.totals.proceed_orders)"></td>
                    <td class="num" x-text="modal.totals.cpp != null ? money(modal.totals.cpp) : '—'"></td>
                    <td class="num"></td>
                    <td class="num"></td>
                    <td class="num"></td>
                  </tr>
                </tfoot>
              </table>
            </template>
          </div>
        </div>
      </div>
    </template>

    {{-- ── Hourly totals: detail of one hour cell (spec 292) ─────────────
         Same look as the snapshot detail above. The rows are the Hourly view's page rows at that hour and the TOTAL is
         its Total cell, from the same endpoint, so the modal, the cell and the Hourly view never disagree. --}}
    <template x-if="hm.open">
      <div class="tl-modal-backdrop" id="tl-hm" @click.self="closeHour()" @keydown.escape.window="closeHour()">
        <div class="tl-modal">
          <div class="tl-modal-head">
            <div>
              <div class="tl-modal-title" x-text="hmTitle()"></div>
              <div class="tl-modal-sub">
                <span x-text="hmRange()"></span>
                <template x-if="!hm.loading && !hm.error"><span> · <span x-text="hm.rows.length"></span> pages</span></template>
              </div>
              <template x-if="hm.total">
                <div class="text-sm text-gray-800 mt-1" id="tl-hm-totals" x-text="hmTotalsLine()"></div>
              </template>
            </div>
            <div class="flex items-center gap-2">
              <button @click="copyDetail('tlHourTable')" class="bg-blue-600 hover:bg-blue-700 text-white text-sm px-3 py-1.5 rounded">📋 Copy</button>
              <button @click="closeHour()" class="tl-modal-close">✕ Close</button>
            </div>
          </div>
          <div class="tl-modal-body">
            <template x-if="hm.loading">
              <div class="text-center text-gray-400 py-8">⏳ Loading detail…</div>
            </template>
            <template x-if="!hm.loading && hm.error">
              <div class="text-center text-red-700 py-8" x-text="hm.error"></div>
            </template>
            <template x-if="!hm.loading && !hm.error">
              <table class="tl-detail-table" id="tlHourTable">
                <thead>
                  <tr>
                    <th tabindex="0" :aria-sort="hmAria(0)" @click="sortHour(0)" @keydown.enter.prevent="sortHour(0)" @keydown.space.prevent="sortHour(0)">Page</th>
                    <th class="num" tabindex="0" :aria-sort="hmAria(1)" @click="sortHour(1)" @keydown.enter.prevent="sortHour(1)" @keydown.space.prevent="sortHour(1)">Adspent</th>
                    <th class="num" tabindex="0" :aria-sort="hmAria(2)" @click="sortHour(2)" @keydown.enter.prevent="sortHour(2)" @keydown.space.prevent="sortHour(2)">Orders</th>
                    <th class="num" tabindex="0" :aria-sort="hmAria(3)" @click="sortHour(3)" @keydown.enter.prevent="sortHour(3)" @keydown.space.prevent="sortHour(3)">CPP</th>
                    <th class="num" tabindex="0" :aria-sort="hmAria(4)" @click="sortHour(4)" @keydown.enter.prevent="sortHour(4)" @keydown.space.prevent="sortHour(4)" :title="'Adspent added ' + hmLastHour()">+ Adspent last hour</th>
                    <th class="num" tabindex="0" :aria-sort="hmAria(5)" @click="sortHour(5)" @keydown.enter.prevent="sortHour(5)" @keydown.space.prevent="sortHour(5)" :title="'Orders added ' + hmLastHour()">+ Orders last hour</th>
                  </tr>
                </thead>
                <tbody>
                  <template x-for="r in hmRows()" :key="r.page">
                    <tr :class="r.source === 'orders' ? 'bg-amber-50' : ''" :title="r.source === 'orders' ? 'Orders only: Meta hourly spend not stored yet' : ''">
                      <td x-text="r.page"></td>
                      <template x-for="(v, i) in hmCells(r)" :key="r.page + '|' + i"><td class="num" x-text="v"></td></template>
                    </tr>
                  </template>
                  <template x-if="hm.rows.length === 0">
                    <tr><td colspan="6" class="text-center text-gray-400 py-6">Walang data sa oras na ito.</td></tr>
                  </template>
                </tbody>
                <tfoot x-show="hm.total">
                  <tr>
                    <td>TOTAL</td>
                    <template x-for="(v, i) in hmFoot()" :key="'hmf' + i"><td class="num" x-text="v"></td></template>
                  </tr>
                </tfoot>
              </table>
            </template>
          </div>
        </div>
      </div>
    </template>
  </div>

  <script src="{{ asset('js/cpp-sort.js') }}?v={{ substr(md5_file(public_path('js/cpp-sort.js')), 0, 10) }}"></script>
  <script>
    function cppTimeline() {
      return {
        start:      @json($start),
        end:        @json($end),
        preset:     'last7',
        view:       'buckets', // 'buckets' | 'hourly' (spec 288) | 'totals' (spec 291)
        totals:     { days: [], stored_at: null, today_stored_at: null, stale: false, clamped: false, max_days: 31, end: null },
        totalsLoading: false,
        totalsError: '',
        // Orders are live and the hourly spend is stored every hour at :10, so the totals view re-reads its data while
        // it is open (no page reload); the timer stops when another view is chosen.
        totalsRefreshMs: 5 * 60 * 1000,
        totalsTimer: null,
        hourDate:   @json(\Carbon\Carbon::now('Asia/Manila')->toDateString()),
        hourly:     { pages: [], total: [], stored_at: null, last_hour: 23 },
        hourLoading: false,
        cutoffMode: 'upload', // 'upload' | 'clock' — controls inferred cell cutoff
        showInferredBadge: 'hide', // 'hide' | 'show' — toggle the ⚠ inferred badge text

        dates:   [],
        buckets: [],
        cells:   {},
        loading: false,

        modal: {
          open: false,
          loading: false,
          date: '',
          bucket: '',
          rows: [],
          totals: { amount_spent: 0, orders: 0, proceed_orders: 0, cpp: null },
          savedAt: null,
          savedBy: [],
          // 0-adspent rows are hidden by default — wala silang contribution
          // sa CPP calc kaya noise lang sila. User can toggle them on
          // anytime via the "Show 0-adspent" button para makita yung organic
          // pages na may orders pero walang ads na that bucket.
          hideZeroSpent: true,
          zeroSpentCount: 0,
          // Ang sort ng table sa header click; habang bukas lang ang popup, kaya bawat bukas ng snapshot ay hindi pa sorted.
          sort: { col: null, dir: null },
        },

        // Ang value ng bawat column ng popup table, sa order ng mga header, para sa pag-sort.
        modalSortFields: ['page_name', 'item_names', 'amount_spent', 'orders', 'proceed_orders', 'cpp', 'cpi', 'cpm', 'tcpr_pct'],

        // Spec 292: the detail of one Hourly totals cell (date + hour). rows = the Hourly view's page cells at that hour,
        // total = its Total cell at that hour (the cell itself).
        hm: { open: false, loading: false, error: '', date: '', hour: 0, label: '', rows: [], total: null, sort: { col: null, dir: null } },
        hmSortFields: ['page', 'spend', 'orders', 'cpp', 'add_spend', 'add_orders'],

        // Filtered row list for the modal table. When hideZeroSpent is on
        // (default), drops rows where amount_spent == 0. Totals are always
        // computed sa backend from the ad-spending subset regardless of
        // visibility toggle.
        modalVisibleRows() {
          const rows = this.modal.hideZeroSpent ? this.modal.rows.filter(r => Number(r.amount_spent ?? 0) > 0) : this.modal.rows;
          return CppSort.sortItems(rows, this.modalSortFields, this.modal.sort);
        },
        sortModal(col) { this.modal.sort = CppSort.nextFor(this.modal.sort, this.modal.rows, this.modalSortFields, col); },
        modalAria(col) { return CppSort.aria(this.modal.sort, col); },

        init() { this.reload(); },

        // PH-local "now" for preset date computation. Server is PH-timed too,
        // so dates match what `Carbon::now('Asia/Manila')` would produce.
        phNow() {
          return new Date(new Date().toLocaleString('en-US', { timeZone: 'Asia/Manila' }));
        },
        toIso(d) { return d.toISOString().slice(0, 10); },

        applyPreset() {
          const now = this.phNow();
          let s, e;
          switch (this.preset) {
            case 'last7':
              e = now; s = new Date(now); s.setDate(s.getDate() - 6);
              break;
            case 'last14':
              e = now; s = new Date(now); s.setDate(s.getDate() - 13);
              break;
            case 'last30':
              e = now; s = new Date(now); s.setDate(s.getDate() - 29);
              break;
            case 'this_month':
              s = new Date(now.getFullYear(), now.getMonth(), 1);
              e = now;
              break;
            case 'last_month':
              s = new Date(now.getFullYear(), now.getMonth() - 1, 1);
              e = new Date(now.getFullYear(), now.getMonth(), 0); // last day of prev month
              break;
            case 'last_month_to_date':
              // Last month, from 1st up to today-of-month (e.g., today is May 13 →
              // Apr 1 to Apr 13). Clamps if last month has fewer days.
              s = new Date(now.getFullYear(), now.getMonth() - 1, 1);
              const lastMonthEnd = new Date(now.getFullYear(), now.getMonth(), 0);
              const tgtDay = Math.min(now.getDate(), lastMonthEnd.getDate());
              e = new Date(now.getFullYear(), now.getMonth() - 1, tgtDay);
              break;
            case 'this_year':
              s = new Date(now.getFullYear(), 0, 1);
              e = now;
              break;
            case 'custom':
              return; // do nothing — user picks dates manually
          }
          this.start = this.toIso(s);
          this.end   = this.toIso(e);
          this.reload();
        },

        async reload() {
          this.loading = true;
          try {
            const qs = new URLSearchParams({
              start: this.start,
              end:   this.end,
              cutoff_mode: this.cutoffMode,
            });
            const res = await fetch(`{{ route('ads_manager.cpp.timeline.data') }}?${qs.toString()}`);
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const j = await res.json();
            this.dates   = j.dates   || [];
            this.buckets = j.buckets || [];
            this.cells   = j.cells   || {};
          } catch (e) {
            console.error('Timeline reload failed:', e);
          } finally {
            this.loading = false;
          }
        },

        cellOf(bucket, date) {
          return (this.cells[bucket] && this.cells[bucket][date]) || null;
        },

        // The fill is the CPP colour; the state (saved / inferred / estimate) is a thin edge and the tooltip.
        cellClass(bucket, date) {
          const c = this.cellOf(bucket, date);
          if (!c) return 'tl-cell-empty';
          const tone = this.cppTone(c.cpp);
          if (c.is_estimate) return 'tl-cell tl-cell-estimate ' + tone; // future projection (dashed blue edge)
          if (c.inferred)    return 'tl-cell tl-cell-inferred ' + tone; // past inferred (amber edge)
          return 'tl-cell ' + tone; // saved snapshot
        },
        cellState(bucket, date) {
          const c = this.cellOf(bucket, date);
          if (!c) return '';
          if (c.is_estimate) return 'Estimate: projected from earlier today × historical ratio';
          if (c.inferred) return 'Inferred: orders from macro_output';
          return 'Saved snapshot';
        },

        // CPP colours by amount (the team's CPP report): below ₱30 green, ₱30–49 cyan, ₱50–69 orange, ₱70+ red, no CPP grey.
        cppTone(cpp) {
          if (cpp == null || cpp === '' || isNaN(Number(cpp))) return 'cpp-n';
          const v = Number(cpp);
          if (v < 30) return 'cpp-g';
          if (v < 50) return 'cpp-c';
          if (v < 70) return 'cpp-o';
          return 'cpp-r';
        },

        setView(v) {
          this.view = v;
          if (v === 'hourly' && !this.hourly.loaded) this.loadHourly();
          if (v === 'totals') {
            this.loadTotals();
            if (!this.totalsTimer) {
              this.totalsTimer = setInterval(() => {
                if (this.view === 'totals' && !(typeof document !== 'undefined' && document.hidden)) this.loadTotals();
              }, this.totalsRefreshMs);
            }
          } else if (this.totalsTimer) {
            clearInterval(this.totalsTimer);
            this.totalsTimer = null;
          }
        },
        async loadTotals() {
          this.totalsLoading = true;
          try {
            const qs = new URLSearchParams({ start: this.start || '', end: this.end || '' });
            const res = await fetch(`{{ route('ads_manager.cpp.timeline.hourly_totals') }}?${qs.toString()}`);
            const j = await res.json();
            if (!res.ok) { this.totalsError = j.message || ('HTTP ' + res.status); return; }
            this.totalsError = '';
            this.totals = {
              days: j.days || [], stored_at: j.stored_at || null, today_stored_at: j.today_stored_at || null,
              stale: !!j.stale, clamped: !!j.clamped, max_days: j.max_days, end: j.end,
            };
          } catch (e) {
            console.error('Hourly totals load failed:', e);
          } finally {
            this.totalsLoading = false;
          }
        },
        // Spec 297: the row's 24 "as of" cells. Cell h is the day as of (h + 1):00, which is the Total cell of hour h
        // (spend from H(h), orders before (h + 1):00), shown only while h < asof_hours: today a column waits for the
        // hourly run that stored its full hour, so orders alone never fill it.
        totCells(row) {
          const total = (row && row.total) || [];
          const n = row && Number.isInteger(row.asof_hours) ? row.asof_hours : total.length;
          return total.map((c, h) => (h < n ? c : null));
        },
        // The hourly view's cell colours, plus a stronger right edge on the day's last filled column.
        totClass(row, c, h) {
          let last = -1;
          this.totCells(row).forEach((x, i) => { if (x) last = i; });
          return this.hourClass(c) + (c && h === last ? ' tl-tot-last' : '');
        },
        // The hourly view's tooltip, "as of" the column's time; a cell with orders but no stored spend has no CPP (never
        // one from missing spend).
        totTip(label, c, h) {
          if (!c) return `${label} · as of ${this.asofLabel(h)} · no data`;
          const lines = [
            `${label} · all pages · as of ${this.asofLabel(h)}`,
            `${this.asofRange(h)} (cumulative)`,
            `CPP: ${c.cpp != null ? this.money(c.cpp) : (c.spend == null ? '— (no hourly spend yet)' : 'no orders yet')}`,
            `Spend: ${c.spend != null ? this.money(c.spend) : 'no hourly spend stored yet'}`,
            `Orders: ${this.num(c.orders)}`,
            `CPI (per 1k impressions): ${c.cpi != null ? this.money(c.cpi) : '—'}`,
            `CPM (per message): ${c.cpm != null ? this.money(c.cpm) : '—'}`,
            `Last hour (${this.hourSpan(h)}): ${c.add_spend != null ? '+' + this.money(c.add_spend) : '—'} spend, +${this.num(c.add_orders)} orders`,
          ];
          if (c.source === 'orders') lines.push('Orders only: Meta hourly spend not stored yet');
          return lines.join('\n');
        },
        staleNote() {
          const at = this.totals.today_stored_at || this.totals.stored_at;
          return at ? `⚠ Hourly spend not updated since ${this.fmtSavedAt(at)}` : '⚠ No hourly spend stored yet today';
        },
        // "Nov 18 Wed" on two lines, "Nov 18" over "Wed", so the date column stays narrow (spec 292).
        dayLines(label) {
          const p = String(label || '').split(' ');
          return [p.slice(0, 2).join(' '), p.slice(2).join(' ')];
        },
        // The cell figures (spec 292): Adspent in whole pesos and Orders with thousands separators, CPP with 2 decimals.
        // A cell has room for 7 characters (see the CSS), so a longer figure takes the compact form for that value only.
        fit(text, v) { return text.length <= 7 ? text : this.compact(v); },
        figSpend(c) { return c.spend != null ? this.fit(Math.round(Number(c.spend)).toLocaleString('en-PH'), c.spend) : '—'; },
        figOrders(c) { return this.fit(this.num(c.orders), c.orders); },
        figCpp(c) {
          return c.cpp != null ? this.fit(Number(c.cpp).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }), c.cpp) : '—';
        },
        // 0 → 12AM, 9 → 9AM, 12 → 12PM, 15 → 3PM: the way the bucket names read.
        hourLabel(h) { return `${h % 12 === 0 ? 12 : h % 12}${h < 12 ? 'AM' : 'PM'}`; },
        // Spec 297: column h (0–23) is "as of" (h + 1):00: 0 → 1AM, 7 → 8AM, 11 → 12PM, 23 → 12AM (the end of the day).
        asofLabel(h) { return this.hourLabel((h + 1) % 24); },
        // What the column covers: 7 → "00:00 – 7:59 AM", 23 → "00:00 – 11:59 PM".
        asofRange(h) { return `00:00 – ${h % 12 === 0 ? 12 : h % 12}:59 ${h < 12 ? 'AM' : 'PM'}`; },
        // The hour before the as-of time, what its "+" figures added: 7 → "7:00 – 7:59 AM".
        hourSpan(h) {
          const n = h % 12 === 0 ? 12 : h % 12;
          return `${n}:00 – ${n}:59 ${h < 12 ? 'AM' : 'PM'}`;
        },

        // Opens the detail of a filled cell. The date and hour come from the page, so they are checked before the fetch;
        // the Hourly endpoint falls back to today on a bad date, so its reply must carry the date that was asked for.
        // The reply's Total row is the same array as this row's cells (one shared code path), and it replaces the row's
        // cells so the cell and its modal show the same figures even when orders came in since the last refresh.
        async openHour(row, h) {
          const date = row && row.date;
          if (typeof date !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(date) || !Number.isInteger(h) || h < 0 || h > 23) return;
          if (!row.total || !this.totCells(row)[h]) return; // only a filled "as of" column opens (spec 297)
          this.hm = { open: true, loading: true, error: '', date, hour: h, label: row.label, rows: [], total: row.total[h], sort: { col: null, dir: null } };
          try {
            const qs = new URLSearchParams({ date });
            const res = await fetch(`{{ route('ads_manager.cpp.timeline.hourly') }}?${qs.toString()}`);
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const j = await res.json();
            if (j.date !== date) throw new Error('Asked for ' + date + ', got ' + j.date);
            if (!this.hm.open || this.hm.date !== date || this.hm.hour !== h) return; // closed or another cell opened
            if (Array.isArray(j.total) && j.total.length === 24) { row.total = j.total; row.last_hour = j.last_hour; }
            this.hm.total = (j.total || [])[h] || null;
            this.hm.rows = (j.pages || []).filter(p => p.cells && p.cells[h]).map(p => Object.assign({ page: p.page }, p.cells[h]));
          } catch (e) {
            console.error('Hour detail load failed:', e);
            this.hm.error = 'Hindi ma-load ang pages ng oras na ito.';
          } finally {
            this.hm.loading = false;
          }
        },
        closeHour() { this.hm.open = false; },
        // Spec 297: "Nov 17, 2026 · as of 8AM" over "00:00 – 7:59 AM"; the rows and the TOTAL are the same bucket.
        hmTitle() { return `${this.fmtDate(this.hm.date)} · as of ${this.asofLabel(this.hm.hour)}`; },
        hmRange() { return this.asofRange(this.hm.hour); },
        hmLastHour() { return this.hourSpan(this.hm.hour); },
        hmTotalsLine() {
          const t = this.hm.total;
          if (!t) return '';
          return `Adspent ${t.spend != null ? this.money(t.spend) : '—'} · Orders ${this.num(t.orders)} · CPP ${t.cpp != null ? this.money(t.cpp) : '—'}`;
        },
        hmRows() { return CppSort.sortItems(this.hm.rows, this.hmSortFields, this.hm.sort); },
        sortHour(col) { this.hm.sort = CppSort.nextFor(this.hm.sort, this.hm.rows, this.hmSortFields, col); },
        hmAria(col) { return CppSort.aria(this.hm.sort, col); },
        // Adspent, Orders, CPP, + Adspent last hour, + Orders last hour (the hour before the as-of time); "—" where no hourly spend is stored.
        hmCells(r) {
          if (!r) return ['', '', '', '', ''];
          return [
            r.spend != null ? this.money(r.spend) : '—',
            this.num(r.orders),
            r.cpp != null ? this.money(r.cpp) : '—',
            r.add_spend != null ? this.money(r.add_spend) : '—',
            this.num(r.add_orders),
          ];
        },
        hmFoot() { return this.hmCells(this.hm.total); },

        // Short spend for a narrow cell: 850, 12.4k, 245k, 1.24M (the exact amount is in the tooltip).
        compact(v) {
          const n = Number(v ?? 0), a = Math.abs(n);
          if (a < 999.5) return String(Math.round(n));
          if (a < 99950) return (n / 1000).toFixed(1) + 'k';
          if (a < 999500) return Math.round(n / 1000) + 'k';
          return (n / 1000000).toFixed(2) + 'M';
        },
        async loadHourly() {
          this.hourLoading = true;
          try {
            const qs = new URLSearchParams({ date: this.hourDate });
            const res = await fetch(`{{ route('ads_manager.cpp.timeline.hourly') }}?${qs.toString()}`);
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const j = await res.json();
            this.hourly = { pages: j.pages || [], total: j.total || [], stored_at: j.stored_at || null, last_hour: j.last_hour, loaded: true };
          } catch (e) {
            console.error('Hourly load failed:', e);
          } finally {
            this.hourLoading = false;
          }
        },
        hourClass(c) {
          if (!c) return 'tl-hour-empty';
          return this.cppTone(c.cpp) + (c.source === 'orders' ? ' tl-hour-orders' : '');
        },
        // Tooltip: cumulative to the end of the hour, then what the hour itself added.
        hourTip(page, c, h) {
          const hh = String(h).padStart(2, '0');
          if (!c) return `${page} · ${hh}:00–${hh}:59 · no data`;
          const lines = [
            `${page} · 00:00–${hh}:59 (cumulative)`,
            `CPP: ${c.cpp != null ? this.money(c.cpp) : 'no orders yet'}`,
            `Spend: ${c.spend != null ? this.money(c.spend) : 'no hourly spend stored yet'}`,
            `Orders: ${this.num(c.orders)}`,
            `CPI (per 1k impressions): ${c.cpi != null ? this.money(c.cpi) : '—'}`,
            `CPM (per message): ${c.cpm != null ? this.money(c.cpm) : '—'}`,
            `This hour: ${c.add_spend != null ? '+' + this.money(c.add_spend) : '—'} spend, +${this.num(c.add_orders)} orders`,
          ];
          if (c.source === 'orders') lines.push('Orders only: Meta hourly spend not stored yet');
          return lines.join('\n');
        },

        // Compose the inferred-cell badge text — shows the cutoff time used.
        // Distinguishes between upload-based cutoff vs clock fallback.
        inferredBadgeText(c) {
          if (!c) return '';
          const t = c.cutoff_at || '—';
          if (c.cutoff_src === 'upload') return `⚠ inferred · as of ${t} (upload)`;
          if (c.cutoff_src === 'clock_fallback') return `⚠ inferred · as of ${t} (no upload, clock fallback)`;
          return `⚠ inferred · as of ${t} (clock)`;
        },

        async openDetail(date, bucket) {
          this.modal.open    = true;
          this.modal.loading = true;
          this.modal.date    = date;
          this.modal.bucket  = bucket;
          this.modal.rows    = [];
          this.modal.sort    = { col: null, dir: null };
          try {
            const qs = new URLSearchParams({ date, bucket });
            const res = await fetch(`{{ route('ads_manager.cpp.timeline.detail') }}?${qs.toString()}`);
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const j = await res.json();
            if (j.ok) {
              this.modal.rows           = j.rows || [];
              this.modal.totals         = j.totals || this.modal.totals;
              this.modal.savedAt        = j.saved_at || null;
              this.modal.savedBy        = j.saved_by || [];
              this.modal.zeroSpentCount = Number(j.zero_spent_count || 0);
              // Reset toggle to default (hide) every fresh detail load para
              // walang carry-over state from previous modal session.
              this.modal.hideZeroSpent  = true;
            }
          } catch (e) {
            console.error('Detail load failed:', e);
          } finally {
            this.modal.loading = false;
          }
        },

        closeDetail() { this.modal.open = false; },

        copyDetail(id = 'tlDetailTable') {
          const table = document.getElementById(id);
          if (!table) return;
          const rows = Array.from(table.querySelectorAll('tr'));
          const tsv = rows.map(row =>
            Array.from(row.querySelectorAll('th, td'))
              .map(c => c.textContent.replace(/₱/g, '').replace(/\n+/g, ', ').trim())
              .join('\t')
          ).join('\n');
          navigator.clipboard.writeText(tsv).then(() => {
            alert('Detail table copied!');
          }).catch(e => console.error('Copy failed:', e));
        },

        // Formatters
        fmtDate(iso) {
          if (!iso) return '';
          const [y, m, d] = iso.split('-');
          const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
          return `${months[+m - 1]} ${+d}, ${y}`;
        },
        fmtSavedAt(iso) {
          if (!iso) return '—';
          const d = new Date(iso.replace(' ', 'T'));
          if (isNaN(d.getTime())) return iso;
          return d.toLocaleString('en-US', {
            timeZone: 'Asia/Manila',
            month: 'short', day: 'numeric',
            hour: 'numeric', minute: '2-digit', hour12: true,
          });
        },
        money(v) {
          const n = Number(v ?? 0);
          return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
        num(v) { return Number(v ?? 0).toLocaleString('en-PH'); },
      };
    }
  </script>
</x-layout>
