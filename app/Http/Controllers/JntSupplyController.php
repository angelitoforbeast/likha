<?php

namespace App\Http\Controllers;

use App\Models\ItemClassThreshold;
use App\Models\SupplyItemSetting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        if ($daysRunning <= $newItemDays && $recentVel > 0) {
            return 'new';
        }
        if ($recentVel == 0 && $prevVel > 0) {
            return 'phasing_out';
        }
        if ($recentVel == 0 && $prevVel == 0) {
            return 'dormant';
        }
        if ($prevVel == 0 && $recentVel > 0) {
            return 'scaling';
        }
        if ($recentVel >= $prevVel * $scaleThreshold) {
            return 'scaling';
        }
        if ($recentVel <= $prevVel * $declineThreshold) {
            return 'declining';
        }
        if ($daysRunning >= $longRunningDays) {
            return 'consistent';
        }
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
    // Item class classification (A–E)
    // $thresholds = array sorted descending by min_velocity
    //               e.g. [['class_key'=>'A','min_velocity'=>10], ...]
    // -----------------------------------------------------------------------
    private function classifyItemClass(float $velPerDay, string $lifecycle, array $thresholds): string
    {
        // Step 1: velocity-based raw class
        $rawClass = 'E'; // floor
        foreach ($thresholds as $t) {
            if ($velPerDay >= $t['min_velocity']) {
                $rawClass = $t['class_key'];
                break;
            }
        }

        // Step 2: lifecycle penalty (shift down N tiers)
        $penalty = match ($lifecycle) {
            'declining'   => 1,
            'phasing_out' => 2,
            'dormant'     => 2,
            default       => 0,
        };

        if ($penalty === 0) return $rawClass;

        $rankMap  = ['A' => 0, 'B' => 1, 'C' => 2, 'D' => 3, 'E' => 4];
        $keyMap   = [0 => 'A', 1 => 'B', 2 => 'C', 3 => 'D', 4 => 'E'];
        $rawRank  = $rankMap[$rawClass] ?? 4;
        $final    = min(4, $rawRank + $penalty);

        return $keyMap[$final];
    }

    // -----------------------------------------------------------------------
    // Item class badge label + Tailwind classes
    // -----------------------------------------------------------------------
    private function classBadge(string $key): array
    {
        return match ($key) {
            'A' => ['A · Hero',    'bg-purple-600 text-white'],
            'B' => ['B · Solid',   'bg-blue-500 text-white'],
            'C' => ['C · Average', 'bg-yellow-400 text-gray-900'],
            'D' => ['D · At-Risk', 'bg-orange-400 text-white'],
            'E' => ['E · Dead',    'bg-gray-400 text-white'],
            default => ['? · Unknown', 'bg-gray-200 text-gray-500'],
        };
    }

    // -----------------------------------------------------------------------
    // Class badge Tailwind map (for Blade threshold editor)
    // -----------------------------------------------------------------------
    private function classBadgeMap(): array
    {
        return [
            'A' => 'bg-purple-600 text-white',
            'B' => 'bg-blue-500 text-white',
            'C' => 'bg-yellow-400 text-gray-900',
            'D' => 'bg-orange-400 text-white',
            'E' => 'bg-gray-400 text-white',
        ];
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

        // Class params
        $classFilter = $request->input('class_filter', '');

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

        // -- Load class thresholds (sorted descending for classification) ---
        $classThresholdsFull = ItemClassThreshold::orderBy('sort_order')->get();
        $classThresholds = $classThresholdsFull
            ->sortByDesc('min_velocity')
            ->map(fn($t) => ['class_key' => $t->class_key, 'min_velocity' => (float)$t->min_velocity])
            ->values()
            ->toArray();
        $classBadgeMap = $this->classBadgeMap();

        // -- 1. Hold counts -------------------------------------------------
        // Scope: last month (1st) onwards, by ts_date.
        // e.g. if today = Apr 24, filter ts_date >= Mar 1.
        $holdFromDate = Carbon::parse($asOfDate, 'Asia/Manila')
            ->startOfMonth()
            ->subMonth()
            ->toDateString();

        $holdBase = DB::table('macro_output as mo')
            ->leftJoin('from_jnts as fj', 'fj.waybill_number', '=', 'mo.waybill')
            ->whereNull('fj.waybill_number')
            ->whereRaw("NULLIF(TRIM($moWaybill), '') IS NOT NULL")
            ->where('mo.ts_date', '>=', $holdFromDate);

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

        $holdUnitsMap = [];
        foreach ($holdRows as $r) {
            [$qty, $base] = $this->parseItem((string)($r->item_name ?? ''));
            $holdUnitsMap[$base] = ($holdUnitsMap[$base] ?? 0) + $qty * (int)$r->hold_count;
        }

        // -- 2. Velocity ----------------------------------------------------
        $velQuery = DB::table('macro_output')
            ->whereBetween('ts_date', [$velocityFrom, $asOfDate])
            ->selectRaw("$itemCol as item_name, COUNT(*) as order_count")
            ->groupByRaw($itemCol);

        if ($q !== '') {
            $velQuery->where($itemCol, $likeOp === 'ILIKE' ? 'ilike' : 'like', "%{$q}%");
        }

        $velRows = $velQuery->get();

        $velUnitsMap = [];
        foreach ($velRows as $r) {
            [$qty, $base] = $this->parseItem((string)($r->item_name ?? ''));
            $velUnitsMap[$base] = ($velUnitsMap[$base] ?? 0) + $qty * (int)$r->order_count;
        }

        // -- 3. RTS% --------------------------------------------------------
        $rtsRows = DB::table('page_item_settings')
            ->selectRaw('item_name, MAX(rts_pct) as max_rts')
            ->groupBy('item_name')
            ->get();

        $rtsMap = [];
        foreach ($rtsRows as $r) {
            [, $base] = $this->parseItem((string)($r->item_name ?? ''));
            $rtsMap[$base] = max($rtsMap[$base] ?? 0, (float)($r->max_rts ?? 0));
        }

        // -- 4. Supply settings ---------------------------------------------
        $supplySettings = SupplyItemSetting::all()->keyBy('item_name');

        // -- 5. First order date (for lifecycle) ----------------------------
        $firstDateRows = DB::table('macro_output')
            ->selectRaw("$itemCol as item_name, MIN(ts_date) as first_date")
            ->groupByRaw($itemCol)
            ->get();

        $firstDateMap = [];
        foreach ($firstDateRows as $r) {
            [, $base] = $this->parseItem((string)($r->item_name ?? ''));
            $existing = $firstDateMap[$base] ?? null;
            if ($existing === null || $r->first_date < $existing) {
                $firstDateMap[$base] = $r->first_date;
            }
        }

        // -- 6. Recent window velocity --------------------------------------
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

        // -- 7. Previous window velocity ------------------------------------
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

        // -- 8. Merge all items ---------------------------------------------
        $allBases = array_unique(array_merge(
            array_keys($holdUnitsMap),
            array_keys($velUnitsMap)
        ));

        if ($q !== '') {
            $allBases = array_filter($allBases, fn($b) => stripos($b, $q) !== false);
        }

        $asOfCarbon      = Carbon::parse($asOfDate, 'Asia/Manila');
        $velocityFromCar = Carbon::parse($velocityFrom, 'Asia/Manila');

        $items = [];
        foreach ($allBases as $base) {
            if ($base === '—') continue;

            $holdUnits     = $holdUnitsMap[$base] ?? 0;
            $totalVelUnits = $velUnitsMap[$base] ?? 0;

            // Velocity denominator = days the item actually existed within the window
            // (raw, no minimum — true velocity for new items)
            $firstDateForVel = $firstDateMap[$base] ?? null;
            if ($firstDateForVel !== null) {
                $firstCar = Carbon::parse($firstDateForVel, 'Asia/Manila');
                // Effective window start = later of (first_order_date, velocity_from)
                $effectiveStart = $firstCar->gt($velocityFromCar) ? $firstCar : $velocityFromCar;
                $effectiveDays  = max(1, (int)$effectiveStart->diffInDays($asOfCarbon) + 1);
                $effectiveDays  = min($velocityDays, $effectiveDays);
            } else {
                $effectiveDays = $velocityDays;
            }
            $velPerDay = $effectiveDays > 0 ? round($totalVelUnits / $effectiveDays, 2) : 0.0;

            $rtsPct       = $rtsMap[$base] ?? 0.0;
            $deliveryRate = max(0.01, 1 - $rtsPct / 100);

            /** @var SupplyItemSetting|null $setting */
            $setting = $supplySettings->get($base);

            $leadTime   = $setting?->lead_time_days ?? $defaultLeadTime;
            $safetyDays = $setting?->safety_days    ?? $defaultSafetyDays;

            if ($setting && $setting->is_running !== null) {
                $isRunning = (bool)$setting->is_running;
            } else {
                $isRunning = $velPerDay >= $runningThreshold;
            }

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

            $recentVel = $recentDays > 0
                ? round(($recentUnitsMap[$base] ?? 0) / $recentDays, 4)
                : 0.0;
            $prevVel = $recentDays > 0
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

            // -- Item Class (A–E) -------------------------------------------
            $classOverride = $setting?->class_override ?? null;
            if ($classOverride) {
                $itemClass     = $classOverride;
                $itemClassAuto = false;
            } else {
                $itemClass     = $this->classifyItemClass($velPerDay, $lifecycle, $classThresholds);
                $itemClassAuto = true;
            }

            [$itemClassLabel, $itemClassBadge] = $this->classBadge($itemClass);

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
                'item_class'          => $itemClass,
                'item_class_auto'     => $itemClassAuto,
                'item_class_label'    => $itemClassLabel,
                'item_class_badge'    => $itemClassBadge,
            ];
        }

        // Apply filters
        if ($lifecycleFilter !== '') {
            $items = array_values(array_filter($items, fn($i) => $i['lifecycle'] === $lifecycleFilter));
        }
        if ($classFilter !== '') {
            $items = array_values(array_filter($items, fn($i) => $i['item_class'] === $classFilter));
        }

        // Sort by recommended DESC, then item ASC
        usort($items, fn($a, $b) =>
            $b['recommended'] <=> $a['recommended'] ?: strcmp($a['item'], $b['item'])
        );

        $totalHoldUnits   = array_sum(array_column($items, 'hold_units'));
        $totalRecommended = array_sum(array_column($items, 'recommended'));
        $itemsWithHolds   = count(array_filter($items, fn($i) => $i['hold_units'] > 0));

        // CEO check for threshold editor
        $isCeo = Auth::user()?->employeeProfile?->role === 'CEO';

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
            'classFilter',
            'classThresholdsFull',
            'classBadgeMap',
            'isCeo',
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
            'class_override'     => 'nullable|in:,A,B,C,D,E',
        ]);

        $itemName  = $validated['item_name'];
        $isRunning = null;
        if (isset($validated['is_running']) && $validated['is_running'] !== 'auto') {
            $isRunning = (bool)(int)$validated['is_running'];
        }

        $lifecycleOverride = ($validated['lifecycle_override'] ?? '') !== ''
            ? $validated['lifecycle_override']
            : null;

        $classOverride = ($validated['class_override'] ?? '') !== ''
            ? $validated['class_override']
            : null;

        $updateData = array_filter([
            'lead_time_days'  => $validated['lead_time_days'] ?? null,
            'safety_days'     => $validated['safety_days']    ?? null,
            'is_running'      => $isRunning,
            'notes'           => $validated['notes']          ?? null,
        ], fn($v) => $v !== null);

        // Handle nullable overrides explicitly (clearing = set to null)
        if (array_key_exists('lifecycle_override', $validated)) {
            $updateData['lifecycle_override'] = $lifecycleOverride;
        }
        if (array_key_exists('class_override', $validated)) {
            $updateData['class_override'] = $classOverride;
        }

        SupplyItemSetting::updateOrCreate(
            ['item_name' => $itemName],
            $updateData
        );

        return response()->json(['success' => true]);
    }

    // -----------------------------------------------------------------------
    // POST /jnt/supply/class-thresholds — CEO only, save velocity thresholds
    // -----------------------------------------------------------------------
    public function saveClassThresholds(Request $request)
    {
        // CEO-only guard
        $role = Auth::user()?->employeeProfile?->role;
        if ($role !== 'CEO') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'class_key'    => 'required|in:A,B,C,D,E',
            'min_velocity' => 'required|numeric|min:0',
        ]);

        // Class E is always 0 — prevent accidental changes
        if ($validated['class_key'] === 'E') {
            return response()->json(['success' => true, 'note' => 'Class E is always 0']);
        }

        ItemClassThreshold::where('class_key', $validated['class_key'])
            ->update(['min_velocity' => (float)$validated['min_velocity']]);

        return response()->json(['success' => true]);
    }
}
