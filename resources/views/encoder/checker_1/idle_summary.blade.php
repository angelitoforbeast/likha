<!doctype html>
<html lang="en" x-data="idleSummaryUI()" x-init="init()" x-cloak>
<head>
  <meta charset="utf-8" />
  <title>Checker 1 – Idle Summary</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
  <style>
    [x-cloak]{display:none!important}
    body { background:#f3f4f6; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; }
    .matrix th, .matrix td { border:1px solid #e5e7eb; padding:6px 8px; font-size:12px; }
    .matrix th { background:#f9fafb; font-weight:600; position:sticky; top:0; z-index:5; }
    .matrix th.user-col { text-align:left; }
    .matrix td.user-col { text-align:left; font-weight:600; background:#f9fafb;
                          position:sticky; left:0; z-index:4; min-width:140px; }
    .matrix th.user-col { position:sticky; left:0; z-index:6; background:#f3f4f6; }
    .cell-num { text-align:right; font-family:ui-monospace,monospace; }
    .cell-link { cursor:pointer; }
    .cell-link:hover { background:#eff6ff; outline:2px solid #2563eb; outline-offset:-2px; }
    .metric-good { color:#15803d; }
    .metric-warn { color:#b45309; }
    .metric-bad  { color:#b91c1c; }
    .metric-muted { color:#9ca3af; }
    /* Timeline (Gantt) */
    .timeline { position:relative; height:32px; background:#f3f4f6; border-radius:4px; overflow:hidden; }
    .tl-block { position:absolute; top:0; bottom:0; transition:opacity 0.15s; }
    .tl-block:hover { opacity:0.8; }
    .tl-tick { position:absolute; top:0; bottom:0; width:1px; background:#e5e7eb; }
    .tl-tick-label { position:absolute; top:34px; transform:translateX(-50%);
                     font-size:9px; color:#6b7280; font-family:ui-monospace,monospace; }
    .legend-swatch { display:inline-block; width:12px; height:12px; border-radius:2px; vertical-align:middle; margin-right:4px; }
    /* Sliders */
    .slider-row { display:flex; align-items:center; gap:8px; font-size:12px; }
    .slider-row input[type=range] { flex:1; }
    .slider-val { font-family:ui-monospace,monospace; font-size:12px; min-width:55px; text-align:right; }

    /* Block Log Table — spreadsheet-style edge-to-edge matrix with auto-expanded cells.
       Each cell shows the merged block list inline (no click). Bordered, vertical-align top
       so cells with more content extend the row height. */
    .blt-matrix { font-size:11px; line-height:1.35; }
    .blt-matrix th, .blt-matrix td { border:1px solid #cbd5e1; padding:6px 8px; vertical-align:top; }
    .blt-matrix th { background:#f1f5f9; font-weight:600; font-size:11px; color:#334155; text-align:center; position:sticky; top:0; z-index:5; }
    .blt-matrix th.blt-user-col { text-align:left; min-width:130px; position:sticky; left:0; z-index:6; background:#e2e8f0; }
    .blt-matrix td.blt-user-col { font-weight:700; background:#f8fafc; position:sticky; left:0; z-index:4; min-width:130px; }
    .blt-matrix td.blt-cell { min-width:170px; max-width:240px; white-space:nowrap; }
    .blt-cell-header { display:flex; justify-content:space-between; gap:6px; font-weight:600; font-size:12px;
                       padding-bottom:3px; margin-bottom:4px; border-bottom:1px dashed #e2e8f0; }
    .blt-cell-pct { color:#0f172a; }
    .blt-cell-count { color:#64748b; font-weight:500; font-size:10px; }
    .blt-cell-time { font-family:ui-monospace,monospace; font-size:10px; color:#64748b; margin-bottom:4px; }
    .blt-block-line { display:flex; align-items:center; gap:6px; font-size:10.5px;
                      padding:2px 0; font-family:ui-monospace,monospace; }
    .blt-block-line .blt-block-time { color:#475569; min-width:80px; }
    .blt-block-line .blt-block-pill { flex:1; font-weight:700; color:#fff; padding:1px 6px;
                                       border-radius:3px; font-family:-apple-system,BlinkMacSystemFont,sans-serif;
                                       font-size:9.5px; text-align:center; letter-spacing:0.02em;
                                       text-transform:uppercase; }
    .blt-block-line .blt-block-dur { color:#0f172a; font-weight:600; min-width:55px; text-align:right; }
  </style>
</head>
<body class="text-gray-900">

  <nav class="bg-white border-b sticky top-0 z-50">
    <div class="max-w-7xl mx-auto px-4">
      <div class="h-14 flex items-center justify-between">
        <div class="font-semibold text-lg">Checker 1 – Idle Summary</div>
        <div class="flex items-center gap-3 text-sm">
          <a href="{{ route('encoder.checker1.summary') }}"
             class="text-blue-600 hover:underline">← Back to Standard Summary</a>
          <span class="text-gray-500">Range: <span x-text="startDate"></span> → <span x-text="endDate"></span></span>
        </div>
      </div>
    </div>
  </nav>

  {{-- Most sections constrained to max-w-7xl, but Block Log Table breaks out
       to full width (edge-to-edge) so the wide matrix has room to breathe. --}}
  <main class="px-4 py-5 space-y-4">

  <div class="max-w-7xl mx-auto space-y-4">

    {{-- Date filter --}}
    <section class="bg-white rounded-xl shadow p-4">
      <form method="GET" action="{{ route('encoder.checker1.idle-summary') }}" class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
        <div>
          <label class="block text-sm font-medium mb-1">Start date</label>
          <input name="start" type="date" value="{{ $start }}" class="w-full border rounded px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-sm font-medium mb-1">End date</label>
          <input name="end" type="date" value="{{ $end }}" class="w-full border rounded px-3 py-2 text-sm">
        </div>
        <div class="md:col-span-3 flex gap-2 pt-6">
          <button class="px-3 py-2 rounded bg-blue-600 text-white hover:bg-blue-700 text-sm">Apply</button>
          <a href="{{ route('encoder.checker1.idle-summary') }}"
             class="px-3 py-2 rounded border hover:bg-gray-50 text-sm">Reset (Last 7 Days)</a>
        </div>
      </form>
    </section>

    {{-- Threshold summary (read-only) + link to settings page --}}
    <section class="bg-white rounded-xl shadow p-4">
      <div class="flex items-center justify-between mb-2 flex-wrap gap-2">
        <div class="font-semibold">⚙ Activity Thresholds <span class="text-xs text-gray-500 font-normal">(global, applied to all viewers)</span></div>
        <a href="{{ route('encoder.checker1.settings') }}"
           class="text-sm px-3 py-1.5 rounded bg-blue-600 text-white hover:bg-blue-700">
          ⚙ Settings
        </a>
      </div>
      <div class="text-xs text-gray-600 flex flex-wrap gap-x-4 gap-y-1">
        <span><span class="legend-swatch" style="background:#22c55e;"></span>Working ≤ <span class="font-mono font-semibold" x-text="fmtSec(thresholds.work)"></span></span>
        <span><span class="legend-swatch" style="background:#f59e0b;"></span>Idle break ≤ <span class="font-mono font-semibold" x-text="fmtSec(thresholds.idle)"></span></span>
        <span><span class="legend-swatch" style="background:#ef4444;"></span>Long break ≤ <span class="font-mono font-semibold" x-text="fmtSec(thresholds.long)"></span></span>
        <span><span class="legend-swatch" style="background:#9ca3af;"></span>Away (any gap > long break, excluded from totals)</span>
      </div>
    </section>

    {{-- Main matrix --}}
    <section class="bg-white rounded-xl shadow p-4 overflow-x-auto">
      <div class="flex items-center justify-between mb-2 flex-wrap gap-2">
        <div class="font-semibold">Encoder Activity Matrix</div>
        <div class="flex items-center gap-3 text-xs text-gray-500">
          <label>
            Show metric:
            <select x-model="displayMetric" class="border rounded px-2 py-1 ml-1 text-xs">
              <option value="active_pct">Active %</option>
              <option value="active_hrs">Active time (min)</option>
              <option value="idle_hrs">Idle time (min)</option>
              <option value="edits">Edits count</option>
              <option value="prod">Edits / active min</option>
              <option value="first_last">First / Last edit</option>
            </select>
          </label>
          <span class="text-gray-400">Click any cell for hourly timeline.</span>
        </div>
      </div>

      <table class="matrix" style="border-collapse:separate; border-spacing:0;">
        <thead>
          <tr>
            <th class="user-col">Encoder</th>
            @foreach($prettyDates as $i => $label)
              <th class="text-center" style="min-width:90px;">{{ $label }}</th>
            @endforeach
            <th class="text-center" style="min-width:90px;background:#eff6ff;">Total</th>
          </tr>
        </thead>
        <tbody>
          <template x-if="users.length === 0">
            <tr>
              <td :colspan="dates.length + 2" class="text-center py-12 text-gray-400">No status-log activity in range.</td>
            </tr>
          </template>
          <template x-for="u in users" :key="u">
            <tr>
              <td class="user-col" x-text="u"></td>
              <template x-for="d in dates" :key="u + '|' + d">
                <td class="cell-num cell-link"
                    @click="openDrilldown(u, d)"
                    :title="cellTooltip(u, d)">
                  <span x-html="cellDisplay(u, d)"></span>
                </td>
              </template>
              <td class="cell-num" style="background:#f0f9ff;font-weight:600;" x-html="rowTotalDisplay(u)"></td>
            </tr>
          </template>
        </tbody>
      </table>
    </section>

  </div>{{-- /max-w-7xl wrapper. Block Log Table breaks out to edge-to-edge below. --}}

    {{-- Block Log Table — same matrix layout as the Encoder Activity Matrix
         above (rows = encoders, cols = dates), but each cell auto-expands to
         show its merged block log content directly (no click). Edge-to-edge
         layout so the wide content has room. --}}
    <section class="bg-white rounded-xl shadow p-4 overflow-x-auto">
      <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
        <div class="font-semibold">📋 Block Log Table <span class="text-xs text-gray-500 font-normal">(auto-expanded · each cell shows merged work / idle / break sessions)</span></div>
      </div>

      <table class="blt-matrix" style="border-collapse:collapse;width:100%;">
        <thead>
          <tr>
            <th class="blt-user-col">Encoder</th>
            @foreach($prettyDates as $i => $label)
              <th class="blt-date-col">{{ $label }}</th>
            @endforeach
          </tr>
        </thead>
        <tbody>
          <template x-if="users.length === 0">
            <tr>
              <td :colspan="dates.length + 1" class="text-center py-12 text-gray-400">No status-log activity in range.</td>
            </tr>
          </template>
          <template x-for="u in users" :key="'blt-' + u">
            <tr>
              <td class="blt-user-col" x-text="u"></td>
              <template x-for="d in dates" :key="'blt-cell-' + u + '|' + d">
                <td class="blt-cell">
                  {{-- No-data cell: show em-dash. --}}
                  <template x-if="!statsFor(u, d)">
                    <span class="metric-muted">—</span>
                  </template>
                  {{-- With data: header summary + merged block list inline (auto-expanded). --}}
                  <template x-if="statsFor(u, d)">
                    <div>
                      <div class="blt-cell-header">
                        <span class="blt-cell-pct" x-text="statsFor(u, d).activePct !== null ? statsFor(u, d).activePct.toFixed(0) + '%' : '—'"></span>
                        <span class="blt-cell-count" x-text="statsFor(u, d).count + ' edits'"></span>
                      </div>
                      <div class="blt-cell-time">
                        <span x-text="statsFor(u, d).firstEdit"></span>
                        <span class="text-gray-400">→</span>
                        <span x-text="statsFor(u, d).lastEdit"></span>
                      </div>
                      <template x-if="mergedBlocksFor(u, d).length === 0">
                        <div class="blt-block-line text-gray-400 italic">(only 1 edit)</div>
                      </template>
                      <template x-for="(b, i) in mergedBlocksFor(u, d)" :key="i + '|' + b.from">
                        <div class="blt-block-line">
                          <span class="blt-block-time" x-text="fmtTime(b.from) + '–' + fmtTime(b.to)"></span>
                          <span class="blt-block-pill"
                                :style="'background:' + bucketColor(b.bucket) + ';'"
                                x-text="bucketLabel(b.bucket)"></span>
                          <span class="blt-block-dur" x-text="fmtDur(b.to - b.from)"></span>
                        </div>
                      </template>
                    </div>
                  </template>
                </td>
              </template>
            </tr>
          </template>
        </tbody>
      </table>
    </section>

  <div class="max-w-7xl mx-auto">

    {{-- Drilldown timeline (hidden until cell click) --}}
    <section x-show="drill.open" x-cloak class="bg-white rounded-xl shadow p-4">
      <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
        <div>
          <div class="font-semibold text-base">
            <span x-text="drill.user"></span>
            <span class="text-gray-400 mx-1">·</span>
            <span x-text="drill.date"></span>
          </div>
          <div class="text-xs text-gray-500 mt-0.5">Hourly timeline · each block = period between consecutive edits</div>
        </div>
        <button @click="drill.open = false" class="text-sm text-gray-500 hover:text-gray-900">✕ Close</button>
      </div>

      {{-- Per-day stats summary --}}
      <div class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-4 text-sm">
        <div class="bg-gray-50 rounded p-2">
          <div class="text-xs text-gray-500">Edits</div>
          <div class="font-bold text-lg" x-text="drill.stats.count"></div>
        </div>
        <div class="bg-green-50 rounded p-2">
          <div class="text-xs text-gray-500">Active</div>
          <div class="font-bold text-lg metric-good" x-text="fmtDur(drill.stats.active)"></div>
        </div>
        <div class="bg-amber-50 rounded p-2">
          <div class="text-xs text-gray-500">Idle break</div>
          <div class="font-bold text-lg metric-warn" x-text="fmtDur(drill.stats.idle)"></div>
        </div>
        <div class="bg-red-50 rounded p-2">
          <div class="text-xs text-gray-500">Long break</div>
          <div class="font-bold text-lg metric-bad" x-text="fmtDur(drill.stats.longBreak)"></div>
        </div>
        <div class="bg-blue-50 rounded p-2">
          <div class="text-xs text-gray-500">Active %</div>
          <div class="font-bold text-lg" x-text="(drill.stats.activePct !== null ? drill.stats.activePct.toFixed(1) + '%' : '—')"></div>
        </div>
        <div class="bg-gray-50 rounded p-2">
          <div class="text-xs text-gray-500">Edits / active min</div>
          <div class="font-bold text-lg" x-text="(drill.stats.prod !== null ? drill.stats.prod.toFixed(2) : '—')"></div>
        </div>
      </div>

      {{-- Hourly Gantt timeline (00:00 → 24:00) --}}
      <div class="mb-1 text-xs text-gray-500 flex justify-between">
        <span>First edit: <span class="font-mono" x-text="drill.stats.firstEdit || '—'"></span></span>
        <span>Last edit: <span class="font-mono" x-text="drill.stats.lastEdit || '—'"></span></span>
      </div>
      <div class="timeline" style="position:relative;">
        <template x-for="b in drill.blocks" :key="b.from + '-' + b.to">
          <div class="tl-block"
               :style="'left:' + b.leftPct + '%; width:' + b.widthPct + '%; background:' + b.color + ';'"
               :title="b.tooltip"></div>
        </template>
        {{-- Hour ticks --}}
        <template x-for="h in [0,3,6,9,12,15,18,21,24]" :key="'tick'+h">
          <div class="tl-tick" :style="'left:' + (h/24*100) + '%;'"></div>
        </template>
      </div>
      <div style="position:relative; height:16px;">
        <template x-for="h in [0,3,6,9,12,15,18,21,24]" :key="'lbl'+h">
          <span class="tl-tick-label" :style="'left:' + (h/24*100) + '%; top:0;'" x-text="String(h).padStart(2,'0') + ':00'"></span>
        </template>
      </div>

      <div class="mt-4 text-xs text-gray-500 flex flex-wrap gap-x-4 gap-y-1">
        <span><span class="legend-swatch" style="background:#22c55e;"></span>Active work</span>
        <span><span class="legend-swatch" style="background:#f59e0b;"></span>Idle break</span>
        <span><span class="legend-swatch" style="background:#ef4444;"></span>Long break</span>
        <span><span class="legend-swatch" style="background:#9ca3af;"></span>Away / off-shift</span>
        <span class="ml-auto">Hover any block for exact timing.</span>
      </div>

      {{-- Chronological ON/OFF block log — line-by-line time ranges with bucket
           labels. Easier to scan than the Gantt for "anong oras working vs idle". --}}
      <div class="mt-5 border-t pt-4">
        <div class="flex items-center justify-between mb-2 flex-wrap gap-2">
          <div class="font-semibold text-sm">📋 Block Log (chronological)</div>
          <div class="flex items-center gap-3 text-xs text-gray-500">
            <label class="inline-flex items-center gap-1">
              <input type="checkbox" x-model="drill.logHideActive">
              Hide active blocks
            </label>
            <label class="inline-flex items-center gap-1">
              <input type="checkbox" x-model="drill.logHideAway">
              Hide away
            </label>
          </div>
        </div>
        <template x-if="!drill.stats.blocks || drill.stats.blocks.length === 0">
          <div class="text-xs text-gray-400 italic py-4">Only 1 edit on this day — no blocks to show.</div>
        </template>
        <template x-if="drill.stats.blocks && drill.stats.blocks.length > 0">
          <div class="rounded border overflow-hidden">
            <table class="w-full text-xs">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-2 py-1.5 text-left w-16">#</th>
                  <th class="px-2 py-1.5 text-left">Time range</th>
                  <th class="px-2 py-1.5 text-left">Status</th>
                  <th class="px-2 py-1.5 text-right">Duration</th>
                </tr>
              </thead>
              <tbody>
                <template x-for="(b, i) in filteredBlockLog()" :key="i + '|' + b.from">
                  <tr :class="i % 2 === 0 ? 'bg-white' : 'bg-gray-50'">
                    <td class="px-2 py-1 text-gray-400" x-text="(i + 1)"></td>
                    <td class="px-2 py-1 font-mono">
                      <span x-text="fmtTime(b.from)"></span>
                      <span class="text-gray-400">→</span>
                      <span x-text="fmtTime(b.to)"></span>
                    </td>
                    <td class="px-2 py-1">
                      <span class="inline-block px-1.5 py-0.5 rounded text-white font-semibold"
                            :style="'background:' + bucketColor(b.bucket) + ';'"
                            x-text="bucketLabel(b.bucket)"></span>
                    </td>
                    <td class="px-2 py-1 text-right font-mono">
                      <span x-text="fmtDur(b.to - b.from)"></span>
                      <template x-if="b.bucket === 'active' && b.count > 1">
                        <span class="text-gray-400 ml-1" x-text="'(' + b.count + ' edits)'"></span>
                      </template>
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
        </template>
      </div>
    </section>

  </div>{{-- /max-w-7xl wrapper around drilldown --}}

  </main>

  <script>
    // ── Embedded data from server ──
    // byUserDate = { user: { 'Y-m-d': [unix_ts, ...] } }
    const _BY_USER_DATE = @json($byUserDate);
    const _DATES        = @json($dates);
    const _USERS        = @json($users);
    const _START        = @json($start);
    const _END          = @json($end);
    // Thresholds are GLOBAL (saved in app_settings via /encoder/checker_1/idle-thresholds).
    // They no longer change client-side — page reloads after admin saves new values.
    const _THRESHOLDS   = @json($thresholds);

    function idleSummaryUI() {
      return {
        // Server-provided baseline
        byUserDate: _BY_USER_DATE,
        users:      _USERS,
        dates:      _DATES,
        startDate:  _START,
        endDate:    _END,

        // Thresholds loaded from server (managed at /encoder/checker_1/idle-thresholds).
        // Static during the page lifetime — admin edits in the separate route, refresh applies.
        thresholds: _THRESHOLDS,
        displayMetric: 'active_pct',

        // Drilldown state (cell-click panel below matrix)
        drill: {
          open: false, user: '', date: '',
          stats: { count:0, active:0, idle:0, longBreak:0, away:0, activePct:null, prod:null, firstEdit:'', lastEdit:'', blocks:[] },
          blocks: [],
          // Block log filters (chronological ON/OFF table)
          logHideActive: false,
          logHideAway:   false,
        },

        // Block Log Table inline-expand state. Keyed by `${user}|${date}`.
        flatExpanded: {},

        init(){
          // Stays empty — server data already loaded inline.
        },

        // ── Core classification ──
        // Returns per (user, date) summary stats using current thresholds.
        // Single-edit per day → assume 30s active (per spec).
        // Returns null when no entries for the cell (cell shows "—").
        statsFor(user, dateKey){
          const list = (this.byUserDate[user] && this.byUserDate[user][dateKey]) || [];
          if (!list.length) return null;

          const T_W = this.thresholds.work;
          const T_I = this.thresholds.idle;
          const T_L = this.thresholds.long;

          let active = 0, idle = 0, longBreak = 0, away = 0;
          const blocks = []; // for drilldown timeline

          if (list.length === 1) {
            active = 30; // edge case: assume 30 sec of active work per spec
          } else {
            for (let i = 0; i < list.length - 1; i++) {
              const gap = list[i+1] - list[i];
              let bucket;
              if      (gap <= T_W) { bucket = 'active';    active    += gap; }
              else if (gap <= T_I) { bucket = 'idle';      idle      += gap; }
              else if (gap <= T_L) { bucket = 'longBreak'; longBreak += gap; }
              else                 { bucket = 'away';      away      += gap; }
              blocks.push({ from: list[i], to: list[i+1], bucket });
            }
          }

          // First + last edit times (HH:MM PH).
          const firstTs = list[0];
          const lastTs  = list[list.length - 1];
          const presence = active + idle + longBreak; // 'away' excluded
          const activePct = presence > 0 ? (active / presence * 100) : null;
          const prod = active > 0 ? (list.length / (active / 60)) : null;

          return {
            count: list.length,
            active, idle, longBreak, away,
            activePct, prod,
            firstEdit: this.fmtTime(firstTs),
            lastEdit:  this.fmtTime(lastTs),
            blocks,
          };
        },

        // ── Cell display formatting ──
        cellDisplay(user, dateKey){
          const s = this.statsFor(user, dateKey);
          if (!s) return '<span class="metric-muted">—</span>';

          switch (this.displayMetric) {
            case 'active_pct': {
              if (s.activePct === null) return '<span class="metric-muted">—</span>';
              const cls = s.activePct >= 70 ? 'metric-good'
                        : s.activePct >= 40 ? 'metric-warn' : 'metric-bad';
              return `<span class="${cls}">${s.activePct.toFixed(0)}%</span>` +
                     `<div class="text-xs text-gray-400">${s.count} edits</div>`;
            }
            case 'active_hrs':
              return this.fmtDur(s.active) + `<div class="text-xs text-gray-400">${s.count} edits</div>`;
            case 'idle_hrs':
              return this.fmtDur(s.idle) + (s.longBreak > 0 ? `<div class="text-xs text-gray-400">+${this.fmtDur(s.longBreak)} long</div>` : '');
            case 'edits':
              return `<span>${s.count}</span>`;
            case 'prod':
              return s.prod !== null
                ? `<span>${s.prod.toFixed(2)}</span><div class="text-xs text-gray-400">edits/min</div>`
                : '<span class="metric-muted">—</span>';
            case 'first_last':
              return `<span class="font-mono text-xs">${s.firstEdit}</span><div class="font-mono text-xs text-gray-500">→ ${s.lastEdit}</div>`;
            default:
              return '—';
          }
        },

        cellTooltip(user, dateKey){
          const s = this.statsFor(user, dateKey);
          if (!s) return 'No edits';
          return `${user} · ${dateKey}\n` +
                 `${s.count} edits\n` +
                 `Active: ${this.fmtDur(s.active)}\n` +
                 `Idle:   ${this.fmtDur(s.idle)}\n` +
                 `Long:   ${this.fmtDur(s.longBreak)}\n` +
                 `Away:   ${this.fmtDur(s.away)}\n` +
                 (s.activePct !== null ? `Active%: ${s.activePct.toFixed(1)}%\n` : '') +
                 `First → Last: ${s.firstEdit} → ${s.lastEdit}\n` +
                 `(click to open hourly timeline)`;
        },

        // Per-row total across all visible dates.
        rowTotalDisplay(user){
          let count = 0, active = 0, idle = 0, longBreak = 0;
          for (const d of this.dates) {
            const s = this.statsFor(user, d);
            if (!s) continue;
            count     += s.count;
            active    += s.active;
            idle      += s.idle;
            longBreak += s.longBreak;
          }
          if (count === 0) return '<span class="metric-muted">—</span>';
          const presence = active + idle + longBreak;
          const pct = presence > 0 ? (active / presence * 100) : null;
          const main = pct !== null ? `${pct.toFixed(0)}%` : '—';
          return `<span>${main}</span><div class="text-xs text-gray-500">${count} edits</div>`;
        },

        // ── Drilldown ──
        openDrilldown(user, dateKey){
          const s = this.statsFor(user, dateKey);
          if (!s) { this.drill.open = false; return; }
          this.drill.user = user;
          this.drill.date = dateKey;
          this.drill.stats = s;
          this.drill.blocks = this.computeTimelineBlocks(user, dateKey, s);
          this.drill.open = true;
          // Smooth-scroll to drilldown section.
          this.$nextTick(() => {
            const el = document.querySelector('[x-show="drill.open"]');
            if (el) el.scrollIntoView({ behavior:'smooth', block:'start' });
          });
        },

        // Convert gap blocks → positioned timeline bars (0-24h horizontal axis).
        // Day starts at the date's 00:00 PH and ends at 24:00. Out-of-day bars
        // (rare, only if status_log timestamp wraps midnight) clamp to bounds.
        computeTimelineBlocks(user, dateKey, stats){
          const list = (this.byUserDate[user] && this.byUserDate[user][dateKey]) || [];
          if (!list.length) return [];

          // Day boundaries (PH) — use the date string + browser locale interpretation.
          // We treat the timestamp's day-of-PH-time by using 00:00 of the displayed
          // calendar date as start. Since server-side already filtered to PH dates,
          // simple unix-day math works.
          const dayStart = Math.floor(list[0] / 86400) * 86400; // start of day (UTC-aligned approximation)
          // Refine: derive day start from the date string directly.
          const [y, m, d] = dateKey.split('-').map(Number);
          // PH (UTC+8) — convert to unix by computing midnight UTC of that PH date.
          const dayStartPH = Date.UTC(y, m - 1, d, 0, 0, 0) / 1000 - 8 * 3600;
          const dayEndPH   = dayStartPH + 86400;
          const span = 86400; // sec in day

          const colorOf = (bucket) => ({
            active:    '#22c55e',
            idle:      '#f59e0b',
            longBreak: '#ef4444',
            away:      '#9ca3af',
          }[bucket] || '#9ca3af');

          const out = [];
          for (const b of stats.blocks) {
            const from = Math.max(dayStartPH, b.from);
            const to   = Math.min(dayEndPH,   b.to);
            if (to <= from) continue;
            const leftPct  = ((from - dayStartPH) / span) * 100;
            const widthPct = ((to   - from) / span) * 100;
            out.push({
              from: b.from, to: b.to,
              leftPct, widthPct,
              color: colorOf(b.bucket),
              tooltip: `${this.fmtTime(b.from)} → ${this.fmtTime(b.to)} · ${b.bucket} (${this.fmtDur(b.to - b.from)})`,
            });
          }
          // Also mark each edit point as a tiny vertical tick — slim 3px bar.
          for (const ts of list) {
            const left = ((ts - dayStartPH) / span) * 100;
            if (left < 0 || left > 100) continue;
            out.push({
              from: ts, to: ts + 1,
              leftPct: left,
              widthPct: 0.15, // tiny mark
              color: '#1e3a8a',
              tooltip: `Edit at ${this.fmtTime(ts)}`,
            });
          }
          return out;
        },

        // ── Block Log Table (flat list w/ inline expand) ──
        // Builds a flat array of (user, date, stats) for every cell with edits.
        // Excludes empty cells so the table only shows real activity.
        flatRows(){
          const out = [];
          for (const u of this.users) {
            for (const d of this.dates) {
              const s = this.statsFor(u, d);
              if (!s) continue;
              out.push({ user: u, date: d, stats: s });
            }
          }
          return out;
        },
        flatKey(user, date){ return user + '|' + date; },
        isFlatExpanded(user, date){ return !!this.flatExpanded[this.flatKey(user, date)]; },
        toggleFlatRow(user, date){
          const k = this.flatKey(user, date);
          if (this.flatExpanded[k]) delete this.flatExpanded[k];
          else                       this.flatExpanded[k] = true;
        },
        expandAll(){
          const next = {};
          for (const r of this.flatRows()) next[this.flatKey(r.user, r.date)] = true;
          this.flatExpanded = next;
        },
        collapseAll(){ this.flatExpanded = {}; },

        // Returns merged consecutive same-bucket blocks for (user, date).
        // Same logic as filteredBlockLog but without the filter checkboxes
        // (those are drilldown-only, this table shows all blocks).
        mergedBlocksFor(user, date){
          const s = this.statsFor(user, date);
          if (!s || !s.blocks || s.blocks.length === 0) return [];
          const merged = [];
          for (const b of s.blocks) {
            const last = merged[merged.length - 1];
            if (last && last.bucket === b.bucket && last.to === b.from) {
              last.to    = b.to;
              last.count = (last.count || 1) + 1;
            } else {
              merged.push({ from: b.from, to: b.to, bucket: b.bucket, count: 1 });
            }
          }
          return merged;
        },

        // ── Block log helpers (chronological ON/OFF table) ──
        bucketColor(bucket){
          return ({
            active:    '#22c55e',
            idle:      '#f59e0b',
            longBreak: '#ef4444',
            away:      '#9ca3af',
          }[bucket] || '#9ca3af');
        },
        bucketLabel(bucket){
          return ({
            active:    'Working',
            idle:      'Idle break',
            longBreak: 'Long break',
            away:      'Away',
          }[bucket] || bucket);
        },
        filteredBlockLog(){
          // 1. Merge consecutive same-bucket blocks into one row each. Encoders
          //    log many micro-edits in a row — without merging, the log explodes
          //    into hundreds of "Working 20s" lines. Merged, you see clean
          //    "09:12 → 10:59 Working 1h 47m" sessions punctuated by breaks.
          // 2. Apply user filters (hide active / hide away).
          const all = this.drill.stats.blocks || [];
          if (all.length === 0) return [];

          const merged = [];
          for (const b of all) {
            const last = merged[merged.length - 1];
            if (last && last.bucket === b.bucket && last.to === b.from) {
              // Extend the previous session — same bucket + contiguous.
              last.to    = b.to;
              last.count = (last.count || 1) + 1;
            } else {
              merged.push({ from: b.from, to: b.to, bucket: b.bucket, count: 1 });
            }
          }

          return merged.filter(b => {
            if (this.drill.logHideActive && b.bucket === 'active') return false;
            if (this.drill.logHideAway   && b.bucket === 'away')   return false;
            return true;
          });
        },

        // ── Formatters ──
        fmtSec(s){
          if (s < 60) return s + 's';
          if (s < 3600) return Math.round(s/60) + 'm';
          return (s/3600).toFixed(1) + 'h';
        },
        fmtDur(s){
          // All durations in minutes with 1 decimal place (e.g., "107.5m", "0.5m").
          // User pref — uniform unit easier to compare than mixed h/m/s.
          if (!s || s <= 0) return '0.0m';
          return (s / 60).toFixed(1) + 'm';
        },
        fmtTime(unixSec){
          // Convert to PH (UTC+8) HH:MM display.
          const d = new Date(unixSec * 1000);
          const ph = new Date(d.toLocaleString('en-US', { timeZone: 'Asia/Manila' }));
          const hh = String(ph.getHours()).padStart(2, '0');
          const mm = String(ph.getMinutes()).padStart(2, '0');
          return hh + ':' + mm;
        },
      };
    }
  </script>
</body>
</html>
