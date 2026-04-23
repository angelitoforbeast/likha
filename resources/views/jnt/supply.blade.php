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
    .class-badge     { display:inline-block; padding:2px 10px; border-radius:9999px; font-size:12px; font-weight:800; cursor:pointer; letter-spacing:.04em; }
    .recommended-cell { font-weight:800; font-size:15px; }
    .inline-num { width:60px; border:1px solid #d1d5db; border-radius:4px; padding:2px 6px;
                  font-size:12px; text-align:center; background:white; }
    .inline-num:focus { outline:none; border-color:#2563eb; }
    .save-toast { position:fixed; bottom:24px; right:24px; background:#16a34a; color:white;
                  padding:8px 18px; border-radius:8px; font-size:13px; font-weight:600;
                  display:none; z-index:9999; box-shadow:0 4px 12px rgba(0,0,0,.15); }
    .lifecycle-sel, .class-sel {
      font-size:11px; border:1px solid #d1d5db; border-radius:4px;
      padding:2px 4px; background:white; display:none; margin-top:2px;
    }
  </style>

  <div class="mx-auto px-4 py-4" style="max-width:100%;">

    {{-- ================================================================== --}}
    {{-- Filter bar                                                          --}}
    {{-- ================================================================== --}}
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
          <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Class Filter</label>
          <select name="class_filter" class="border border-gray-300 rounded-md px-3 py-1.5 text-sm bg-white">
            <option value=""  {{ $classFilter === ''  ? 'selected' : '' }}>All Classes</option>
            <option value="A" {{ $classFilter === 'A' ? 'selected' : '' }}>A · Hero</option>
            <option value="B" {{ $classFilter === 'B' ? 'selected' : '' }}>B · Solid</option>
            <option value="C" {{ $classFilter === 'C' ? 'selected' : '' }}>C · Average</option>
            <option value="D" {{ $classFilter === 'D' ? 'selected' : '' }}>D · New Item</option>
            <option value="E" {{ $classFilter === 'E' ? 'selected' : '' }}>E · Clearing</option>
            <option value="F" {{ $classFilter === 'F' ? 'selected' : '' }}>F · Off / Hold-only</option>
          </select>
        </div>

        <div>
          <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Lifecycle Filter</label>
          <select name="lifecycle_filter" class="border border-gray-300 rounded-md px-3 py-1.5 text-sm bg-white">
            <option value=""           {{ $lifecycleFilter === ''            ? 'selected' : '' }}>All</option>
            <option value="new"        {{ $lifecycleFilter === 'new'         ? 'selected' : '' }}>🆕 New</option>
            <option value="scaling"    {{ $lifecycleFilter === 'scaling'     ? 'selected' : '' }}>📈 Scaling</option>
            <option value="consistent" {{ $lifecycleFilter === 'consistent'  ? 'selected' : '' }}>✅ Consistent</option>
            <option value="active"     {{ $lifecycleFilter === 'active'      ? 'selected' : '' }}>🔄 Active</option>
            <option value="declining"  {{ $lifecycleFilter === 'declining'   ? 'selected' : '' }}>📉 Declining</option>
            <option value="phasing_out"{{ $lifecycleFilter === 'phasing_out' ? 'selected' : '' }}>🚫 Phasing Out</option>
            <option value="dormant"    {{ $lifecycleFilter === 'dormant'     ? 'selected' : '' }}>💤 Dormant</option>
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

        {{-- Hidden lifecycle params --}}
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

      {{-- Legend row --}}
      <div class="mt-3 flex flex-wrap gap-3 text-xs text-gray-500 items-center">
        <span class="font-semibold text-gray-600">Class:</span>
        <span class="class-badge bg-purple-600 text-white" style="cursor:default;">A · Hero</span>
        <span class="class-badge bg-blue-500 text-white"   style="cursor:default;">B · Solid</span>
        <span class="class-badge bg-yellow-400 text-gray-900" style="cursor:default;">C · Average</span>
        <span class="class-badge bg-orange-400 text-white" style="cursor:default;">D · New Item</span>
        <span class="class-badge bg-pink-500 text-white"   style="cursor:default;">E · Clearing</span>
        <span class="class-badge bg-gray-700 text-white"   style="cursor:default;">F · Off / Hold-only</span>
        <span class="text-gray-300 mx-1">|</span>
        <span class="font-semibold text-gray-600">Lifecycle:</span>
        <span class="lifecycle-badge bg-blue-100 text-blue-800"   style="cursor:default;">🆕 New</span>
        <span class="lifecycle-badge bg-green-100 text-green-800" style="cursor:default;">📈 Scaling</span>
        <span class="lifecycle-badge bg-teal-100 text-teal-800"   style="cursor:default;">✅ Consistent</span>
        <span class="lifecycle-badge bg-slate-100 text-slate-700" style="cursor:default;">🔄 Active</span>
        <span class="lifecycle-badge bg-orange-100 text-orange-800" style="cursor:default;">📉 Declining</span>
        <span class="lifecycle-badge bg-red-100 text-red-800"     style="cursor:default;">🚫 Phasing Out</span>
        <span class="lifecycle-badge bg-gray-100 text-gray-500"   style="cursor:default;">💤 Dormant</span>
        <span class="text-gray-400 ml-1">(Click any badge on a row to override)</span>
      </div>
    </form>

    {{-- ================================================================== --}}
    {{-- CEO-only: Class threshold editor                                    --}}
    {{-- ================================================================== --}}
    @if($isCeo)
    <details class="bg-white rounded-lg shadow border border-indigo-200 mb-4">
      <summary class="px-4 py-3 font-semibold text-sm text-indigo-700 cursor-pointer select-none flex items-center gap-2">
        <i class="fa-solid fa-sliders text-indigo-500"></i>
        Class Velocity Thresholds
        <span class="text-xs font-normal text-gray-400 ml-1">CEO only — click to expand</span>
      </summary>
      <div class="px-4 pb-4 pt-2">
        <p class="text-xs text-gray-500 mb-3">
          An item's auto-class is the <strong>highest class</strong> whose threshold ≤ the item's velocity (u/day).
          Declining items drop 1 tier, Phasing Out / Dormant items drop 2 tiers.
          Changes apply on next <em>Apply</em>.
        </p>
        <table class="text-sm border-collapse">
          <thead>
            <tr class="bg-gray-50">
              <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase border border-gray-200 w-20">Class</th>
              <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase border border-gray-200">Label</th>
              <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 uppercase border border-gray-200">Min Velocity (u/day)</th>
              <th class="px-3 py-2 border border-gray-200 w-16"></th>
            </tr>
          </thead>
          <tbody>
            @foreach($classThresholdsFull as $ct)
            @php $badgeCls = $classBadgeMap[$ct->class_key] ?? 'bg-gray-200 text-gray-600'; @endphp
            <tr class="border-b border-gray-100">
              <td class="px-3 py-2 border border-gray-200">
                <span class="class-badge {{ $badgeCls }}" style="cursor:default;">{{ $ct->class_key }}</span>
              </td>
              <td class="px-3 py-2 border border-gray-200 text-gray-700 font-medium">{{ $ct->label }}</td>
              <td class="px-3 py-2 border border-gray-200">
                @if(in_array($ct->class_key, ['D','E','F'], true))
                  <input type="number" class="inline-num" value="{{ $ct->min_velocity }}" readonly
                         title="Class {{ $ct->class_key }} is rule-based — see Supply Rules panel"
                         style="opacity:.5; cursor:not-allowed; width:80px;">
                @else
                  <input type="number" class="inline-num threshold-input" min="0" step="0.001"
                         value="{{ $ct->min_velocity }}" data-class="{{ $ct->class_key }}" style="width:80px;">
                @endif
              </td>
              <td class="px-3 py-2 border border-gray-200 text-center">
                @if(!in_array($ct->class_key, ['D','E','F'], true))
                  <button type="button"
                          class="save-threshold-btn text-xs bg-indigo-600 hover:bg-indigo-700 text-white px-2 py-1 rounded"
                          data-class="{{ $ct->class_key }}">
                    Save
                  </button>
                @else
                  <span class="text-[10px] text-gray-400">rule-based</span>
                @endif
              </td>
            </tr>
            @endforeach
          </tbody>
        </table>
        <p class="text-xs text-gray-400 mt-2">
          <strong>A / B / C</strong> are velocity-based. <strong>D</strong> (New Item), <strong>E</strong> (Clearing, manual only), and <strong>F</strong> (Off / Hold-only) are rule-based — edit the rules in the Supply Rules panel below.
        </p>
      </div>
    </details>

    <details class="bg-white rounded-lg shadow border border-emerald-200 mb-4">
      <summary class="px-4 py-3 font-semibold text-sm text-emerald-700 cursor-pointer select-none flex items-center gap-2">
        <i class="fa-solid fa-gears text-emerald-500"></i>
        Supply Rules & Thresholds
        <span class="text-xs font-normal text-gray-400 ml-1">CEO only — click to expand</span>
      </summary>
      <div class="px-4 pb-4 pt-2">
        <p class="text-xs text-gray-500 mb-3">
          Rule-based knobs for <strong>Class D / F</strong> detection, lifecycle windows, and velocity defaults.
          An item only graduates out of Class D when <strong>BOTH</strong> its age ≥ <em>New item days</em>
          AND velocity ≥ <em>Graduation velocity</em>.
        </p>
        @php
          $grouped = $supplySettingsAll->groupBy('group');
          $groupLabels = [
            'class_d'   => 'Class D — New Item rules',
            'class_f'   => 'Class F — Off / Hold-only rules',
            'lifecycle' => 'Lifecycle comparison',
            'velocity'  => 'Velocity defaults',
            'general'   => 'General',
          ];
        @endphp
        @foreach($grouped as $groupKey => $rows)
          <div class="mb-3">
            <div class="text-xs font-bold text-emerald-700 uppercase tracking-wide mb-1">
              {{ $groupLabels[$groupKey] ?? ucfirst($groupKey) }}
            </div>
            <table class="text-sm border-collapse w-full">
              <thead>
                <tr class="bg-gray-50">
                  <th class="px-3 py-1 text-left text-xs font-semibold text-gray-500 uppercase border border-gray-200">Setting</th>
                  <th class="px-3 py-1 text-left text-xs font-semibold text-gray-500 uppercase border border-gray-200 w-32">Value</th>
                  <th class="px-3 py-1 text-left text-xs font-semibold text-gray-500 uppercase border border-gray-200 w-20">Type</th>
                  <th class="px-3 py-1 border border-gray-200 w-16"></th>
                </tr>
              </thead>
              <tbody>
                @foreach($rows as $s)
                <tr class="border-b border-gray-100">
                  <td class="px-3 py-1.5 border border-gray-200 text-gray-700">
                    {{ $s->label }}
                    <code class="ml-1 text-[10px] text-gray-400">{{ $s->key }}</code>
                  </td>
                  <td class="px-3 py-1.5 border border-gray-200">
                    <input type="number"
                           class="inline-num supply-kv-input"
                           style="width:100px;"
                           step="{{ $s->data_type === 'int' ? '1' : '0.001' }}"
                           min="0"
                           data-key="{{ $s->key }}"
                           value="{{ $s->value }}">
                  </td>
                  <td class="px-3 py-1.5 border border-gray-200 text-xs text-gray-500">
                    {{ $s->data_type }}
                  </td>
                  <td class="px-3 py-1.5 border border-gray-200 text-center">
                    <button type="button"
                            class="save-kv-btn text-xs bg-emerald-600 hover:bg-emerald-700 text-white px-2 py-1 rounded"
                            data-key="{{ $s->key }}">
                      Save
                    </button>
                  </td>
                </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        @endforeach
        <p class="text-xs text-gray-400 mt-2">
          Saved changes apply on next <em>Apply</em> (or page reload).
        </p>
      </div>
    </details>
    @endif

    {{-- ================================================================== --}}
    {{-- Summary cards                                                       --}}
    {{-- ================================================================== --}}
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

    {{-- ================================================================== --}}
    {{-- Table                                                               --}}
    {{-- ================================================================== --}}
    @if(count($items) > 0)
    <div class="bg-white rounded-lg shadow border border-gray-200 overflow-auto">
      <table id="supplyTable" class="min-w-full text-sm border-collapse">
        <thead>
          <tr style="background:#f1f5f9; border-bottom:2px solid #cbd5e1;">
            <th class="px-3 py-2 text-left   text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap border border-gray-200">Item</th>
            <th class="px-3 py-2 text-right  text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap border border-gray-200">Hold Units</th>
            <th class="px-3 py-2 text-right  text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap border border-gray-200">Velocity (u/day)</th>
            <th class="px-3 py-2 text-center text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap border border-gray-200">Strength</th>
            <th class="px-3 py-2 text-center text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap border border-gray-200"
                style="background:#ede9fe; color:#6d28d9;">Class</th>
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
              data-class="{{ $row['item_class'] }}"
              data-class-auto="{{ $row['item_class_auto'] ? '1' : '0' }}">

            {{-- Item --}}
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

            {{-- Strength --}}
            <td class="px-3 py-2 border border-gray-200 text-center" data-order="{{ $row['vel_per_day'] }}">
              <span class="strength-badge {{ $row['strength_class'] }}">{{ $row['strength_label'] }}</span>
            </td>

            {{-- Class badge + override --}}
            <td class="px-3 py-2 border border-gray-200 text-center" style="background:#faf5ff;"
                data-order="{{ $row['item_class'] }}">
              <span class="class-badge {{ $row['item_class_badge'] }}"
                    title="Velocity: {{ $row['vel_per_day'] }} u/d · Lifecycle: {{ $row['lifecycle'] }}{{ $row['item_class_auto'] ? ' · auto' : ' · manual override' }}">
                {{ $row['item_class_label'] }}@if(!$row['item_class_auto'])<sup title="Manual override" class="text-[9px] opacity-70">★</sup>@endif
              </span>
              <select class="class-sel" title="Override class for {{ $row['item'] }}">
                <option value="">auto</option>
                <option value="A" {{ $row['item_class'] === 'A' && !$row['item_class_auto'] ? 'selected' : '' }}>A · Hero</option>
                <option value="B" {{ $row['item_class'] === 'B' && !$row['item_class_auto'] ? 'selected' : '' }}>B · Solid</option>
                <option value="C" {{ $row['item_class'] === 'C' && !$row['item_class_auto'] ? 'selected' : '' }}>C · Average</option>
                <option value="D" {{ $row['item_class'] === 'D' && !$row['item_class_auto'] ? 'selected' : '' }}>D · New Item</option>
                <option value="E" {{ $row['item_class'] === 'E' && !$row['item_class_auto'] ? 'selected' : '' }}>E · Clearing</option>
                <option value="F" {{ $row['item_class'] === 'F' && !$row['item_class_auto'] ? 'selected' : '' }}>F · Off / Hold-only</option>
              </select>
            </td>

            {{-- Lifecycle badge + override --}}
            <td class="px-3 py-2 border border-gray-200 text-center"
                data-order="{{ $row['lifecycle'] }}">
              <span class="lifecycle-badge {{ $row['lifecycle_badge'] }}"
                    title="Recent: {{ $row['recent_vel'] }} u/d · Prev: {{ $row['prev_vel'] }} u/d · Running {{ $row['days_running'] }}d{{ $row['lifecycle_auto'] ? ' · auto' : ' · manual override' }}">
                {{ $row['lifecycle_label'] }}@if(!$row['lifecycle_auto'])<sup title="Manual override" class="text-[9px] opacity-60">★</sup>@endif
              </span>
              <select class="lifecycle-sel" title="Override lifecycle for {{ $row['item'] }}">
                <option value="">auto</option>
                <option value="new"         {{ $row['lifecycle'] === 'new'         && !$row['lifecycle_auto'] ? 'selected' : '' }}>🆕 New</option>
                <option value="scaling"     {{ $row['lifecycle'] === 'scaling'     && !$row['lifecycle_auto'] ? 'selected' : '' }}>📈 Scaling</option>
                <option value="consistent"  {{ $row['lifecycle'] === 'consistent'  && !$row['lifecycle_auto'] ? 'selected' : '' }}>✅ Consistent</option>
                <option value="active"      {{ $row['lifecycle'] === 'active'      && !$row['lifecycle_auto'] ? 'selected' : '' }}>🔄 Active</option>
                <option value="declining"   {{ $row['lifecycle'] === 'declining'   && !$row['lifecycle_auto'] ? 'selected' : '' }}>📉 Declining</option>
                <option value="phasing_out" {{ $row['lifecycle'] === 'phasing_out' && !$row['lifecycle_auto'] ? 'selected' : '' }}>🚫 Phasing Out</option>
                <option value="dormant"     {{ $row['lifecycle'] === 'dormant'     && !$row['lifecycle_auto'] ? 'selected' : '' }}>💤 Dormant</option>
              </select>
            </td>

            {{-- Running? --}}
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

            {{-- Lead time --}}
            <td class="px-3 py-2 border border-gray-200 text-center">
              <input type="number" class="inline-num lead-input" min="1" max="365"
                     value="{{ $row['lead_time_days'] }}" title="Lead time for {{ $row['item'] }}">
            </td>

            {{-- Safety buffer --}}
            <td class="px-3 py-2 border border-gray-200 text-center">
              <input type="number" class="inline-num safety-input" min="0" max="365"
                     value="{{ $row['safety_days'] }}" title="Safety buffer for {{ $row['item'] }}">
            </td>

            {{-- Recommended --}}
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
            <td class="px-3 py-2 border border-gray-300" colspan="8"></td>
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

  </div>

  {{-- Save toast --}}
  <div class="save-toast" id="saveToast">✓ Saved</div>

  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
  <script>
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    // ---- Lifecycle metadata ----
    const LIFECYCLE_META = {
      'new':         { label: '🆕 New',        cls: 'bg-blue-100 text-blue-800' },
      'scaling':     { label: '📈 Scaling',     cls: 'bg-green-100 text-green-800' },
      'consistent':  { label: '✅ Consistent',  cls: 'bg-teal-100 text-teal-800' },
      'active':      { label: '🔄 Active',      cls: 'bg-slate-100 text-slate-700' },
      'declining':   { label: '📉 Declining',   cls: 'bg-orange-100 text-orange-800' },
      'phasing_out': { label: '🚫 Phasing Out', cls: 'bg-red-100 text-red-800' },
      'dormant':     { label: '💤 Dormant',     cls: 'bg-gray-100 text-gray-500' },
    };
    const ALL_LIFECYCLE_CLS = Object.values(LIFECYCLE_META).flatMap(m => m.cls.split(' '));

    // ---- Class metadata ----
    const CLASS_META = {
      'A': { label: 'A · Hero',    cls: 'bg-purple-600 text-white' },
      'B': { label: 'B · Solid',   cls: 'bg-blue-500 text-white' },
      'C': { label: 'C · Average', cls: 'bg-yellow-400 text-gray-900' },
      'D': { label: 'D · New Item',        cls: 'bg-orange-400 text-white' },
      'E': { label: 'E · Clearing',        cls: 'bg-pink-500 text-white' },
      'F': { label: 'F · Off / Hold-only', cls: 'bg-gray-700 text-white' },
    };
    const ALL_CLASS_CLS = Object.values(CLASS_META).flatMap(m => m.cls.split(' '));

    // ---- DataTable ----
    document.addEventListener('DOMContentLoaded', function () {
      if (!document.getElementById('supplyTable')) return;
      $('#supplyTable').DataTable({
        paging:    true,
        searching: false,
        ordering:  true,
        info:      true,
        dom:       'rtip',
        order:     [[10, 'desc']],   // Recommended Order (col index 10 now)
        pageLength: 50,
      });
    });

    // ---- Recompute recommended ----
    function recompute(row) {
      const vel       = parseFloat(row.dataset.vel)   || 0;
      const holdUnits = parseInt(row.dataset.holds)   || 0;
      const rtsPct    = parseFloat(row.dataset.rts)   || 0;
      const delivRate = Math.max(0.01, 1 - rtsPct / 100);
      const running   = row.dataset.running === '1';
      const lead      = parseInt(row.querySelector('.lead-input').value)   || 7;
      const safety    = parseInt(row.querySelector('.safety-input').value) || 0;

      const holdsGross = holdUnits > 0 ? Math.ceil(holdUnits / delivRate) : 0;
      let recommended  = holdsGross;
      if (running && vel > 0) {
        recommended = holdsGross
          + Math.ceil(vel * lead   / delivRate)
          + Math.ceil(vel * safety / delivRate);
      }

      const span = row.querySelector('.rec-val');
      if (span) span.textContent = recommended.toLocaleString('en-PH');
    }

    // ---- Toast ----
    let toastTimer;
    function showToast(msg = '✓ Saved') {
      const t = document.getElementById('saveToast');
      t.textContent = msg;
      t.style.display = 'block';
      clearTimeout(toastTimer);
      toastTimer = setTimeout(() => t.style.display = 'none', 2000);
    }

    // ---- Save item settings ----
    function saveRow(row, extra = {}) {
      const isAuto = row.dataset.runningAuto === '1';
      fetch('/jnt/supply/settings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
        body: JSON.stringify({
          item_name:      row.dataset.item,
          lead_time_days: parseInt(row.querySelector('.lead-input').value)   || 7,
          safety_days:    parseInt(row.querySelector('.safety-input').value) || 0,
          is_running:     isAuto ? 'auto' : row.dataset.running,
          ...extra,
        }),
      }).then(r => r.json()).then(d => { if (d.success) showToast(); }).catch(console.error);
    }

    // ---- Badge click → show dropdown ----
    function attachBadgeToggle(badgeSel, dropSel) {
      document.addEventListener('click', function (e) {
        // Close all open dropdowns of this type when clicking outside
        if (!e.target.classList.contains(badgeSel) && !e.target.classList.contains(dropSel)) {
          document.querySelectorAll('.' + dropSel).forEach(s => {
            if (s.style.display !== 'none' && s.style.display !== '') {
              const badge = s.closest('td').querySelector('.' + badgeSel);
              if (badge) badge.style.display = '';
              s.style.display = 'none';
            }
          });
          return;
        }
        if (e.target.classList.contains(badgeSel)) {
          const td  = e.target.closest('td');
          if (!td) return;
          const sel = td.querySelector('.' + dropSel);
          if (!sel) return;
          const isOpen = sel.style.display === 'block';
          // Close all first
          document.querySelectorAll('.' + dropSel).forEach(s => {
            const b = s.closest('td')?.querySelector('.' + badgeSel);
            if (b) b.style.display = '';
            s.style.display = 'none';
          });
          if (!isOpen) {
            sel.style.display = 'block';
            e.target.style.display = 'none';
          }
        }
      });
    }
    attachBadgeToggle('class-badge', 'class-sel');
    attachBadgeToggle('lifecycle-badge', 'lifecycle-sel');

    // ---- Change handlers ----
    document.addEventListener('change', function (e) {
      const row = e.target.closest('tr[data-item]');
      if (!row) return;

      // Running checkbox
      if (e.target.classList.contains('running-chk')) {
        row.dataset.running     = e.target.checked ? '1' : '0';
        row.dataset.runningAuto = '0';
        row.querySelector('td .text-gray-400.text-xs')?.remove();
        recompute(row);
        saveRow(row);
        return;
      }

      // Class override
      if (e.target.classList.contains('class-sel')) {
        const val   = e.target.value;
        const badge = e.target.closest('td').querySelector('.class-badge');
        if (val === '') {
          saveRow(row, { class_override: '' });
          setTimeout(() => location.reload(), 400);
        } else {
          const meta = CLASS_META[val];
          if (meta && badge) {
            badge.classList.remove(...ALL_CLASS_CLS);
            meta.cls.split(' ').forEach(c => badge.classList.add(c));
            badge.childNodes[0].textContent = meta.label;
            let star = badge.querySelector('sup');
            if (!star) { star = document.createElement('sup'); star.className = 'text-[9px] opacity-70'; badge.appendChild(star); }
            star.textContent = '★';
          }
          row.dataset.class     = val;
          row.dataset.classAuto = '0';
          e.target.style.display = 'none';
          if (badge) badge.style.display = '';
          saveRow(row, { class_override: val });
        }
        return;
      }

      // Lifecycle override
      if (e.target.classList.contains('lifecycle-sel')) {
        const val   = e.target.value;
        const badge = e.target.closest('td').querySelector('.lifecycle-badge');
        if (val === '') {
          saveRow(row, { lifecycle_override: '' });
          setTimeout(() => location.reload(), 400);
        } else {
          const meta = LIFECYCLE_META[val];
          if (meta && badge) {
            badge.classList.remove(...ALL_LIFECYCLE_CLS);
            meta.cls.split(' ').forEach(c => badge.classList.add(c));
            badge.childNodes[0].textContent = meta.label;
            let star = badge.querySelector('sup');
            if (!star) { star = document.createElement('sup'); star.className = 'text-[9px] opacity-60'; badge.appendChild(star); }
            star.textContent = '★';
          }
          row.dataset.lifecycle     = val;
          row.dataset.lifecycleAuto = '0';
          e.target.style.display    = 'none';
          if (badge) badge.style.display = '';
          saveRow(row, { lifecycle_override: val });
        }
        return;
      }
    });

    // Lead / safety blur → recompute + save
    document.addEventListener('blur', function (e) {
      const row = e.target.closest('tr[data-item]');
      if (!row) return;
      if (e.target.classList.contains('lead-input') || e.target.classList.contains('safety-input')) {
        recompute(row);
        saveRow(row);
      }
    }, true);

    // ---- CEO threshold save ----
    document.addEventListener('click', function (e) {
      if (!e.target.classList.contains('save-threshold-btn')) return;
      const classKey = e.target.dataset.class;
      const input    = document.querySelector(`.threshold-input[data-class="${classKey}"]`);
      if (!input) return;
      const minVel = parseFloat(input.value);
      if (isNaN(minVel) || minVel < 0) { alert('Invalid velocity value'); return; }

      e.target.disabled = true;
      fetch('/jnt/supply/class-thresholds', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
        body: JSON.stringify({ class_key: classKey, min_velocity: minVel }),
      })
      .then(r => r.json())
      .then(d => {
        e.target.disabled = false;
        if (d.success) showToast('✓ Threshold saved');
      })
      .catch(err => { console.error(err); e.target.disabled = false; });
    });

    // ---- CEO supply_settings KV save ----
    document.addEventListener('click', function (e) {
      if (!e.target.classList.contains('save-kv-btn')) return;
      const key   = e.target.dataset.key;
      const input = document.querySelector(`.supply-kv-input[data-key="${key}"]`);
      if (!input) return;
      const val = input.value.trim();
      if (val === '' || isNaN(parseFloat(val))) { alert('Invalid value'); return; }

      e.target.disabled = true;
      fetch('/jnt/supply/setting-kv', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
        body: JSON.stringify({ key, value: val }),
      })
      .then(r => r.json())
      .then(d => {
        e.target.disabled = false;
        if (d.success) showToast('✓ ' + key + ' = ' + d.value);
        else alert(d.error || 'Save failed');
      })
      .catch(err => { console.error(err); e.target.disabled = false; });
    });
  </script>
</x-layout>
