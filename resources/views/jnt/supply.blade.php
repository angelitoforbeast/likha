<x-layout>
  <x-slot name="title">Supply</x-slot>
  <x-slot name="heading">Supply Planner</x-slot>

  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">

  <style>
    .dt-paging .paginate_button,
    .dataTables_paginate .paginate_button {
      padding:.2rem .65rem; margin:0 2px; border-radius:.375rem;
      background:#374151 !important; color:#fff !important; border:none !important;
      cursor:pointer; font-size:12px; display:inline-block;
    }
    .dataTables_paginate .paginate_button.current  { background:#2563eb !important; font-weight:700; }
    .dataTables_paginate .paginate_button.disabled { opacity:.4; cursor:default; }
    .dataTables_wrapper .dataTables_filter { display:none; }
    .dataTables_wrapper .dataTables_info   { color:#6b7280; font-size:12px; }
    .strength-badge  { display:inline-block; padding:2px 8px; border-radius:9999px; font-size:11px; font-weight:700; }
    .lifecycle-badge { display:inline-block; padding:2px 8px; border-radius:9999px; font-size:11px; font-weight:600; cursor:pointer; white-space:nowrap; }
    .recommended-cell { font-weight:800; font-size:15px; }
    .inline-num { width:60px; border:1px solid #d1d5db; border-radius:4px; padding:2px 6px;
                  font-size:12px; text-align:center; background:white; }
    .inline-num:focus { outline:none; border-color:#2563eb; }
    .save-toast { position:fixed; bottom:24px; right:24px; background:#16a34a; color:white;
                  padding:8px 18px; border-radius:8px; font-size:13px; font-weight:600;
                  display:none; z-index:9999; box-shadow:0 4px 12px rgba(0,0,0,.15); }
    /* Lifecycle override select (hidden by default, shown on badge click) */
    .lifecycle-sel { font-size:11px; border:1px solid #d1d5db; border-radius:4px; padding:2px 4px;
                     background:white; display:none; }
  </style>

  <div class="mx-auto px-4 py-4" style="max-width:100%;">

    {{-- Filter bar --}}
    <form method="GET" action="{{ url('/jnt/supply') }}" id="supplyForm"
          class="bg-white rounded-lg shadow border border-gray-200 p-4 mb-4">
      <div class="flex flex-wrap items-end gap-3">

        <div>
          <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Search Item</label>
          <input name="q" value="{{ $q }}" type="text" placeholder="item name…"
                 class="border border-gray-300 rounded-md px-3 py-1.5 text-sm w-48">
        </div>

        <div>
          <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Velocity Period (days)</label>
          <input name="velocity_days" value="{{ $velocityDays }}" type="number" min="1" max="365"
                 class="border border-gray-300 rounded-md px-3 py-1.5 text-sm w-28">
        </div>

        <div>
          <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Lifecycle Window (days)</label>
          <input name="recent_days" value="{{ $recentDays }}" type="number" min="1" max="180"
                 class="border border-gray-300 rounded-md px-3 py-1.5 text-sm w-28">
        </div>

        <div>
          <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Lifecycle Filter</label>
          <select name="lifecycle_filter"
                  class="border border-gray-300 rounded-md px-3 py-1.5 text-sm bg-white">
            <option value=""          {{ $lifecycleFilter === ''            ? 'selected' : '' }}>All</option>
            <option value="new"       {{ $lifecycleFilter === 'new'         ? 'selected' : '' }}>🆕 New</option>
            <option value="scaling"   {{ $lifecycleFilter === 'scaling'     ? 'selected' : '' }}>📈 Scaling</option>
            <option value="consistent"{{ $lifecycleFilter === 'consistent'  ? 'selected' : '' }}>✅ Consistent</option>
            <option value="active"    {{ $lifecycleFilter === 'active'      ? 'selected' : '' }}>🔄 Active</option>
            <option value="declining" {{ $lifecycleFilter === 'declining'   ? 'selected' : '' }}>📉 Declining</option>
            <option value="phasing_out"{{ $lifecycleFilter==='phasing_out'  ? 'selected' : '' }}>🚫 Phasing Out</option>
            <option value="dormant"   {{ $lifecycleFilter === 'dormant'     ? 'selected' : '' }}>💤 Dormant</option>
          </select>
        </div>

        <div>
          <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Running Threshold (u/day)</label>
          <input name="running_threshold" value="{{ $runningThreshold }}" type="number" min="0" step="0.1"
                 class="border border-gray-300 rounded-md px-3 py-1.5 text-sm w-32">
        </div>

        <div>
          <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Default Lead Time (days)</label>
          <input name="default_lead_time" value="{{ $defaultLeadTime }}" type="number" min="1" max="365"
                 class="border border-gray-300 rounded-md px-3 py-1.5 text-sm w-32">
        </div>

        <div>
          <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Default Safety Buffer (days)</label>
          <input name="default_safety_days" value="{{ $defaultSafetyDays }}" type="number" min="0" max="365"
                 class="border border-gray-300 rounded-md px-3 py-1.5 text-sm w-32">
        </div>

        <div>
          <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">As Of Date</label>
          <input name="as_of_date" value="{{ $asOfDate }}" type="date"
                 class="border border-gray-300 rounded-md px-3 py-1.5 text-sm">
        </div>

        {{-- Hidden lifecycle params (keep user values on apply) --}}
        <input type="hidden" name="new_item_days"     value="{{ $newItemDays }}">
        <input type="hidden" name="long_running_days" value="{{ $longRunningDays }}">
        <input type="hidden" name="scale_threshold"   value="{{ $scaleThreshold }}">
        <input type="hidden" name="decline_threshold" value="{{ $declineThreshold }}">

        <button type="submit"
                class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-4 py-2 rounded-md shadow">
          Apply
        </button>
        <a href="{{ url('/jnt/supply') }}"
           class="text-sm px-4 py-2 rounded-md border border-gray-300 hover:bg-gray-50 text-gray-700">
          Reset
        </a>
      </div>

      {{-- Lifecycle legend --}}
      <div class="mt-3 flex flex-wrap gap-2 text-xs text-gray-500">
        <span class="font-semibold">Lifecycle:</span>
        <span class="lifecycle-badge bg-blue-100 text-blue-800">🆕 New</span>
        <span class="lifecycle-badge bg-green-100 text-green-800">📈 Scaling</span>
        <span class="lifecycle-badge bg-teal-100 text-teal-800">✅ Consistent</span>
        <span class="lifecycle-badge bg-slate-100 text-slate-700">🔄 Active</span>
        <span class="lifecycle-badge bg-orange-100 text-orange-800">📉 Declining</span>
        <span class="lifecycle-badge bg-red-100 text-red-800">🚫 Phasing Out</span>
        <span class="lifecycle-badge bg-gray-100 text-gray-500">💤 Dormant</span>
        <span class="text-gray-400 ml-2">(Click badge to override · window: {{ $recentDays }}d vs prev {{ $recentDays }}d)</span>
      </div>
    </form>

    {{-- Summary cards --}}
    <div class="grid grid-cols-3 gap-4 mb-4">
      <div class="bg-white rounded-lg shadow border border-gray-200 p-4 text-center">
        <div class="text-2xl font-bold text-gray-800">{{ number_format($itemsWithHolds) }}</div>
        <div class="text-xs text-gray-500 uppercase tracking-wide mt-1">Items with Holds</div>
      </div>
      <div class="bg-white rounded-lg shadow border border-gray-200 p-4 text-center">
        <div class="text-2xl font-bold text-blue-600">{{ number_format($totalHoldUnits) }}</div>
        <div class="text-xs text-gray-500 uppercase tracking-wide mt-1">Total Hold Units</div>
      </div>
      <div class="bg-white rounded-lg shadow border border-gray-200 p-4 text-center">
        <div class="text-2xl font-bold text-green-600">{{ number_format($totalRecommended) }}</div>
        <div class="text-xs text-gray-500 uppercase tracking-wide mt-1">Total Recommended Order</div>
      </div>
    </div>

    {{-- Table --}}
    @if(count($items) > 0)
    <div class="bg-white rounded-lg shadow border border-gray-200 overflow-auto">
      <table id="supplyTable" class="min-w-full text-sm border-collapse">
        <thead>
          <tr style="background:#f1f5f9; border-bottom:2px solid #cbd5e1;">
            <th class="px-3 py-2 text-left   text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap border border-gray-200">Item</th>
            <th class="px-3 py-2 text-right  text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap border border-gray-200">Hold Units</th>
            <th class="px-3 py-2 text-right  text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap border border-gray-200">Velocity (u/day)</th>
            <th class="px-3 py-2 text-center text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap border border-gray-200">Strength</th>
            <th class="px-3 py-2 text-center text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap border border-gray-200">Lifecycle</th>
            <th class="px-3 py-2 text-center text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap border border-gray-200">Running?</th>
            <th class="px-3 py-2 text-right  text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap border border-gray-200">RTS%</th>
            <th class="px-3 py-2 text-center text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap border border-gray-200">Lead Time (d)</th>
            <th class="px-3 py-2 text-center text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap border border-gray-200">Safety Buffer (d)</th>
            <th class="px-3 py-2 text-right  text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap border border-gray-200"
                style="background:#dbeafe; color:#1d4ed8;">Recommended Order</th>
          </tr>
        </thead>
        <tbody>
          @foreach($items as $row)
          <tr class="hover:bg-blue-50 transition-colors border-b border-gray-100"
              data-item="{{ $row['item'] }}"
              data-vel="{{ $row['vel_per_day'] }}"
              data-running="{{ $row['is_running'] ? '1' : '0' }}"
              data-running-auto="{{ $row['is_running_auto'] ? '1' : '0' }}"
              data-rts="{{ $row['rts_pct'] }}"
              data-delivery="{{ $row['delivery_rate'] }}"
              data-holds="{{ $row['hold_units'] }}"
              data-lead="{{ $row['lead_time_days'] }}"
              data-safety="{{ $row['safety_days'] }}"
              data-threshold="{{ $runningThreshold }}"
              data-lifecycle="{{ $row['lifecycle'] }}"
              data-lifecycle-auto="{{ $row['lifecycle_auto'] ? '1' : '0' }}"
              data-days-running="{{ $row['days_running'] }}"
              data-recent-vel="{{ $row['recent_vel'] }}"
              data-prev-vel="{{ $row['prev_vel'] }}">

            {{-- Item name --}}
            <td class="px-3 py-2 border border-gray-200 font-medium text-gray-800 whitespace-nowrap">
              {{ $row['item'] }}
            </td>

            {{-- Hold units --}}
            <td class="px-3 py-2 border border-gray-200 text-right font-semibold
                        {{ $row['hold_units'] > 0 ? 'text-blue-700' : 'text-gray-400' }}"
                data-order="{{ $row['hold_units'] }}">
              {{ $row['hold_units'] > 0 ? number_format($row['hold_units']) : '—' }}
            </td>

            {{-- Velocity --}}
            <td class="px-3 py-2 border border-gray-200 text-right text-gray-700"
                data-order="{{ $row['vel_per_day'] }}">
              {{ $row['vel_per_day'] > 0 ? number_format($row['vel_per_day'], 1) : '—' }}
            </td>

            {{-- Strength badge --}}
            <td class="px-3 py-2 border border-gray-200 text-center" data-order="{{ $row['vel_per_day'] }}">
              <span class="strength-badge {{ $row['strength_class'] }}">{{ $row['strength_label'] }}</span>
            </td>

            {{-- Lifecycle badge + inline override --}}
            <td class="px-3 py-2 border border-gray-200 text-center" data-order="{{ array_search($row['lifecycle'], ['dormant','active','consistent','declining','new','phasing_out','scaling']) }}">
              <span class="lifecycle-badge {{ $row['lifecycle_badge'] }}"
                    title="Recent: {{ $row['recent_vel'] }} u/d · Prev: {{ $row['prev_vel'] }} u/d · Running {{ $row['days_running'] }}d{{ $row['lifecycle_auto'] ? ' · auto' : ' · manual override' }}">
                {{ $row['lifecycle_label'] }}
                @if(!$row['lifecycle_auto'])<sup title="Manual override" class="text-[9px] opacity-60">★</sup>@endif
              </span>
              <select class="lifecycle-sel mt-1"
                      title="Override lifecycle for {{ $row['item'] }}">
                <option value="">auto</option>
                <option value="new"          {{ $row['lifecycle'] === 'new'          && !$row['lifecycle_auto'] ? 'selected' : '' }}>🆕 New</option>
                <option value="scaling"      {{ $row['lifecycle'] === 'scaling'      && !$row['lifecycle_auto'] ? 'selected' : '' }}>📈 Scaling</option>
                <option value="consistent"   {{ $row['lifecycle'] === 'consistent'   && !$row['lifecycle_auto'] ? 'selected' : '' }}>✅ Consistent</option>
                <option value="active"       {{ $row['lifecycle'] === 'active'       && !$row['lifecycle_auto'] ? 'selected' : '' }}>🔄 Active</option>
                <option value="declining"    {{ $row['lifecycle'] === 'declining'    && !$row['lifecycle_auto'] ? 'selected' : '' }}>📉 Declining</option>
                <option value="phasing_out"  {{ $row['lifecycle'] === 'phasing_out'  && !$row['lifecycle_auto'] ? 'selected' : '' }}>🚫 Phasing Out</option>
                <option value="dormant"      {{ $row['lifecycle'] === 'dormant'      && !$row['lifecycle_auto'] ? 'selected' : '' }}>💤 Dormant</option>
              </select>
            </td>

            {{-- Running? checkbox --}}
            <td class="px-3 py-2 border border-gray-200 text-center">
              <input type="checkbox" class="running-chk w-4 h-4 cursor-pointer"
                     {{ $row['is_running'] ? 'checked' : '' }}
                     title="{{ $row['is_running_auto'] ? 'Auto-detected' : 'Manually set' }}">
              @if($row['is_running_auto'])
                <span class="text-gray-400 text-xs ml-1">auto</span>
              @endif
            </td>

            {{-- RTS% --}}
            <td class="px-3 py-2 border border-gray-200 text-right text-gray-700">
              {{ $row['rts_pct'] > 0 ? $row['rts_pct'].'%' : '—' }}
            </td>

            {{-- Lead time (inline edit) --}}
            <td class="px-3 py-2 border border-gray-200 text-center">
              <input type="number" class="inline-num lead-input" min="1" max="365"
                     value="{{ $row['lead_time_days'] }}"
                     title="Lead time days for {{ $row['item'] }}">
            </td>

            {{-- Safety buffer (inline edit) --}}
            <td class="px-3 py-2 border border-gray-200 text-center">
              <input type="number" class="inline-num safety-input" min="0" max="365"
                     value="{{ $row['safety_days'] }}"
                     title="Safety buffer days for {{ $row['item'] }}">
            </td>

            {{-- Recommended qty (computed live) --}}
            <td class="px-3 py-2 border border-gray-200 text-right recommended-cell"
                data-order="{{ $row['recommended'] }}"
                style="background:#eff6ff; color:#1d4ed8;">
              <span class="rec-val">{{ number_format($row['recommended']) }}</span>
            </td>

          </tr>
          @endforeach
        </tbody>
        <tfoot>
          <tr style="background:#f8fafc; border-top:2px solid #94a3b8; font-weight:700;">
            <td class="px-3 py-2 border border-gray-300 text-xs text-gray-500 uppercase">TOTAL</td>
            <td class="px-3 py-2 border border-gray-300 text-right text-blue-700">{{ number_format($totalHoldUnits) }}</td>
            <td class="px-3 py-2 border border-gray-300" colspan="7"></td>
            <td class="px-3 py-2 border border-gray-300 text-right" style="color:#1d4ed8;">
              {{ number_format($totalRecommended) }}
            </td>
          </tr>
        </tfoot>
      </table>
    </div>
    @else
    <div class="bg-white rounded-lg shadow border border-gray-200 p-8 text-center text-gray-500">
      No items found. All items may be fully shipped, or try adjusting the filters.
    </div>
    @endif

  </div>{{-- end container --}}

  {{-- Save toast --}}
  <div class="save-toast" id="saveToast">✓ Saved</div>

  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
  <script>
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    // Lifecycle badge info map (for live-updating badge after override)
    const LIFECYCLE_META = {
      'new':         { label: '🆕 New',        cls: 'bg-blue-100 text-blue-800' },
      'scaling':     { label: '📈 Scaling',     cls: 'bg-green-100 text-green-800' },
      'consistent':  { label: '✅ Consistent',  cls: 'bg-teal-100 text-teal-800' },
      'active':      { label: '🔄 Active',      cls: 'bg-slate-100 text-slate-700' },
      'declining':   { label: '📉 Declining',   cls: 'bg-orange-100 text-orange-800' },
      'phasing_out': { label: '🚫 Phasing Out', cls: 'bg-red-100 text-red-800' },
      'dormant':     { label: '💤 Dormant',     cls: 'bg-gray-100 text-gray-500' },
    };
    const ALL_LIFECYCLE_CLS = Object.values(LIFECYCLE_META).map(m => m.cls.split(' ')).flat();

    // ---- DataTable init ----
    document.addEventListener('DOMContentLoaded', function () {
      const tableEl = document.getElementById('supplyTable');
      if (!tableEl) return;

      $('#supplyTable').DataTable({
        paging:    true,
        searching: false,   // external search via form
        ordering:  true,
        info:      true,
        dom:       'rtip',
        order:     [[9, 'desc']],  // Recommended Order col (now col index 9)
        pageLength: 50,
      });
    });

    // ---- Recompute recommended qty for a row ----
    function recompute(row) {
      const vel        = parseFloat(row.dataset.vel)     || 0;
      const holdUnits  = parseInt(row.dataset.holds)     || 0;
      const rtsPct     = parseFloat(row.dataset.rts)     || 0;
      const delivRate  = Math.max(0.01, 1 - rtsPct / 100);
      const running    = row.dataset.running === '1';
      const lead       = parseInt(row.querySelector('.lead-input').value)   || 7;
      const safety     = parseInt(row.querySelector('.safety-input').value) || 0;

      const holdsGross = holdUnits > 0 ? Math.ceil(holdUnits / delivRate) : 0;
      let recommended  = holdsGross;

      if (running && vel > 0) {
        const leadDemand  = Math.ceil(vel * lead   / delivRate);
        const safetyStock = Math.ceil(vel * safety / delivRate);
        recommended = holdsGross + leadDemand + safetyStock;
      }

      const recSpan = row.querySelector('.rec-val');
      if (recSpan) recSpan.textContent = recommended.toLocaleString('en-PH');
      // Update data-order for sorting
      const recCell = row.querySelector('td[style*="#eff6ff"]');
      if (recCell) recCell.dataset.order = recommended;
    }

    // ---- Save settings via AJAX ----
    let toastTimer;
    function showToast() {
      const t = document.getElementById('saveToast');
      t.style.display = 'block';
      clearTimeout(toastTimer);
      toastTimer = setTimeout(() => t.style.display = 'none', 2000);
    }

    function saveRow(row, extra = {}) {
      const itemName  = row.dataset.item;
      const lead      = parseInt(row.querySelector('.lead-input').value)   || 7;
      const safety    = parseInt(row.querySelector('.safety-input').value) || 0;
      const isRunning = row.dataset.running;
      const isAuto    = row.dataset.runningAuto === '1';
      const runVal    = isAuto ? 'auto' : isRunning;

      fetch('/jnt/supply/settings', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': CSRF,
          'Accept': 'application/json',
        },
        body: JSON.stringify({
          item_name:      itemName,
          lead_time_days: lead,
          safety_days:    safety,
          is_running:     runVal,
          ...extra,
        }),
      })
      .then(r => r.json())
      .then(d => { if (d.success) showToast(); })
      .catch(console.error);
    }

    // ---- Event listeners (delegated) ----
    document.addEventListener('change', function (e) {
      const row = e.target.closest('tr[data-item]');
      if (!row) return;

      // Running checkbox
      if (e.target.classList.contains('running-chk')) {
        row.dataset.running     = e.target.checked ? '1' : '0';
        row.dataset.runningAuto = '0';
        const autoLabel = row.querySelector('td .text-gray-400.text-xs');
        if (autoLabel) autoLabel.remove();
        recompute(row);
        saveRow(row);
      }

      // Lifecycle override select
      if (e.target.classList.contains('lifecycle-sel')) {
        const val    = e.target.value;   // '' = auto, or a lifecycle key
        const badge  = e.target.closest('td').querySelector('.lifecycle-badge');

        if (val === '') {
          // Clear override — reload to get server-computed value
          saveRow(row, { lifecycle_override: '' });
          setTimeout(() => location.reload(), 400);
        } else {
          const meta = LIFECYCLE_META[val];
          if (meta && badge) {
            // Update badge visually
            badge.classList.remove(...ALL_LIFECYCLE_CLS);
            meta.cls.split(' ').forEach(c => badge.classList.add(c));
            badge.childNodes[0].textContent = meta.label + ' ';
            // Add or update manual star marker
            let star = badge.querySelector('sup');
            if (!star) {
              star = document.createElement('sup');
              star.title = 'Manual override';
              star.className = 'text-[9px] opacity-60';
              badge.appendChild(star);
            }
            star.textContent = '★';
          }
          row.dataset.lifecycle     = val;
          row.dataset.lifecycleAuto = '0';
          e.target.style.display    = 'none';
          if (badge) badge.style.display = '';
          saveRow(row, { lifecycle_override: val });
        }
      }
    });

    document.addEventListener('blur', function (e) {
      const row = e.target.closest('tr[data-item]');
      if (!row) return;

      if (e.target.classList.contains('lead-input') || e.target.classList.contains('safety-input')) {
        recompute(row);
        saveRow(row);
      }
    }, true);

    // ---- Badge click → show/hide override select ----
    document.addEventListener('click', function (e) {
      if (e.target.classList.contains('lifecycle-badge')) {
        const td  = e.target.closest('td');
        if (!td) return;
        const sel = td.querySelector('.lifecycle-sel');
        if (!sel) return;

        const showing = sel.style.display === 'block' || sel.style.display === '';
        // Hide all open selects first
        document.querySelectorAll('.lifecycle-sel').forEach(s => s.style.display = 'none');

        if (!showing) {
          sel.style.display = 'block';
          e.target.style.display = 'none';
        } else {
          e.target.style.display = '';
        }
      }

      // Clicking elsewhere closes selects
      if (!e.target.classList.contains('lifecycle-badge') && !e.target.classList.contains('lifecycle-sel')) {
        document.querySelectorAll('.lifecycle-sel').forEach(s => {
          if (s.style.display !== 'none') {
            const badge = s.closest('td').querySelector('.lifecycle-badge');
            if (badge) badge.style.display = '';
            s.style.display = 'none';
          }
        });
      }
    });
  </script>
</x-layout>
