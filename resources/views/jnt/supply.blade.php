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
    /* Frozen column headers */
    .supply-table-wrap { max-height: calc(100vh - 260px); overflow: auto; }
    #supplyTable thead tr.col-filter-row th {
      position: sticky;
      top: 34px;                 /* sits directly under main header row */
      z-index: 4;
      background: #f8fafc;
      box-shadow: inset 0 -1px 0 #cbd5e1;
      padding: 4px 6px;
    }
    #supplyTable thead tr:first-child th {
      position: sticky; top: 0; z-index: 5;
      background: #f1f5f9; box-shadow: inset 0 -2px 0 #cbd5e1;
    }
    .col-filter-input {
      width: 100%; font-size: 11px;
      border: 1px solid #d1d5db; border-radius: 4px;
      padding: 2px 5px; background: white;
    }
    .col-filter-input:focus { outline: none; border-color: #2563eb; }
    .profit-cell { font-weight: 800; font-size: 14px; }
    .profit-green  { background:#dcfce7; color:#166534; }
    .profit-yellow { background:#fef9c3; color:#854d0e; }
    .profit-orange { background:#ffedd5; color:#9a3412; }
    .profit-red    { background:#fee2e2; color:#991b1b; }
    .profit-gray   { background:#f3f4f6; color:#6b7280; }
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
            <option value="" {{ $classFilter === '' ? 'selected' : '' }}>All Classes</option>
            @foreach($classRules as $cr)
              <option value="{{ $cr->class_key }}" {{ $classFilter === $cr->class_key ? 'selected' : '' }}>{{ $cr->label }}</option>
            @endforeach
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
          <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Profit Filter</label>
          <select name="profit_filter" class="border border-gray-300 rounded-md px-3 py-1.5 text-sm bg-white">
            <option value=""        {{ $profitFilter === ''        ? 'selected' : '' }}>All</option>
            <option value="green"   {{ $profitFilter === 'green'   ? 'selected' : '' }}>🟢 ≥ 15%</option>
            <option value="yellow"  {{ $profitFilter === 'yellow'  ? 'selected' : '' }}>🟡 5 – 15%</option>
            <option value="orange"  {{ $profitFilter === 'orange'  ? 'selected' : '' }}>🟠 0 – 5%</option>
            <option value="red"     {{ $profitFilter === 'red'     ? 'selected' : '' }}>🔴 &lt; 0% / Missing data</option>
            <option value="missing" {{ $profitFilter === 'missing' ? 'selected' : '' }}>⬜ No data</option>
          </select>
        </div>

        <div>
          <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">As Of Date</label>
          <input name="as_of_date" value="{{ $asOfDate }}" type="date"
                 class="border border-gray-300 rounded-md px-3 py-1.5 text-sm">
        </div>

        {{-- Hidden lifecycle/defaults params (not UI-exposed anymore) --}}
        <input type="hidden" name="new_item_days"       value="{{ $newItemDays }}">
        <input type="hidden" name="long_running_days"   value="{{ $longRunningDays }}">
        <input type="hidden" name="scale_threshold"     value="{{ $scaleThreshold }}">
        <input type="hidden" name="decline_threshold"   value="{{ $declineThreshold }}">
        <input type="hidden" name="default_lead_time"   value="{{ $defaultLeadTime }}">
        <input type="hidden" name="default_safety_days" value="{{ $defaultSafetyDays }}">

        <button type="submit"
                class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-4 py-2 rounded-md shadow">
          Apply
        </button>
        <a href="{{ url('/jnt/supply') }}"
           class="text-sm px-4 py-2 rounded-md border border-gray-300 hover:bg-gray-50 text-gray-700">
          Reset
        </a>
        @if($isCeo)
          <a href="{{ url('/jnt/supply/config') }}"
             class="text-sm px-4 py-2 rounded-md border border-indigo-300 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-semibold">
            <i class="fa-solid fa-sliders mr-1"></i> Config
          </a>
        @endif
      </div>

      {{-- Legend row --}}
      <div class="mt-3 flex flex-wrap gap-3 text-xs text-gray-500 items-center">
        <span class="font-semibold text-gray-600">Class:</span>
        @foreach($classRules as $cr)
          <span class="class-badge {{ $cr->badge_tailwind }}" style="cursor:default;">{{ $cr->label }}</span>
        @endforeach
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
    {{-- Summary cards                                                       --}}
    {{-- ================================================================== --}}
    <div class="grid grid-cols-2 gap-4 mb-4">
      <div class="bg-white rounded-lg shadow border border-gray-200 p-4 text-center">
        <div class="text-2xl font-bold text-gray-800">{{ number_format($itemsWithHolds) }}</div>
        <div class="text-xs text-gray-500 uppercase tracking-wide mt-1">Items with Holds</div>
      </div>
      <div class="bg-white rounded-lg shadow border border-gray-200 p-4 text-center">
        <div class="text-2xl font-bold text-blue-600">{{ number_format($totalHoldUnits) }}</div>
        <div class="text-xs text-gray-500 uppercase tracking-wide mt-1">Total Hold Units</div>
      </div>
    </div>

    {{-- ================================================================== --}}
    {{-- Table                                                               --}}
    {{-- ================================================================== --}}
    @if(count($items) > 0)
    <div class="bg-white rounded-lg shadow border border-gray-200 supply-table-wrap">
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
            <th class="px-3 py-2 text-right  text-xs font-semibold text-gray-600 uppercase tracking-wide whitespace-nowrap border border-gray-200"
                style="background:#ecfccb; color:#3f6212;">Proj Profit%</th>
          </tr>
          {{-- gSheet-style per-column header filters --}}
          <tr class="col-filter-row">
            <th><input type="text" class="col-filter-input" data-col="0" placeholder="🔍 search…"></th>
            <th><input type="text" class="col-filter-input col-filter-num" data-col="1" placeholder="≥ min"></th>
            <th><input type="text" class="col-filter-input col-filter-num" data-col="2" placeholder="≥ min"></th>
            <th>
              <select class="col-filter-input col-filter-select" data-col="3">
                <option value="">All</option>
                <option value="Hot">Hot</option>
                <option value="Strong">Strong</option>
                <option value="Active">Active</option>
                <option value="Light">Light</option>
                <option value="Inactive">Inactive</option>
              </select>
            </th>
            <th>
              <select class="col-filter-input col-filter-select" data-col="4">
                <option value="">All</option>
                @foreach($classRules as $cr)
                  <option value="{{ $cr->class_key }}">{{ $cr->class_key }}</option>
                @endforeach
              </select>
            </th>
            <th>
              <select class="col-filter-input col-filter-select" data-col="5">
                <option value="">All</option>
                <option value="new">🆕 New</option>
                <option value="scaling">📈 Scaling</option>
                <option value="consistent">✅ Consistent</option>
                <option value="active">🔄 Active</option>
                <option value="declining">📉 Declining</option>
                <option value="phasing_out">🚫 Phasing Out</option>
                <option value="dormant">💤 Dormant</option>
              </select>
            </th>
            <th>
              <select class="col-filter-input col-filter-select" data-col="6">
                <option value="">All</option>
                <option value="1">Running</option>
                <option value="0">Not Running</option>
              </select>
            </th>
            <th><input type="text" class="col-filter-input col-filter-num" data-col="7" placeholder="≥ min%"></th>
            <th>
              <select class="col-filter-input col-filter-select" data-col="8">
                <option value="">All</option>
                <option value="green">🟢 ≥ 15%</option>
                <option value="yellow">🟡 5–15%</option>
                <option value="orange">🟠 0–5%</option>
                <option value="red">🔴 &lt; 0% / Missing</option>
                <option value="missing">⬜ No data</option>
              </select>
            </th>
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
              @php
                $diagParts = [];
                foreach ($row['rule_diagnostics'] ?? [] as $rk => $diag) {
                    $diagParts[] = "{$rk}: {$diag['win_avg']} (alive {$diag['alive_avg']})";
                }
                $diagText = implode(' · ', $diagParts);
              @endphp
              <span class="class-badge {{ $row['item_class_badge'] }}"
                    title="{{ $diagText }}{{ $diagText ? ' · ' : '' }}Running: {{ $row['is_running'] ? 'yes' : 'no' }} · Age: {{ $row['days_running'] }}d{{ $row['item_class_auto'] ? ' · auto' : ' · manual override' }}">
                {{ $row['item_class_label'] }}@if(!$row['item_class_auto'])<sup title="Manual override" class="text-[9px] opacity-70">★</sup>@endif
              </span>
              <select class="class-sel" title="Override class for {{ $row['item'] }}">
                <option value="">auto</option>
                @foreach($classRules as $cr)
                  <option value="{{ $cr->class_key }}" {{ $row['item_class'] === $cr->class_key && !$row['item_class_auto'] ? 'selected' : '' }}>{{ $cr->label }}</option>
                @endforeach
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
            <td class="px-3 py-2 border border-gray-200 text-right text-gray-700"
                data-order="{{ $row['rts_pct'] }}">
              {{ $row['rts_pct'] > 0 ? $row['rts_pct'].'%' : '—' }}
            </td>

            {{-- Projected Profit% --}}
            @php
              $bucketClass = 'profit-' . ($row['profit_bucket'] ?? 'gray');
              $profitVal   = $row['profit_pct'];
              $tipParts = [
                'Window: ' . $row['profit_window_days'] . 'd (class ' . $row['item_class'] . ')',
                'Σ gross: ₱' . number_format($row['profit_sum_gross'], 2),
                'Σ net: ₱'   . number_format($row['profit_sum_net'], 2),
              ];
              if ($row['profit_skipped_days'] > 0) {
                $tipParts[] = 'Skipped ' . $row['profit_skipped_days'] . ' slice(s) due to tied primary';
              }
              if ($row['profit_mismatch_ct'] > 0) {
                $tipParts[] = '⚠ ' . $row['profit_mismatch_ct'] . ' COGS override/base mismatch';
              }
              if (!$row['profit_has_cogs']) $tipParts[] = '⚠ No COGS data';
              if (!$row['profit_has_rts'])  $tipParts[] = '⚠ No RTS% data';
              $tip = implode(' · ', $tipParts);
              $sortKey = $profitVal === null ? -9999 : $profitVal;
            @endphp
            <td class="px-3 py-2 border border-gray-200 text-right profit-cell {{ $bucketClass }}"
                data-order="{{ $sortKey }}"
                data-bucket="{{ $row['profit_bucket'] }}"
                title="{{ $tip }}">
              @if($profitVal === null)
                <span class="text-xs">
                  @if(!$row['profit_has_cogs']) No COGS @elseif(!$row['profit_has_rts']) No RTS @else No data @endif
                </span>
              @else
                {{ number_format($profitVal, 1) }}%@if($row['profit_mismatch_ct'] > 0 || !$row['profit_has_cogs'] || !$row['profit_has_rts'])
                  <sup class="text-[10px] opacity-70" title="{{ $tip }}">⚠</sup>
                @endif
              @endif
            </td>

          </tr>
          @endforeach
        </tbody>
        <tfoot>
          <tr style="background:#f8fafc; border-top:2px solid #94a3b8; font-weight:700;">
            <td class="px-3 py-2 border border-gray-300 text-xs text-gray-500 uppercase">TOTAL</td>
            <td class="px-3 py-2 border border-gray-300 text-right text-blue-700">{{ number_format($totalHoldUnits) }}</td>
            <td class="px-3 py-2 border border-gray-300" colspan="7"></td>
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

    // ---- Class metadata (populated from server-side $classRules) ----
    const CLASS_META = {
      @foreach($classRules as $cr)
        {!! json_encode($cr->class_key) !!}: { label: {!! json_encode($cr->label) !!}, cls: {!! json_encode($cr->badge_tailwind) !!} },
      @endforeach
    };
    const ALL_CLASS_CLS = Object.values(CLASS_META).flatMap(m => m.cls.split(' '));

    // ---- DataTable + per-column gSheet-style filters ----
    document.addEventListener('DOMContentLoaded', function () {
      if (!document.getElementById('supplyTable')) return;

      const dt = $('#supplyTable').DataTable({
        paging:    false,
        searching: true,   // needed for per-column filters
        ordering:  true,
        info:      true,
        dom:       'rti',
        order:     [[8, 'desc']],    // Proj Profit% col
        orderCellsTop: true,
        // Disable sort on filter row
        columnDefs: [{ orderable: true, targets: '_all' }],
      });

      // Custom filter function for numeric ≥ min and bucket match
      $.fn.dataTable.ext.search.push(function (settings, rowData, rowIndex, rowNodeOrig) {
        if (settings.nTable.id !== 'supplyTable') return true;
        const row = dt.row(rowIndex).node();

        // Numeric "≥ min" filters
        const numCols = document.querySelectorAll('.col-filter-num');
        for (const inp of numCols) {
          const v = inp.value.trim();
          if (v === '') continue;
          const min = parseFloat(v);
          if (isNaN(min)) continue;
          const colIdx = parseInt(inp.dataset.col);
          const td = row.children[colIdx];
          if (!td) continue;
          const raw = parseFloat(td.getAttribute('data-order') ?? td.textContent.replace(/[^\d.\-]/g, ''));
          if (isNaN(raw) || raw < min) return false;
        }

        // Class (col 4) — match data-order
        const classSel = document.querySelector('.col-filter-select[data-col="4"]');
        if (classSel && classSel.value !== '') {
          const td = row.children[4];
          if ((td?.getAttribute('data-order') ?? '') !== classSel.value) return false;
        }

        // Lifecycle (col 5) — match data-order
        const lcSel = document.querySelector('.col-filter-select[data-col="5"]');
        if (lcSel && lcSel.value !== '') {
          const td = row.children[5];
          if ((td?.getAttribute('data-order') ?? '') !== lcSel.value) return false;
        }

        // Strength (col 3) — match badge text
        const stSel = document.querySelector('.col-filter-select[data-col="3"]');
        if (stSel && stSel.value !== '') {
          const td = row.children[3];
          const label = (td?.querySelector('.strength-badge')?.textContent || '').trim();
          if (label !== stSel.value) return false;
        }

        // Running (col 6) — checkbox checked state
        const rnSel = document.querySelector('.col-filter-select[data-col="6"]');
        if (rnSel && rnSel.value !== '') {
          const td = row.children[6];
          const cb = td?.querySelector('.running-chk');
          const val = cb && cb.checked ? '1' : '0';
          if (val !== rnSel.value) return false;
        }

        // Profit bucket (col 8) — data-bucket
        const pfSel = document.querySelector('.col-filter-select[data-col="8"]');
        if (pfSel && pfSel.value !== '') {
          const td = row.children[8];
          if ((td?.getAttribute('data-bucket') ?? '') !== pfSel.value) return false;
        }

        return true;
      });

      // Item text filter (col 0) — use DT column search
      document.querySelectorAll('.col-filter-input').forEach(inp => {
        const ev = inp.tagName === 'SELECT' ? 'change' : 'input';
        inp.addEventListener(ev, function () {
          const col = parseInt(this.dataset.col);
          if (col === 0 && this.classList.contains('col-filter-input') && !this.classList.contains('col-filter-num') && !this.classList.contains('col-filter-select')) {
            dt.column(0).search(this.value).draw();
          } else {
            dt.draw();
          }
        });
      });

      // Prevent filter-row clicks from triggering sort
      document.querySelectorAll('.col-filter-row th').forEach(th => {
        th.addEventListener('click', e => e.stopPropagation());
      });
    });

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
          item_name:  row.dataset.item,
          is_running: isAuto ? 'auto' : row.dataset.running,
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

    // ---- CEO supply_settings KV save (multi-key row) ----
    document.addEventListener('click', async function (e) {
      if (!e.target.classList.contains('save-kv-btn-multi')) return;
      const keys = (e.target.dataset.keys || '').split(',').filter(Boolean);
      e.target.disabled = true;
      let allOk = true, lastMsg = '';
      for (const key of keys) {
        const input = document.querySelector(`.supply-kv-input[data-key="${key}"]`);
        if (!input) continue;
        const val = input.value.trim();
        if (val === '' || isNaN(parseFloat(val))) { allOk = false; continue; }
        const resp = await fetch('/jnt/supply/setting-kv', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
          body: JSON.stringify({ key, value: val }),
        }).then(r => r.json()).catch(() => ({ success: false }));
        if (resp.success) lastMsg = key + ' = ' + resp.value;
        else allOk = false;
      }
      e.target.disabled = false;
      showToast(allOk ? '✓ Saved — reload to apply' : '⚠ Some saves failed');
    });

    // ---- CEO supply_settings KV save (single key) ----
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
