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

        $velocityFrom = Carbon::parse($asOfDate, 'Asia/Manila')
            ->subDays($velocityDays - 1)
            ->toDateString();

        // -- 1. Hold counts (all pending, no date filter) --------------------
        // Hold = in macro_output WITH a waybill, but waybill NOT in from_jnts
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
            ->selectRaw(
                ($driver === 'pgsql' ? '"ITEM_NAME"' : '`ITEM_NAME`') . ' as item_name, COUNT(*) as order_count'
            )
            ->groupByRaw($driver === 'pgsql' ? '"ITEM_NAME"' : '`ITEM_NAME`');

        if ($q !== '') {
            $velQuery->where(
                $driver === 'pgsql' ? '"ITEM_NAME"' : '`ITEM_NAME`',
                $likeOp === 'ILIKE' ? 'ilike' : 'like',
                "%{$q}%"
            );
        }

        $velRows = $velQuery->get();

        // Accumulate velocity units per base item
        $velUnitsMap = []; // base_item => total_units (over period)
        foreach ($velRows as $r) {
            [$qty, $base] = $this->parseItem((string)($r->item_name ?? ''));
            $velUnitsMap[$base] = ($velUnitsMap[$base] ?? 0) + $qty * (int)$r->order_count;
        }

        // -- 3. RTS% per item (max across pages from page_item_settings) ----
        // Take max rts_pct per item_name (most conservative)
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

        // -- 5. Merge all items (union of hold items + velocity items) -------
        $allBases = array_unique(array_merge(
            array_keys($holdUnitsMap),
            array_keys($velUnitsMap)
        ));

        // Apply search filter on base item name
        if ($q !== '') {
            $allBases = array_filter($allBases, fn($b) => stripos($b, $q) !== false);
        }

        $items = [];
        foreach ($allBases as $base) {
            if ($base === '—') continue;

            $holdUnits  = $holdUnitsMap[$base] ?? 0;
            $totalVelUnits = $velUnitsMap[$base] ?? 0;
            $velPerDay  = $velocityDays > 0 ? round($totalVelUnits / $velocityDays, 2) : 0.0;

            $rtsPct        = $rtsMap[$base] ?? 0.0;
            $deliveryRate  = max(0.01, 1 - $rtsPct / 100);

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

            $items[] = [
                'item'             => $base,
                'hold_units'       => $holdUnits,
                'vel_per_day'      => $velPerDay,
                'strength_label'   => $strengthLabel,
                'strength_class'   => $strengthClass,
                'is_running'       => $isRunning,
                'is_running_auto'  => ($setting?->is_running === null),
                'rts_pct'          => round($rtsPct, 1),
                'delivery_rate'    => round($deliveryRate * 100, 1),
                'lead_time_days'   => $leadTime,
                'safety_days'      => $safetyDays,
                'holds_gross'      => $holdsGross,
                'recommended'      => $recommended,
                'notes'            => $setting?->notes ?? '',
            ];
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
        ));
    }

    // -----------------------------------------------------------------------
    // POST /jnt/supply/settings — inline save per item
    // -----------------------------------------------------------------------
    public function saveSettings(Request $request)
    {
        $validated = $request->validate([
            'item_name'      => 'required|string|max:255',
            'lead_time_days' => 'nullable|integer|min:1|max:365',
            'safety_days'    => 'nullable|integer|min:0|max:365',
            'is_running'     => 'nullable|in:0,1,auto',
            'notes'          => 'nullable|string|max:500',
        ]);

        $itemName   = $validated['item_name'];
        $isRunning  = null;
        if (isset($validated['is_running']) && $validated['is_running'] !== 'auto') {
            $isRunning = (bool)(int)$validated['is_running'];
        }

        SupplyItemSetting::updateOrCreate(
            ['item_name' => $itemName],
            array_filter([
                'lead_time_days' => $validated['lead_time_days'] ?? null,
                'safety_days'    => $validated['safety_days']    ?? null,
                'is_running'     => $isRunning,
                'notes'          => $validated['notes']          ?? null,
            ], fn($v) => $v !== null)
        );

        return response()->json(['success' => true]);
    }
}
