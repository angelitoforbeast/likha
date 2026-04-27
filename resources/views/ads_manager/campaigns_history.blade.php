<!DOCTYPE html>
<html lang="en" x-data="historyUI()" x-cloak>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Campaigns History — Likha</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
  <style>
    [x-cloak]{display:none!important}
    body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background:#f0f2f5; }
    .ev-row { display:grid; grid-template-columns: 90px 110px minmax(0,1fr) 110px 110px; gap:10px; align-items:center; padding:7px 12px; border-bottom:1px solid #e4e6eb; font-size:13px; }
    .ev-row:hover { background:#f7f8fa; }
    .ev-pill { display:inline-flex; align-items:center; gap:5px; font-size:11px; padding:2px 8px; border-radius:9999px; font-weight:600; line-height:1; white-space:nowrap; }
    .ev-pill.created     { background:#dbeafe; color:#1d4ed8; }
    .ev-pill.created_with_spend { background:#dcfce7; color:#15803d; }
    .ev-pill.turned_on   { background:#dcfce7; color:#166534; }
    .ev-pill.turned_off  { background:#fee2e2; color:#b91c1c; }
    .level-pill { display:inline-flex; align-items:center; font-size:10px; padding:2px 7px; border-radius:4px; font-weight:600; line-height:1; text-transform:uppercase; letter-spacing:0.05em; }
    .level-pill.campaign { background:#fef3c7; color:#92400e; }
    .level-pill.adset    { background:#e0e7ff; color:#3730a3; }
    .level-pill.ad       { background:#f3e8ff; color:#7c3aed; }
    .day-header { background:#1f2937; color:white; padding:10px 14px; font-size:13px; font-weight:700; display:flex; align-items:center; justify-content:space-between; }
    .day-header .chips { display:inline-flex; gap:6px; }
    .day-chip { font-size:10px; padding:2px 8px; border-radius:9999px; background:rgba(255,255,255,.15); font-weight:600; }
    .num { text-align:right; font-variant-numeric:tabular-nums; }
    .empty-state { padding:60px 20px; text-align:center; color:#65676b; font-size:13px; }
  </style>
</head>
<body>
  <nav class="bg-white border-b sticky top-0 z-30 shadow-sm">
    <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
      <div class="flex items-center gap-3">
        <a href="{{ route('ads_manager.campaigns') }}" class="text-sm text-blue-600 hover:underline">← Campaigns</a>
        <span class="text-gray-300">·</span>
        <h1 class="font-semibold text-base">⏱ Daily Change History</h1>
      </div>
      <div class="text-xs text-gray-500">
        Derived from spend transitions in <code>ads_manager_reports</code>
      </div>
    </div>
  </nav>

  <main class="max-w-7xl mx-auto p-4 space-y-4">

    <div class="bg-white rounded-lg shadow border border-gray-200 p-4">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
        <div>
          <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">From</label>
          <input type="date" x-model="filters.start_date"
                 class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">To</label>
          <input type="date" x-model="filters.end_date"
                 class="w-full border border-gray-300 rounded px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Page</label>
          <select x-model="filters.page_name"
                  class="w-full border border-gray-300 rounded px-3 py-2 text-sm bg-white">
            <option value="all">All Pages</option>
            @foreach(($pages ?? []) as $p)
              <option value="{{ trim($p) }}">{{ trim($p) }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Level</label>
          <select x-model="filters.level"
                  class="w-full border border-gray-300 rounded px-3 py-2 text-sm bg-white">
            <option value="all">All levels</option>
            <option value="campaigns">Campaigns only</option>
            <option value="adsets">Ad Sets only</option>
            <option value="ads">Ads only</option>
          </select>
        </div>
      </div>

      <div class="flex items-center gap-2 mt-4">
        <button @click="reload()" :disabled="loading"
                class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-4 py-2 rounded disabled:opacity-50">
          <span x-show="!loading">🔍 Apply</span>
          <span x-show="loading">Loading…</span>
        </button>
        <span class="text-xs text-gray-500" x-show="events.length > 0"
              x-text="events.length + ' event(s) across ' + Object.keys(byDay).length + ' day(s)'"></span>
      </div>
    </div>

    {{-- Event log grouped by day --}}
    <div class="bg-white rounded-lg shadow border border-gray-200 overflow-hidden">

      <template x-if="!loading && events.length === 0">
        <div class="empty-state">
          Walang change events sa selected date range.<br>
          <span class="text-[11px]">Try a different page or wider date range.</span>
        </div>
      </template>

      <template x-if="loading">
        <div class="empty-state">Loading change log…</div>
      </template>

      <template x-for="day in sortedDays()" :key="day">
        <div>
          <div class="day-header">
            <div>
              📅 <span x-text="fmtDay(day)"></span>
            </div>
            <div class="chips">
              <template x-if="byDay[day].turned_on > 0">
                <span class="day-chip" style="background:rgba(34,197,94,.25);"
                      x-text="'▶ ' + byDay[day].turned_on + ' on'"></span>
              </template>
              <template x-if="byDay[day].turned_off > 0">
                <span class="day-chip" style="background:rgba(239,68,68,.25);"
                      x-text="'■ ' + byDay[day].turned_off + ' off'"></span>
              </template>
              <template x-if="(byDay[day].created + byDay[day].created_with_spend) > 0">
                <span class="day-chip" style="background:rgba(59,130,246,.25);"
                      x-text="'🆕 ' + (byDay[day].created + byDay[day].created_with_spend) + ' new'"></span>
              </template>
            </div>
          </div>

          <div class="ev-row" style="background:#f5f6f7;font-weight:600;color:#65676b;font-size:10px;text-transform:uppercase;letter-spacing:0.05em;">
            <div>Event</div>
            <div>Level</div>
            <div>Entity</div>
            <div class="num">Spend</div>
            <div class="num">Prev spend</div>
          </div>

          <template x-for="e in eventsForDay(day)" :key="e.level + '-' + e.entity_id + '-' + day">
            <div class="ev-row">
              <div>
                <span :class="'ev-pill ' + e.event" x-text="eventLabel(e.event)"></span>
              </div>
              <div>
                <span :class="'level-pill ' + e.level" x-text="e.level"></span>
              </div>
              <div>
                <div class="font-medium text-gray-900" x-text="e.entity_name"></div>
                <div class="text-[11px] text-gray-500">
                  <span x-text="e.page_name"></span>
                  <template x-if="e.level === 'ad' && e.item_name">
                    <span> · <span x-text="e.item_name"></span></span>
                  </template>
                  <template x-if="e.level === 'adset' && e.campaign_name">
                    <span> · <span x-text="e.campaign_name"></span></span>
                  </template>
                  <template x-if="e.level === 'ad' && (e.campaign_name || e.ad_set_name)">
                    <span> · <span x-text="(e.campaign_name||'')+(e.ad_set_name?(' / '+e.ad_set_name):'')"></span></span>
                  </template>
                </div>
              </div>
              <div class="num" x-text="peso(e.spend)"></div>
              <div class="num text-gray-400" x-text="e.prev_spend === null ? '—' : peso(e.prev_spend)"></div>
            </div>
          </template>
        </div>
      </template>
    </div>
  </main>

  <script>
    function historyUI() {
      return {
        events: [],
        byDay: {},
        loading: false,
        filters: (function(){
          // Default: this calendar month (PH).
          const ph = new Date(new Date().toLocaleString('en-US', {timeZone:'Asia/Manila'}));
          const p = n => String(n).padStart(2,'0');
          const fmt = d => d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate());
          const start = new Date(ph.getFullYear(), ph.getMonth(), 1);
          return {
            start_date: fmt(start),
            end_date:   fmt(ph),
            page_name:  'all',
            level:      'all',
          };
        })(),

        sortedDays(){
          return Object.keys(this.byDay).sort((a, b) => b.localeCompare(a));
        },
        eventsForDay(day){
          return this.events.filter(e => e.day === day);
        },

        peso(v){ if (v == null || isNaN(Number(v))) return '—'; return '₱'+Number(v).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2}); },
        fmtDay(s){
          if (!s) return '';
          const d = new Date(s+'T00:00:00');
          if (isNaN(d.getTime())) return s;
          return d.toLocaleDateString('en-US', {weekday:'short', month:'short', day:'numeric', year:'numeric'});
        },
        eventLabel(ev){
          return ({
            'created':            'Created',
            'created_with_spend': 'Created (spending)',
            'turned_on':          'Turned ON',
            'turned_off':         'Turned OFF',
          })[ev] || ev;
        },

        async reload(){
          this.loading = true;
          try {
            const qs = new URLSearchParams({
              start_date: this.filters.start_date || '',
              end_date:   this.filters.end_date   || '',
              page_name:  this.filters.page_name  || 'all',
              level:      this.filters.level      || 'all',
            });
            const r = await fetch('{{ route('ads_manager.campaigns.history.data') }}?' + qs.toString());
            const j = await r.json();
            if (!j.ok) throw new Error(j.error || 'Failed');
            this.events = j.events || [];
            this.byDay  = j.by_day || {};
          } catch (e) {
            alert('Load failed: ' + e.message);
          } finally {
            this.loading = false;
          }
        },

        async init(){
          await this.reload();
        },
      };
    }
  </script>
</body>
</html>
