<?php

namespace App\Http\Controllers;

use App\Models\SupplyItemSetting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JntSupplyController extends Controller
{
    // -----------------------------------------------------------------------
    // Helper: strip quantity prefix from item name
    // "2x SHOE CLEANER" → [2, "SHOE CLEANER"]
    // "SEAT COVER"      → [1, "SEAT COVER"]
    // -----------------------------------------------------------------------
    private function parseItem(string $name): array
    {
        $name = trim($name);
        if ($name === '') return [1, '—'];
        if (preg_match('/^\s*(\d+)\s*[x×]\s*(.+)$/iu', $name, $m)) {
            return [(int)$m[1], trim($m[2])];
        }
        return [1, $name];
    }

    // -----------------------------------------------------------------------
    // Velocity strength label + CSS class
    // -----------------------------------------------------------------------
    private function strengthClass(float $v): array
    {
        if ($v >= 10) return ['Hot',      'bg-red-500 text-white'];
        if ($v >= 3)  return ['Strong',   'bg-orange-400 text-white'];
        if ($v >= 1)  return ['Active',   'bg-yellow-300 text-gray-800'];
        if ($v > 0)   return ['Light',    'bg-green-200 text-gray-800'];
        return             ['Inactive', 'bg-gray-200 text-gray-500'];
    }

    // -----------------------------------------------------------------------
    // Lifecycle classification
    // Priority: New > Phasing Out > Scaling > Declining > Consistent > Active > Dormant
    // -----------------------------------------------------------------------
    private function classifyLifecycle(
        float  $recentVel,
        float  $prevVel,
        int    $daysRunning,
        bool   $hasOldOrders,
        int    $newItemDays,
        int    $longRunningDays,
        float  $scaleThreshold,
        float  $declineThreshold
    ): string {
        // 1. New: first order was within $newItemDays and still selling
        if ($daysRunning <= $newItemDays && $recentVel > 0) {
            return 'new';
        }

        // 2. Phasing Out: was selling before, zero recent velocity
        if ($recentVel == 0 && $prevVel > 0) {
            return 'phasing_out';
        }

        // 3. Dormant: no recent AND no previous sales (but has old data)
        if ($recentVel == 0 && $prevVel == 0) {
            return $hasOldOrders ? 'dormant' : 'dormant';
        }

        // 4. Scaling: previous was 0 and now selling, OR velocity grew significantly
        if ($prevVel == 0 && $recentVel > 0) {
            return 'scaling';
        }
        if ($recentVel >= $prevVel * $scaleThreshold) {
            return 'scaling';
        }

        // 5. Declining: velocity dropped significantly
        if ($recentVel <= $prevVel * $declineThreshold) {
            return 'declining';
        }

        // 6. Consistent: long-running item with stable velocity
        if ($daysRunning >= $longRunningDays) {
            return 'consistent';
        }

        // 7. Active: running but not long enough to be "consistent"
        return 'active';
    }

    // -----------------------------------------------------------------------
    // Lifecycle badge label + Tailwind classes
    // -----------------------------------------------------------------------
    private function lifecycleBadge(string $class): array
    {
        return match ($class) {
            'new'         => ['🆕 New',          'bg-blue-100 text-blue-800'],
            'scaling'     => ['📈 Scaling',       'bg-green-100 text-green-800'],
            'consistent'  => ['✅ Consistent',    'bg-teal-100 text-teal-800'],
            'active'      => ['🔄 Active',        'bg-slate-100 text-slate-700'],
            'declining'   => ['📉 Declining',     'bg-orange-100 text-orange-800'],
            'phasing_out' => ['🚫 Phasing Out',   'bg-red-100 text-red-800'],
            'dormant'     => ['💤 Dormant',       'bg-gray-100 text-gray-500'],
            default       => ['— Unknown',        'bg-gray-100 text-gray-400'],
        };
    }

    // -----------------------------------------------------------------------
    // GET /jnt/supply
    // -----------------------------------------------------------------------
    public function index(Request $request)
    {
        $today    = Carbon::now('Asia/Manila')->toDateString();
        $driver   = DB::getDriverName();
        $likeOp   = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';
        $moItem   = $driver === 'pgsql' ? 'mo."ITEM_NAME"' : 'mo.`ITEM_NAME`';
        $moWaybill= $driver === 'pgsql' ? 'mo."waybill"'   : 'mo.`waybill`';

        // -- Filter params --------------------------------------------------
        $q                 = trim((string) $request->input('q', ''));
        $velocityDays      = max(1, (int) $request->input('velocity_days', 30));
        $runningThreshold  = max(0.0, (float) $request->input('running_threshold', 1.0));
        $defaultLeadTime   = max(1, (int) $request->input('default_lead_time', 7));
        $defaultSafetyDays = max(0, (int) $request->input('default_safety_days', 3));
        $asOfDate          = $request->input('as_of_date', $today);

        // Lifecycle params
        $recentDays        = max(1, (int) $request->input('recent_days', 14));
        $newItemDays       = max(1, (int) $request->input('new_item_days', 30));
        $longRunningDays   = max(1, (int) $request->input('long_running_days', 90));
        $scaleThreshold    = max(1.0, (float) $request->input('scale_threshold', 1.5));
        $declineThreshold  = min(1.0, max(0.0, (float) $request->input('decline_threshold', 0.5)));
        $lifecycleFilter   = $request->input('lifecycle_filter', '');

        $velocityFrom = Carbon::parse($asOfDate, 'Asia/Manila')
            ->subDays($velocityDays - 1)
            ->toDateString();

        // Lifecycle windows
        $recentFrom = Carbon::parse($asOfDate, 'Asia/Manila')
            ->subDays($recentDays - 1)
            ->toDateString();
        $prevTo     = Carbon::parse($asOfDate, 'Asia/Manila')
            ->subDays($recentDays)
            ->toDateString();
        $prevFrom   = Carbon::parse($asOfDate, 'Asia/Manila')
            ->subDays($recentDays * 2 - 1)
            ->toDateString();

        $itemCol = $driver === 'pgsql' ? '"ITEM_NAME"' : '`ITEM_NAME`';

        // -- 1. Hold counts (all pending, no date filter) --------------------
        $holdBase = DB::table('macro_output as mo')
            ->leftJoin('from_jnts as fj', 'fj.waybill_number', '=', 'mo.waybill')
            ->whereNull('fj.waybill_number')
            ->whereRaw("NULLIF(TRIM($moWaybill), '') IS NOT NULL");

        if ($q !== '') {
            $holdBase->where(function ($w) use ($q, $likeOp, $moItem, $moWaybill) {
                $w->whereRaw("$moItem $likeOp ?", ["%{$q}%"])
                  ->orWhereRaw("$moWaybill $likeOp ?", ["%{$q}%"]);
            });
        }

        $holdRows = (clone $holdBase)
            ->selectRaw("$moItem as item_name, COUNT(*) as hold_count")
            ->groupByRaw($moItem)
            ->get();

        // Accumulate hold units per base item
        $holdUnitsMap = []; // base_item => units
        foreach ($holdRows as $r) {
            [$qty, $base] = $this->parseItem((string)($r->item_name ?? ''));
            $holdUnitsMap[$base] = ($holdUnitsMap[$base] ?? 0) + $qty * (int)$r->hold_count;
        }

        // -- 2. Velocity (orders/day over last N days) ----------------------
        $velQuery = DB::table('macro_output')
            ->whereBetween('ts_date', [$velocityFrom, $asOfDate])
            ->selectRaw("$itemCol as item_name, COUNT(*) as order_count")
            ->groupByRaw($itemCol);

        if ($q !== '') {
            $velQuery->where($itemCol, $likeOp === 'ILIKE' ? 'ilike' : 'like', "%{$q}%");
        }

        $velRows = $velQuery->get();

        $velUnitsMap = []; // base_item => total_units (over period)
        foreach ($velRows as $r) {
            [$qty, $base] = $this->parseItem((string)($r->item_name ?? ''));
            $velUnitsMap[$base] = ($velUnitsMap[$base] ?? 0) + $qty * (int)$r->order_count;
        }

        // -- 3. RTS% per item (max across pages from page_item_settings) ----
        $rtsRows = DB::table('page_item_settings')
            ->selectRaw('item_name, MAX(rts_pct) as max_rts')
            ->groupBy('item_name')
            ->get();

        $rtsMap = []; // base_item => max rts_pct
        foreach ($rtsRows as $r) {
            [, $base] = $this->parseItem((string)($r->item_name ?? ''));
            $cur = $rtsMap[$base] ?? 0;
            $rtsMap[$base] = max($cur, (float)($r->max_rts ?? 0));
        }

        // -- 4. Supply settings (lead time overrides per item) ---------------
        $supplySettings = SupplyItemSetting::all()->keyBy('item_name');

        // -- 5. Lifecycle: first order date per base item --------------------
        $firstDateRows = DB::table('macro_output')
            ->selectRaw("$itemCol as item_name, MIN(ts_date) as first_date")
            ->groupByRaw($itemCol)
            ->get();

        $firstDateMap = []; // base_item => first_date string
        foreach ($firstDateRows as $r) {
            [, $base] = $this->parseItem((string)($r->item_name ?? ''));
            $existing = $firstDateMap[$base] ?? null;
            if ($existing === null || $r->first_date < $existing) {
                $firstDateMap[$base] = $r->first_date;
            }
        }

        // -- 6. Lifecycle: recent window velocity ----------------------------
        $recentVelRows = DB::table('macro_output')
            ->whereBetween('ts_date', [$recentFrom, $asOfDate])
            ->selectRaw("$itemCol as item_name, COUNT(*) as cnt")
            ->groupByRaw($itemCol)
            ->get();

        $recentUnitsMap = [];
        foreach ($recentVelRows as $r) {
            [$qty, $base] = $this->parseItem((string)($r->item_name ?? ''));
            $recentUnitsMap[$base] = ($recentUnitsMap[$base] ?? 0) + $qty * (int)$r->cnt;
        }

        // -- 7. Lifecycle: previous window velocity --------------------------
        $prevVelRows = DB::table('macro_output')
            ->whereBetween('ts_date', [$prevFrom, $prevTo])
            ->selectRaw("$itemCol as item_name, COUNT(*) as cnt")
            ->groupByRaw($itemCol)
            ->get();

        $prevUnitsMap = [];
        foreach ($prevVelRows as $r) {
            [$qty, $base] = $this->parseItem((string)($r->item_name ?? ''));
            $prevUnitsMap[$base] = ($prevUnitsMap[$base] ?? 0) + $qty * (int)$r->cnt;
        }

        // -- 8. Merge all items (union of hold items + velocity items) -------
        $allBases = array_unique(array_merge(
            array_keys($holdUnitsMap),
            array_keys($velUnitsMap)
        ));

        // Apply search filter on base item name
        if ($q !== '') {
            $allBases = array_filter($allBases, fn($b) => stripos($b, $q) !== false);
        }

        $asOfCarbon = Carbon::parse($asOfDate, 'Asia/Manila');

        $items = [];
        foreach ($allBases as $base) {
            if ($base === '—') continue;

            $holdUnits     = $holdUnitsMap[$base] ?? 0;
            $totalVelUnits = $velUnitsMap[$base] ?? 0;
            $velPerDay     = $velocityDays > 0 ? round($totalVelUnits / $velocityDays, 2) : 0.0;

            $rtsPct       = $rtsMap[$base] ?? 0.0;
            $deliveryRate = max(0.01, 1 - $rtsPct / 100);

            /** @var SupplyItemSetting|null $setting */
            $setting = $supplySettings->get($base);

            $leadTime   = $setting?->lead_time_days ?? $defaultLeadTime;
            $safetyDays = $setting?->safety_days    ?? $defaultSafetyDays;

            // Running: explicit override > auto-detect by velocity
            if ($setting && $setting->is_running !== null) {
                $isRunning = (bool)$setting->is_running;
            } else {
                $isRunning = $velPerDay >= $runningThreshold;
            }

            // Recommended qty
            $holdsGross = $holdUnits > 0 ? (int)ceil($holdUnits / $deliveryRate) : 0;

            if ($isRunning && $velPerDay > 0) {
                $leadDemand  = (int)ceil($velPerDay * $leadTime   / $deliveryRate);
                $safetyStock = (int)ceil($velPerDay * $safetyDays / $deliveryRate);
                $recommended = $holdsGross + $leadDemand + $safetyStock;
            } else {
                $recommended = $holdsGross;
            }

            [$strengthLabel, $strengthClass] = $this->strengthClass($velPerDay);

            // -- Lifecycle --------------------------------------------------
            $firstDate   = $firstDateMap[$base] ?? null;
            $daysRunning = $firstDate
                ? (int) Carbon::parse($firstDate, 'Asia/Manila')->diffInDays($asOfCarbon)
                : 9999;

            $recentVel  = $recentDays > 0
                ? round(($recentUnitsMap[$base] ?? 0) / $recentDays, 4)
                : 0.0;
            $prevVel    = $recentDays > 0
                ? round(($prevUnitsMap[$base] ?? 0) / $recentDays, 4)
                : 0.0;
            $hasOldOrders = ($firstDate !== null);

            $lifecycleOverride = $setting?->lifecycle_override ?? null;
            if ($lifecycleOverride) {
                $lifecycle     = $lifecycleOverride;
                $lifecycleAuto = false;
            } else {
                $lifecycle = $this->classifyLifecycle(
                    $recentVel, $prevVel, $daysRunning, $hasOldOrders,
                    $newItemDays, $longRunningDays, $scaleThreshold, $declineThreshold
                );
                $lifecycleAuto = true;
            }

            [$lifecycleLabel, $lifecycleBadgeClass] = $this->lifecycleBadge($lifecycle);

            $items[] = [
                'item'                => $base,
                'hold_units'          => $holdUnits,
                'vel_per_day'         => $velPerDay,
                'strength_label'      => $strengthLabel,
                'strength_class'      => $strengthClass,
                'is_running'          => $isRunning,
                'is_running_auto'     => ($setting?->is_running === null),
                'rts_pct'             => round($rtsPct, 1),
                'delivery_rate'       => round($deliveryRate * 100, 1),
                'lead_time_days'      => $leadTime,
                'safety_days'         => $safetyDays,
                'holds_gross'         => $holdsGross,
                'recommended'         => $recommended,
                'notes'               => $setting?->notes ?? '',
                'lifecycle'           => $lifecycle,
                'lifecycle_auto'      => $lifecycleAuto,
                'lifecycle_label'     => $lifecycleLabel,
                'lifecycle_badge'     => $lifecycleBadgeClass,
                'days_running'        => $daysRunning,
                'recent_vel'          => round($recentVel, 2),
                'prev_vel'            => round($prevVel, 2),
            ];
        }

        // Apply lifecycle filter
        if ($lifecycleFilter !== '') {
            $items = array_filter($items, fn($i) => $i['lifecycle'] === $lifecycleFilter);
            $items = array_values($items);
        }

        // Sort by recommended DESC, then item ASC
        usort($items, fn($a, $b) =>
            $b['recommended'] <=> $a['recommended'] ?: strcmp($a['item'], $b['item'])
        );

        $totalHoldUnits   = array_sum(array_column($items, 'hold_units'));
        $totalRecommended = array_sum(array_column($items, 'recommended'));
        $itemsWithHolds   = count(array_filter($items, fn($i) => $i['hold_units'] > 0));

        return view('jnt.supply', compact(
            'items',
            'totalHoldUnits',
            'totalRecommended',
            'itemsWithHolds',
            'q',
            'velocityDays',
            'runningThreshold',
            'defaultLeadTime',
            'defaultSafetyDays',
            'asOfDate',
            'recentDays',
            'newItemDays',
            'longRunningDays',
            'scaleThreshold',
            'declineThreshold',
            'lifecycleFilter',
        ));
    }

    // -----------------------------------------------------------------------
    // POST /jnt/supply/settings — inline save per item
    // -----------------------------------------------------------------------
    public function saveSettings(Request $request)
    {
        $validated = $request->validate([
            'item_name'          => 'required|string|max:255',
            'lead_time_days'     => 'nullable|integer|min:1|max:365',
            'safety_days'        => 'nullable|integer|min:0|max:365',
            'is_running'         => 'nullable|in:0,1,auto',
            'notes'              => 'nullable|string|max:500',
            'lifecycle_override' => 'nullable|in:,new,scaling,consistent,active,declining,phasing_out,dormant',
        ]);

        $itemName  = $validated['item_name'];
        $isRunning = null;
        if (isset($validated['is_running']) && $validated['is_running'] !== 'auto') {
            $isRunning = (bool)(int)$validated['is_running'];
        }

        $lifecycleOverride = ($validated['lifecycle_override'] ?? '') !== ''
            ? $validated['lifecycle_override']
            : null;

        $updateData = array_filter([
            'lead_time_days'     => $validated['lead_time_days'] ?? null,
            'safety_days'        => $validated['safety_days']    ?? null,
            'is_running'         => $isRunning,
            'notes'              => $validated['notes']          ?? null,
            'lifecycle_override' => $lifecycleOverride,
        ], fn($v) => $v !== null);

        // Explicitly handle null lifecycle_override (clearing override)
        if (array_key_exists('lifecycle_override', $validated) && $lifecycleOverride === null) {
            $updateData['lifecycle_override'] = null;
        }

        SupplyItemSetting::updateOrCreate(
            ['item_name' => $itemName],
            $updateData
        );

        return response()->json(['success' => true]);
    }
}
