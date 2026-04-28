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
    /* Single-row event layout — full viewport width, no horizontal scroll
       (handled by removing the max-w-7xl wrapper sa main). Columns:
       Event, Level, Entity, Headline, Welcome msg, Quick replies + ad link,
       Spend, Prev, Save. */
    .ev-row { display:grid; grid-template-columns: 90px 70px minmax(170px,1fr) minmax(120px,0.8fr) minmax(150px,1fr) minmax(180px,1.3fr) minmax(200px,1.4fr) 80px 80px 80px; gap:8px; align-items:stretch; padding:8px 12px; border-bottom:1px solid #e4e6eb; font-size:12px; }
    /* Welcome Message textarea fills its cell height so it lines up with
       the QR1+QR2+QR3+Ad Link stack on its right. */
    .ev-row textarea.crew-input { height:100%; min-height:120px; }
    .ev-row:hover { background:#fafbfd; }
    .crew-input { width:100%; border:1px solid #d4d6db; border-radius:4px; padding:4px 6px; font-size:11px; line-height:1.35; min-height:28px; resize:vertical; font-family:inherit; box-sizing:border-box; }
    .crew-input:focus { outline:2px solid #3b82f6; outline-offset:-1px; border-color:#3b82f6; }
    .crew-input.dirty { border-color:#f59e0b; background:#fffbeb; }
    .crew-input.saved { border-color:#10b981; background:#f0fdf4; transition:background 600ms ease; }
    .crew-input:disabled { background:#f5f6f7; color:#374151; cursor:default; opacity:.85; }
    .crew-input.readonly { background:#f9fafb; color:#374151; cursor:default; }
    .crew-stack > * + * { margin-top:4px; }
    .crew-btn { font-size:10px; padding:4px 8px; border-radius:4px; border:1px solid #d4d6db; background:white; cursor:pointer; font-weight:600; }
    .crew-btn.save { background:#2563eb; color:white; border-color:#2563eb; }
    .crew-btn.save:hover:not(:disabled) { background:#1d4ed8; }
    .crew-btn.save:disabled { opacity:.4; cursor:not-allowed; }
    .crew-feedback-toggle { display:inline-flex; align-items:center; gap:4px; font-size:10px; cursor:pointer; user-select:none; }
    .ev-actions { display:flex; flex-direction:column; gap:4px; align-items:stretch; }
    /* Body block — read-only, clamped to 4 lines with hover-to-expand tooltip. */
    .body-block { font-size:10px; line-height:1.4; color:#475569; background:#f8fafc; border:1px dashed #cbd5e1; border-radius:4px; padding:4px 6px; margin-top:4px; max-height:5.5em; overflow:hidden; position:relative; white-space:pre-wrap; word-wrap:break-word; }
    .body-block.empty { color:#cbd5e1; font-style:italic; border-style:dotted; }
    .body-block-label { font-size:9px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:2px; }
    .num { text-align:right; font-variant-numeric:tabular-nums; }
    /* "Read-only" hint for non-turned_on events */
    .ev-readonly-badge { display:inline-block; font-size:9px; padding:1px 5px; border-radius:3px; background:#e5e7eb; color:#6b7280; font-weight:600; letter-spacing:0.03em; }
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

  <main class="px-4 py-4 space-y-4" style="max-width:none;">

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

          <div class="ev-row" style="background:#f5f6f7;font-weight:600;color:#65676b;font-size:10px;text-transform:uppercase;letter-spacing:0.05em;border-bottom:1px solid #d4d6db;">
            <div>Event</div>
            <div>Level</div>
            <div>Entity</div>
            <div>Account</div>
            <div>Headline</div>
            <div>Welcome message</div>
            <div>Quick replies + ad link</div>
            <div class="num">Spend</div>
            <div class="num">Prev</div>
            <div>Action</div>
          </div>

          <template x-for="(e, eIdx) in eventsForDay(day)" :key="e.level + '-' + e.entity_id + '-' + day + '-' + eIdx">
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
                  <template x-if="e.creative_id">
                    <span class="text-blue-600"> · creative #<span x-text="e.creative_id"></span></span>
                  </template>
                  <template x-if="!e.creative_id">
                    <span class="text-gray-400"> · no creative linked</span>
                  </template>
                  <template x-if="!isEditable(e)">
                    <div class="mt-1"><span class="ev-readonly-badge">READ-ONLY · only Turned ON is editable</span></div>
                  </template>
                </div>
              </div>
              {{-- Account (dedicated column) --}}
              <div>
                <template x-if="e.account_id && e.account_name">
                  <div>
                    <div style="font-weight:500;color:#0f172a;font-size:11px;line-height:1.3;" x-text="e.account_name"></div>
                    <div style="font-size:10px;color:#94a3b8;font-family:monospace;margin-top:2px;" x-text="e.account_id"></div>
                  </div>
                </template>
                <template x-if="e.account_id && !e.account_name">
                  <div title="Hindi pa naka-register sa /ads_manager/ad_account">
                    <div style="color:#dc2626;font-weight:600;font-size:11px;">⚠ unmapped</div>
                    <div style="font-size:10px;color:#94a3b8;font-family:monospace;margin-top:2px;" x-text="e.account_id"></div>
                  </div>
                </template>
                <template x-if="!e.account_id">
                  <span style="color:#cbd5e1;font-size:11px;">—</span>
                </template>
              </div>
              {{-- Headline + Body (body is read-only, mirrors edit-messaging-template) --}}
              <div>
                <input type="text" class="crew-input"
                       :class="{ dirty: e._dirty?.headline, saved: e._saved?.headline, readonly: !isEditable(e) }"
                       :value="e.headline"
                       :disabled="!isEditable(e) || !e.creative_id"
                       @input="markDirty(e, 'headline', $event.target.value)"
                       placeholder="(empty)">
                <div class="body-block-label" style="margin-top:6px;">Body</div>
                <div :class="'body-block' + (e.body ? '' : ' empty')"
                     :title="e.body || ''"
                     x-text="e.body || '(no body)'"></div>
              </div>
              {{-- Welcome message --}}
              <div>
                <textarea class="crew-input"
                          :class="{ dirty: e._dirty?.welcome_message, saved: e._saved?.welcome_message, readonly: !isEditable(e) }"
                          rows="3"
                          :disabled="!isEditable(e) || !e.creative_id"
                          @input="markDirty(e, 'welcome_message', $event.target.value)"
                          placeholder="(empty)"
                          x-text="e.welcome_message"></textarea>
              </div>
              {{-- Quick replies + ad link --}}
              <div class="crew-stack">
                <input type="text" class="crew-input"
                       :class="{ dirty: e._dirty?.quick_reply_1, saved: e._saved?.quick_reply_1, readonly: !isEditable(e) }"
                       :value="e.quick_reply_1"
                       :disabled="!isEditable(e) || !e.creative_id"
                       @input="markDirty(e, 'quick_reply_1', $event.target.value)"
                       placeholder="QR 1">
                <input type="text" class="crew-input"
                       :class="{ dirty: e._dirty?.quick_reply_2, saved: e._saved?.quick_reply_2, readonly: !isEditable(e) }"
                       :value="e.quick_reply_2"
                       :disabled="!isEditable(e) || !e.creative_id"
                       @input="markDirty(e, 'quick_reply_2', $event.target.value)"
                       placeholder="QR 2">
                <input type="text" class="crew-input"
                       :class="{ dirty: e._dirty?.quick_reply_3, saved: e._saved?.quick_reply_3, readonly: !isEditable(e) }"
                       :value="e.quick_reply_3"
                       :disabled="!isEditable(e) || !e.creative_id"
                       @input="markDirty(e, 'quick_reply_3', $event.target.value)"
                       placeholder="QR 3">
                <input type="url" class="crew-input"
                       :class="{ dirty: e._dirty?.ad_link, saved: e._saved?.ad_link, readonly: !isEditable(e) }"
                       :value="e.ad_link"
                       :disabled="!isEditable(e) || !e.creative_id"
                       @input="markDirty(e, 'ad_link', $event.target.value)"
                       placeholder="https://… (ad link)">
              </div>
              <div class="num" x-text="peso(e.spend)"></div>
              <div class="num text-gray-400" x-text="e.prev_spend === null ? '—' : peso(e.prev_spend)"></div>
              <div class="ev-actions">
                <button type="button" class="crew-btn save"
                        :disabled="!isEditable(e) || !e.creative_id || !hasDirty(e) || e._saving"
                        @click="saveCreative(e)"
                        x-text="e._saving ? 'Saving…' : 'Save'"></button>
                <label class="crew-feedback-toggle">
                  <input type="checkbox"
                         :checked="!!e.feedback"
                         :disabled="!isEditable(e) || !e.creative_id"
                         @change="markDirty(e, 'feedback', $event.target.checked ? 1 : 0)">
                  Feedback
                </label>
              </div>
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
          // Default: this calendar month (PH), level=campaigns. Reads URL
          // query params first so a refresh/share preserves the user's state.
          const ph = new Date(new Date().toLocaleString('en-US', {timeZone:'Asia/Manila'}));
          const p = n => String(n).padStart(2,'0');
          const fmt = d => d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate());
          const start = new Date(ph.getFullYear(), ph.getMonth(), 1);
          const url = new URL(window.location.href);
          const q   = url.searchParams;
          return {
            start_date: q.get('start_date') || fmt(start),
            end_date:   q.get('end_date')   || fmt(ph),
            page_name:  q.get('page_name')  || 'all',
            level:      q.get('level')      || 'campaigns',
          };
        })(),

        // Sync current filters into the URL (replaceState — no history entry,
        // just so refresh/share preserves state). Called on every reload().
        _syncUrl(){
          try {
            const url = new URL(window.location.href);
            const set = (k, v) => {
              if (v === null || v === '' || v === undefined) url.searchParams.delete(k);
              else url.searchParams.set(k, v);
            };
            set('start_date', this.filters.start_date);
            set('end_date',   this.filters.end_date);
            set('page_name',  this.filters.page_name);
            set('level',      this.filters.level);
            window.history.replaceState({}, '', url.toString());
          } catch (e) { /* ignore — non-fatal */ }
        },

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
          // Mirror the active filters into the browser URL so refresh keeps state.
          this._syncUrl();
          try {
            const qs = new URLSearchParams({
              start_date: this.filters.start_date || '',
              end_date:   this.filters.end_date   || '',
              page_name:  this.filters.page_name  || 'all',
              level:      this.filters.level      || 'campaigns',
            });
            const r = await fetch('{{ route('ads_manager.campaigns.history.data') }}?' + qs.toString());
            const j = await r.json();
            if (!j.ok) throw new Error(j.error || 'Failed');
            // Initialize per-event mutable state for inline edits.
            this.events = (j.events || []).map(e => Object.assign({}, e, {
              _dirty: {}, _saved: {}, _saving: false, _draft: {},
            }));
            this.byDay  = j.by_day || {};
          } catch (e) {
            alert('Load failed: ' + e.message);
          } finally {
            this.loading = false;
          }
        },

        // Inline edit helpers
        // Only "turned_on" events are editable. turned_off + created_with_spend
        // are read-only — those don't represent a fresh creative state, just
        // transitions on existing creatives.
        isEditable(e){
          return e && e.event === 'turned_on';
        },
        markDirty(e, field, value){
          // Compare with original event field — if same as original, clear dirty.
          const original = e[field] ?? '';
          const changed = String(value ?? '') !== String(original ?? '');
          if (!e._dirty) e._dirty = {};
          if (!e._draft) e._draft = {};
          e._draft[field] = value;
          e._dirty[field] = changed;
          // Clear "saved" indicator on edit
          if (e._saved) e._saved[field] = false;
        },
        hasDirty(e){
          if (!e._dirty) return false;
          return Object.values(e._dirty).some(v => v === true);
        },
        async saveCreative(e){
          if (!e.creative_id) { alert('Walang creative na naka-link sa event na ito.'); return; }
          if (!this.hasDirty(e)) return;
          e._saving = true;
          try {
            const fields = ['headline','welcome_message','quick_reply_1','quick_reply_2','quick_reply_3','ad_link','feedback'];
            const body = new FormData();
            // Laravel needs _method=PUT for spoofing on FormData.
            body.append('_method', 'PUT');
            for (const f of fields) {
              if (e._dirty?.[f]) {
                let v = e._draft?.[f];
                if (f === 'feedback') v = (v ? 1 : 0);
                body.append(f, v ?? '');
              }
            }
            const url = '/ads-manager/edit-messaging-template/' + e.creative_id;
            const r = await fetch(url, {
              method: 'POST',
              headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
              },
              body,
            });
            const j = await r.json().catch(() => ({}));
            if (!r.ok || j.ok === false) throw new Error(j.message || ('HTTP ' + r.status));
            // Commit drafts to source-of-truth fields, mark _saved (for green flash),
            // clear _dirty.
            if (!e._saved) e._saved = {};
            for (const f of fields) {
              if (e._dirty?.[f]) {
                e[f] = e._draft[f];
                e._saved[f] = true;
                e._dirty[f] = false;
              }
            }
            // After 1.5s, clear the green flash
            setTimeout(() => {
              for (const f of fields) if (e._saved && e._saved[f]) e._saved[f] = false;
            }, 1500);
          } catch (err) {
            console.error(err);
            alert('Save failed: ' + err.message);
          } finally {
            e._saving = false;
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
