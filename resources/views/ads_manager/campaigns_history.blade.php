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
    /* 9-column layout (no more Action column — autosave handles persistence).
       Entity + Account narrowed since they're mostly short text — gives more
       room to the editable creative columns. */
    .ev-row { display:grid; grid-template-columns: 90px 70px minmax(140px,0.65fr) 135px minmax(110px,0.6fr) minmax(180px,1.2fr) minmax(200px,1.4fr) minmax(220px,1.5fr) 70px; gap:8px; align-items:stretch; padding:8px 12px; border-bottom:1px solid #e4e6eb; font-size:12px; }
    /* Compact key-value table for the lifetime metrics under Spend. Fixed
       2-col grid so labels at left, values at right, aligned even when "—". */
    .lt-metrics { display:grid; grid-template-columns: auto auto; column-gap:6px; row-gap:2px; margin-top:4px; padding-top:4px; border-top:1px dashed #cbd5e1; font-size:9px; line-height:1.4; color:#64748b; justify-content:end; }
    .lt-metrics .lt-k { text-align:right; font-weight:600; color:#475569; align-self:center; }
    /* Values are the focal data — bold, pure black, larger font para
       emphasized siya kumpara sa muted labels sa kaliwa. */
    .lt-metrics .lt-v { text-align:right; color:#000; font-weight:800; font-size:12px; min-width:60px; font-variant-numeric:tabular-nums; letter-spacing:-0.01em; }
    .lt-metrics .lt-v.empty { color:#cbd5e1; font-weight:400; font-size:9px; }
    /* Per-input save indicator badge, sits absolute at top-right of each input. */
    .crew-save-flag { position:absolute; top:2px; right:4px; font-size:9px; padding:1px 5px; border-radius:3px; pointer-events:none; opacity:0; transition:opacity 200ms ease; }
    .crew-save-flag.saving { background:#fef3c7; color:#92400e; opacity:1; }
    .crew-save-flag.saved  { background:#dcfce7; color:#166534; opacity:1; }
    .crew-save-flag.error  { background:#fee2e2; color:#991b1b; opacity:1; }
    .crew-cell { position:relative; }
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
    /* Body block — read-only, fills remaining height of its cell so the
       column total (Body + Headline) matches the height of Welcome Message
       and the Quick Replies + Ad Link stack on its right. */
    .body-block { font-size:10px; line-height:1.4; color:#475569; background:#f8fafc; border:1px dashed #cbd5e1; border-radius:4px; padding:4px 6px; overflow-y:auto; white-space:pre-wrap; word-wrap:break-word; flex:1 1 auto; min-height:0; }
    .body-block.empty { color:#cbd5e1; font-style:italic; border-style:dotted; }
    .body-block-label { font-size:9px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:2px; }
    /* Wrapper for Body + Headline column to enable flex stretch. */
    .head-col { display:flex; flex-direction:column; gap:4px; height:100%; }
    .head-col .crew-input { flex:0 0 auto; }
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
      <div class="grid grid-cols-1 md:grid-cols-5 gap-3 items-end">
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
        <div>
          <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Event</label>
          <select x-model="filters.event"
                  class="w-full border border-gray-300 rounded px-3 py-2 text-sm bg-white">
            <option value="all">All events</option>
            <option value="turned_on">Turned ON only</option>
            <option value="turned_off">Turned OFF only</option>
            <option value="created_with_spend">Created (with spend) only</option>
            <option value="on_off">Turned ON + OFF (skip Created)</option>
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
            <div class="num">Spend</div>
            <div>Account</div>
            <div>Body / Headline</div>
            <div>Welcome message</div>
            <div>Quick replies + ad link</div>
            <div class="num">Prev</div>
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

                {{-- ── ASSIGNMENT TAGGER ─────────────────────────────────────
                     Campaign-level rows: editable dropdown + history button
                     Ad set / ad rows: read-only "via campaign: X" inherit display
                --}}
                <template x-if="e.level === 'campaign' && e.entity_id">
                  <div class="assignment-row" style="margin-top:6px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                    <span style="font-size:11px;color:#65676b;">👤 Created by:</span>
                    <select :value="assignments[e.entity_id]?.employee_id || ''"
                            @change="onAssignmentChange(e.entity_id, $event.target.value)"
                            :disabled="!canWriteAssignment"
                            style="font-size:11.5px;padding:3px 6px;border:1px solid #cbd5e1;border-radius:4px;background:white;min-width:180px;cursor:pointer;">
                      <option value="">— Unassigned —</option>
                      <template x-for="emp in employees" :key="emp.id">
                        <option :value="emp.id" x-text="emp.name + ' (' + (emp.role || '—') + ')'"></option>
                      </template>
                    </select>

                    <template x-if="assignFlag[e.entity_id] === 'saving'">
                      <span style="font-size:11px;color:#65676b;">⏳ saving…</span>
                    </template>
                    <template x-if="assignFlag[e.entity_id] === 'saved'">
                      <span style="font-size:11px;color:#15803d;font-weight:600;">✓ saved</span>
                    </template>
                    <template x-if="assignFlag[e.entity_id] === 'error'">
                      <span style="font-size:11px;color:#dc2626;font-weight:600;" :title="assignError[e.entity_id] || ''">⚠ failed</span>
                    </template>

                    <button type="button" @click="openHistoryModal(e.entity_id, e.entity_name)"
                            style="font-size:11px;color:#1877f2;background:transparent;border:none;cursor:pointer;padding:2px 6px;border-radius:3px;"
                            onmouseover="this.style.background='#eef2ff';"
                            onmouseout="this.style.background='transparent';"
                            title="View assignment history">📜 history</button>

                    <template x-if="assignments[e.entity_id]?.note">
                      <span style="font-size:11px;color:#65676b;font-style:italic;" :title="assignments[e.entity_id]?.note">💬 has note</span>
                    </template>

                    <template x-if="assignments[e.entity_id]?.updated_at">
                      <span style="font-size:10.5px;color:#94a3b8;" :title="'Last updated: ' + (assignments[e.entity_id]?.updated_at || '')">
                        · updated <span x-text="fmtRelative(assignments[e.entity_id]?.updated_at)"></span>
                      </span>
                    </template>
                  </div>
                </template>

                {{-- Adset / Ad — inherit display (read-only) --}}
                <template x-if="(e.level === 'adset' || e.level === 'ad') && e.campaign_id && assignments[e.campaign_id]?.employee_id">
                  <div style="margin-top:4px;font-size:11px;color:#94a3b8;font-style:italic;">
                    👤 via campaign: <span x-text="assignments[e.campaign_id]?.employee_name || '(deleted)'"></span>
                    <template x-if="assignments[e.campaign_id]?.employee_role">
                      <span> · <span x-text="assignments[e.campaign_id]?.employee_role"></span></span>
                    </template>
                  </div>
                </template>
              </div>
              {{-- Spend cell — lifetime cumulative spend (same basis as
                   CPM/CPP/WMR/Conv below it), pure raw FB amount_spent_php
                   na walang VAT math. --}}
              <div class="num">
                <div x-text="peso(e.lifetime_spend)"></div>
                <div class="lt-metrics">
                  <div class="lt-k" title="Lifetime CPM = Cost per Messaging (spend / messages started)">CPM</div>
                  <div :class="'lt-v' + (e.lifetime_cpm == null ? ' empty' : '')"
                       x-text="e.lifetime_cpm == null ? '—' : ('₱'+Number(e.lifetime_cpm).toFixed(2))"></div>
                  <div class="lt-k" title="Lifetime Cost per Purchase">CPP</div>
                  <div :class="'lt-v' + (e.lifetime_cpp == null ? ' empty' : '')"
                       x-text="e.lifetime_cpp == null ? '—' : ('₱'+Number(e.lifetime_cpp).toFixed(2))"></div>
                  <div class="lt-k" title="Lifetime Welcome Message Rate (msgs / link_clicks)">WMR</div>
                  <div :class="'lt-v' + (e.lifetime_wmr == null ? ' empty' : '')"
                       x-text="e.lifetime_wmr == null ? '—' : (Number(e.lifetime_wmr).toFixed(1)+'%')"></div>
                  <div class="lt-k" title="Lifetime Conversion Rate (purchases / msgs)">Conv</div>
                  <div :class="'lt-v' + (e.lifetime_conv == null ? ' empty' : '')"
                       x-text="e.lifetime_conv == null ? '—' : (Number(e.lifetime_conv).toFixed(1)+'%')"></div>
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
              {{-- Body (top, read-only) + Headline (bottom, autosave on turned_on).
                   Column uses flex column so Body grows to fill remaining height,
                   matching the Welcome Message column and the QR+ad link stack. --}}
              <div class="head-col">
                <div class="body-block-label">Body</div>
                <div :class="'body-block' + (e.body ? '' : ' empty')"
                     :title="e.body || ''"
                     x-text="e.body || '(no body)'"></div>
                <div class="crew-cell">
                  <input type="text" class="crew-input"
                         :class="{ readonly: !isEditable(e) }"
                         :value="e.headline"
                         :disabled="!isEditable(e) || !e.creative_id"
                         @input="onFieldInput(e, 'headline', $event.target.value)"
                         @blur="onFieldBlur(e, 'headline', $event.target.value)"
                         placeholder="Headline">
                  <span :class="'crew-save-flag ' + (e._flag?.headline || '')"
                        x-text="flagText(e._flag?.headline)"></span>
                </div>
              </div>
              {{-- Welcome message --}}
              <div class="crew-cell">
                <textarea class="crew-input"
                          :class="{ readonly: !isEditable(e) }"
                          rows="3"
                          :disabled="!isEditable(e) || !e.creative_id"
                          @input="onFieldInput(e, 'welcome_message', $event.target.value)"
                          @blur="onFieldBlur(e, 'welcome_message', $event.target.value)"
                          placeholder="(empty)"
                          x-text="e.welcome_message"></textarea>
                <span :class="'crew-save-flag ' + (e._flag?.welcome_message || '')"
                      x-text="flagText(e._flag?.welcome_message)"></span>
              </div>
              {{-- Quick replies + ad link --}}
              <div class="crew-stack">
                <div class="crew-cell">
                  <input type="text" class="crew-input"
                         :class="{ readonly: !isEditable(e) }"
                         :value="e.quick_reply_1"
                         :disabled="!isEditable(e) || !e.creative_id"
                         @input="onFieldInput(e, 'quick_reply_1', $event.target.value)"
                         @blur="onFieldBlur(e, 'quick_reply_1', $event.target.value)"
                         placeholder="QR 1">
                  <span :class="'crew-save-flag ' + (e._flag?.quick_reply_1 || '')"
                        x-text="flagText(e._flag?.quick_reply_1)"></span>
                </div>
                <div class="crew-cell">
                  <input type="text" class="crew-input"
                         :class="{ readonly: !isEditable(e) }"
                         :value="e.quick_reply_2"
                         :disabled="!isEditable(e) || !e.creative_id"
                         @input="onFieldInput(e, 'quick_reply_2', $event.target.value)"
                         @blur="onFieldBlur(e, 'quick_reply_2', $event.target.value)"
                         placeholder="QR 2">
                  <span :class="'crew-save-flag ' + (e._flag?.quick_reply_2 || '')"
                        x-text="flagText(e._flag?.quick_reply_2)"></span>
                </div>
                <div class="crew-cell">
                  <input type="text" class="crew-input"
                         :class="{ readonly: !isEditable(e) }"
                         :value="e.quick_reply_3"
                         :disabled="!isEditable(e) || !e.creative_id"
                         @input="onFieldInput(e, 'quick_reply_3', $event.target.value)"
                         @blur="onFieldBlur(e, 'quick_reply_3', $event.target.value)"
                         placeholder="QR 3">
                  <span :class="'crew-save-flag ' + (e._flag?.quick_reply_3 || '')"
                        x-text="flagText(e._flag?.quick_reply_3)"></span>
                </div>
                <div class="crew-cell">
                  <input type="url" class="crew-input"
                         :class="{ readonly: !isEditable(e) }"
                         :value="e.ad_link"
                         :disabled="!isEditable(e) || !e.creative_id"
                         @input="onFieldInput(e, 'ad_link', $event.target.value)"
                         @blur="onFieldBlur(e, 'ad_link', $event.target.value)"
                         placeholder="https://… (ad link)">
                  <span :class="'crew-save-flag ' + (e._flag?.ad_link || '')"
                        x-text="flagText(e._flag?.ad_link)"></span>
                </div>
              </div>
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

        // ── ASSIGNMENT TAGGER STATE ─────────────────────────────────────
        // Map: campaign_id → { employee_id, employee_name, employee_role, note, updated_at }
        assignments: {},
        // List of allowed employees for dropdown options
        employees: [],
        // Per-campaign save flags: 'saving' | 'saved' | 'error'
        assignFlag: {},
        assignError: {},
        // Write permission flag — set from server config (mirrors backend role check).
        // Computed sa $canWriteAssignment variable above (PHP scope).
        canWriteAssignment: @json($canWriteAssignment ?? false),
        // History modal state
        historyModal: {
          open: false,
          loading: false,
          campaign_id: null,
          campaign_name: '',
          entries: [],
          error: null,
        },
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
            event:      q.get('event')      || 'all',
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
            set('event',      this.filters.event);
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
              event:      this.filters.event      || 'all',
            });
            const r = await fetch('{{ route('ads_manager.campaigns.history.data') }}?' + qs.toString());
            const j = await r.json();
            if (!j.ok) throw new Error(j.error || 'Failed');
            // Initialize per-event mutable state for inline edits.
            // _flag tracks per-field save status: '' | 'saving' | 'saved' | 'error'
            // _debounce tracks per-event-per-field setTimeout handles for debounced
            // saves. We deliberately don't store _saving as a row-level lock —
            // each field saves independently.
            this.events = (j.events || []).map(e => Object.assign({}, e, {
              _flag: {}, _debounce: {},
            }));
            this.byDay  = j.by_day || {};
            // After events load, fetch assignments for all visible campaign_ids
            // Async — doesn't block the events render
            this.loadAssignmentsForVisible();
          } catch (e) {
            alert('Load failed: ' + e.message);
          } finally {
            this.loading = false;
          }
        },

        // Inline edit helpers — autosave model.
        // Only "turned_on" events are editable. turned_off + created_with_spend
        // are read-only — those don't represent a fresh creative state, just
        // transitions on existing creatives.
        isEditable(e){
          return e && e.event === 'turned_on';
        },
        flagText(state){
          return state === 'saving' ? '…' : state === 'saved' ? '✓' : state === 'error' ? '!' : '';
        },
        // Debounced autosave on input — fires the save 600ms after the user
        // stops typing. Mirrors edit-messaging-template's debounce behavior.
        onFieldInput(e, field, value){
          if (!this.isEditable(e) || !e.creative_id) return;
          if (!e._debounce) e._debounce = {};
          if (e._debounce[field]) clearTimeout(e._debounce[field]);
          e._debounce[field] = setTimeout(() => {
            this.saveField(e, field, value);
          }, 600);
        },
        // Immediate save on blur — flush any pending debounced timer first.
        onFieldBlur(e, field, value){
          if (!this.isEditable(e) || !e.creative_id) return;
          if (e._debounce && e._debounce[field]) {
            clearTimeout(e._debounce[field]);
            e._debounce[field] = null;
          }
          // Skip blur-save if value hasn't changed from original.
          if (String(value ?? '') === String(e[field] ?? '')) return;
          this.saveField(e, field, value);
        },
        async saveField(e, field, value){
          if (!e.creative_id) return;
          // Skip if value unchanged (debounce can fire while editing back to original).
          if (String(value ?? '') === String(e[field] ?? '')) return;
          if (!e._flag) e._flag = {};
          e._flag[field] = 'saving';
          try {
            const body = new FormData();
            body.append('_method', 'PUT');
            body.append(field, value ?? '');
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
            e[field]      = value;     // commit local source-of-truth
            e._flag[field] = 'saved';
            // Auto-fade the saved badge after 1.5s
            setTimeout(() => {
              if (e._flag && e._flag[field] === 'saved') e._flag[field] = '';
            }, 1500);
          } catch (err) {
            console.error('autosave failed', field, err);
            e._flag[field] = 'error';
            setTimeout(() => {
              if (e._flag && e._flag[field] === 'error') e._flag[field] = '';
            }, 3000);
          }
        },

        async init(){
          // Load employee dropdown list in parallel with first events fetch.
          this.loadEmployees(); // fire-and-forget — dropdown populates async
          await this.reload();
        },

        // ── ASSIGNMENT TAGGER METHODS ────────────────────────────────────

        // Fetch employees once on page load. Cached para sa session.
        async loadEmployees(){
          try {
            const r = await fetch('{{ route('ads_manager.employees') }}', { headers: { 'Accept': 'application/json' } });
            const j = await r.json();
            if (j.ok) this.employees = j.employees || [];
          } catch (e) {
            console.warn('Failed to load employees:', e);
          }
        },

        // Bulk fetch current assignments for all visible campaign IDs.
        // Called automatically after reload() (events loaded) — see _afterReload below.
        async loadAssignmentsForVisible(){
          // Collect unique campaign_ids from events (campaign-level OR via parent).
          const ids = new Set();
          for (const e of this.events) {
            if (e.campaign_id) ids.add(String(e.campaign_id));
          }
          if (ids.size === 0) { this.assignments = {}; return; }

          const idsArr = Array.from(ids);
          // Batch in chunks of 200 to avoid massive URLs
          const chunkSize = 200;
          const out = {};
          for (let i = 0; i < idsArr.length; i += chunkSize) {
            const chunk = idsArr.slice(i, i + chunkSize);
            try {
              const url = '{{ route('ads_manager.campaigns.assignments.list') }}?campaign_ids=' + encodeURIComponent(chunk.join(','));
              const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
              const j = await r.json();
              if (j.ok && j.assignments) {
                Object.assign(out, j.assignments);
              }
            } catch (e) {
              console.warn('Assignment fetch failed:', e);
            }
          }
          this.assignments = out;
        },

        // Triggered on dropdown change.
        async onAssignmentChange(campaignId, employeeIdRaw){
          if (!this.canWriteAssignment) return;
          const employeeId = employeeIdRaw ? parseInt(employeeIdRaw, 10) : null;
          this.assignFlag[campaignId] = 'saving';
          this.assignError[campaignId] = null;
          try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const r = await fetch('{{ route('ads_manager.campaigns.assignments.save') }}', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'Accept':       'application/json',
                'X-CSRF-TOKEN': csrf,
              },
              body: JSON.stringify({
                campaign_id: campaignId,
                employee_id: employeeId,
                note: null,
              }),
            });
            const j = await r.json();
            if (!r.ok || !j.ok) {
              this.assignFlag[campaignId] = 'error';
              this.assignError[campaignId] = j.message || ('HTTP ' + r.status);
              return;
            }
            // Update local state with server's response (canonical)
            this.assignments[campaignId] = j.assignment;
            this.assignFlag[campaignId] = 'saved';
            // Auto-clear "saved" badge after 2 seconds
            setTimeout(() => {
              if (this.assignFlag[campaignId] === 'saved') this.assignFlag[campaignId] = '';
            }, 2000);
          } catch (e) {
            this.assignFlag[campaignId] = 'error';
            this.assignError[campaignId] = e.message;
          }
        },

        // History modal
        async openHistoryModal(campaignId, campaignName){
          this.historyModal = {
            open: true, loading: true, error: null,
            campaign_id: campaignId, campaign_name: campaignName || '',
            entries: [],
          };
          try {
            const url = '{{ route('ads_manager.campaigns.assignments.history') }}?campaign_id=' + encodeURIComponent(campaignId);
            const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
            const j = await r.json();
            if (!j.ok) {
              this.historyModal.error = j.message || 'Fetch failed';
            } else {
              this.historyModal.entries = j.history || [];
            }
          } catch (e) {
            this.historyModal.error = 'Network: ' + e.message;
          } finally {
            this.historyModal.loading = false;
          }
        },

        closeHistoryModal(){
          this.historyModal.open = false;
        },

        // Relative time helper — "5 min ago", "2 hours ago"
        fmtRelative(dt){
          if (!dt) return '';
          const d = new Date(dt);
          if (isNaN(d.getTime())) return dt;
          const now = new Date();
          const diff = Math.floor((now - d) / 1000); // seconds
          if (diff < 60) return 'just now';
          if (diff < 3600) return Math.floor(diff / 60) + ' min ago';
          if (diff < 86400) return Math.floor(diff / 3600) + ' hr ago';
          if (diff < 86400 * 7) return Math.floor(diff / 86400) + ' days ago';
          return d.toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'});
        },
      };
    }

    // After reload() completes, fetch assignments. Hooked via Alpine's $watch in DOM.
  </script>

  {{-- ── ASSIGNMENT HISTORY MODAL ─────────────────────────────────────── --}}
  <template x-teleport="body">
  <div x-show="historyModal.open"
       x-transition.opacity
       @click.self="closeHistoryModal()"
       style="position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;">
    <div style="background:white;border-radius:10px;max-width:600px;width:100%;max-height:85vh;overflow:hidden;display:flex;flex-direction:column;">
      <div style="padding:14px 18px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">
        <div style="flex:1;min-width:0;">
          <div style="font-size:10.5px;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;font-weight:700;">📜 Assignment History</div>
          <div style="font-size:14px;font-weight:700;color:#0f172a;margin-top:3px;word-break:break-word;" x-text="historyModal.campaign_name || historyModal.campaign_id"></div>
          <div style="font-size:11px;color:#94a3b8;margin-top:2px;font-family:ui-monospace,monospace;" x-text="historyModal.campaign_id"></div>
        </div>
        <button @click="closeHistoryModal()"
                style="background:transparent;border:none;font-size:18px;color:#64748b;cursor:pointer;padding:4px 8px;">✕</button>
      </div>

      <div style="overflow-y:auto;flex:1;padding:14px 18px;">
        <template x-if="historyModal.loading">
          <div style="text-align:center;color:#65676b;padding:30px;font-size:13px;">⏳ Loading history…</div>
        </template>
        <template x-if="historyModal.error">
          <div style="background:#fef2f2;color:#991b1b;padding:10px;border-radius:6px;font-size:12px;" x-text="'⚠ ' + historyModal.error"></div>
        </template>
        <template x-if="!historyModal.loading && !historyModal.error && historyModal.entries.length === 0">
          <div style="text-align:center;color:#94a3b8;padding:30px;font-size:12px;font-style:italic;">No changes recorded yet.</div>
        </template>
        <template x-if="!historyModal.loading && !historyModal.error && historyModal.entries.length > 0">
          <div style="display:flex;flex-direction:column;gap:8px;">
            <template x-for="entry in historyModal.entries" :key="entry.id">
              <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px;">
                <div style="font-size:12.5px;color:#0f172a;line-height:1.4;">
                  <template x-if="!entry.old_employee_name && entry.new_employee_name">
                    <span><strong style="color:#15803d;">Assigned</strong> to <strong x-text="entry.new_employee_name"></strong>
                      <span style="color:#65676b;font-size:11px;" x-text="entry.new_employee_role ? ('(' + entry.new_employee_role + ')') : ''"></span>
                    </span>
                  </template>
                  <template x-if="entry.old_employee_name && entry.new_employee_name">
                    <span><strong style="color:#1d4ed8;">Reassigned</strong> from <strong x-text="entry.old_employee_name"></strong>
                      to <strong x-text="entry.new_employee_name"></strong>
                      <span style="color:#65676b;font-size:11px;" x-text="entry.new_employee_role ? ('(' + entry.new_employee_role + ')') : ''"></span>
                    </span>
                  </template>
                  <template x-if="entry.old_employee_name && !entry.new_employee_name">
                    <span><strong style="color:#dc2626;">Unassigned</strong> from <strong x-text="entry.old_employee_name"></strong></span>
                  </template>
                </div>
                <template x-if="entry.note">
                  <div style="font-size:11.5px;color:#475569;margin-top:4px;font-style:italic;">💬 <span x-text="entry.note"></span></div>
                </template>
                <div style="font-size:10.5px;color:#94a3b8;margin-top:4px;">
                  by <strong style="color:#64748b;" x-text="entry.changed_by_name || '(unknown)'"></strong>
                  · <span x-text="entry.created_at"></span>
                </div>
              </div>
            </template>
          </div>
        </template>
      </div>

      <div style="padding:10px 18px;border-top:1px solid #e2e8f0;text-align:right;">
        <button @click="closeHistoryModal()"
                style="background:#e2e8f0;color:#475569;font-size:12px;padding:6px 14px;border-radius:6px;border:none;cursor:pointer;font-weight:600;">Close</button>
      </div>
    </div>
  </div>
  </template>
</body>
</html>
