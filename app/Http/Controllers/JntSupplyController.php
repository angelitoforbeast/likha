<?php

namespace App\Http\Controllers;

use App\Models\Cogs;
use App\Models\FeeSetting;
use App\Models\ItemClassRule;
use App\Models\ItemClassThreshold;
use App\Models\PageItemSetting;
use App\Models\SupplyExcludedPage;
use App\Models\SupplyItemSetting;
use App\Models\SupplySetting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
    // Item class classification — DB-driven via item_class_rules table.
    // Rules are evaluated in sort_order; first match wins.
    //
    // Supported rule_type values:
    //   - 'age_lt'        → age < age_threshold   (new-item guard)
    //   - 'velocity_tier' → age ≥ window AND window_avg ≥ min_velocity
    //                       AND alive_avg ≥ alive_min
    //   - 'catch_all'     → always matches (place last)
    // -----------------------------------------------------------------------
    private function classifyItemClass(
        iterable $rules,
        int $daysRunning,
        callable $windowSum
    ): string {
        $fallback = 'X';

        foreach ($rules as $rule) {
            $type = $rule->rule_type;

            if ($type === ItemClassRule::TYPE_AGE_LT) {
                $t = (int) ($rule->age_threshold ?? 0);
                if ($t > 0 && $daysRunning < $t) {
                    return $rule->class_key;
                }
                continue;
            }

            if ($type === ItemClassRule::TYPE_VELOCITY_TIER) {
                $win       = (int)   ($rule->window_days  ?? 0);
                $minVel    = (float) ($rule->min_velocity ?? 0);
                $aliveWin  = (int)   ($rule->alive_window ?? $win);
                $aliveMin  = (float) ($rule->alive_min    ?? 0);
                if ($win <= 0) continue;

                if ($daysRunning < $win) continue;

                $windowAvg = $windowSum($win) / $win;
                if ($windowAvg < $minVel) continue;

                if ($aliveWin > 0 && $aliveMin > 0) {
                    $aliveAvg = $windowSum($aliveWin) / $aliveWin;
                    if ($aliveAvg < $aliveMin) continue;
                }
                return $rule->class_key;
            }

            if ($type === ItemClassRule::TYPE_CATCH_ALL) {
                $fallback = $rule->class_key;
                // don't return yet — a later rule could still match; but by
                // sort_order, catch_all should be last. Safe to return.
                return $rule->class_key;
            }
        }

        return $fallback;
    }

    // -----------------------------------------------------------------------
    // GET /jnt/supply
    // -----------------------------------------------------------------------
    public function index(Request $request)
    {
        $today     = Carbon::now('Asia/Manila')->toDateString();
        // Default as-of date is yesterday (so full-day data is stable).
        $yesterday = Carbon::now('Asia/Manila')->subDay()->toDateString();
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
        $asOfDate          = $request->input('as_of_date', $yesterday);
        // Debug instrumentation: ?debug_item=<base> dumps per-(page,date) slices for that item
        // as JSON so we can compare column-by-column with /owner/private. Off by default.
        $debugItem = strtolower(trim((string) $request->input('debug_item', '')));

        // Lifecycle params
        $recentDays        = max(1, (int) $request->input('recent_days', 14));
        $newItemDays       = max(1, (int) $request->input('new_item_days', 30));
        $longRunningDays   = max(1, (int) $request->input('long_running_days', 90));
        $scaleThreshold    = max(1.0, (float) $request->input('scale_threshold', 1.5));
        $declineThreshold  = min(1.0, max(0.0, (float) $request->input('decline_threshold', 0.5)));
        $lifecycleFilter   = $request->input('lifecycle_filter', '');

        // Class params
        $classFilter = $request->input('class_filter', '');

        // Profit filter (color bucket: green|yellow|orange|red|missing|'')
        $profitFilter = $request->input('profit_filter', '');


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

        // -- Load class rules (DB-driven CRUD) ------------------------------
        $classRules = ItemClassRule::ordered()->get();

        // Badge map: class_key => ['label', 'badge_tailwind']
        $classBadgeMap = [];
        foreach ($classRules as $r) {
            $classBadgeMap[$r->class_key] = [
                'label'  => $r->label,
                'badge'  => $r->badge_tailwind,
            ];
        }

        // Available palette for badge color dropdown
        $classPalette = ItemClassRule::palette();

        // -- Load supply_settings (CEO-editable KV, lifecycle defaults) -----
        $supplyKv = SupplySetting::asMap();
        $supplySettingsAll = SupplySetting::orderBy('group')->orderBy('sort_order')->get();

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

        // -- 3b. RTS% DETAIL for transparency (shown in RTS% column tooltip).
        // Per (base_item, page_name, qty-variant) → list of rts values so user
        // can see how many distinct values exist (the displayed RTS is the MAX).
        $rtsDetailRows = DB::table('page_item_settings')
            ->whereNotNull('rts_pct')
            ->get(['page_name', 'item_name', 'rts_pct']);
        $rtsDetailMap = []; // base => [ ['page'=>..., 'variant'=>..., 'rts'=>...], ... ]
        foreach ($rtsDetailRows as $r) {
            [, $base] = $this->parseItem((string)($r->item_name ?? ''));
            if ($base === '') continue;
            $rtsDetailMap[$base][] = [
                'page'    => (string)$r->page_name,
                'variant' => (string)$r->item_name,
                'rts'     => (float)$r->rts_pct,
            ];
        }

        // -- 3c. LAST ORDER DATE per base item (uses macro_output).
        //        Grouped by the same item_name column used for velocity, then
        //        parseItem-reduced to base so qty-variants collapse together.
        $lastDateRows = DB::table('macro_output')
            ->selectRaw("$itemCol as item_name, MAX(ts_date) as last_date")
            ->groupByRaw($itemCol)
            ->get();
        $lastDateMap = [];
        foreach ($lastDateRows as $r) {
            [, $base] = $this->parseItem((string)($r->item_name ?? ''));
            if ($base === '') continue;
            $existing = $lastDateMap[$base] ?? null;
            if ($existing === null || $r->last_date > $existing) {
                $lastDateMap[$base] = (string)$r->last_date;
            }
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

        // -- 7b. Class-window aggregates: per-item-per-date orders within
        //       the largest window required by any active rule. Sub-windows
        //       computed in PHP via $windowSum closure.
        $maxClassWindow = 1;
        foreach ($classRules as $r) {
            if ($r->rule_type === ItemClassRule::TYPE_VELOCITY_TIER) {
                $maxClassWindow = max($maxClassWindow, (int)($r->window_days ?? 0), (int)($r->alive_window ?? 0));
            }
        }
        $classFromDate  = Carbon::parse($asOfDate, 'Asia/Manila')
            ->subDays($maxClassWindow - 1)
            ->toDateString();

        $classRows = DB::table('macro_output')
            ->whereBetween('ts_date', [$classFromDate, $asOfDate])
            ->selectRaw("$itemCol as item_name, ts_date, COUNT(*) as cnt")
            ->groupByRaw("$itemCol, ts_date")
            ->get();

        // daily[base][yyyy-mm-dd] = units that day
        $daily = [];
        foreach ($classRows as $r) {
            [$qty, $base] = $this->parseItem((string)($r->item_name ?? ''));
            $d = (string) $r->ts_date;
            $daily[$base][$d] = ($daily[$base][$d] ?? 0) + $qty * (int)$r->cnt;
        }

        // Helper: sum of units in window ending at $asOfDate covering $days calendar days
        $windowSum = function (string $base, int $days) use ($daily, $asOfDate) {
            if ($days <= 0) return 0;
            $sum = 0;
            $end = Carbon::parse($asOfDate, 'Asia/Manila');
            for ($i = 0; $i < $days; $i++) {
                $d = $end->copy()->subDays($i)->toDateString();
                $sum += $daily[$base][$d] ?? 0;
            }
            return $sum;
        };

        // -- 7c. PROFIT% compute data -------------------------------------
        // Window = max class.window_days (includes Y/X defaults from migration).
        $maxProfitWindow = $maxClassWindow;
        foreach ($classRules as $r) {
            $maxProfitWindow = max($maxProfitWindow, (int) ($r->window_days ?? 0));
        }
        $maxProfitWindow = max(1, $maxProfitWindow);
        $profitFrom = Carbon::parse($asOfDate, 'Asia/Manila')
            ->subDays($maxProfitWindow - 1)
            ->toDateString();

        // Excluded pages (affects profit% only)
        $excludedSet = array_flip(SupplyExcludedPage::excludedSet());

        // Normalize an item label the SAME way daily_page_primary_item.primary_item_key does:
        // LOWER + strip spaces/dashes/underscores.
        $normItem = fn(string $s) => strtolower(preg_replace('/[\s\-_]+/u', '', $s));

        // === PRIMARY ITEMS (materialized): read cached (date, page, primary_item, mode_cod)
        // Absence of row for a (date, page_key) = tied/unresolved → automatically excluded.
        $primaryRowsProfit = DB::table('daily_page_primary_item')
            ->whereBetween('ts_date', [$profitFrom, $asOfDate])
            ->get([
                'ts_date', 'page_key', 'primary_item', 'primary_orders', 'primary_mode_cod',
            ]);

        // $primaryByBase[base_key_normalized][date][page_key] = ['orders', 'mode_cod']
        // $primaryPageSet[date][page_key] = true  (used later to attribute ads only when
        //   this base is THE primary on that slice — same semantics as before)
        $primaryByBase = [];
        $primaryPageSet = [];
        foreach ($primaryRowsProfit as $r) {
            $pgKey = (string) $r->page_key;
            if ($pgKey === '' || isset($excludedSet[$pgKey])) continue;

            $d = (string) $r->ts_date;
            // primary_item still carries qty prefix ("2 x FLASHLIGHT") → strip via parseItem
            [, $base] = $this->parseItem((string) $r->primary_item);
            if ($base === '' || $base === '—') continue;

            $baseKey = $normItem($base);
            $primaryByBase[$baseKey][$d][$pgKey] = [
                'orders'   => (int) $r->primary_orders,
                'mode_cod' => $r->primary_mode_cod !== null ? (float) $r->primary_mode_cod : 0.0,
                // Full qty-preserving name (e.g. "1 x MINI FLASHLIGHT") — needed so
                // page_item_settings lookup matches /owner/private's exact-qty key.
                'item_raw' => (string) $r->primary_item,
            ];
            $primaryPageSet[$d][$pgKey] = true;
        }

        // === PROCEED ORDERS (from macro_output) — mirrors /owner/private ===
        // Build $proceedMap[item_key][date][page_key] = proceed_orders
        // where proceed_orders = COUNT(rows with status='proceed') grouped by
        // (DATE(ts_date), page_key, item_norm). Normalization must match $normItem
        // (lower + strip spaces/dashes/underscores).
        $quoteCol   = fn(string $col) => $driver === 'pgsql' ? '"'.$col.'"' : '`'.$col.'`';
        $trimFn     = $driver === 'pgsql' ? 'BTRIM' : 'TRIM';
        $pickCol    = function (string $table, array $candidates) {
            foreach ($candidates as $c) {
                if (Schema::hasColumn($table, $c)) return $c;
            }
            return null;
        };
        $moPageCol   = $pickCol('macro_output', ['PAGE','page','page_name','Page']) ?? 'PAGE';
        $moItemCol   = $pickCol('macro_output', ['ITEM_NAME','item_name','ITEM','item']) ?? 'ITEM_NAME';
        $moStatusCol = $pickCol('macro_output', ['STATUS','status','Status']) ?? 'STATUS';
        $moPageExpr   = 'mo.'.$quoteCol($moPageCol);
        $moItemExpr   = 'mo.'.$quoteCol($moItemCol);
        $moStatusExpr = 'mo.'.$quoteCol($moStatusCol);

        $po_statusNorm = "LOWER(REPLACE(REPLACE($trimFn($moStatusExpr),' ',''),'_',''))";
        $po_pageTrim   = "$trimFn(COALESCE($moPageExpr,''))";
        $po_pageKey    = "LOWER($po_pageTrim)";
        $po_itemTrim   = "$trimFn(COALESCE($moItemExpr,''))";
        // NOTE: itemTrim keeps the raw name (with possible qty prefix like "2 x ...").
        // We strip qty + normalize in PHP below (must match parseItem + $normItem).

        if (Schema::hasColumn('macro_output', 'ts_date')) {
            $po_dateExpr = 'DATE(mo.ts_date)';
        } else {
            $tsCol = $pickCol('macro_output', ['TIMESTAMP','timestamp']) ?? 'created_at';
            $tsRef = 'mo.'.$quoteCol($tsCol);
            if ($driver === 'mysql') {
                $po_dateExpr = "COALESCE(DATE(STR_TO_DATE($tsRef,'%H:%i %d-%m-%Y')),DATE(STR_TO_DATE($tsRef,'%H:%i %m-%d-%Y')),DATE(mo.`created_at`))";
            } else {
                $po_dateExpr = "DATE(COALESCE(TO_TIMESTAMP(NULLIF($tsRef,''),'HH24:MI DD-MM-YYYY'),TO_TIMESTAMP(NULLIF($tsRef,''),'HH24:MI MM-DD-YYYY'),mo.\"created_at\"))";
            }
        }

        // Pull raw (date, page_key, raw item name, proceed_count) — strip qty prefix in PHP
        // so the item_key matches $baseKey = $normItem(parseItem(...)[1]) used upstream.
        $proceedRows = DB::table('macro_output as mo')
            ->whereRaw("$po_dateExpr BETWEEN ? AND ?", [$profitFrom, $asOfDate])
            ->whereRaw("$po_pageTrim != ''")
            ->selectRaw("
                $po_dateExpr   AS d,
                $po_pageKey    AS page_key,
                $po_itemTrim   AS item_raw,
                SUM(CASE WHEN $po_statusNorm = 'proceed' THEN 1 ELSE 0 END) AS proceed_orders
            ")
            ->groupByRaw("$po_dateExpr, $po_pageKey, $po_itemTrim")
            ->get();

        // $proceedMap[base_key][date][page_key] = proceed_orders (summed across qty variants)
        $proceedMap = [];
        foreach ($proceedRows as $pr) {
            $raw = (string) $pr->item_raw;
            $pk  = (string) $pr->page_key;
            $dd  = (string) $pr->d;
            if ($raw === '' || $pk === '') continue;
            [, $baseName] = $this->parseItem($raw);              // strip "2 x " etc.
            if ($baseName === '' || $baseName === '—') continue;
            $ik = $normItem($baseName);                           // same normalizer as upstream
            $proceedMap[$ik][$dd][$pk] = ($proceedMap[$ik][$dd][$pk] ?? 0) + (int) $pr->proceed_orders;
        }

        // Ads per (day, page_key) — mirrors /owner/private exactly (line 1367-1381):
        //   1) DATE(day) cast so DATETIME columns don't fragment groups by timestamp.
        //   2) $castMoney sanitizes ₱/,/spaces from amount_spent_php before SUM.
        $castMoneyAds = $driver === 'pgsql'
            ? "COALESCE(NULLIF(REGEXP_REPLACE(COALESCE((amount_spent_php)::text, ''), '[^0-9\\.\\-]', '', 'g'), '')::numeric, 0)"
            : "CAST(REPLACE(REPLACE(REPLACE(COALESCE(amount_spent_php,''), '₱',''), ',', ''), ' ', '') AS DECIMAL(18,2))";
        $adsPageKeyExpr = "LOWER($trimFn(COALESCE(page_name,'')))";
        $adsRows = DB::table('ads_manager_reports')
            ->whereRaw('DATE(day) BETWEEN ? AND ?', [$profitFrom, $asOfDate])
            ->selectRaw("
                DATE(day) AS d,
                $adsPageKeyExpr AS pg,
                SUM($castMoneyAds) AS spent
            ")
            ->groupByRaw("DATE(day), $adsPageKeyExpr")
            ->get();

        $adsMap = [];
        foreach ($adsRows as $r) {
            $adsMap[(string) $r->d][(string) $r->pg] = (float) $r->spent;
        }

        // COGS as-of asOfDate — keyed by QTY-EXACT item name (mirrors /owner/private).
        //
        // Why qty-exact: the cogs table stores one row per SKU variant (e.g.
        // "1 x ANTI - PULGAS" = ₱17 and "2 x ANTI - PULGAS" = ₱34 are separate entries
        // because the 2x bundle has 2× the material). Previously this code stripped
        // the qty prefix and keyed by base name, causing the first-seen variant
        // (alphabetical) to silently win — e.g. a legacy "1 x" row at ₱17 would mask
        // the current "2 x" row at ₱34, halving cogs on every 2x slice. That bug
        // inflated PROJ PROFIT% across every item with a qty prefix.
        //
        // Two indexes built:
        //   $cogsMapExact[lower("2 x anti - pulgas")] = 34   — authoritative, used first
        //   $cogsMapBase [lower("anti - pulgas")]      = 17  — fallback only (first-seen
        //                                                     by date DESC so latest wins)
        $cogsRows = DB::table('cogs')
            ->where('date', '<=', $asOfDate)
            ->orderBy('item_name')
            ->orderByDesc('date')
            ->get(['item_name', 'date', 'unit_cost']);
        $cogsMapExact = [];   // exact_key => ['unit_cost', 'item_name']
        $cogsMapBase  = [];   // base_key  => ['unit_cost', 'item_name']
        foreach ($cogsRows as $r) {
            $rawName = (string) ($r->item_name ?? '');
            if ($rawName === '') continue;
            $exactKey = mb_strtolower(trim($rawName));
            if (!isset($cogsMapExact[$exactKey])) {
                $cogsMapExact[$exactKey] = [
                    'unit_cost' => (float) $r->unit_cost,
                    'item_name' => $rawName,
                ];
            }
            [, $base] = $this->parseItem($rawName);
            $baseKey = mb_strtolower(trim((string) $base));
            if ($baseKey !== '' && !isset($cogsMapBase[$baseKey])) {
                $cogsMapBase[$baseKey] = [
                    'unit_cost' => (float) $r->unit_cost,
                    'item_name' => $rawName,
                ];
            }
        }

        // PageItemSetting overrides: latest effective_date ≤ asOfDate per (page, item).
        // Only rts_pct is per-page — unit cost is now sourced globally from `cogs`
        // (see migration 2026_04_24_000010). Key by QTY-EXACT item name so different
        // qty variants don't cross-contaminate (e.g. Luna's "1 x" vs "2 x" settings).
        $pisRows = PageItemSetting::where('effective_date', '<=', $asOfDate)
            ->orderBy('page_name')
            ->orderBy('item_name')
            ->orderByDesc('effective_date')
            ->get(['page_name', 'item_name', 'rts_pct']);
        $pisMap = [];
        foreach ($pisRows as $r) {
            $pgKey   = mb_strtolower(trim((string) $r->page_name));
            $ikExact = mb_strtolower(trim((string) ($r->item_name ?? '')));
            if ($ikExact === '') continue;
            if (!isset($pisMap[$pgKey][$ikExact])) {
                $pisMap[$pgKey][$ikExact] = [
                    'rts_pct' => $r->rts_pct !== null ? (float) $r->rts_pct : null,
                ];
            }
        }

        // Fee settings
        $host = strtolower((string) $request->getHost());
        $COD_FEE_RATE         = FeeSetting::getRate('cod_fee_rate', $host, $asOfDate) ?? 0.015;
        $COD_FEE_VAT_RATE     = FeeSetting::getRate('cod_fee_vat_rate', $host, $asOfDate) ?? 0.12;
        $SHIPPING_PER_SHIPPED = 37.0;

        // Build a lookup of class_key → window_days (from loaded rules)
        $classWindowMap = [];
        foreach ($classRules as $r) {
            $classWindowMap[$r->class_key] = (int) ($r->window_days ?? 30);
            if ($classWindowMap[$r->class_key] <= 0) $classWindowMap[$r->class_key] = 30;
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

            // -- Item Class (DB-driven via ItemClassRule) -------------------
            // Per-base window-sum closure (binds the item name)
            $sumFor = fn(int $days) => $windowSum($base, $days);

            $classOverride = $setting?->class_override ?? null;
            if ($classOverride && isset($classBadgeMap[$classOverride])) {
                $itemClass     = $classOverride;
                $itemClassAuto = false;
            } else {
                $itemClass     = $this->classifyItemClass($classRules, $daysRunning, $sumFor);
                $itemClassAuto = true;
            }

            $itemClassLabel = $classBadgeMap[$itemClass]['label'] ?? ($itemClass . ' · Unknown');
            $itemClassBadge = $classBadgeMap[$itemClass]['badge'] ?? 'bg-gray-200 text-gray-500';

            // Per-rule diagnostic averages (for hover tooltip)
            $ruleDiagnostics = [];
            foreach ($classRules as $r) {
                if ($r->rule_type !== ItemClassRule::TYPE_VELOCITY_TIER) continue;
                $win   = (int)($r->window_days  ?? 0);
                $aliveW= (int)($r->alive_window ?? 0);
                $ruleDiagnostics[$r->class_key] = [
                    'win_avg'   => $win > 0      ? round($windowSum($base, $win)    / $win,    2) : 0,
                    'alive_avg' => $aliveW > 0   ? round($windowSum($base, $aliveW) / $aliveW, 2) : 0,
                ];
            }

            // -- Projected Profit% -----------------------------------------
            // Sources slices EXCLUSIVELY from daily_page_primary_item.
            // Tied / unresolved slices are already absent from that table → no manual skip needed here.
            $profitWindowDays = $classWindowMap[$itemClass] ?? 30;
            $profitStartDate  = $asOfCarbon->copy()->subDays($profitWindowDays - 1)->toDateString();

            $sumGross      = 0.0;
            $sumNet        = 0.0;
            $mismatchCount = 0;   // cogs override vs base cogs mismatch slices
            $hasAnyCogs    = false;
            $usedAnyRts    = ($rtsPct > 0);

            // Per-row cogs transparency: track every qty-variant name + cogs actually
            // used during the slice loop, so the view can render new "ITEM NAME(S)"
            // and "COGS USED" columns for audit.
            //   $variantCogs[lower(item_raw)] = ['item_name', 'unit_cost', 'source' (exact|base|missing)]
            $variantCogs     = [];
            $missingCogsAny  = false;

            $baseKey  = $normItem($base);
            $perDate  = $primaryByBase[$baseKey] ?? [];

            // Capture per-slice diagnostics only when the request targets this item.
            $isDebugThis = ($debugItem !== '' && strtolower($base) === $debugItem);
            $debugSlices = [];

            // Ground truth: /owner/private → profit% = projected_profit / Σ(SRP × primary_orders)
            // Numerator uses PROCEED orders (delivered-only revenue + delivered-only COGS/COD fee,
            // but shipping is charged on all proceed attempts). Denominator uses all primary orders.
            foreach ($perDate as $d => $pagesForDate) {
                if ($d < $profitStartDate || $d > $asOfDate) continue;
                foreach ($pagesForDate as $pgKey => $slice) {
                    $orders  = (int) $slice['orders'];
                    if ($orders <= 0) continue;
                    $modeCod = (float) $slice['mode_cod'];

                    // proceed_orders from macro_output (status='proceed'); 0 when unresolved.
                    $proceed = (int) ($proceedMap[$baseKey][$d][$pgKey] ?? 0);

                    // QTY-EXACT key for RTS + COGS lookup (mirrors /owner/private).
                    $itemRaw      = (string) ($slice['item_raw'] ?? '');
                    $primaryExact = mb_strtolower(trim($itemRaw));

                    // COGS — qty-exact lookup first, fallback to base. See the
                    // $cogsMapExact/$cogsMapBase build above for rationale. This
                    // mirrors /owner/private which never strips the qty prefix.
                    $cogsHit = $cogsMapExact[$primaryExact]
                        ?? $cogsMapBase[mb_strtolower(trim($base))]
                        ?? null;
                    $unitCost    = $cogsHit['unit_cost'] ?? 0.0;
                    $cogsSource  = $cogsHit
                        ? (isset($cogsMapExact[$primaryExact]) ? 'exact' : 'base')
                        : 'missing';
                    if ($unitCost > 0) $hasAnyCogs = true;
                    if ($cogsSource === 'missing') $missingCogsAny = true;

                    // Track per-variant cogs used (for the new transparency columns).
                    if ($primaryExact !== '' && !isset($variantCogs[$primaryExact])) {
                        $variantCogs[$primaryExact] = [
                            'item_name' => $cogsHit['item_name'] ?? $itemRaw,
                            'item_raw'  => $itemRaw,
                            'unit_cost' => $unitCost,
                            'source'    => $cogsSource,
                        ];
                    }

                    // RTS — per-page override (qty-exact), fallback to item-wide rate.
                    $sliceRts      = $pisMap[$pgKey][$primaryExact]['rts_pct'] ?? $rtsPct;
                    $deliverFactor = max(0.0, 1.0 - $sliceRts / 100.0);

                    $ads       = (float) ($adsMap[$d][$pgKey] ?? 0);
                    $codFeeDay = $modeCod * $COD_FEE_RATE * (1 + $COD_FEE_VAT_RATE);

                    // Denominator: Σ(SRP × primary_orders) — matches /owner/private
                    $sumGross += $modeCod * $orders;

                    // Numerator: proceed-based, delivered-only — matches /owner/private
                    $revenue  = $proceed * $modeCod * $deliverFactor;
                    $shipping = $proceed * $SHIPPING_PER_SHIPPED;
                    $cogs     = $proceed * $deliverFactor * $unitCost;
                    $codFee   = $proceed * $deliverFactor * $codFeeDay;
                    $netCon   = $revenue - $shipping - $cogs - $ads - $codFee;

                    $sumNet += $netCon;

                    if ($isDebugThis) {
                        $debugSlices[] = [
                            'date'          => $d,
                            'page_key'      => $pgKey,
                            'item_raw'      => (string)($slice['item_raw'] ?? ''),
                            'orders'        => $orders,
                            'mode_cod'      => round($modeCod, 2),
                            'proceed'       => $proceed,
                            'rts_pct'       => round($sliceRts, 2),
                            'rts_source'    => isset($pisMap[$pgKey][$primaryExact]['rts_pct']) ? 'pis_override' : 'fallback_max',
                            'unit_cost'     => round($unitCost, 2),
                            'cogs_source'   => $cogsSource,
                            'ads'           => round($ads, 2),
                            'cod_fee_day'   => round($codFeeDay, 4),
                            'deliver_fct'   => round($deliverFactor, 4),
                            'gross_contrib' => round($modeCod * $orders, 2),
                            'revenue'       => round($revenue, 2),
                            'shipping'      => round($shipping, 2),
                            'cogs_cost'     => round($cogs, 2),
                            'cod_fee_cost'  => round($codFee, 2),
                            'net_contrib'   => round($netCon, 2),
                        ];
                    }
                }
            }

            if ($isDebugThis) {
                // Short-circuit: render JSON dump so it's easy to compare against /owner/private.
                return response()->json([
                    'item'               => $base,
                    'item_class'         => $itemClass,
                    'profit_window_days' => $profitWindowDays,
                    'profit_start_date'  => $profitStartDate,
                    'as_of_date'         => $asOfDate,
                    'fallback_rts_pct'   => $rtsPct,
                    'total_orders'       => array_sum(array_column($debugSlices, 'orders')),
                    'total_proceed'      => array_sum(array_column($debugSlices, 'proceed')),
                    'total_ads'          => array_sum(array_column($debugSlices, 'ads')),
                    'sum_gross'          => round($sumGross, 2),
                    'sum_net'            => round($sumNet, 2),
                    'profit_pct'         => $sumGross > 0 ? round(($sumNet / $sumGross) * 100, 2) : null,
                    'slice_count'        => count($debugSlices),
                    'slices'             => $debugSlices,
                ], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }

            // User rule: if ANY required input is missing (no COGS for item OR no
            // RTS% for item), don't compute profit% at all — show as No data.
            // Per-slice missing cogs (cogs_source === 'missing') also counts as
            // "no COGS for this item", since a slice with ₱0 cost silently inflates
            // profit. If even one slice had missing cogs, we null the whole row.
            $profitPct     = null;
            $profitBucket  = 'missing';
            if ($sumGross > 0 && $hasAnyCogs && $usedAnyRts && !$missingCogsAny) {
                $profitPct = round(($sumNet / $sumGross) * 100, 2);
                if ($profitPct >= 15)       $profitBucket = 'green';
                elseif ($profitPct >= 5)    $profitBucket = 'yellow';
                elseif ($profitPct >= 0)    $profitBucket = 'orange';
                else                        $profitBucket = 'red';
            }

            $items[] = [
                'item'                => $base,
                'hold_units'          => $holdUnits,
                'vel_per_day'         => $velPerDay,
                'strength_label'      => $strengthLabel,
                'strength_class'      => $strengthClass,
                'is_running'          => $isRunning,
                'is_running_auto'     => ($setting?->is_running === null),
                'rts_pct'             => round($rtsPct, 1),
                // Transparency: all distinct (page, qty-variant, rts%) rows that
                // feed this item. Displayed RTS is the MAX of these; the tooltip
                // shows the full list so the user can see if multiple exist.
                'rts_details'         => (function() use ($rtsDetailMap, $base) {
                    $rows = $rtsDetailMap[$base] ?? [];
                    // De-dupe by (page|variant|rts) and sort for stable display.
                    $seen = [];
                    $out  = [];
                    foreach ($rows as $r) {
                        $k = strtolower($r['page']) . '|' . strtolower($r['variant']) . '|' . $r['rts'];
                        if (isset($seen[$k])) continue;
                        $seen[$k] = true;
                        $out[] = $r;
                    }
                    usort($out, function($a, $b) {
                        return $b['rts'] <=> $a['rts']
                            ?: strcmp($a['page'], $b['page'])
                            ?: strcmp($a['variant'], $b['variant']);
                    });
                    return $out;
                })(),
                // Last date this base item appeared in macro_output (any status).
                'last_order_date'     => $lastDateMap[$base] ?? null,
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
                'rule_diagnostics'    => $ruleDiagnostics,
                // Projected profit%
                'profit_pct'          => $profitPct,
                'profit_bucket'       => $profitBucket,
                'profit_window_days'  => $profitWindowDays,
                'profit_sum_gross'    => round($sumGross, 2),
                'profit_sum_net'      => round($sumNet, 2),
                'profit_skipped_days' => 0, // Ties now excluded at materialization; always 0 here.
                'profit_mismatch_ct'  => $mismatchCount,
                'profit_has_cogs'     => $hasAnyCogs,
                'profit_has_rts'      => $usedAnyRts,
                // Per-row transparency: which qty-exact variants fed into the profit
                // compute and the cogs each one resolved to. Sorted ascending so the
                // most common "1 x" appears before "2 x" etc.
                'cogs_variants'       => (function() use ($variantCogs) {
                    $out = array_values($variantCogs);
                    usort($out, fn($a, $b) => strcmp($a['item_raw'], $b['item_raw']));
                    return $out;
                })(),
                'cogs_missing_any'    => $missingCogsAny,
            ];
        }

        // Apply filters
        if ($lifecycleFilter !== '') {
            $items = array_values(array_filter($items, fn($i) => $i['lifecycle'] === $lifecycleFilter));
        }
        if ($classFilter !== '') {
            $items = array_values(array_filter($items, fn($i) => $i['item_class'] === $classFilter));
        }
        if ($profitFilter !== '') {
            $items = array_values(array_filter($items, fn($i) => $i['profit_bucket'] === $profitFilter));
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

        // Days-Last-Order color rules (CEO-editable in /jnt/supply/config).
        $dloColorRules = $this->loadColorRules();

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
            'profitFilter',
            'classRules',
            'classBadgeMap',
            'classPalette',
            'isCeo',
            'supplySettingsAll',
            'dloColorRules',
        ));
    }

    // -----------------------------------------------------------------------
    // GET /jnt/supply/config — CEO-only admin page:
    // Classification Rules CRUD + Other Settings (lifecycle / velocity defaults)
    // -----------------------------------------------------------------------
    public function config()
    {
        $isCeo = Auth::user()?->employeeProfile?->role === 'CEO';
        if (!$isCeo) abort(403);

        $classRules        = ItemClassRule::ordered()->get();
        $classPalette      = ItemClassRule::palette();
        $supplySettingsAll = SupplySetting::orderBy('group')->orderBy('sort_order')->get();

        // Days Last Order — color rules (CEO-editable). Stored as JSON in
        // app_settings. Each rule has { op, value, color, label }, evaluated
        // top-to-bottom; first match wins.
        $colorRules = $this->loadColorRules();

        return view('jnt.supply_config', compact(
            'classRules', 'classPalette', 'supplySettingsAll', 'isCeo', 'colorRules'
        ));
    }

    /**
     * Load Days-Last-Order color rules. Defaults if nothing saved.
     */
    private function loadColorRules(): array
    {
        $row = DB::table('app_settings')->where('key', 'supply_dlo_color_rules')->first(['value']);
        if ($row && $row->value) {
            $decoded = json_decode($row->value, true);
            if (is_array($decoded)) return $decoded;
        }
        // Sensible defaults if nothing configured yet — background-friendly
        // (light enough na readable yung text, gsheet-style).
        return [
            ['op' => '>=', 'value' => 30, 'color' => '#fecaca', 'label' => 'Cold (≥30d)',   'bold' => true],
            ['op' => '>=', 'value' => 7,  'color' => '#fed7aa', 'label' => 'Slowing (≥7d)', 'bold' => false],
            ['op' => '>=', 'value' => 2,  'color' => '#fef3c7', 'label' => 'Normal (≥2d)',  'bold' => false],
            ['op' => '<',  'value' => 2,  'color' => '#bbf7d0', 'label' => 'Fresh (<2d)',   'bold' => true],
        ];
    }

    // POST /jnt/supply/dlo-color-rules — CEO only, save the rules JSON.
    public function saveDloColorRules(Request $request)
    {
        $role = Auth::user()?->employeeProfile?->role;
        if ($role !== 'CEO') return response()->json(['error' => 'Unauthorized'], 403);

        $rules = $request->input('rules');
        if (is_string($rules)) {
            $decoded = json_decode($rules, true);
            $rules   = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($rules)) {
            return response()->json(['ok' => false, 'error' => 'Invalid rules payload'], 422);
        }

        // Sanitize each rule.
        $clean = [];
        $allowedOps = ['>', '>=', '=', '<=', '<'];
        foreach ($rules as $r) {
            if (!is_array($r)) continue;
            $op = (string) ($r['op'] ?? '');
            if (!in_array($op, $allowedOps, true)) continue;
            $val = $r['value'] ?? null;
            if (!is_numeric($val)) continue;
            $color = trim((string) ($r['color'] ?? ''));
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) $color = '#6b7280';
            $label = trim((string) ($r['label'] ?? ''));
            if (mb_strlen($label) > 40) $label = mb_substr($label, 0, 40);
            $clean[] = [
                'op'    => $op,
                'value' => (float) $val,
                'color' => strtolower($color),
                'label' => $label,
                'bold'  => !empty($r['bold']),
            ];
            if (count($clean) >= 20) break;
        }

        $json = json_encode($clean, JSON_UNESCAPED_SLASHES);
        DB::table('app_settings')->updateOrInsert(
            ['key' => 'supply_dlo_color_rules'],
            ['value' => $json, 'updated_at' => now(), 'created_at' => now()]
        );
        return response()->json(['ok' => true, 'rules' => $clean]);
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
            'class_override'     => 'nullable|string|max:10',
        ]);

        // Validate class_override against existing rules (empty OK)
        if (!empty($validated['class_override'])) {
            $exists = ItemClassRule::where('class_key', $validated['class_override'])->exists();
            if (!$exists) {
                return response()->json(['error' => 'Unknown class_key'], 422);
            }
        }

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

        // Deprecated: class velocity thresholds now live in supply_settings KV.
        // Kept endpoint for backward compat — redirect to saveSupplySetting mapping.
        return response()->json([
            'success' => false,
            'error'   => 'Deprecated. Use /jnt/supply/setting-kv to edit class_[a|b|c]_min_velocity and class_[a|b|c]_window_days.',
        ], 410);
    }

    // -----------------------------------------------------------------------
    // POST /jnt/supply/setting-kv — CEO only, save a single supply_settings KV
    // -----------------------------------------------------------------------
    public function saveSupplySetting(Request $request)
    {
        $role = Auth::user()?->employeeProfile?->role;
        if ($role !== 'CEO') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'key'   => 'required|string|max:100',
            'value' => 'required|string|max:50',
        ]);

        $setting = SupplySetting::where('key', $validated['key'])->first();
        if (!$setting) {
            return response()->json(['error' => 'Unknown setting key'], 404);
        }

        // Type-validate against declared data_type
        $val = $validated['value'];
        switch ($setting->data_type) {
            case 'int':
                if (!is_numeric($val) || (int)$val != (float)$val) {
                    return response()->json(['error' => 'Expected integer'], 422);
                }
                $val = (string)(int)$val;
                break;
            case 'float':
                if (!is_numeric($val)) {
                    return response()->json(['error' => 'Expected number'], 422);
                }
                $val = (string)(float)$val;
                break;
        }

        $setting->value = $val;
        $setting->save();

        return response()->json(['success' => true, 'key' => $setting->key, 'value' => $setting->value]);
    }

    // =======================================================================
    //  Global supply-table UI state (column order / sort / filters)
    //
    //  Stored in `app_settings` under key `ui_supply_table_state` as a JSON
    //  blob. Shared across ALL users & devices — drag a column once, and it
    //  stays that way for everyone. Anyone who can see the page can save it
    //  (no CEO gate) since reordering columns is a UI preference, not data.
    // =======================================================================
    public function getTableState()
    {
        $row = DB::table('app_settings')
            ->where('key', 'ui_supply_table_state')
            ->first(['value']);
        $raw = $row->value ?? null;
        $data = null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $data = $decoded;
        }
        return response()->json(['ok' => true, 'state' => $data]);
    }

    public function saveTableState(Request $request)
    {
        // Accept either a JSON body or a 'state' field.
        $state = $request->input('state');
        if (is_string($state)) {
            $decoded = json_decode($state, true);
            $state   = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($state)) {
            return response()->json(['ok' => false, 'error' => 'Invalid state payload'], 422);
        }

        // Size guard — DataTables state is typically < 10KB.
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) > 65000) {
            return response()->json(['ok' => false, 'error' => 'State too large'], 422);
        }

        DB::table('app_settings')->updateOrInsert(
            ['key' => 'ui_supply_table_state'],
            ['value' => $json, 'updated_at' => now(), 'created_at' => now()]
        );
        return response()->json(['ok' => true]);
    }

    public function resetTableState()
    {
        DB::table('app_settings')->where('key', 'ui_supply_table_state')->delete();
        return response()->json(['ok' => true]);
    }

    // =======================================================================
    //  Class Rules CRUD — CEO only
    // =======================================================================

    private function ensureCeo()
    {
        $role = Auth::user()?->employeeProfile?->role;
        if ($role !== 'CEO') {
            abort(response()->json(['error' => 'Unauthorized'], 403));
        }
    }

    /**
     * POST /jnt/supply/class-rules — create a new rule (tier only).
     * Fixed types (age_lt, catch_all) are seeded via migration and cannot
     * be created/deleted from the UI.
     */
    public function createClassRule(Request $request)
    {
        $this->ensureCeo();

        $validated = $request->validate([
            'class_key'      => 'required|string|max:10|regex:/^[A-Za-z0-9_\-]+$/',
            'label'          => 'required|string|max:80',
            'badge_tailwind' => 'required|string|max:120',
            'sort_order'     => 'required|integer|min:1|max:998',
            'min_velocity'   => 'required|numeric|min:0',
            'window_days'    => 'required|integer|min:1|max:365',
            'alive_min'      => 'required|numeric|min:0',
            'alive_window'   => 'required|integer|min:1|max:365',
        ]);

        $key = strtoupper($validated['class_key']);
        if (in_array($key, ['X', 'Y'])) {
            return response()->json(['error' => 'X and Y are reserved fixed classes'], 422);
        }
        if (ItemClassRule::where('class_key', $key)->exists()) {
            return response()->json(['error' => 'class_key already exists'], 422);
        }

        $rule = ItemClassRule::create([
            'class_key'      => $key,
            'label'          => $validated['label'],
            'badge_tailwind' => $validated['badge_tailwind'],
            'sort_order'     => $validated['sort_order'],
            'rule_type'      => ItemClassRule::TYPE_VELOCITY_TIER,
            'min_velocity'   => $validated['min_velocity'],
            'window_days'    => $validated['window_days'],
            'alive_min'      => $validated['alive_min'],
            'alive_window'   => $validated['alive_window'],
            'is_fixed'       => false,
            'is_active'      => true,
        ]);

        return response()->json(['success' => true, 'rule' => $rule]);
    }

    /**
     * PUT /jnt/supply/class-rules/{id} — edit existing rule.
     * class_key is IMMUTABLE. For fixed rules (Y, X) only label + badge
     * + age_threshold (Y) are editable.
     */
    public function updateClassRule(Request $request, int $id)
    {
        $this->ensureCeo();

        /** @var ItemClassRule $rule */
        $rule = ItemClassRule::findOrFail($id);

        $validated = $request->validate([
            'label'          => 'required|string|max:80',
            'badge_tailwind' => 'required|string|max:120',
            'sort_order'     => 'nullable|integer|min:1|max:998',
            'min_velocity'   => 'nullable|numeric|min:0',
            'window_days'    => 'nullable|integer|min:1|max:365',
            'alive_min'      => 'nullable|numeric|min:0',
            'alive_window'   => 'nullable|integer|min:1|max:365',
            'age_threshold'  => 'nullable|integer|min:1|max:365',
        ]);

        // Always-editable
        $rule->label          = $validated['label'];
        $rule->badge_tailwind = $validated['badge_tailwind'];

        if ($rule->rule_type === ItemClassRule::TYPE_AGE_LT) {
            // Y: age-based. window_days is non-bearing for classification
            // but used by Proj Profit% compute window.
            if (isset($validated['age_threshold'])) {
                $rule->age_threshold = $validated['age_threshold'];
            }
            if (isset($validated['window_days'])) {
                $rule->window_days = $validated['window_days'];
            }
        } elseif ($rule->rule_type === ItemClassRule::TYPE_VELOCITY_TIER) {
            foreach (['min_velocity', 'window_days', 'alive_min', 'alive_window'] as $f) {
                if (isset($validated[$f])) $rule->{$f} = $validated[$f];
            }
            if (isset($validated['sort_order'])) {
                $rule->sort_order = $validated['sort_order'];
            }
        } elseif ($rule->rule_type === ItemClassRule::TYPE_CATCH_ALL) {
            // X: non-bearing window_days used by Proj Profit% compute only.
            if (isset($validated['window_days'])) {
                $rule->window_days = $validated['window_days'];
            }
        }

        $rule->save();
        return response()->json(['success' => true, 'rule' => $rule]);
    }

    /**
     * DELETE /jnt/supply/class-rules/{id} — delete a rule.
     * Blocked for fixed rules (Y, X) or when any item references this key.
     */
    public function deleteClassRule(int $id)
    {
        $this->ensureCeo();

        /** @var ItemClassRule $rule */
        $rule = ItemClassRule::findOrFail($id);

        if ($rule->is_fixed) {
            return response()->json([
                'error' => 'Cannot delete fixed class (' . $rule->class_key . ')',
            ], 422);
        }

        $referencing = SupplyItemSetting::where('class_override', $rule->class_key)->count();
        if ($referencing > 0) {
            return response()->json([
                'error' => "Cannot delete: {$referencing} item(s) still reference this class. Reassign them first.",
            ], 422);
        }

        $rule->delete();
        return response()->json(['success' => true]);
    }

    /**
     * POST /jnt/supply/class-rules/reorder — bulk sort_order update.
     * Payload: { "order": [{"id":1,"sort_order":10}, ...] }
     */
    public function reorderClassRules(Request $request)
    {
        $this->ensureCeo();

        $validated = $request->validate([
            'order'              => 'required|array|min:1',
            'order.*.id'         => 'required|integer',
            'order.*.sort_order' => 'required|integer|min:1|max:999',
        ]);

        foreach ($validated['order'] as $row) {
            ItemClassRule::where('id', $row['id'])->update(['sort_order' => $row['sort_order']]);
        }
        return response()->json(['success' => true]);
    }

    // =======================================================================
    //  Excluded Pages CRUD — CEO only (affects Proj Profit% only)
    // =======================================================================

    public function excludedPagesIndex()
    {
        $this->ensureCeo();

        $excluded = SupplyExcludedPage::orderBy('page_name')->get();

        $driver  = DB::getDriverName();
        $pageCol = $driver === 'pgsql' ? '"PAGE"' : '`PAGE`';

        // Distinct pages from macro_output for the picker (recent 120 days)
        $since = Carbon::now('Asia/Manila')->subDays(120)->toDateString();
        $pages = DB::table('macro_output')
            ->where('ts_date', '>=', $since)
            ->selectRaw(($driver === 'pgsql'
                ? "BTRIM(COALESCE({$pageCol}::text,'')) as page_name"
                : "TRIM(COALESCE({$pageCol},'')) as page_name"))
            ->groupByRaw($driver === 'pgsql'
                ? "BTRIM(COALESCE({$pageCol}::text,''))"
                : "TRIM(COALESCE({$pageCol},''))")
            ->orderBy('page_name')
            ->pluck('page_name')
            ->filter(fn($p) => (string) $p !== '')
            ->values()
            ->all();

        return view('jnt.supply_excluded_pages', compact('excluded', 'pages'));
    }

    public function storeExcludedPage(Request $request)
    {
        $this->ensureCeo();

        $validated = $request->validate([
            'page_name' => 'required|string|max:200',
            'reason'    => 'nullable|string|max:255',
        ]);

        $row = SupplyExcludedPage::updateOrCreate(
            ['page_name' => trim($validated['page_name'])],
            ['reason'    => $validated['reason'] ?? null]
        );

        return response()->json(['success' => true, 'row' => $row]);
    }

    public function destroyExcludedPage(int $id)
    {
        $this->ensureCeo();

        $row = SupplyExcludedPage::findOrFail($id);
        $row->delete();

        return response()->json(['success' => true]);
    }
}
