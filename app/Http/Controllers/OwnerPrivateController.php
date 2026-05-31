<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;
use App\Models\Cogs;
use App\Models\FeeSetting;
use App\Models\SupplyExcludedPage;

class OwnerPrivateController extends Controller
{
    /**
     * Allow CEO + Marketing-OIC + Marketing for VIEW endpoints.
     * Write endpoints (saveItemSetting, refreshPrimaryItems) call
     * checkWriteAccess() instead — CEO-only.
     */
    private function checkAccess(): void
    {
        $role = $this->getNormalizedRole();
        if (!in_array($role, ['CEO', 'Marketing - OIC', 'Marketing'], true)) {
            abort(404);
        }
    }

    /** Stricter check for endpoints that mutate data — CEO + Marketing-OIC. */
    private function checkWriteAccess(): void
    {
        $role = $this->getNormalizedRole();
        if (!in_array($role, ['CEO', 'Marketing - OIC'], true)) abort(404);
    }

    /** Audit-log viewer — same role gate as write access. */
    private function checkLogsAccess(): void
    {
        $role = $this->getNormalizedRole();
        if (!in_array($role, ['CEO', 'Marketing - OIC'], true)) abort(404);
    }

    /** True if the current user is the CEO. */
    private function isCEO(): bool
    {
        return $this->getNormalizedRole() === 'CEO';
    }

    /**
     * Auto-mirror missing (item_name, date) pairs from `cogs` → `cogs_ceo`.
     *
     * Called after Marketing's cogs upsert / cascade. Only inserts rows na wala
     * pa sa cogs_ceo — existing CEO values stay untouched. Driver-aware (MySQL
     * uses INSERT IGNORE, Postgres uses ON CONFLICT DO NOTHING).
     */
    private function mirrorMissingCogsToCogsCeo(string $itemName): void
    {
        try {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'pgsql') {
                DB::statement('
                    INSERT INTO cogs_ceo (item_name, date, unit_cost, created_at, updated_at)
                    SELECT item_name, date, unit_cost, NOW(), NOW()
                    FROM cogs
                    WHERE item_name = ?
                    ON CONFLICT (item_name, date) DO NOTHING
                ', [$itemName]);
            } else {
                DB::statement('
                    INSERT IGNORE INTO cogs_ceo (item_name, date, unit_cost, created_at, updated_at)
                    SELECT item_name, date, unit_cost, NOW(), NOW()
                    FROM cogs
                    WHERE item_name = ?
                ', [$itemName]);
            }
        } catch (\Throwable $e) {
            \Log::warning('mirrorMissingCogsToCogsCeo failed', [
                'item' => $itemName, 'err' => $e->getMessage(),
            ]);
        }
    }

    /** Canonical role string from the logged-in user's employee profile. */
    private function getNormalizedRole(): string
    {
        $raw  = Auth::user()?->employeeProfile?->role ?? '';
        $norm = preg_replace('/\s+/u', ' ', trim((string) $raw));
        if (preg_match('/^ceo$/iu', $norm)) return 'CEO';
        if (preg_match('/^marketing\s*[-–—]\s*oic$/iu', $norm)) return 'Marketing - OIC';
        if (preg_match('/^marketing$/iu', $norm)) return 'Marketing';
        return $norm;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // CACHE LAYER — wraps the heavy data() + itemSummary() endpoints.
    //
    // Strategy: read-through cache na may "version" sentinel. Pag may bagong
    // upload (ads_manager_reports, macro_output, from_jnts) or user saves
    // (RTS/cogs/promo), bumpVersion() ang tinatawag at lahat ng old cache
    // entries ay automatically becomes orphans (eventually evicted by backend).
    //
    // No TTL — caches live forever or until version bump. User can also
    // explicitly bypass via ?refresh=1 (re-runs queries + rewrites cache).
    // ═══════════════════════════════════════════════════════════════════════

    private const CACHE_VERSION_KEY = 'owner_private:cache_version';

    /** Bumps the global version sentinel — all existing cache keys orphaned. */
    public static function bumpCacheVersion(): void
    {
        // Use time() so concurrent bumps don't collide. Resolution: 1 second.
        Cache::forever(self::CACHE_VERSION_KEY, (string) time());
    }

    /** Current version sentinel (lazy-init kung blank pa). */
    private function cacheVersion(): string
    {
        $v = Cache::get(self::CACHE_VERSION_KEY);
        if ($v === null) {
            $v = (string) time();
            Cache::forever(self::CACHE_VERSION_KEY, $v);
        }
        return (string) $v;
    }

    /** Build a deterministic cache key for itemSummary() given request params. */
    private function cacheKeyForItemSummary(Request $request): string
    {
        $parts = [
            'host'  => strtolower((string) $request->getHost()),
            'start' => (string) $request->input('start_date', ''),
            'end'   => (string) $request->input('end_date', ''),
            'date'  => (string) $request->input('date', ''), // legacy single-date param
            'view'  => strtolower(trim((string) $request->input('view_as', 'ceo'))),
            'role'  => $this->getNormalizedRole(),
            // Optional 1D override (?partial_date=YYYY-MM-DD). Null default = no override.
            // Included sa cache key so different partial dates get separate cache entries.
            'partial' => (string) $request->input('partial_date', ''),
        ];
        return 'owner_private:item_summary:v' . $this->cacheVersion()
             . ':' . md5(json_encode($parts));
    }

    /** Build a deterministic cache key for data() given request params. */
    private function cacheKeyForData(Request $request): string
    {
        $parts = [
            'host' => strtolower((string) $request->getHost()),
            'start'=> (string) $request->input('start_date', ''),
            'end'  => (string) $request->input('end_date', ''),
            'page' => (string) $request->input('page_name', 'all'),
            'role' => $this->getNormalizedRole(),
        ];
        return 'owner_private:data:v' . $this->cacheVersion()
             . ':' . md5(json_encode($parts));
    }

    public function index()
    {
        $this->checkAccess();

        $driver = DB::getDriverName();
        $trimFn = $driver === 'pgsql' ? 'BTRIM' : 'TRIM';

        $pages = DB::table('ads_manager_reports')
            ->whereNotNull('page_name')
            ->selectRaw("$trimFn(page_name) AS page_name")
            ->distinct()
            ->orderBy('page_name')
            ->pluck('page_name')
            ->toArray();

        $role           = $this->getNormalizedRole();
        $isCEO          = $role === 'CEO';
        $isMarketingOIC = $role === 'Marketing - OIC';

        // CEO-only "view as" mode. Default 'ceo'. When CEO toggles to 'marketing',
        // initial render hides CEO-only chrome (Daily, Columns, Excluded, Primary
        // Items buttons + Item Val. (CEO) column + modal CEO field). The View
        // toggle itself stays visible so they can switch back. Non-CEO ignores
        // this param — always rendered as Marketing's view.
        $viewAs = strtolower(trim((string) request()->input('view_as', 'ceo')));
        if (!in_array($viewAs, ['ceo', 'marketing'], true)) $viewAs = 'ceo';
        // Derived flag — convenience for template @if() checks. False when CEO
        // is previewing Marketing's UI, even if they actually have CEO role.
        $effectiveIsCEO = $isCEO && $viewAs === 'ceo';

        // Column visibility/order configs (CEO-managed via /owner/column-settings).
        // Per-role filtering is applied — CEO sees all; Marketing/MOIC see only
        // columns explicitly granted in visible_by_role.
        //
        // CEO in "view_as=marketing" simulates Marketing's column visibility too —
        // so it's truly mirrors what Marketing sees (not just the cogs_ceo column).
        // Effective role for column config = 'Marketing' when CEO previews as one.
        $viewRoleForCols = ($isCEO && $viewAs === 'marketing') ? 'Marketing' : $role;
        $colsCtrl = new \App\Http\Controllers\OwnerColumnSettingsController();
        $ownerPrivateColsConfig = $colsCtrl->loadConfig('owner_private', $viewRoleForCols);
        $campaignsColsConfig    = $colsCtrl->loadConfig('campaigns', $viewRoleForCols);
        // Computed-column settings (also CEO-managed in the same page).
        $breakevenTargetPct     = $colsCtrl->loadBreakevenTargetPct();    // e.g. 5.0
        // loadColFormat($table) returns ['groups' => [...], 'byCol' => {...}].
        // The view consumes the flattened byCol maps for cellFormatStyle().
        $colFormatRules           = $colsCtrl->loadColFormat('owner_private')['byCol'] ?? [];
        $campaignsColFormatRules  = $colsCtrl->loadColFormat('campaigns')['byCol']     ?? [];

        // Fee settings — pinapasa sa view as window.__FEES__ kaya yung JS-side
        // formulas (breakeven_cpp, profit_pct sa campaigns expand) ay hindi na
        // hardcoded. Latest effective values lang gamit (today's date).
        $host = strtolower((string) request()->getHost());
        $today = (new \DateTime('now', new \DateTimeZone('Asia/Manila')))->format('Y-m-d');
        $feeShipping  = FeeSetting::getRate('shipping_fee_per_order', $host, $today);
        $feeCodRate   = FeeSetting::getRate('cod_fee_rate',           $host, $today);
        $feeVatRate   = FeeSetting::getRate('cod_fee_vat_rate',       $host, $today);

        return view('owner.private', compact(
            'pages', 'isCEO', 'isMarketingOIC', 'viewAs', 'effectiveIsCEO',
            'ownerPrivateColsConfig', 'campaignsColsConfig',
            'breakevenTargetPct', 'colFormatRules', 'campaignsColFormatRules',
            'feeShipping', 'feeCodRate', 'feeVatRate'
        ));
    }

    /**
     * Resolve the start date of the most recent uninterrupted streak where
     * this page's primary == $anchorKey AND mode_cod ≈ $anchorCodInt,
     * walking backward day-by-day from $endDate through daily_page_primary_item.
     *
     * Tie/missing days (no row for that date) are skipped transparently —
     * they don't break the streak. Only an explicitly different primary
     * OR a price change > ₱1 (rounded integer comparison) stops the walk.
     *
     * Pass $anchorCodInt = null to disable price-based anchor (legacy
     * item-only mode, for callers na walang COD info).
     *
     * Walks past the user's start_date up to $maxDaysBack total. Returns
     * YYYY-MM-DD or null if anchor doesn't match $endDate's primary.
     */
    private function resolveAnchorStreakStart(
        string $pageKey,
        string $anchorKey,
        ?int $anchorCodInt,
        string $endDate,
        int $maxDaysBack = 400
    ): ?string {
        $earliestQuery = (new \DateTime($endDate))
            ->modify("-{$maxDaysBack} days")
            ->format('Y-m-d');

        $rows = DB::table('daily_page_primary_item')
            ->where('page_key', $pageKey)
            ->whereBetween('ts_date', [$earliestQuery, $endDate])
            ->get(['ts_date', 'primary_item_key', 'primary_mode_cod']);

        $byDate = [];
        foreach ($rows as $r) {
            $codInt = $r->primary_mode_cod !== null ? (int) round((float) $r->primary_mode_cod) : null;
            $byDate[(string)$r->ts_date] = [
                'item_key' => (string)$r->primary_item_key,
                'cod_int'  => $codInt,
            ];
        }

        $end = $byDate[$endDate] ?? null;
        if ($end === null || $end['item_key'] !== $anchorKey) {
            return null;
        }

        // Match helper — compares both item_key and (rounded) mode_cod.
        // Price tolerance: same anchor if rounded ints differ by ≤ 1 peso.
        // Skips price check when either side is null (no price data).
        $matchesAnchor = function (array $day) use ($anchorKey, $anchorCodInt): bool {
            if ($day['item_key'] !== $anchorKey) return false;
            if ($anchorCodInt === null || $day['cod_int'] === null) return true;
            return abs($day['cod_int'] - $anchorCodInt) <= 1;
        };

        $streakStart = $endDate;
        $cursor = (new \DateTime($endDate))->modify('-1 day');
        $stop   = new \DateTime($earliestQuery);

        while ($cursor >= $stop) {
            $d = $cursor->format('Y-m-d');
            if (isset($byDate[$d])) {
                if ($matchesAnchor($byDate[$d])) {
                    $streakStart = $d;
                } else {
                    break;
                }
            }
            $cursor->modify('-1 day');
        }

        return $streakStart;
    }

    public function data(Request $request)
    {
        $this->checkAccess();

        // ── Cache gate ────────────────────────────────────────────────────
        // Same pattern as itemSummary(). Cached forever until version bump or
        // explicit ?refresh=1 from user.
        $cacheKey = $this->cacheKeyForData($request);
        $bypassCache = (bool) $request->boolean('refresh');
        if (!$bypassCache) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                // Easy-to-spot cache status sa DevTools Network → Headers tab.
                // Plus diagnostic headers para makita kung bakit nagca-cache (or hindi).
                return response()->json($cached + ['_cache' => 'hit'])
                    ->header('X-Cache-Status', 'HIT')
                    ->header('X-Cache-Key',    $cacheKey)
                    ->header('X-Cache-Version', $this->cacheVersion());
            }
        }

        // PH timezone (for "Today" label in top summary)
        $phTz  = new \DateTimeZone('Asia/Manila');
        $today = (new \DateTime('now', $phTz))->format('Y-m-d');

        $start    = $request->input('start_date');
        $end      = $request->input('end_date');
        $pageName = $request->input('page_name', 'all');

        $driver = DB::getDriverName(); // 'mysql' | 'pgsql'
        $trimFn = $driver === 'pgsql' ? 'BTRIM' : 'TRIM';

        // === FEE SETTINGS (from database with effective date) — no fallback ===
        $host = strtolower((string) $request->getHost());
        $refDate = $end ?? $start ?? $today;
        $COD_FEE_RATE         = FeeSetting::getRate('cod_fee_rate', $host, $refDate);
        $COD_FEE_VAT_RATE     = FeeSetting::getRate('cod_fee_vat_rate', $host, $refDate);
        $SHIPPING_PER_SHIPPED = FeeSetting::getRate('shipping_fee_per_order', $host, $refDate);
        if ($COD_FEE_RATE === null || $COD_FEE_VAT_RATE === null || $SHIPPING_PER_SHIPPED === null) {
            $missing = array_filter([
                $COD_FEE_RATE         === null ? 'cod_fee_rate'           : null,
                $COD_FEE_VAT_RATE     === null ? 'cod_fee_vat_rate'       : null,
                $SHIPPING_PER_SHIPPED === null ? 'shipping_fee_per_order' : null,
            ]);
            abort(422, "Missing fee_settings for {$refDate} (host: {$host}): " . implode(', ', $missing) . ". Configure at /jnt/fee-settings.");
        }
        $DEFAULT_RTS_PCT      = 30.0;

        // helpers
        $quote = fn(string $col) => $driver === 'pgsql' ? '"' . $col . '"' : '`' . $col . '`';
        $fmtMonthDay = fn(string $d) => date('M j', strtotime($d));
        $makeFilteredLabel = function($s, $e) use ($fmtMonthDay) {
            if ($s && $e) {
                if ($s === $e) return $fmtMonthDay($s);
                return $fmtMonthDay($s) . ' – ' . $fmtMonthDay($e);
            } elseif ($s)   { return $fmtMonthDay($s) . ' – …'; }
              elseif ($e)   { return '… – ' . $fmtMonthDay($e); }
            return '—';
        };

        $pgColumns = function (string $table): array {
            if (DB::getDriverName() === 'pgsql') {
                $rows = DB::select(
                    "SELECT column_name FROM information_schema.columns
                     WHERE table_schema = current_schema() AND table_name = ?",
                    [$table]
                );
                return array_map(fn($r) => $r->column_name, $rows);
            }
            return [];
        };

        $pickCol = function (string $table, array $candidates) use ($pgColumns) {
            if (DB::getDriverName() === 'pgsql') {
                $cols = $pgColumns($table);
                foreach ($candidates as $c) if (in_array($c, $cols, true)) return $c;
                return null;
            } else {
                foreach ($candidates as $c) if (Schema::hasColumn($table, $c)) return $c;
                return null;
            }
        };

        $castMoney = function (string $expr) use ($driver) {
            return $driver === 'pgsql'
                ? "COALESCE(NULLIF(REGEXP_REPLACE(COALESCE(($expr)::text, ''), '[^0-9\\.\\-]', '', 'g'), '')::numeric, 0)"
                : "CAST(REPLACE(REPLACE(REPLACE(COALESCE($expr,''), '₱',''), ',', ''), ' ', '') AS DECIMAL(18,2))";
        };

        $pageColName = $pickCol('macro_output', ['PAGE','page','page_name','Page','Page_Name']);
        if (!$pageColName) throw new \RuntimeException('macro_output: page column not found');
        $moPageSql = 'mo.' . $quote($pageColName);
        $moPageCol = 'mo.' . $pageColName;

        $pageLabelExpr = "$trimFn(COALESCE($moPageSql,''))";
        $pageKeyExpr   = "LOWER($pageLabelExpr)";

        $statusColName = $pickCol('macro_output', ['STATUS','status','Status']) ?? 'status';
        $statusExpr    = 'mo.' . $quote($statusColName);
        $statusNorm    = "LOWER(REPLACE(REPLACE($trimFn($statusExpr),' ',''),'_',''))";

        $wbColName    = $pickCol('macro_output', ['waybill','Waybill','WAYBILL']) ?? 'waybill';
        $moWaybillSql = 'mo.' . $quote($wbColName);
        $moWaybillCol = 'mo.' . $wbColName;

        $itemColName = $pickCol('macro_output', ['ITEM_NAME','item_name','Product','product_name','ITEM','item']);
        if (!$itemColName) throw new \RuntimeException('macro_output: item column not found');
        $moItemExpr = 'mo.' . $quote($itemColName);
        $itemLabel  = "$trimFn(COALESCE($moItemExpr,''))";
        $itemNorm   = "LOWER(REPLACE(REPLACE(REPLACE($itemLabel,' ',''),'-',''),'_',''))";

        $moCodColName = $pickCol('macro_output', ['COD','cod','Cod']) ?? 'COD';
        $moCodExpr    = 'mo.' . $quote($moCodColName);
        $moCodClean   = $castMoney($moCodExpr);

        $hasTsDate = Schema::hasColumn('macro_output', 'ts_date');

        if ($hasTsDate) {
            $dateExpr = "mo.ts_date";
        } else {
            $tsCols = [];
            foreach (['TIMESTAMP','timestamp'] as $c) if ($pickCol('macro_output', [$c])) $tsCols[] = $c;

            if ($driver === 'mysql') {
                if (!empty($tsCols)) {
                    $ts = 'mo.' . $quote($tsCols[0]);
                    $dateExpr = "COALESCE(
                        DATE(STR_TO_DATE($ts, '%H:%i %d-%m-%Y')),
                        DATE(STR_TO_DATE($ts, '%H:%i %m-%d-%Y')),
                        DATE(mo.`created_at`)
                    )";
                } else {
                    $dateExpr = "DATE(mo.`created_at`)";
                }
            } else {
                $pgParts = [];
                foreach ($tsCols as $c) {
                    $ref = 'mo.' . $quote($c);
                    $pgParts[] = "TO_TIMESTAMP(NULLIF($ref, ''), 'HH24:MI DD-MM-YYYY')";
                    $pgParts[] = "TO_TIMESTAMP(NULLIF($ref, ''), 'HH24:MI MM-DD-YYYY')";
                }
                $pgParts[] = 'mo."created_at"';
                $dateExpr  = 'DATE(COALESCE(' . implode(', ', $pgParts) . '))';
            }
        }

        $castSpend = $castMoney('amount_spent_php');

        $jSubmitColName = $pickCol('from_jnts', ['submission_time','submitted_at','submission_datetime','submissiondate','submission']) ?? 'submission_time';

        $cogsItemColName = $pickCol('cogs', ['item_name','ITEM_NAME','product','Product','Product_Name']) ?? 'item_name';
        $cogsItemExpr    = 'c.' . $quote($cogsItemColName);
        $cogsItemNorm    = "LOWER(REPLACE(REPLACE(REPLACE($trimFn(COALESCE($cogsItemExpr,'')),' ',''),'-',''),'_',''))";

        $cogsDateColName = $pickCol('cogs', ['effective_date','date','valid_from','cogs_date']) ?? 'effective_date';
        $cogsDateExpr    = 'c.' . $quote($cogsDateColName);

        $cogsUnitColName = $pickCol('cogs', ['unit_cost','cost','unitprice','unit_price','price']) ?? 'unit_cost';
        $cogsUnitExpr    = 'c.' . $quote($cogsUnitColName);

        $AGGREGATE_RANGE = ($pageName === 'all');
        $pageNameNorm = $AGGREGATE_RANGE ? null : mb_strtolower(trim((string)$pageName));

        $adsPageLabelExpr = "$trimFn(COALESCE(page_name,''))";
        $adsPageKeyExpr   = "LOWER($adsPageLabelExpr)";

        $adsBase = DB::table('ads_manager_reports');

        if ($start && $end) {
            $adsBase->whereRaw('DATE(day) BETWEEN ? AND ?', [$start, $end]);
        } elseif ($start) {
            $adsBase->whereRaw('DATE(day) >= ?', [$start]);
        } elseif ($end) {
            $adsBase->whereRaw('DATE(day) <= ?', [$end]);
        }

        if (!$AGGREGATE_RANGE && $pageNameNorm !== null && $pageNameNorm !== '') {
            $adsBase->whereRaw("$adsPageKeyExpr = ?", [$pageNameNorm]);
        }

        if ($AGGREGATE_RANGE) {
            $adsRows = (clone $adsBase)
                ->selectRaw("$adsPageKeyExpr AS page_key, MIN($adsPageLabelExpr) AS page_label, SUM($castSpend) AS adspent")
                ->groupByRaw("$adsPageKeyExpr")
                ->havingRaw("SUM($castSpend) > 0")
                ->orderBy('page_key')
                ->get();
        } else {
            $adsRows = (clone $adsBase)
                ->whereNotNull('day')
                ->selectRaw("DATE(day) AS day_key, $adsPageKeyExpr AS page_key, MIN($adsPageLabelExpr) AS page_label, SUM($castSpend) AS adspent")
                ->groupByRaw("DATE(day), $adsPageKeyExpr")
                ->havingRaw("SUM($castSpend) > 0")
                ->orderBy('day_key', 'asc')
                ->orderBy('page_key', 'asc')
                ->get();
        }

        $adsMap   = [];
        $labelMap = [];
        foreach ($adsRows as $r) {
            if ($AGGREGATE_RANGE) {
                $k = (string)$r->page_key;
                $adsMap[$k] = (float)($r->adspent ?? 0);
                if (!empty($r->page_label)) $labelMap[$k] = (string)$r->page_label;
            } else {
                $k = (string)$r->day_key . '|' . (string)$r->page_key;
                $adsMap[$k] = (float)($r->adspent ?? 0);
                if (!empty($r->page_label)) $labelMap[$k] = (string)$r->page_label;
            }
        }

        $mo = DB::table('macro_output as mo');

        if ($start && $end) {
            $mo->whereRaw("$dateExpr BETWEEN ? AND ?", [$start, $end]);
        } elseif ($start) {
            $mo->whereRaw("$dateExpr >= ?", [$start]);
        } elseif ($end) {
            $mo->whereRaw("$dateExpr <= ?", [$end]);
        }

        if (!$AGGREGATE_RANGE && $pageNameNorm !== null && $pageNameNorm !== '') {
            $mo->whereRaw("$pageKeyExpr = ?", [$pageNameNorm]);
        }

        if ($AGGREGATE_RANGE) {
            $selectKeyAgg  = "$pageKeyExpr AS page_key, MIN($pageLabelExpr) AS page_label";
            $groupByKey    = "$pageKeyExpr";
            $selectKeyBase = "$pageKeyExpr AS page_key, $pageLabelExpr AS page_label";
        } else {
            $selectKeyAgg  = "$dateExpr AS day_key, $pageKeyExpr AS page_key, MIN($pageLabelExpr) AS page_label";
            $groupByKey    = "$dateExpr, $pageKeyExpr";
            $selectKeyBase = "$dateExpr AS day_key, $pageKeyExpr AS page_key, $pageLabelExpr AS page_label";
        }

        $orderAgg = (clone $mo)
            ->selectRaw("$selectKeyAgg,
                COUNT(*) AS orders_total,
                SUM(CASE WHEN $statusNorm = 'proceed' THEN 1 ELSE 0 END) AS proceed_total,
                SUM(CASE WHEN $statusNorm = 'cannotproceed' THEN 1 ELSE 0 END) AS cannot_total,
                SUM(CASE WHEN $statusNorm = 'odz' THEN 1 ELSE 0 END) AS odz_total
            ")
            ->groupByRaw($groupByKey)
            ->get();

        $ordersMap = $proceedMap = $cannotMap = $odzMap = [];
        foreach ($orderAgg as $r) {
            $k = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
            $ordersMap[$k]  = (int)($r->orders_total ?? 0);
            $proceedMap[$k] = (int)($r->proceed_total ?? 0);
            $cannotMap[$k]  = (int)($r->cannot_total ?? 0);
            $odzMap[$k]     = (int)($r->odz_total ?? 0);
            if (!empty($r->page_label)) $labelMap[$k] = (string)$r->page_label;
        }

        $dateFilterParts = [];
        $dateFilterBindings = [];
        if ($start && $end) {
            $dateFilterParts[] = "$dateExpr BETWEEN ? AND ?";
            $dateFilterBindings = [$start, $end];
        } elseif ($start) {
            $dateFilterParts[] = "$dateExpr >= ?";
            $dateFilterBindings = [$start];
        } elseif ($end) {
            $dateFilterParts[] = "$dateExpr <= ?";
            $dateFilterBindings = [$end];
        }

        $pageFilterSql = '';
        if (!$AGGREGATE_RANGE && $pageNameNorm !== null && $pageNameNorm !== '') {
            $pageFilterSql = " AND $pageKeyExpr = ?";
            $dateFilterBindings[] = $pageNameNorm;
        }

        $dateWhere = !empty($dateFilterParts) ? 'WHERE ' . implode(' AND ', $dateFilterParts) . $pageFilterSql : ($pageFilterSql ? 'WHERE 1=1' . $pageFilterSql : '');

        if ($driver === 'mysql') {
            DB::statement("DROP TEMPORARY TABLE IF EXISTS _jnt_agg");

            $jaMinTsExpr = "MIN(COALESCE(
                STR_TO_DATE(j.`$jSubmitColName`, '%Y-%m-%d %H:%i:%s'),
                STR_TO_DATE(j.`$jSubmitColName`, '%Y/%m/%d %H:%i:%s'),
                STR_TO_DATE(j.`$jSubmitColName`, '%Y-%m-%d'),
                j.`created_at`
            ))";

            DB::statement("
                CREATE TEMPORARY TABLE _jnt_agg AS
                SELECT
                    j.waybill_number AS wb,
                    MAX(CASE WHEN j.status LIKE 'Delivered%'  OR j.status LIKE 'DELIVERED%'  THEN 1 ELSE 0 END) AS is_delivered,
                    MAX(CASE WHEN j.status LIKE 'Returned%'   OR j.status LIKE 'RETURNED%'   THEN 1 ELSE 0 END) AS is_returned,
                    MAX(CASE WHEN j.status LIKE 'For Return%' OR j.status LIKE 'FOR RETURN%' THEN 1 ELSE 0 END) AS is_for_return,
                    MAX(CASE
                        WHEN j.status LIKE 'Delivered%'  OR j.status LIKE 'DELIVERED%'  THEN 0
                        WHEN j.status LIKE 'Returned%'   OR j.status LIKE 'RETURNED%'   THEN 0
                        WHEN j.status LIKE 'For Return%' OR j.status LIKE 'FOR RETURN%' THEN 0
                        ELSE 1
                    END) AS is_in_transit,
                    $jaMinTsExpr AS min_submit_ts,
                    COALESCE(MAX(CAST(j.total_shipping_cost AS DECIMAL(18,2))), 0) AS actual_shipping_cost
                FROM from_jnts j
                WHERE j.waybill_number IN (
                    SELECT DISTINCT $moWaybillSql
                    FROM macro_output mo
                    $dateWhere
                    AND $moWaybillSql IS NOT NULL AND $moWaybillSql != ''
                )
                GROUP BY j.waybill_number
            ", $dateFilterBindings);

            DB::statement("ALTER TABLE _jnt_agg ADD PRIMARY KEY (wb)");
        } else {
            DB::statement("DROP TABLE IF EXISTS _jnt_agg");

            $jaMinTsExpr = "MIN(COALESCE(
                TO_TIMESTAMP(NULLIF(j.\"$jSubmitColName\",'') , 'YYYY-MM-DD HH24:MI:SS'),
                TO_TIMESTAMP(NULLIF(j.\"$jSubmitColName\",'') , 'YYYY/MM/DD HH24:MI:SS'),
                TO_TIMESTAMP(NULLIF(j.\"$jSubmitColName\",'') , 'YYYY-MM-DD'),
                j.\"created_at\"
            ))";

            DB::statement("
                CREATE TEMPORARY TABLE _jnt_agg AS
                SELECT
                    j.waybill_number AS wb,
                    MAX(CASE WHEN j.status LIKE 'Delivered%'  OR j.status LIKE 'DELIVERED%'  THEN 1 ELSE 0 END)::int AS is_delivered,
                    MAX(CASE WHEN j.status LIKE 'Returned%'   OR j.status LIKE 'RETURNED%'   THEN 1 ELSE 0 END)::int AS is_returned,
                    MAX(CASE WHEN j.status LIKE 'For Return%' OR j.status LIKE 'FOR RETURN%' THEN 1 ELSE 0 END)::int AS is_for_return,
                    MAX(CASE
                        WHEN j.status LIKE 'Delivered%'  OR j.status LIKE 'DELIVERED%'  THEN 0
                        WHEN j.status LIKE 'Returned%'   OR j.status LIKE 'RETURNED%'   THEN 0
                        WHEN j.status LIKE 'For Return%' OR j.status LIKE 'FOR RETURN%' THEN 0
                        ELSE 1
                    END)::int AS is_in_transit,
                    $jaMinTsExpr AS min_submit_ts,
                    COALESCE(MAX(CAST(j.total_shipping_cost AS DECIMAL(18,2))), 0) AS actual_shipping_cost
                FROM from_jnts j
                WHERE j.waybill_number IN (
                    SELECT DISTINCT $moWaybillSql
                    FROM macro_output mo
                    $dateWhere
                    AND $moWaybillSql IS NOT NULL AND $moWaybillSql != ''
                )
                GROUP BY j.waybill_number
            ", $dateFilterBindings);

            DB::statement("ALTER TABLE _jnt_agg ADD PRIMARY KEY (wb)");
        }

        // Role-aware cogs source — CEO uses cogs_ceo, non-CEO uses cogs.
        // Profit aggregations downstream use $findUnitCost transparently.
        $cogsTable = ($this->isCEO() && Schema::hasTable('cogs_ceo')) ? 'cogs_ceo' : 'cogs';

        $cogsAll = DB::table($cogsTable)
            ->selectRaw("
                LOWER(REPLACE(REPLACE(REPLACE($trimFn(COALESCE(" . $quote($cogsItemColName) . ",'')),' ',''),'-',''),'_','')) AS item_key,
                DATE(" . $quote($cogsDateColName) . ") AS eff_date,
                " . $castMoney($quote($cogsUnitColName)) . " AS unit_cost
            ")
            ->orderByRaw("LOWER(REPLACE(REPLACE(REPLACE($trimFn(COALESCE(" . $quote($cogsItemColName) . ",'')),' ',''),'-',''),'_','')) ASC, DATE(" . $quote($cogsDateColName) . ") DESC")
            ->get();

        $cogsLookup = [];
        foreach ($cogsAll as $row) {
            $cogsLookup[(string)$row->item_key][] = [
                'date' => (string)$row->eff_date,
                'cost' => (float)$row->unit_cost,
            ];
        }

        $findUnitCost = function(string $itemKey, string $maxDate) use ($cogsLookup): float {
            if (!isset($cogsLookup[$itemKey])) return 0.0;
            foreach ($cogsLookup[$itemKey] as $entry) {
                if ($entry['date'] <= $maxDate) return $entry['cost'];
            }
            return 0.0;
        };

        $findAllUnitCosts = function(string $itemKey, ?string $startDate = null, ?string $endDate = null) use ($cogsLookup, &$findUnitCost): array {
            if (!isset($cogsLookup[$itemKey])) return [];

            if ($startDate && $endDate) {
                $costsInRange = [];
                $carryForward = $findUnitCost($itemKey, $endDate);
                if ($carryForward > 0) {
                    $costsInRange[] = $carryForward;
                }
                foreach ($cogsLookup[$itemKey] as $entry) {
                    if ($entry['date'] >= $startDate && $entry['date'] <= $endDate && $entry['cost'] > 0) {
                        $costsInRange[] = $entry['cost'];
                    }
                }
                if (empty($costsInRange)) return [];
                $costs = array_unique($costsInRange);
                sort($costs);
                return array_values($costs);
            }

            $costs = array_unique(array_map(fn($e) => $e['cost'], $cogsLookup[$itemKey]));
            sort($costs);
            return array_values($costs);
        };

        $joinedBase = (clone $mo)
            ->whereNotNull($moWaybillCol)
            ->where($moWaybillCol, '!=', '')
            ->join('_jnt_agg AS ja', $moWaybillCol, '=', 'ja.wb');

        $shipAgg = (clone $joinedBase)
            ->selectRaw("$selectKeyAgg,
                COUNT(DISTINCT $moWaybillSql) AS shipped_total,
                COUNT(DISTINCT CASE WHEN ja.is_delivered  = 1 THEN $moWaybillSql END) AS delivered_total,
                COUNT(DISTINCT CASE WHEN ja.is_returned   = 1 THEN $moWaybillSql END) AS returned_total,
                COUNT(DISTINCT CASE WHEN ja.is_for_return = 1 THEN $moWaybillSql END) AS for_return_total,
                COUNT(DISTINCT CASE WHEN ja.is_in_transit = 1 THEN $moWaybillSql END) AS in_transit_total
            ")
            ->groupByRaw($groupByKey)
            ->get();

        $shippedMap = $deliveredMap = $returnedMap = $forReturnMap = $inTransitMap = [];
        foreach ($shipAgg as $r) {
            $k = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
            $shippedMap[$k]   = (int)($r->shipped_total   ?? 0);
            $deliveredMap[$k] = (int)($r->delivered_total ?? 0);
            $returnedMap[$k]  = (int)($r->returned_total  ?? 0);
            $forReturnMap[$k] = (int)($r->for_return_total?? 0);
            $inTransitMap[$k] = (int)($r->in_transit_total?? 0);
            if (!empty($r->page_label)) $labelMap[$k] = (string)$r->page_label;
        }

        $actualShipAgg = (clone $joinedBase)
            ->selectRaw("$selectKeyAgg,
                SUM(ja.actual_shipping_cost) AS total_actual_shipping")
            ->groupByRaw($groupByKey)
            ->get();

        $actualShippingMap = [];
        foreach ($actualShipAgg as $r) {
            $k = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
            $actualShippingMap[$k] = (float)($r->total_actual_shipping ?? 0);
        }

        $innerDeliveredCod = (clone $joinedBase)
            ->whereRaw('ja.is_delivered = 1')
            ->selectRaw("$selectKeyAgg, $moWaybillSql AS wb, MAX($moCodClean) AS cod_mo")
            ->groupByRaw("$groupByKey, $moWaybillSql");

        if ($AGGREGATE_RANGE) {
            $grossRows = DB::query()
                ->fromSub($innerDeliveredCod, 'd')
                ->selectRaw("page_key, SUM(cod_mo) AS gross_sales")
                ->groupBy('page_key')
                ->get();
        } else {
            $grossRows = DB::query()
                ->fromSub($innerDeliveredCod, 'd')
                ->selectRaw("day_key, page_key, SUM(cod_mo) AS gross_sales")
                ->groupBy('day_key','page_key')
                ->get();
        }

        $grossMap = [];
        foreach ($grossRows as $r) {
            $k = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
            $grossMap[$k] = (float)($r->gross_sales ?? 0);
        }

        $innerAllCod = (clone $joinedBase)
            ->selectRaw("$selectKeyAgg, $moWaybillSql AS wb, MAX($moCodClean) AS cod_mo")
            ->groupByRaw("$groupByKey, $moWaybillSql");

        if ($AGGREGATE_RANGE) {
            $allCodRows = DB::query()
                ->fromSub($innerAllCod, 'd')
                ->selectRaw("page_key, SUM(cod_mo) AS all_cod")
                ->groupBy('page_key')
                ->get();
        } else {
            $allCodRows = DB::query()
                ->fromSub($innerAllCod, 'd')
                ->selectRaw("day_key, page_key, SUM(cod_mo) AS all_cod")
                ->groupBy('day_key','page_key')
                ->get();
        }

        $allCodMap = [];
        foreach ($allCodRows as $r) {
            $k = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
            $allCodMap[$k] = (float)($r->all_cod ?? 0);
        }

        $moProceedOnly = (clone $mo)->whereRaw("$statusNorm = 'proceed'");

        $itemsProceedRows = (clone $moProceedOnly)
            ->selectRaw("$selectKeyAgg,
                          $itemNorm AS item_key,
                          MIN($itemLabel) AS item_label,
                          COUNT(*) AS qty,
                          MAX($dateExpr) AS last_order_date,
                          MIN($moCodClean) AS min_cod,
                          MAX($moCodClean) AS max_cod")
            ->groupByRaw("$groupByKey, $itemNorm")
            ->get();

        $itemsListMap = [];
        $itemsCostRows = [];
        foreach ($itemsProceedRows as $r) {
            $key = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)($r->day_key ?? '') . '|' . (string)$r->page_key);
            $unitCost = $findUnitCost((string)$r->item_key, (string)$r->last_order_date);
            $allCosts = $findAllUnitCosts((string)$r->item_key, $start, $end);

            $itemsListMap[$key] ??= [];
            $itemsListMap[$key][] = [
                'label'     => (string)($r->item_label ?? ''),
                'qty'       => (int)($r->qty ?? 0),
                'unit_cost' => $unitCost,
                'all_costs' => $allCosts,
                'min_cod'   => (float)($r->min_cod ?? 0),
                'max_cod'   => (float)($r->max_cod ?? 0),
            ];
            if (!empty($r->page_label)) $labelMap[$key] = (string)$r->page_label;

            $itemsCostRows[] = (object)[
                'item_key' => (string)$r->item_key,
                'qty' => (int)($r->qty ?? 0),
                'unit_cost_disp' => $unitCost,
            ];
        }

        $procCodRows = (clone $moProceedOnly)
            ->selectRaw("$selectKeyAgg, SUM($moCodClean) AS proceed_cod_sum")
            ->groupByRaw("$groupByKey")
            ->get();

        $proceedCodSumMap = [];
        foreach ($procCodRows as $r) {
            $k = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
            $proceedCodSumMap[$k] = (float)($r->proceed_cod_sum ?? 0);
            if (!empty($r->page_label)) $labelMap[$k] = (string)$r->page_label;
        }

        $proceedUnitCostSumMap = [];
        foreach ($itemsProceedRows as $r) {
            $key = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)($r->day_key ?? '') . '|' . (string)$r->page_key);
            $unitCost = $findUnitCost((string)$r->item_key, (string)$r->last_order_date);
            $proceedUnitCostSumMap[$key] = ($proceedUnitCostSumMap[$key] ?? 0.0) + ((int)$r->qty * $unitCost);
            if (!empty($r->page_label)) $labelMap[$key] = (string)$r->page_label;
        }

        if ($driver === 'mysql') {
            $jSubmitDate = "DATE(ja.min_submit_ts)";
            $delayDays   = "DATEDIFF($jSubmitDate, $dateExpr)";
        } else {
            $jSubmitDate = "DATE(ja.min_submit_ts)";
            $delayDays   = "($jSubmitDate - $dateExpr)";
        }

        $delayRaw = (clone $joinedBase)
            ->selectRaw("$selectKeyBase, $moWaybillSql AS wb, $delayDays AS delay_days");

        if ($AGGREGATE_RANGE) {
            $delayDistinct = DB::query()
                ->fromSub($delayRaw, 'r')
                ->selectRaw("page_key, MIN(page_label) AS page_label, wb, MIN(delay_days) AS delay_days")
                ->groupBy('page_key','wb');

            $delayAvgRows = DB::query()
                ->fromSub($delayDistinct, 'd')
                ->selectRaw("page_key, MIN(page_label) AS page_label, AVG(delay_days) AS avg_delay_days")
                ->groupBy('page_key')
                ->get();
        } else {
            $delayDistinct = DB::query()
                ->fromSub($delayRaw, 'r')
                ->selectRaw("day_key, page_key, MIN(page_label) AS page_label, wb, MIN(delay_days) AS delay_days")
                ->groupBy('day_key','page_key','wb');

            $delayAvgRows = DB::query()
                ->fromSub($delayDistinct, 'd')
                ->selectRaw("day_key, page_key, MIN(page_label) AS page_label, AVG(delay_days) AS avg_delay_days")
                ->groupBy('day_key','page_key')
                ->get();
        }

        $avgDelayMap = [];
        foreach ($delayAvgRows as $r) {
            $k = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
            $avgDelayMap[$k] = (float)($r->avg_delay_days ?? 0);
            if (!empty($r->page_label)) $labelMap[$k] = (string)$r->page_label;
        }

        $deliveredItemsRows = (clone $joinedBase)
            ->whereRaw('ja.is_delivered = 1')
            ->selectRaw("$selectKeyAgg, $itemNorm AS item_key, MIN($itemLabel) AS item_label,
                         COUNT(DISTINCT $moWaybillSql) AS qty, MAX($dateExpr) AS last_order_date")
            ->groupByRaw("$groupByKey, $itemNorm")
            ->get();

        $cogsMap = [];
        foreach ($deliveredItemsRows as $r) {
            $k = $AGGREGATE_RANGE ? (string)$r->page_key : ((string)$r->day_key . '|' . (string)$r->page_key);
            $unitCost = $findUnitCost((string)$r->item_key, (string)$r->last_order_date);
            $cogsMap[$k] = ($cogsMap[$k] ?? 0.0) + ((int)$r->qty * $unitCost);
        }

        $perDatePageShipMap = [];
        if ($AGGREGATE_RANGE) {
            $perDatePageShipRows = (clone $joinedBase)
                ->selectRaw("$dateExpr AS day_key, $pageKeyExpr AS page_key,
                    COUNT(DISTINCT $moWaybillSql) AS shipped_total,
                    COUNT(DISTINCT CASE WHEN ja.is_delivered  = 1 THEN $moWaybillSql END) AS delivered_total,
                    COUNT(DISTINCT CASE WHEN ja.is_returned   = 1 THEN $moWaybillSql END) AS returned_total,
                    COUNT(DISTINCT CASE WHEN ja.is_for_return = 1 THEN $moWaybillSql END) AS for_return_total,
                    COUNT(DISTINCT CASE WHEN ja.is_in_transit = 1 THEN $moWaybillSql END) AS in_transit_total
                ")
                ->groupByRaw("$dateExpr, $pageKeyExpr")
                ->get();

            foreach ($perDatePageShipRows as $r) {
                $pk = (string)$r->page_key;
                $perDatePageShipMap[$pk][] = [
                    'shipped'    => (int)($r->shipped_total ?? 0),
                    'delivered'  => (int)($r->delivered_total ?? 0),
                    'returned'   => (int)($r->returned_total ?? 0),
                    'for_return' => (int)($r->for_return_total ?? 0),
                    'in_transit' => (int)($r->in_transit_total ?? 0),
                ];
            }
        }

        if ($driver === 'mysql') {
            DB::statement("DROP TEMPORARY TABLE IF EXISTS _jnt_agg");
        } else {
            DB::statement("DROP TABLE IF EXISTS _jnt_agg");
        }

        $keys = array_unique(array_merge(
            array_keys($adsMap),
            array_keys($ordersMap),
            array_keys($proceedMap),
            array_keys($cannotMap),
            array_keys($odzMap),
            array_keys($shippedMap),
            array_keys($deliveredMap),
            array_keys($returnedMap),
            array_keys($forReturnMap),
            array_keys($inTransitMap),
            array_keys($grossMap),
            array_keys($allCodMap),
            array_keys($itemsListMap),
            array_keys($avgDelayMap),
            array_keys($proceedCodSumMap),
            array_keys($proceedUnitCostSumMap),
            array_keys($cogsMap)
        ));

        $rangeLabel = '—';
        if ($AGGREGATE_RANGE) {
            if ($start && $end)      $rangeLabel = "$start – $end";
            elseif ($start)          $rangeLabel = "$start – …";
            elseif ($end)            $rangeLabel = "… – $end";
        }

        $rows = [];
        foreach ($keys as $key) {
            $adspent = $adsMap[$key] ?? 0.0;
            if ($adspent <= 0) continue;

            $itemsDisplay = null;
            $unitCostsArr = [];
            $itemsWithCosts = [];
            if (!empty($itemsListMap[$key])) {
                $items = $itemsListMap[$key];
                usort($items, fn($a,$b) => strcmp($a['label'], $b['label']));
                $many = count($items) > 1;
                $labels = [];
                foreach ($items as $it) {
                    $lbl = $it['label'] ?? '';
                    if ($many) $lbl .= '(' . (int)$it['qty'] . ')';
                    $labels[] = $lbl;
                    $unitCostsArr[] = (float)$it['unit_cost'];

                    $allCosts = $it['all_costs'] ?? [(float)$it['unit_cost']];
                    if (count($allCosts) <= 1) {
                        $costStr = '₱' . number_format($allCosts[0] ?? 0, 2);
                    } else {
                        $costStr = '₱' . number_format(min($allCosts), 2) . ' – ₱' . number_format(max($allCosts), 2);
                    }
                    $minCod = (float)($it['min_cod'] ?? 0);
                    $maxCod = (float)($it['max_cod'] ?? 0);
                    if ($minCod == $maxCod || $maxCod <= 0) {
                        $codStr = '₱' . number_format($maxCod > 0 ? $maxCod : $minCod, 2);
                    } else {
                        $codStr = '₱' . number_format($minCod, 2) . ' – ₱' . number_format($maxCod, 2);
                    }

                    $itemsWithCosts[] = [
                        'label' => $lbl,
                        'cost'  => $costStr,
                        'cod'   => $codStr,
                    ];
                }
                $itemsDisplay = implode(' / ', $labels);
            }

            $pageLabelResolved = $labelMap[$key] ?? null;

            if ($AGGREGATE_RANGE) {
                $orders    = $ordersMap[$key]      ?? 0;
                $proc      = $proceedMap[$key]     ?? 0;
                $cannot    = $cannotMap[$key]      ?? 0;
                $odz       = $odzMap[$key]         ?? 0;
                $shipped   = $shippedMap[$key]     ?? 0;
                $delivered = $deliveredMap[$key]   ?? 0;
                $returned  = $returnedMap[$key]    ?? 0;
                $forRet    = $forReturnMap[$key]   ?? 0;
                $inTrans   = $inTransitMap[$key]   ?? 0;
                $gross     = $grossMap[$key]       ?? 0.0;
                $all_cod   = $allCodMap[$key]      ?? 0.0;
                $avgDelay  = $avgDelayMap[$key]    ?? null;
                $cogs      = $cogsMap[$key]        ?? 0.0;

                $shipping_fee   = $actualShippingMap[$key] ?? ($SHIPPING_PER_SHIPPED * $shipped);
                $cod_fee_actual = $gross * $COD_FEE_RATE;
                $cod_fee_vat    = $cod_fee_actual * $COD_FEE_VAT_RATE;

                $cpp            = $orders  > 0 ? ($adspent / $orders) * 1.0 : null;
                $proceed_cpp    = $proc    > 0 ? ($adspent / $proc)   * 1.0 : null;
                $rts_pct        = $shipped > 0 ? (($returned + $forRet) / $shipped) * 100.0 : null;
                $in_transit_pct = $shipped > 0 ? ($inTrans / $shipped) * 100.0 : null;
                $tcpr           = $orders  > 0 ? (1 - ($proc / $orders)) * 100.0 : null;

                $net_profit     = $gross - $adspent - $shipping_fee - $cod_fee_actual - $cod_fee_vat - $cogs;
                $net_profit_pct = $all_cod > 0 ? ($net_profit / $all_cod) * 100.0 : null;
                $hold           = $proc - $shipped;

                $rows[] = [
                    'date'                   => $rangeLabel,
                    'page'                   => $pageLabelResolved ?: null,
                    'adspent'                => $adspent,
                    'orders'                 => $orders,
                    'proceed'                => $proc,
                    'cannot_proceed'         => $cannot,
                    'odz'                    => $odz,
                    'shipped'                => $shipped,
                    'delivered'              => $delivered,
                    'avg_delay_days'         => $avgDelay,
                    'items_display'          => $itemsDisplay,
                    'unit_costs'             => $unitCostsArr,
                    'items_with_costs'       => $itemsWithCosts,
                    'gross_sales'            => $gross,
                    'shipping_fee'           => $shipping_fee,
                    'cod_fee'                => $cod_fee_actual,
                    'cod_fee_vat'            => $cod_fee_vat,
                    'cogs'                   => $cogs,
                    'net_profit'             => $net_profit,
                    'net_profit_pct'         => $net_profit_pct,
                    'returned'               => $returned,
                    'for_return'             => $forRet,
                    'in_transit'             => $inTrans,
                    'cpp'                    => $cpp,
                    'proceed_cpp'            => $proceed_cpp,
                    'rts_pct'                => $rts_pct,
                    'in_transit_pct'         => $in_transit_pct,
                    'tcpr'                   => $tcpr,
                    'hold'                   => $hold,
                    'is_total'               => false,
                    'all_cod'                => $all_cod,
                    'proceed_cod_sum'        => $proceedCodSumMap[$key]      ?? 0.0,
                    'proceed_unit_cost_sum'  => $proceedUnitCostSumMap[$key] ?? 0.0,
                ];
            } else {
                [$d, $pKey] = explode('|', $key, 2);
                $orders    = $ordersMap[$key]      ?? 0;
                $proc      = $proceedMap[$key]     ?? 0;
                $cannot    = $cannotMap[$key]      ?? 0;
                $odz       = $odzMap[$key]         ?? 0;
                $shipped   = $shippedMap[$key]     ?? 0;
                $delivered = $deliveredMap[$key]   ?? 0;
                $returned  = $returnedMap[$key]    ?? 0;
                $forRet    = $forReturnMap[$key]   ?? 0;
                $inTrans   = $inTransitMap[$key]   ?? 0;
                $gross     = $grossMap[$key]       ?? 0.0;
                $all_cod   = $allCodMap[$key]      ?? 0.0;
                $avgDelay  = $avgDelayMap[$key]    ?? null;
                $cogs      = $cogsMap[$key]        ?? 0.0;

                $shipping_fee   = $actualShippingMap[$key] ?? ($SHIPPING_PER_SHIPPED * $shipped);
                $cod_fee_actual = $gross * $COD_FEE_RATE;
                $cod_fee_vat    = $cod_fee_actual * $COD_FEE_VAT_RATE;

                $cpp            = $orders  > 0 ? ($adspent / $orders) * 1.0 : null;
                $proceed_cpp    = $proc    > 0 ? ($adspent / $proc)   * 1.0 : null;
                $rts_pct        = $shipped > 0 ? (($returned + $forRet) / $shipped) * 100.0 : null;
                $in_transit_pct = $shipped > 0 ? ($inTrans / $shipped) * 100.0 : null;
                $tcpr           = $orders  > 0 ? (1 - ($proc / $orders)) * 100.0 : null;

                $net_profit     = $gross - $adspent - $shipping_fee - $cod_fee_actual - $cod_fee_vat - $cogs;
                $net_profit_pct = $all_cod > 0 ? ($net_profit / $all_cod) * 100.0 : null;
                $hold           = $proc - $shipped;

                $rows[] = [
                    'date'                   => $d,
                    'page'                   => $pageLabelResolved ?: null,
                    'adspent'                => $adspent,
                    'orders'                 => $orders,
                    'proceed'                => $proc,
                    'cannot_proceed'         => $cannot,
                    'odz'                    => $odz,
                    'shipped'                => $shipped,
                    'delivered'              => $delivered,
                    'avg_delay_days'         => $avgDelay,
                    'items_display'          => $itemsDisplay,
                    'unit_costs'             => $unitCostsArr,
                    'items_with_costs'       => $itemsWithCosts,
                    'gross_sales'            => $gross,
                    'shipping_fee'           => $shipping_fee,
                    'cod_fee'                => $cod_fee_actual,
                    'cod_fee_vat'            => $cod_fee_vat,
                    'cogs'                   => $cogs,
                    'net_profit'             => $net_profit,
                    'net_profit_pct'         => $net_profit_pct,
                    'returned'               => $returned,
                    'for_return'             => $forRet,
                    'in_transit'             => $inTrans,
                    'cpp'                    => $cpp,
                    'proceed_cpp'            => $proceed_cpp,
                    'rts_pct'                => $rts_pct,
                    'in_transit_pct'         => $in_transit_pct,
                    'tcpr'                   => $tcpr,
                    'hold'                   => $hold,
                    'is_total'               => false,
                    'all_cod'                => $all_cod,
                    'proceed_cod_sum'        => $proceedCodSumMap[$key]      ?? 0.0,
                    'proceed_unit_cost_sum'  => $proceedUnitCostSumMap[$key] ?? 0.0,
                ];
            }
        }

        if ($AGGREGATE_RANGE) {
            usort($rows, fn($a,$b) => strcmp($a['page'] ?? '', $b['page'] ?? ''));
        } else {
            usort($rows, function ($a, $b) {
                if ($a['date'] === $b['date']) {
                    return strcmp($a['page'] ?? '', $b['page'] ?? '');
                }
                return strcmp($a['date'], $b['date']);
            });
        }

        $actualRtsPct = null;
        if (!$AGGREGATE_RANGE) {
            $num = 0;
            $den = 0;
            foreach ($rows as $r) {
                if (!empty($r['is_total'])) continue;
                $inPct = $r['in_transit_pct'] ?? null;
                if ($inPct !== null && $inPct < 3.0) {
                    $ret = (int)($r['returned']   ?? 0);
                    $fr  = (int)($r['for_return'] ?? 0);
                    $del = (int)($r['delivered']  ?? 0);
                    $num += ($ret + $fr);
                    $den += ($del + $ret + $fr);
                }
            }
            $actualRtsPct = $den > 0 ? ($num / $den) * 100.0 : null;
        }

        $effectiveRtsPct = $actualRtsPct;
        if (!$AGGREGATE_RANGE && $effectiveRtsPct === null) {
            $histNum = 0; $histDen = 0;
            foreach ($rows as $r) {
                if (!empty($r['is_total'])) continue;
                $ret = (int)($r['returned']   ?? 0);
                $fr  = (int)($r['for_return'] ?? 0);
                $del = (int)($r['delivered']  ?? 0);
                $histNum += ($ret + $fr);
                $histDen += ($del + $ret + $fr);
            }
            if ($histDen > 0) {
                $effectiveRtsPct = ($histNum / $histDen) * 100.0;
            } else {
                $effectiveRtsPct = $DEFAULT_RTS_PCT;
            }
        }

        if ($AGGREGATE_RANGE) {
            foreach ($rows as &$r) {
                if (!empty($r['is_total'])) { $r['projected_net_profit'] = null; $r['projected_net_profit_pct'] = null; continue; }

                $pageKey = mb_strtolower(trim((string)($r['page'] ?? '')));
                $perDateRows = $perDatePageShipMap[$pageKey] ?? [];

                $estRtsNum = 0;
                $estRtsDen = 0;
                foreach ($perDateRows as $pd) {
                    $pdShipped = $pd['shipped'];
                    $pdInTransitPct = $pdShipped > 0 ? ($pd['in_transit'] / $pdShipped) * 100.0 : null;
                    if ($pdInTransitPct !== null && $pdInTransitPct < 3.0) {
                        $estRtsNum += ($pd['returned'] + $pd['for_return']);
                        $estRtsDen += ($pd['delivered'] + $pd['returned'] + $pd['for_return']);
                    }
                }

                $pageRtsPct = null;
                if ($estRtsDen > 0) {
                    $pageRtsPct = ($estRtsNum / $estRtsDen) * 100.0;
                } else {
                    $fallbackNum = 0; $fallbackDen = 0;
                    foreach ($perDateRows as $pd) {
                        $fallbackNum += ($pd['returned'] + $pd['for_return']);
                        $fallbackDen += ($pd['delivered'] + $pd['returned'] + $pd['for_return']);
                    }
                    $pageRtsPct = $fallbackDen > 0 ? ($fallbackNum / $fallbackDen) * 100.0 : null;
                }

                if ($pageRtsPct === null) $pageRtsPct = $DEFAULT_RTS_PCT;
                $r['actual_rts_pct'] = $pageRtsPct;
                $rtsFactor = max(0.0, min(1.0, 1.0 - ($pageRtsPct / 100.0)));

                $procCodSum  = (float)($r['proceed_cod_sum']       ?? 0.0);
                $procUCSum   = (float)($r['proceed_unit_cost_sum'] ?? 0.0);
                $proceedCnt  = (int)  ($r['proceed']               ?? 0);
                $adsp        = (float)($r['adspent']               ?? 0.0);

                $projGross      = $procCodSum * $rtsFactor;
                $projCodFee     = $procCodSum * $COD_FEE_RATE;
                $projCodFeeVat  = $projCodFee * $COD_FEE_VAT_RATE;
                $projCogs       = $procUCSum  * $rtsFactor;
                $projShipFee    = $SHIPPING_PER_SHIPPED * $proceedCnt;

                $projNP = $projGross - $adsp - $projCodFee - $projCodFeeVat - $projCogs - $projShipFee;

                $r['projected_net_profit']     = $projNP;
                $den                           = $procCodSum;
                $r['projected_net_profit_pct'] = ($den > 0) ? ($projNP / $den) * 100.0 : null;
                $r['_proj_cod_den'] = $den;
            }
            unset($r);
        } else {
            $rtsFactor = 1.0;
            if ($effectiveRtsPct !== null) {
                $rtsFactor = max(0.0, min(1.0, 1.0 - ($effectiveRtsPct / 100.0)));
            }

            foreach ($rows as &$r) {
                if (!empty($r['is_total'])) { $r['projected_net_profit'] = null; $r['projected_net_profit_pct'] = null; $r['actual_rts_pct'] = $actualRtsPct; continue; }
                $r['actual_rts_pct'] = $actualRtsPct;

                $procCodSum  = (float)($r['proceed_cod_sum']       ?? 0.0);
                $procUCSum   = (float)($r['proceed_unit_cost_sum'] ?? 0.0);
                $proceedCnt  = (int)  ($r['proceed']               ?? 0);
                $adsp        = (float)($r['adspent']               ?? 0.0);

                $projGross      = $procCodSum * $rtsFactor;
                $projCodFee     = $procCodSum * $COD_FEE_RATE;
                $projCodFeeVat  = $projCodFee * $COD_FEE_VAT_RATE;
                $projCogs       = $procUCSum  * $rtsFactor;
                $projShipFee    = $SHIPPING_PER_SHIPPED * $proceedCnt;

                $projNP = $projGross - $adsp - $projCodFee - $projCodFeeVat - $projCogs - $projShipFee;

                $r['projected_net_profit']     = $projNP;
                $den                           = $procCodSum;
                $r['projected_net_profit_pct'] = ($den > 0) ? ($projNP / $den) * 100.0 : null;

                $r['_proj_cod_den'] = $den;
            }
            unset($r);
        }

        if (!empty($rows)) {
            $sum = [
                'adspent' => 0.0,
                'orders' => 0, 'proceed' => 0, 'cannot_proceed' => 0, 'odz' => 0,
                'shipped' => 0, 'delivered' => 0, 'returned' => 0, 'for_return' => 0, 'in_transit' => 0,
                'gross_sales' => 0.0,
                'all_cod' => 0.0,
                'proceed_cod_sum' => 0.0,
                'cogs' => 0.0,
                'shipping_fee' => 0.0,
                'cod_fee_vat' => 0.0,
            ];
            $delayWeightedSum = 0.0;
            $delayShipCount   = 0;

            foreach ($rows as $r) {
                $sum['adspent']        += (float)($r['adspent'] ?? 0);
                $sum['orders']         += (int)  ($r['orders'] ?? 0);
                $sum['proceed']        += (int)  ($r['proceed'] ?? 0);
                $sum['cannot_proceed'] += (int)  ($r['cannot_proceed'] ?? 0);
                $sum['odz']            += (int)  ($r['odz'] ?? 0);
                $sum['shipped']        += (int)  ($r['shipped'] ?? 0);
                $sum['delivered']      += (int)  ($r['delivered'] ?? 0);
                $sum['returned']       += (int)  ($r['returned'] ?? 0);
                $sum['for_return']     += (int)  ($r['for_return'] ?? 0);
                $sum['in_transit']     += (int)  ($r['in_transit'] ?? 0);
                $sum['gross_sales']    += (float)($r['gross_sales'] ?? 0);
                $sum['all_cod']        += (float)($r['all_cod'] ?? 0);
                $sum['proceed_cod_sum']+= (float)($r['proceed_cod_sum'] ?? 0);
                $sum['cogs']           += (float)($r['cogs'] ?? 0);
                $sum['shipping_fee']   += (float)($r['shipping_fee'] ?? 0);
                $sum['cod_fee_vat']    += (float)($r['cod_fee_vat'] ?? 0);

                if (isset($r['avg_delay_days']) && $r['avg_delay_days'] !== null && ($r['shipped'] ?? 0) > 0 && empty($r['is_total'])) {
                    $delayWeightedSum += (float)$r['avg_delay_days'] * (int)$r['shipped'];
                    $delayShipCount   += (int)$r['shipped'];
                }
            }

            $total_cpp            = $sum['orders']  > 0 ? ($sum['adspent'] / $sum['orders']) * 1.0 : null;
            $total_proceed_cpp    = $sum['proceed'] > 0 ? ($sum['adspent'] / $sum['proceed']) * 1.0 : null;
            $total_rts_pct        = $sum['shipped'] > 0 ? (($sum['returned'] + $sum['for_return']) / $sum['shipped']) * 100.0 : null;
            $total_in_transit_pct = $sum['shipped'] > 0 ? ($sum['in_transit'] / $sum['shipped']) * 100.0 : null;
            $total_tcpr           = $sum['orders']  > 0 ? (1 - ($sum['proceed'] / $sum['orders'])) * 100.0 : null;
            $total_shipping_fee   = $sum['shipping_fee'] ?? ($SHIPPING_PER_SHIPPED * $sum['shipped']);
            $total_cod_fee        = $sum['gross_sales'] * $COD_FEE_RATE;
            $total_cod_fee_vat    = $sum['cod_fee_vat'] ?? ($total_cod_fee * $COD_FEE_VAT_RATE);

            $total_net_profit     = $sum['gross_sales'] - $sum['adspent'] - $total_shipping_fee - $total_cod_fee - $total_cod_fee_vat - $sum['cogs'];
            $total_net_profit_pct = $sum['all_cod'] > 0 ? ($total_net_profit / $sum['all_cod']) * 100.0 : null;
            $total_avg_delay      = $delayShipCount > 0 ? ($delayWeightedSum / $delayShipCount) : null;

            $sumProjNP  = 0.0;
            $totalActualRtsNum = 0;
            $totalActualRtsDen = 0;
            foreach ($rows as $r) {
                if (!empty($r['is_total'])) continue;
                $sumProjNP += (float)($r['projected_net_profit'] ?? 0.0);
                $inPct = $r['in_transit_pct'] ?? null;
                if ($inPct !== null && $inPct < 3.0) {
                    $totalActualRtsNum += (int)($r['returned'] ?? 0) + (int)($r['for_return'] ?? 0);
                    $totalActualRtsDen += (int)($r['delivered'] ?? 0) + (int)($r['returned'] ?? 0) + (int)($r['for_return'] ?? 0);
                }
            }
            $totalActualRtsPct = $totalActualRtsDen > 0 ? ($totalActualRtsNum / $totalActualRtsDen) * 100.0 : null;
            $total_projected_net_profit_pct = ($sum['proceed_cod_sum'] > 0)
                ? ($sumProjNP / $sum['proceed_cod_sum']) * 100.0
                : null;

            $rows[] = [
                'date'            => $AGGREGATE_RANGE ? $rangeLabel : 'Total',
                'page'            => (!$AGGREGATE_RANGE && $pageName && strtolower($pageName) !== 'all') ? $pageName : 'TOTAL',
                'adspent'         => $sum['adspent'],
                'orders'          => $sum['orders'],
                'proceed'         => $sum['proceed'],
                'cannot_proceed'  => $sum['cannot_proceed'],
                'odz'             => $sum['odz'],
                'shipped'         => $sum['shipped'],
                'delivered'       => $sum['delivered'],
                'avg_delay_days'  => $total_avg_delay,
                'items_display'   => '—',
                'unit_costs'      => [],
                'gross_sales'     => $sum['gross_sales'],
                'shipping_fee'    => $total_shipping_fee,
                'cod_fee'         => $total_cod_fee,
                'cod_fee_vat'     => $total_cod_fee_vat,
                'cogs'            => $sum['cogs'],
                'net_profit'      => $total_net_profit,
                'net_profit_pct'  => $total_net_profit_pct,
                'returned'        => $sum['returned'],
                'for_return'      => $sum['for_return'],
                'in_transit'      => $sum['in_transit'],
                'cpp'             => $total_cpp,
                'proceed_cpp'     => $total_proceed_cpp,
                'rts_pct'         => $total_rts_pct,
                'in_transit_pct'  => $total_in_transit_pct,
                'tcpr'            => $total_tcpr,
                'hold'            => ($sum['proceed'] - $sum['shipped']),
                'is_total'        => true,
                'projected_net_profit' => null,
                'projected_net_profit_pct'  => $total_projected_net_profit_pct,
                'actual_rts_pct'  => $totalActualRtsPct ?? ($AGGREGATE_RANGE ? null : $actualRtsPct),
            ];
        }

        $topSummary = [];
        if (!$AGGREGATE_RANGE) {
            $daily = array_values(array_filter($rows, fn($r) => empty($r['is_total'])));
            $dates = array_values(array_unique(array_map(fn($r) => (string)$r['date'], $daily)));
            sort($dates);
            if (!empty($dates)) {
                $lastDate = $dates[count($dates)-1];
                $datesBeforeLast = $dates;
                array_pop($datesBeforeLast);

                $pickRowsOn = function(array $wanted) use ($daily) {
                    $set = array_flip($wanted);
                    return array_values(array_filter($daily, fn($r) => isset($set[$r['date']])));
                };

                $rowsFiltered = $daily;
                $rowsLast7    = $pickRowsOn(array_slice($datesBeforeLast, -7));
                $rowsLast3    = $pickRowsOn(array_slice($datesBeforeLast, -3));
                $rowsLast1    = $pickRowsOn([$lastDate]);

                $aggregate = function(array $subset) {
                    if (!count($subset)) return ['adspent'=>null,'proceed_cpp'=>null,'pn_pct'=>null];

                    $adSum   = 0.0;
                    $procSum = 0;
                    $npSum   = 0.0;
                    $denSum  = 0.0;

                    foreach ($subset as $r) {
                        $adSum   += (float)($r['adspent'] ?? 0);
                        $procSum += (int)  ($r['proceed'] ?? 0);
                        $npSum   += (float)($r['projected_net_profit'] ?? 0.0);
                        $denSum  += (float)($r['_proj_cod_den']        ?? 0.0);
                    }

                    $proceedCPP = ($procSum > 0) ? ($adSum / $procSum) : null;
                    $pn_pct     = ($denSum > 0) ? ($npSum / $denSum) * 100.0 : null;

                    return ['adspent'=>$adSum,'proceed_cpp'=>$proceedCPP,'pn_pct'=>$pn_pct];
                };

                $filteredLabel = $makeFilteredLabel($start, $end);
                $last1Label    = ($lastDate === $today) ? 'Today' : ('Last Day (' . $fmtMonthDay($lastDate) . ')');

                $topSummary = [
                    ['key'=>'filtered','rangeLabel'=>$filteredLabel, ...$aggregate($rowsFiltered)],
                    ['key'=>'last7',   'rangeLabel'=>'Last 7 Days', ...$aggregate($rowsLast7)],
                    ['key'=>'last3',   'rangeLabel'=>'Last 3 Days', ...$aggregate($rowsLast3)],
                    ['key'=>'last1',   'rangeLabel'=>$last1Label, ...$aggregate($rowsLast1)],
                ];
            }
        }

        $targetCPP = null;
        $breakevenCPP = null;

        if (!$AGGREGATE_RANGE) {
            $unitCostMode = null;
            if (!empty($itemsCostRows)) {
                $freq = [];
                foreach ($itemsCostRows as $r) {
                    $uc  = (float)($r->unit_cost_disp ?? 0);
                    $qty = (int)($r->qty ?? 0);
                    $key = number_format($uc, 2, '.', '');
                    $freq[$key] = ($freq[$key] ?? 0) + $qty;
                }
                if (!empty($freq)) {
                    arsort($freq);
                    $unitCostMode = (float)array_key_first($freq);
                }
            }

            $codMode = null;
            $codModeRow = (clone $moProceedOnly)
                ->selectRaw("$moCodClean AS cod_clean, COUNT(*) AS cnt")
                ->groupByRaw("$moCodClean")
                ->orderByRaw("COUNT(*) DESC")
                ->limit(1)
                ->first();
            if ($codModeRow) {
                $codMode = (float)$codModeRow->cod_clean;
            }

            $rtsPctUsed = $effectiveRtsPct ?? $DEFAULT_RTS_PCT;
            $rts = max(0.0, min(1.0, $rtsPctUsed / 100.0));

            if ($unitCostMode !== null && $codMode !== null) {
                $codFeeWithVat = $COD_FEE_RATE * (1 + $COD_FEE_VAT_RATE);
                $targetCPP     = (1 - $rts) * ((1 - $codFeeWithVat) * $codMode - $unitCostMode) - 0.2  * $codMode - $SHIPPING_PER_SHIPPED;
                $breakevenCPP  = (1 - $rts) * ((1 - $codFeeWithVat) * $codMode - $unitCostMode) - 0.05 * $codMode - $SHIPPING_PER_SHIPPED;
            }
        }

        $payload = [
            'ads_daily'       => $rows,
            'actual_rts_pct'  => $actualRtsPct,
            'top_summary'     => $topSummary,
            'target_cpp'      => $targetCPP,
            'breakeven_cpp'   => $breakevenCPP,
            'cached_at'       => now()->toIso8601String(),
        ];

        Cache::forever($cacheKey, $payload);

        return response()->json($payload + ['_cache' => $bypassCache ? 'refresh' : 'miss'])
            ->header('X-Cache-Status', $bypassCache ? 'REFRESH' : 'MISS')
            ->header('X-Cache-Key',    $cacheKey)
            ->header('X-Cache-Version', $this->cacheVersion());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Item Summary (one-day view, grouped by page, dominant item used for calcs)
    // ─────────────────────────────────────────────────────────────────────────

    public function itemSummary(Request $request)
    {
        $this->checkAccess();

        // ── Cache gate ────────────────────────────────────────────────────
        // Cached read by default. ?refresh=1 bypasses read pero still writes
        // fresh data to cache after computing. Cache invalidated by
        // bumpCacheVersion() (on uploads / saves).
        $cacheKey = $this->cacheKeyForItemSummary($request);
        $bypassCache = (bool) $request->boolean('refresh');
        if (!$bypassCache) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return response()->json($cached + ['_cache' => 'hit']);
            }
        }

        $phTz = new \DateTimeZone('Asia/Manila');
        $today = (new \DateTime('now', $phTz))->format('Y-m-d');

        // Range mode: start_date/end_date take precedence; legacy ?date=X still works.
        $legacyDate = $request->input('date');
        $startDate  = $request->input('start_date', $legacyDate ?: $today);
        $endDate    = $request->input('end_date',   $legacyDate ?: $startDate);

        // Sanitize — only accept YYYY-MM-DD
        $validDate = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
        if (!$validDate($startDate)) $startDate = $today;
        if (!$validDate($endDate))   $endDate   = $startDate;
        if ($startDate > $endDate)   [$startDate, $endDate] = [$endDate, $startDate];

        $isSingleDate = ($startDate === $endDate);
        $rangeDays    = (int) ((strtotime($endDate) - strtotime($startDate)) / 86400) + 1;

        // CEO-only "view as" toggle. Replaces the prior profit-only toggle —
        // now drives BOTH the visible UI mode AND the profit cogs source:
        //   'ceo'       → full CEO experience: cogs_ceo for profit, CEO column
        //                 + modal field visible (default for CEO viewers).
        //   'marketing' → simulate Marketing's view: cogs for profit, CEO column
        //                 + modal field hidden (lets CEO preview Marketing's UI).
        // Non-CEO viewers: param is ignored downstream — always 'marketing'.
        $viewAs = strtolower(trim((string)$request->input('view_as', 'ceo')));
        if (!in_array($viewAs, ['ceo', 'marketing'], true)) $viewAs = 'ceo';

        // ── Partial-date 1D override (opt-in via ?partial_date=YYYY-MM-DD) ─
        // User picks a separate "partial/today" date to spot-check via 1D column.
        // Decouples the main historical range from the partial date — kaya pwedeng
        // mag-view ka sa 2am at pumili pa rin ng yesterday/today bilang partial.
        //
        // Default null = no override, current behavior preserved.
        //
        // When set:
        //   - Page roster anchored sa partial_date kapag > end_date (instead of
        //     end_date) — para consistent ang totals sa "extended range" view.
        //   - Main aggregations + 3D/7D windows EXTEND to include partial_date
        //     (effective end = $loadEnd). Per-row main + TOTAL row therefore
        //     match what an extended-range query would show.
        //   - 1D column OVERRIDDEN: uses partial_date's actual orders + adspent,
        //     but proceed + profit projected using AGGREGATED HISTORICAL TCPR
        //     (per user spec — projection lets you spot-check "today live"
        //     scenarios where actual proceed_orders is unreliable). Proceed_1D
        //     and Profit_1D derive from the SAME projection so TCPR(1D) is
        //     internally consistent with the displayed historical rate.
        $partialDateRaw = trim((string) $request->input('partial_date', ''));
        $partialDate    = $validDate($partialDateRaw) ? $partialDateRaw : null;

        // For DATA LOADING queries, extend range to include partial_date if it
        // falls outside the main range. Aggregation still scoped to [startDate, endDate]
        // — the extra rows beyond endDate are filtered out sa aggregation loop.
        $loadStart = ($partialDate !== null && $partialDate < $startDate) ? $partialDate : $startDate;
        $loadEnd   = ($partialDate !== null && $partialDate > $endDate)   ? $partialDate : $endDate;

        // Backwards-compat alias: many downstream code paths still reference `$date` for
        // "as-of" snapshots (fee rates, COGS, page_item_settings, JNT stats window).
        // All of those should anchor on END_DATE per spec.
        $date = $endDate;

        $host = strtolower((string) $request->getHost());

        $driver = DB::getDriverName();
        $trimFn = $driver === 'pgsql' ? 'BTRIM' : 'TRIM';
        $quote  = fn(string $col) => $driver === 'pgsql' ? '"'.$col.'"' : '`'.$col.'`';

        $pickCol = function (string $table, array $candidates) {
            foreach ($candidates as $c) {
                if (Schema::hasColumn($table, $c)) return $c;
            }
            return null;
        };

        $castMoney = fn(string $expr) => $driver === 'pgsql'
            ? "COALESCE(NULLIF(REGEXP_REPLACE(COALESCE(($expr)::text, ''), '[^0-9\\.\\-]', '', 'g'), '')::numeric, 0)"
            : "CAST(REPLACE(REPLACE(REPLACE(COALESCE($expr,''), '₱',''), ',', ''), ' ', '') AS DECIMAL(18,2))";

        // ── column detection ──────────────────────────────────────────────────
        $pageCol   = $pickCol('macro_output', ['PAGE','page','page_name','Page']) ?? 'PAGE';
        $itemCol   = $pickCol('macro_output', ['ITEM_NAME','item_name','ITEM','item']) ?? 'ITEM_NAME';
        $statusCol = $pickCol('macro_output', ['STATUS','status','Status']) ?? 'STATUS';
        $hasTsDate = Schema::hasColumn('macro_output', 'ts_date');

        $moPage   = 'mo.'.$quote($pageCol);
        $moItem   = 'mo.'.$quote($itemCol);
        $moStatus = 'mo.'.$quote($statusCol);

        $statusNorm = "LOWER(REPLACE(REPLACE($trimFn($moStatus),' ',''),'_',''))";
        $pageTrim   = "$trimFn(COALESCE($moPage,''))";
        $pageKey    = "LOWER($pageTrim)";
        $itemTrim   = "$trimFn(COALESCE($moItem,''))";

        // ── date expression ───────────────────────────────────────────────────
        if ($hasTsDate) {
            $dateExpr = 'DATE(mo.ts_date)';
        } else {
            $tsCol = $pickCol('macro_output', ['TIMESTAMP','timestamp']) ?? 'created_at';
            $tsRef = 'mo.'.$quote($tsCol);
            if ($driver === 'mysql') {
                $dateExpr = "COALESCE(DATE(STR_TO_DATE($tsRef,'%H:%i %d-%m-%Y')),DATE(STR_TO_DATE($tsRef,'%H:%i %m-%d-%Y')),DATE(mo.`created_at`))";
            } else {
                $dateExpr = "DATE(COALESCE(TO_TIMESTAMP(NULLIF($tsRef,''),'HH24:MI DD-MM-YYYY'),TO_TIMESTAMP(NULLIF($tsRef,''),'HH24:MI MM-DD-YYYY'),mo.\"created_at\"))";
            }
        }

        // ── fee rates ─ no fallback ──────────────────────────────────────────
        $codFeeRate    = FeeSetting::getRate('cod_fee_rate',           $host, $date);
        $codFeeVatRate = FeeSetting::getRate('cod_fee_vat_rate',       $host, $date);
        $shippingFee   = FeeSetting::getRate('shipping_fee_per_order', $host, $date);
        if ($codFeeRate === null || $codFeeVatRate === null || $shippingFee === null) {
            $missing = array_filter([
                $codFeeRate    === null ? 'cod_fee_rate'           : null,
                $codFeeVatRate === null ? 'cod_fee_vat_rate'       : null,
                $shippingFee   === null ? 'shipping_fee_per_order' : null,
            ]);
            abort(422, "Missing fee_settings for {$date} (host: {$host}): " . implode(', ', $missing) . ". Configure at /jnt/fee-settings.");
        }

        // ── COD column detection ───────────────────────────────────────────────
        $codCol   = $pickCol('macro_output', ['COD','cod','Cod']) ?? 'COD';
        $moCod    = 'mo.'.$quote($codCol);
        $codClean = $castMoney($moCod);

        // ── ANCHOR PRIMARY ITEMS ─────────────────────────────────────────────
        // Anchor on END_DATE by default. Kapag may $partialDate at nasa future
        // ng $endDate, anchor doon — para yung page roster pareho ng kung
        // na-extend lang ang range hanggang partial_date (consistent totals
        // between "range + 1D" view at "extended range" view).
        // Ties sa anchor date → no anchor row → page is skipped from the table.
        $anchorDate = ($partialDate !== null && $partialDate > $endDate)
            ? $partialDate
            : $endDate;
        $anchorRows = DB::table('daily_page_primary_item')
            ->where('ts_date', $anchorDate)
            ->get([
                'page_label', 'page_key', 'primary_item', 'primary_item_key',
                'primary_orders', 'total_orders_all', 'primary_mode_cod',
                'second_item', 'second_orders',
            ]);

        // Excluded pages (managed via /jnt/supply/excluded-pages) — never participate in profit compute
        $excludedSet = array_flip(SupplyExcludedPage::excludedSet());

        $anchorByPage = []; // page_key → anchor row
        foreach ($anchorRows as $pr) {
            $pk = (string)$pr->page_key;
            if (isset($excludedSet[$pk])) continue;
            $anchorByPage[$pk] = $pr;
        }
        // Back-compat alias used downstream; semantic = "the anchor primary per page"
        // (resolved at $anchorDate = partial_date kung set & > end_date, else end_date).
        $primaryByPage = $anchorByPage;

        // ── RANGE PRIMARY ITEMS (for mixed-primary flag + included-slice filter) ───
        // Only needed when start != end; but we always query it (cheap, indexed by ts_date).
        // Extended to $loadEnd so partial_date data (if outside main range) is loaded too.
        $rangePrimaryRows = DB::table('daily_page_primary_item')
            ->whereBetween('ts_date', [$loadStart, $loadEnd])
            ->get([
                'ts_date', 'page_key', 'primary_item_key', 'primary_orders', 'primary_mode_cod',
            ]);

        // rangeByPage[page_key][ts_date] = ['item_key','orders','mode_cod']
        $rangeByPage         = [];
        $distinctItemsByPage = []; // page_key → [item_key => true]
        foreach ($rangePrimaryRows as $r) {
            $pk = (string)$r->page_key;
            $d  = (string)$r->ts_date;
            $ik = (string)$r->primary_item_key;
            $rangeByPage[$pk][$d] = [
                'item_key' => $ik,
                'orders'   => (int)$r->primary_orders,
                'mode_cod' => $r->primary_mode_cod !== null ? (float)$r->primary_mode_cod : 0.0,
            ];
            $distinctItemsByPage[$pk][$ik] = true;
        }

        // ── Enrich anchors with proceed_orders + COD range (per date, per page) ───
        // Query macro_output grouped by (page_key, raw item label, date) so we can
        // canonicalize via ItemAliasResolver below — aliased variants collapse into
        // one canonical bucket, matching the alias-aware primary_item_key stored in
        // daily_page_primary_item.
        $statRows = DB::table('macro_output as mo')
            ->whereRaw("$dateExpr BETWEEN ? AND ?", [$loadStart, $loadEnd])
            ->whereRaw("$pageTrim != ''")
            ->selectRaw("
                $dateExpr AS d,
                $pageKey AS page_key,
                $itemTrim AS item_raw,
                SUM(CASE WHEN $statusNorm = 'proceed' THEN 1 ELSE 0 END) AS proceed_orders,
                MAX($codClean) AS max_cod,
                MIN(CASE WHEN $codClean > 0 THEN $codClean ELSE NULL END) AS min_cod
            ")
            ->groupByRaw("$dateExpr, $pageKey, $itemTrim")
            ->get();

        // statByKeyDate[page_key||canonical_item_key][date] = aggregated stats
        // Canonical key collapses aliased variants (e.g. "II" + "VII" → same family);
        // on hosts with zero mappings, canonical == raw normalized key → unchanged.
        $aliases = new \App\Services\ItemAliasResolver();
        $statByKeyDate = [];
        foreach ($statRows as $s) {
            $canonKey = $aliases->canonicalKey((string)$s->item_raw);
            $k = (string)$s->page_key.'||'.$canonKey;
            $d = (string)$s->d;
            if (!isset($statByKeyDate[$k][$d])) {
                $statByKeyDate[$k][$d] = (object)[
                    'proceed_orders' => (int)$s->proceed_orders,
                    'max_cod'        => $s->max_cod !== null ? (float)$s->max_cod : 0.0,
                    'min_cod'        => $s->min_cod !== null ? (float)$s->min_cod : null,
                ];
            } else {
                $cur = $statByKeyDate[$k][$d];
                $cur->proceed_orders += (int)$s->proceed_orders;
                $cur->max_cod = max($cur->max_cod, $s->max_cod !== null ? (float)$s->max_cod : 0.0);
                $sMin = $s->min_cod !== null ? (float)$s->min_cod : null;
                if ($sMin !== null && $sMin > 0) {
                    $cur->min_cod = $cur->min_cod === null ? $sMin : min($cur->min_cod, $sMin);
                }
            }
        }

        // ── Count unresolved slices (pages seen in range but no anchor) ──
        // Range extended to $loadEnd so pages na may activity sa partial_date
        // (but tied/unresolved sa anchor date) ay tama pa rin ang skipped count.
        $pagesSeenRows = DB::table('macro_output as mo')
            ->whereRaw("$dateExpr BETWEEN ? AND ?", [$loadStart, $loadEnd])
            ->whereRaw("$pageTrim != ''")
            ->selectRaw("$pageKey AS page_key")
            ->groupByRaw("$pageKey")
            ->get();
        $pagesSeen = array_map(fn($r) => (string)$r->page_key, $pagesSeenRows->all());
        // Exclude manually-excluded pages from the "skipped" count (they are intentionally out, not unresolved)
        $pagesSeen = array_values(array_filter($pagesSeen, fn($pk) => !isset($excludedSet[$pk])));
        $skippedPages = array_values(array_diff($pagesSeen, array_keys($anchorByPage)));
        $skippedCount = count($skippedPages);

        // ── Per-page, per-date adspent (for summing across included dates only) ─────
        // Loaded for $loadStart..$loadEnd to include partial_date data if outside range.
        // Aggregation loop filters down to [startDate, endDate] sums.
        $castSpend = $castMoney('amount_spent_php');
        $adsRows = DB::table('ads_manager_reports')
            ->whereRaw('DATE(day) BETWEEN ? AND ?', [$loadStart, $loadEnd])
            ->selectRaw("
                DATE(day) AS d,
                LOWER($trimFn(COALESCE(page_name,''))) AS page_key,
                SUM($castSpend) AS adspent
            ")
            ->groupByRaw("DATE(day), LOWER($trimFn(COALESCE(page_name,'')))")
            ->get();
        // adsByDate[page_key][date] = adspent
        $adsByDate = [];
        foreach ($adsRows as $r) {
            $adsByDate[(string)$r->page_key][(string)$r->d] = (float)$r->adspent;
        }
        // adsMap[page_key] = total adspent (used only in single-date back-compat paths below).
        $adsMap = [];
        foreach ($adsByDate as $pk => $byD) {
            $adsMap[$pk] = array_sum($byD);
        }

        // ── Build pageGroups: aggregate over INCLUDED range slices per anchor ──────
        // Included slice = (date, page) within [streakStart, endDate] where the page's
        // primary == anchor. streakStart = start of the most recent uninterrupted streak
        // (walks backward from endDate, tolerating tie/missing days). May predate startDate.
        $pageGroups = [];
        foreach ($anchorByPage as $pk => $pr) {
            $anchorKey   = (string)$pr->primary_item_key;
            $anchorCodInt = $pr->primary_mode_cod !== null
                ? (int) round((float) $pr->primary_mode_cod)
                : null;
            $perDate   = $rangeByPage[$pk] ?? [];

            // Walk back from $anchorDate (= partial_date if set & > end_date,
            // else end_date) para consistent yung roster check sa anchor lookup.
            $streakStart = $this->resolveAnchorStreakStart($pk, $anchorKey, $anchorCodInt, $anchorDate);
            if ($streakStart === null) continue; // anchor primary not found at $anchorDate for this page

            $metricsFrom = $streakStart < $startDate ? $startDate : $streakStart;

            $totalOrders   = 0;
            $proceedOrders = 0;
            $includedDates = [];     // date → ['orders','mode_cod','adspent']
            $maxCod        = 0.0;
            $minCod        = null;
            $statKey       = $pk.'||'.$anchorKey;

            foreach ($perDate as $d => $slice) {
                if ($d < $metricsFrom) continue;                 // before streak window
                if ($d > $loadEnd)    continue;                  // beyond effective main range (= partial_date kapag set & > end_date)
                if ($slice['item_key'] !== $anchorKey) continue; // defensive: tie/different day
                // Price-based anchor: also skip days where mode_cod differs from
                // anchor's by > ₱1 (rounded). Matches resolveAnchorStreakStart logic.
                if ($anchorCodInt !== null && $slice['mode_cod'] !== null) {
                    $sliceCodInt = (int) round((float) $slice['mode_cod']);
                    if (abs($sliceCodInt - $anchorCodInt) > 1) continue;
                }
                $totalOrders += (int)$slice['orders'];

                $stat = $statByKeyDate[$statKey][$d] ?? null;
                if ($stat) {
                    $proceedOrders += (int)$stat->proceed_orders;
                    $maxCod = max($maxCod, (float)($stat->max_cod ?? 0));
                    $sMin = $stat->min_cod !== null ? (float)$stat->min_cod : null;
                    if ($sMin !== null && $sMin > 0) {
                        $minCod = $minCod === null ? $sMin : min($minCod, $sMin);
                    }
                }

                $includedDates[$d] = [
                    'orders'   => (int)$slice['orders'],
                    'mode_cod' => (float)$slice['mode_cod'],
                    'adspent'  => (float)($adsByDate[$pk][$d] ?? 0),
                    'proceed'  => $stat ? (int)$stat->proceed_orders : 0,
                ];
            }

            $excludedDays  = count($perDate) - count($includedDates);
            $distinctCount = isset($distinctItemsByPage[$pk]) ? count($distinctItemsByPage[$pk]) : 0;
            $mixedPrimary  = $distinctCount >= 2;

            // Anchor price = anchor date's primary_mode_cod (= partial_date if set & > end_date, else end_date).
            $anchorModeCod = $pr->primary_mode_cod !== null ? (float)$pr->primary_mode_cod : 0.0;
            if ($maxCod <= 0) $maxCod = $anchorModeCod;
            if ($minCod === null || $minCod <= 0) $minCod = $anchorModeCod;

            if (!empty($includedDates)) ksort($includedDates);

            $primary = [
                'item_name'      => (string)$pr->primary_item,
                'item_key'       => $anchorKey,
                'total_orders'   => $totalOrders,
                'proceed_orders' => $proceedOrders,
                'mode_cod'       => $anchorModeCod,
                'max_cod'        => $maxCod,
                'min_cod'        => $minCod,
                'included_dates' => $includedDates, // for per-slice profit compute below
            ];

            // No secondary items exposed in range mode (spec: single anchor only).
            // Keep empty array for view safety (existing template iterates row.secondary_items||[]).
            $items = [$primary];

            $pageGroups[$pk] = [
                'page_label'     => (string)$pr->page_label,
                'page_key'       => $pk,
                'total_orders'   => $totalOrders,
                'proceed_orders' => $proceedOrders,
                'items'          => $items,
                // Range-mode fields used by response builder:
                'included_days'          => count($includedDates),
                'excluded_days'          => max(0, $excludedDays),
                'distinct_items_in_range'=> $distinctCount,
                'mixed_primary'          => $mixedPrimary,
                'adspent_total'          => array_sum(array_column($includedDates, 'adspent')),
                'anchor_first_date'      => $streakStart,
            ];
        }

        // ── COGS: latest entry ≤ date ─────────────────────────────────────────
        $cogsItemCol = $pickCol('cogs', ['item_name','ITEM_NAME']) ?? 'item_name';
        $cogsDateCol = $pickCol('cogs', ['date','effective_date']) ?? 'date';
        $cogsUnitCol = $pickCol('cogs', ['unit_cost','cost']) ?? 'unit_cost';

        $cogsRows = DB::table('cogs')
            ->where($cogsDateCol, '<=', $date)
            ->orderByDesc($cogsDateCol)
            ->get([$cogsItemCol, $cogsDateCol, $cogsUnitCol]);

        $cogsMap = []; // normalized_item_name → unit_cost (Marketing's view)
        $cogsLastDateMap = []; // normalized_item_name → YYYY-MM-DD of latest cogs row
        foreach ($cogsRows as $r) {
            $k = strtolower(trim((string)($r->$cogsItemCol ?? '')));
            if (!isset($cogsMap[$k])) {
                $cogsMap[$k] = (float)($r->$cogsUnitCol ?? 0);
                // Latest date is first-seen kasi sorted DESC by date sa query above.
                $cogsLastDateMap[$k] = substr((string)($r->$cogsDateCol ?? ''), 0, 10);
            }
        }

        // ── cogs_ceo: latest entry ≤ date (CEO's separate values) ──────────
        // Used for profit computations when the viewer is CEO. Falls back to
        // null per spec — no fallback to cogs sa profit calc.
        $cogsCeoMap = []; // normalized_item_name → unit_cost (CEO's view)
        if (Schema::hasTable('cogs_ceo')) {
            $cogsCeoRows = DB::table('cogs_ceo')
                ->where('date', '<=', $date)
                ->orderByDesc('date')
                ->get(['item_name', 'date', 'unit_cost']);
            foreach ($cogsCeoRows as $r) {
                $k = strtolower(trim((string)($r->item_name ?? '')));
                if (!isset($cogsCeoMap[$k])) {
                    $cogsCeoMap[$k] = $r->unit_cost !== null ? (float) $r->unit_cost : null;
                }
            }
        }
        $isCEO = $this->isCEO();

        // ── page_item_settings: latest per (page, item, mode_cod_int) ≤ date ──
        // RTS% + Promo are scoped by (page, item, price). Different price =
        // independent RTS+Promo period (no cross-price inheritance). Rows na
        // mode_cod_int IS NULL ay orphaned — skipped sa lookup map below.
        //
        // item_value (unit cost) is NOT sourced here — comes from global `cogs`
        // table (keyed by item_name + date only, no page or price scoping).
        $settingsMap = [];
        $hasModeCodIntCol = Schema::hasColumn('page_item_settings', 'mode_cod_int');
        if (Schema::hasTable('page_item_settings')) {
            $cols = ['page_name', 'item_name', 'rts_pct', 'effective_date', 'comment', 'item_value_comment', 'promo'];
            if ($hasModeCodIntCol) $cols[] = 'mode_cod_int';

            $settingRows = DB::table('page_item_settings')
                ->where('effective_date', '<=', $date)
                ->orderBy('page_name')
                ->orderBy('item_name')
                ->orderByDesc('effective_date')
                ->orderByDesc('id')   // tiebreaker: latest insert wins when same date
                ->get($cols);

            foreach ($settingRows as $s) {
                $codInt = $hasModeCodIntCol && $s->mode_cod_int !== null
                    ? (int) $s->mode_cod_int
                    : null;
                // Pre-migration / orphan rows na walang price tag → skipped.
                if ($codInt === null) continue;
                $k = strtolower(trim((string)$s->page_name))
                   .'||'.strtolower(trim((string)$s->item_name))
                   .'||'.$codInt;
                if (!isset($settingsMap[$k])) {
                    // rts_pct can be NULL post-2026-05-21 (promo-only saves
                    // pwedeng walang RTS yet). Preserve null para mahandle
                    // properly sa profit calc downstream + cell display.
                    $settingsMap[$k] = [
                        'rts_pct'              => $s->rts_pct !== null ? (float)$s->rts_pct : null,
                        'effective_date'       => (string)$s->effective_date,
                        'comment'              => (string)($s->comment ?? ''),
                        'item_value_comment'   => (string)($s->item_value_comment ?? ''),
                        'promo'                => (string)($s->promo ?? ''),
                        'mode_cod_int'         => $codInt,
                    ];
                }
            }
        }

        // ── JNT stats: JOIN from_jnts → page_sender_mappings (60-day, excl. selected date) ─
        // One PAGE can have MULTIPLE sender names in page_sender_mappings.
        // The JOIN finds ALL from_jnts records for a page by matching every sender name.
        // Grouped by psm.PAGE + fj.item_name + ROUND(cod) → keyed as page_key||item_key||cod_int.
        // RTS% = rts / total, total = rts + delivered + in-transit (simple total denominator).
        $jntStatsMap = []; // "page_key||item_key||cod_int" → stats
        $jntFrom = date('Y-m-d', strtotime($date.' -60 days'));
        $jntTo   = date('Y-m-d', strtotime($date.' -1 day'));   // exclude selected date itself

        try {
            $jntCodClean = "CAST(REPLACE(REPLACE(REPLACE(COALESCE(fj.cod,''), '₱',''), ',', ''), ' ', '') AS DECIMAL(18,2))";
            $jntRows = DB::table('from_jnts as fj')
                ->join('page_sender_mappings as psm', function ($join) {
                    $join->on(
                        DB::raw("LOWER(TRIM(COALESCE(fj.sender,'')))"),
                        '=',
                        DB::raw("LOWER(TRIM(COALESCE(psm.`SENDER_NAME`,'')))")
                    );
                })
                ->whereRaw("DATE(fj.submission_time) BETWEEN ? AND ?", [$jntFrom, $jntTo])
                ->whereRaw("TRIM(COALESCE(fj.sender,'')) != ''")
                ->whereRaw("TRIM(COALESCE(fj.item_name,'')) != ''")
                ->whereNotNull('fj.status')
                ->whereRaw("TRIM(COALESCE(psm.`PAGE`,'')) != ''")
                ->selectRaw("
                    LOWER(TRIM(COALESCE(psm.`PAGE`,'')))    AS page_key,
                    LOWER(TRIM(COALESCE(fj.item_name,'')))  AS item_key,
                    ROUND($jntCodClean)                     AS cod_val,
                    COUNT(*) AS total,
                    SUM(CASE WHEN LOWER(fj.status) LIKE '%return%' OR LOWER(fj.status) LIKE '%rts%' THEN 1 ELSE 0 END) AS rts_cnt,
                    SUM(CASE WHEN LOWER(fj.status) LIKE '%deliver%'                                  THEN 1 ELSE 0 END) AS del_cnt,
                    SUM(CASE WHEN LOWER(fj.status) LIKE '%transit%'                                  THEN 1 ELSE 0 END) AS transit_cnt
                ")
                ->groupByRaw("LOWER(TRIM(COALESCE(psm.`PAGE`,''))) , LOWER(TRIM(COALESCE(fj.item_name,''))), ROUND($jntCodClean)")
                ->get();
            foreach ($jntRows as $r) {
                // Canonicalize item_name via alias resolver — combines variants
                // (e.g. "ALAGAPAMILYA-II" + "ALAGAPAMILYA-VII") under one bucket
                // when may mapping sa item_type_mappings. Sa hosts with zero
                // mappings, canonical == raw normalized → behavior unchanged.
                $canonItem = $aliases->canonicalKey((string)$r->item_key);
                $k = (string)$r->page_key.'||'.$canonItem.'||'.(string)(int)round((float)$r->cod_val);
                // If multiple sender names map to same page+item+cod, SUM their counts
                if (isset($jntStatsMap[$k])) {
                    $jntStatsMap[$k]['total']       += (int)$r->total;
                    $jntStatsMap[$k]['rts_cnt']     += (int)$r->rts_cnt;
                    $jntStatsMap[$k]['del_cnt']     += (int)$r->del_cnt;
                    $jntStatsMap[$k]['transit_cnt'] += (int)$r->transit_cnt;
                } else {
                    $jntStatsMap[$k] = [
                        'total'       => (int)$r->total,
                        'rts_cnt'     => (int)$r->rts_cnt,
                        'del_cnt'     => (int)$r->del_cnt,
                        'transit_cnt' => (int)$r->transit_cnt,
                    ];
                }
            }
        } catch (\Throwable $e) {
            // JNT stats non-critical — skip silently
        }

        // ── build response ────────────────────────────────────────────────────
        $result = [];
        foreach ($pageGroups as $pk => $pg) {
            if (empty($pg['items'])) continue;

            $dominant    = $pg['items'][0];
            $secondary   = array_slice($pg['items'], 1);
            // JNT stats lookup uses canonical (alias-aware) key — matches the
            // producer-side aggregation sa jntStatsMap above.
            $dominantCanonKey = $aliases->canonicalKey($dominant['item_name']);

            $adspent       = (float)($pg['adspent_total'] ?? ($adsMap[$pk] ?? 0.0));
            $totalOrders   = (int)$pg['total_orders'];
            $proceedOrders = (int)$pg['proceed_orders'];
            $includedDatesArr = $dominant['included_dates'] ?? [];

            // Price = mode COD of dominant item (most frequent = real SRP)
            // max_cod / min_cod kept only for range indicator
            $price    = (float)($dominant['mode_cod'] ?? $dominant['max_cod'] ?? 0);

            // Settings lookup key — page+item+rounded price (matches mode_cod_int
            // sa page_item_settings). Different price = independent RTS+Promo row.
            $dominantKey  = strtolower(trim($dominant['item_name']));
            $priceIntForLookup = (int) round($price);
            $settingKey   = $pk.'||'.$dominantKey.'||'.$priceIntForLookup;
            $priceMin = (float)($dominant['min_cod'] ?? $price);
            $priceMax = (float)($dominant['max_cod'] ?? $price);
            // Show range indicator if min or max differ materially from mode
            $priceIsRange = $price > 0 && (
                ($priceMin > 0 && abs($priceMin - $price) > 0.01) ||
                abs($priceMax - $price) > 0.01
            );

            // Item value sourcing — role-dependent:
            //   • CEO viewer    → profit calc uses cogs_ceo (separate CEO table)
            //   • non-CEO       → profit calc uses cogs (Marketing's shared table)
            //
            // Both maps loaded above. We expose BOTH sa response so the CEO's UI
            // can show the new "Item Val. (CEO)" column alongside the existing
            // "Item Val." (Marketing) column. Non-CEO responses strip the CEO field
            // (see $isCEO check sa response builder below).
            $settings        = $settingsMap[$settingKey] ?? null;
            $itemValueMarket = $cogsMap[$dominantKey]    ?? null;
            $itemValueCeo    = $cogsCeoMap[$dominantKey] ?? null;
            // Profit math source — gated by both role AND view toggle. CEO viewing
            // as Marketing falls back to cogs (Marketing's table). Non-CEO always
            // uses cogs regardless of param.
            $useCeoForProfit = $isCEO && $viewAs === 'ceo';
            $itemValue       = $useCeoForProfit ? $itemValueCeo : $itemValueMarket;
            // rts_pct can be NULL (promo-only saves walang RTS pa) — preserve null
            // para sa profit calc downstream (null → no profit shown, hindi 0%).
            $rtsPct          = ($settings && $settings['rts_pct'] !== null) ? (float)$settings['rts_pct'] : null;
            $rtsComment      = $settings ? $settings['comment'] : null;

            // JNT stats — keyed by page_key||canonical_item_key||cod_int (matches
            // alias-aware aggregation sa jntStatsMap builder above).
            $jntKey   = $pk.'||'.$dominantCanonKey.'||'.(string)(int)round($price);
            $jntStats = $jntStatsMap[$jntKey] ?? null;
            $jntRtsPct = $jntDelPct = $jntTransitPct = null;
            $jntRtsCnt = $jntDelCnt = $jntTransitCnt = $jntTotal = null;
            if ($jntStats && $jntStats['total'] > 0) {
                $t = $jntStats['total']; // total = rts + delivered + in-transit
                $jntRtsPct     = round($jntStats['rts_cnt']     / $t * 100, 1);
                $jntDelPct     = round($jntStats['del_cnt']      / $t * 100, 1);
                $jntTransitPct = round($jntStats['transit_cnt']  / $t * 100, 1);
                $jntRtsCnt     = $jntStats['rts_cnt'];
                $jntDelCnt     = $jntStats['del_cnt'];
                $jntTransitCnt = $jntStats['transit_cnt'];
                $jntTotal      = $t;
            }

            $cpp        = ($totalOrders > 0 && $adspent > 0) ? $adspent / $totalOrders   : null;
            $proceedCpp = ($proceedOrders > 0 && $adspent > 0) ? $adspent / $proceedOrders : null;

            // COD fee per delivered = Price × codFeeRate × (1 + VAT)
            $codFeePerDelivered = $price > 0 ? round($price * $codFeeRate * (1 + $codFeeVatRate), 4) : null;

            // Gross sales = Σ(srp_that_day × orders_that_day) across INCLUDED dates.
            // Uses ALL orders (not proceed). Used by UI total Proj.% = Σprofit / Σgross.
            $grossSales = 0.0;
            foreach ($includedDatesArr as $slice) {
                $pDay      = (float)($slice['mode_cod'] ?? 0);
                $ordersDay = (int)($slice['orders'] ?? 0);
                if ($pDay > 0 && $ordersDay > 0) $grossSales += $pDay * $ordersDay;
            }

            // Projected Profit — per-slice compute summed across INCLUDED dates.
            // Uses each day's own mode_cod & adspent; rts_pct/item_value/fees anchored at end_date.
            $projProfit = $projProfitPerOrder = null;
            // Trailing-N-day variants: same formula, but only the last N days
            // ending at $endDate (inclusive). 1D = end_date strictly.
            // Strict — if a window has no slice for this page+item, the % is null.
            $projProfitLastDay   = null; $grossSalesLastDay   = 0.0; $ordersLastDay   = 0; $procOrdersLastDay   = 0;
            $projProfitLast3Day  = null; $grossSalesLast3Day  = 0.0; $ordersLast3Day  = 0; $procOrdersLast3Day  = 0;
            $projProfitLast7Day  = null; $grossSalesLast7Day  = 0.0; $ordersLast7Day  = 0; $procOrdersLast7Day  = 0;

            // Window lower bounds (clamped to startDate so we never reach outside the
            // user's range — keeps the calc deterministic when range is shorter than N).
            // Anchor sa $loadEnd (= partial_date if set & > end_date, else end_date)
            // so trailing-N-day windows include the partial_date day.
            $startTs    = strtotime($startDate);
            $endTs      = strtotime($loadEnd);
            $start3DTs  = max($startTs, strtotime('-2 days', $endTs));   // last 3 days inclusive
            $start7DTs  = max($startTs, strtotime('-6 days', $endTs));   // last 7 days inclusive

            // Orders / proceed for the last-N-day windows — computed UNCONDITIONALLY
            // (independent of RTS/cogs setup) so the Orders (1D) column (and 3D/7D
            // siblings) show accurate counts kahit yung page ay walang RTS% or
            // walang item_value sa cogs. Profit/gross sums stay sa if-block kasi
            // those need rtsPct + itemValue.
            if (!empty($includedDatesArr)) {
                foreach ($includedDatesArr as $d => $slice) {
                    $dTs       = strtotime((string)$d);
                    $isLastDay = ((string)$d === (string)$loadEnd);   // = partial_date kapag set & > end_date
                    $inLast3D  = ($dTs >= $start3DTs && $dTs <= $endTs);
                    $inLast7D  = ($dTs >= $start7DTs && $dTs <= $endTs);
                    $ord       = (int)($slice['orders']  ?? 0);
                    $proc      = (int)($slice['proceed'] ?? 0);
                    if ($isLastDay) { $ordersLastDay  += $ord; $procOrdersLastDay  += $proc; }
                    if ($inLast3D)  { $ordersLast3Day += $ord; $procOrdersLast3Day += $proc; }
                    if ($inLast7D)  { $ordersLast7Day += $ord; $procOrdersLast7Day += $proc; }
                }
            }

            if ($rtsPct !== null && $itemValue !== null && !empty($includedDatesArr)) {
                $rts           = $rtsPct / 100.0;
                $deliverFactor = 1.0 - $rts;
                $sumProfit = 0.0;
                $anyPrice  = false;
                $sumProfitLastDay  = 0.0; $anyPriceLastDay  = false;
                $sumProfitLast3Day = 0.0; $anyPriceLast3Day = false;
                $sumProfitLast7Day = 0.0; $anyPriceLast7Day = false;
                foreach ($includedDatesArr as $d => $slice) {
                    $pDay       = (float)($slice['mode_cod'] ?? 0);
                    $proceedDay = (int)($slice['proceed'] ?? 0);
                    $adsDay     = (float)($slice['adspent'] ?? 0);
                    $dTs        = strtotime((string)$d);
                    $isLastDay  = ((string)$d === (string)$loadEnd);   // = partial_date kapag set & > end_date
                    $inLast3D   = ($dTs >= $start3DTs && $dTs <= $endTs);
                    $inLast7D   = ($dTs >= $start7DTs && $dTs <= $endTs);

                    // NOTE: orders/proceed accumulation moved OUT of this if-block
                    // (to the unconditional pre-loop above) so columns work even
                    // for pages without RTS/cogs. We only sum gross_sales here
                    // since it's part of the profit calc that needs rtsPct + itemValue.
                    if ($isLastDay && $pDay > 0) {
                        $grossSalesLastDay += $pDay * (int)($slice['orders'] ?? 0);
                    }
                    if ($inLast3D && $pDay > 0) {
                        $grossSalesLast3Day += $pDay * (int)($slice['orders'] ?? 0);
                    }
                    if ($inLast7D && $pDay > 0) {
                        $grossSalesLast7Day += $pDay * (int)($slice['orders'] ?? 0);
                    }

                    if ($pDay <= 0) {
                        // no price that day → can't compute revenue; still subtract adspent so ROI isn't overstated
                        $sumProfit -= $adsDay;
                        if ($isLastDay) $sumProfitLastDay  -= $adsDay;
                        if ($inLast3D)  $sumProfitLast3Day -= $adsDay;
                        if ($inLast7D)  $sumProfitLast7Day -= $adsDay;
                        continue;
                    }
                    $anyPrice = true;
                    $codFeeDay = $pDay * $codFeeRate * (1 + $codFeeVatRate);
                    $sliceProfit =
                        $proceedDay * $pDay * $deliverFactor                      // revenue
                        - $proceedDay * $shippingFee                              // shipping (all proceed)
                        - $proceedDay * $deliverFactor * $itemValue               // COGS (delivered)
                        - $adsDay                                                 // adspent
                        - $proceedDay * $deliverFactor * $codFeeDay;              // COD fee (delivered)
                    $sumProfit += $sliceProfit;
                    if ($isLastDay) { $anyPriceLastDay  = true; $sumProfitLastDay  += $sliceProfit; }
                    if ($inLast3D)  { $anyPriceLast3Day = true; $sumProfitLast3Day += $sliceProfit; }
                    if ($inLast7D)  { $anyPriceLast7Day = true; $sumProfitLast7Day += $sliceProfit; }
                }
                if ($anyPrice) {
                    $projProfit = $sumProfit;
                    $projProfitPerOrder = $totalOrders > 0 ? $projProfit / $totalOrders : null;
                }
                if ($anyPriceLastDay)  $projProfitLastDay  = $sumProfitLastDay;
                if ($anyPriceLast3Day) $projProfitLast3Day = $sumProfitLast3Day;
                if ($anyPriceLast7Day) $projProfitLast7Day = $sumProfitLast7Day;
            }

            // ── 1D PARTIAL-DATE OVERRIDE ─────────────────────────────────────
            // Pag may $partialDate set, i-override yung 1D row values gamit yung
            // date's actual orders + adspent + mode_cod, PERO yung proceed at
            // profit ay i-project gamit ang HISTORICAL TCPR (from main range
            // aggregate). Ginagawa to para internally consistent yung TCPR(1D),
            // proceed_1D, at profit_1D — lahat sila derived sa same historical
            // proceed rate.
            // Walang baguhin sa main aggregation + 3D + 7D.
            //
            // Lookup yung partial_date's slice via $rangeByPage[$pk] (loaded sa
            // pre-loop, extended to $loadEnd). Apply same anchor-filter rules
            // as the main aggregation.
            if ($partialDate !== null) {
                // Re-derive anchor info para sa this scope (pageGroups loop —
                // walang $anchorKey/$anchorCodInt local vars dito unlike sa
                // outer anchor loop)
                $pdAnchorKey   = $dominant['item_key'] ?? '';
                $pdAnchorMode  = (float) ($dominant['mode_cod'] ?? 0);
                $pdAnchorCodI  = $pdAnchorMode > 0 ? (int) round($pdAnchorMode) : null;
                $pdStatKey     = $pk . '||' . $pdAnchorKey;

                $pdSlice = $rangeByPage[$pk][$partialDate] ?? null;
                $pdAnchorMatch = $pdSlice
                    && $pdSlice['item_key'] === $pdAnchorKey
                    && ($pdAnchorCodI === null || $pdSlice['mode_cod'] === null
                        || abs((int)round((float)$pdSlice['mode_cod']) - $pdAnchorCodI) <= 1);

                if ($pdAnchorMatch) {
                    $pdOrders     = (int)   ($pdSlice['orders'] ?? 0);
                    $pdAdspent    = (float) ($adsByDate[$pk][$partialDate] ?? 0);
                    $pdModeCod    = (float) ($pdSlice['mode_cod'] ?? 0);

                    // Project proceed using HISTORICAL TCPR (aggregated from main range).
                    //   proceed = orders × (1 − pending_rate)
                    //           = orders × (historical_proceed / historical_orders)
                    // Display proceed_1D + profit_1D derive from the SAME projection so
                    // TCPR(1D) = main range TCPR (internally consistent).
                    $historicalProceedRate = $totalOrders > 0 ? $proceedOrders / $totalOrders : 0.0;
                    $projectedProceedPd    = $pdOrders * $historicalProceedRate;

                    $ordersLastDay     = $pdOrders;
                    $procOrdersLastDay = (int) round($projectedProceedPd);

                    if ($rtsPct !== null && $itemValue !== null && $pdModeCod > 0) {
                        $rts           = $rtsPct / 100.0;
                        $deliverFactor = 1.0 - $rts;
                        $codFeeDay     = $pdModeCod * $codFeeRate * (1.0 + $codFeeVatRate);

                        $projProfitLastDay =
                            $projectedProceedPd * $pdModeCod * $deliverFactor       // revenue
                            - $projectedProceedPd * $shippingFee                     // shipping
                            - $projectedProceedPd * $deliverFactor * $itemValue      // COGS
                            - $pdAdspent                                              // adspent
                            - $projectedProceedPd * $deliverFactor * $codFeeDay;     // COD fee
                        $grossSalesLastDay = $pdModeCod * $pdOrders;
                    } else {
                        // Missing inputs — null out profit projection but keep orders display
                        $projProfitLastDay = null;
                        $grossSalesLastDay = $pdModeCod > 0 ? $pdModeCod * $pdOrders : 0.0;
                    }
                } else {
                    // partial_date has no slice for this page+item (no data that day,
                    // or different primary item). Reset 1D to 0 / null.
                    $ordersLastDay     = 0;
                    $procOrdersLastDay = 0;
                    $projProfitLastDay = null;
                    $grossSalesLastDay = 0.0;
                }
            }

            // Window %s. Null when den ≤ 0 (no orders / no price) or RTS/cogs missing.
            $projPctLastDay  = ($projProfitLastDay  !== null && $grossSalesLastDay  > 0) ? ($projProfitLastDay  / $grossSalesLastDay)  * 100.0 : null;
            $projPctLast3Day = ($projProfitLast3Day !== null && $grossSalesLast3Day > 0) ? ($projProfitLast3Day / $grossSalesLast3Day) * 100.0 : null;
            $projPctLast7Day = ($projProfitLast7Day !== null && $grossSalesLast7Day > 0) ? ($projProfitLast7Day / $grossSalesLast7Day) * 100.0 : null;

            $result[] = [
                'page_name'             => $pg['page_label'],
                'page_key'              => $pk,
                'item_name'             => $dominant['item_name'],
                'secondary_items'       => array_map(fn($i) => [
                    'item_name'    => $i['item_name'],
                    'total_orders' => $i['total_orders'],
                    'price'        => (float)($i['mode_cod'] ?? $i['max_cod'] ?? 0),  // mode = real SRP
                ], $secondary),
                'adspent'               => $adspent,
                'orders'                => $totalOrders,
                'cpp'                   => $cpp,
                'proceed_orders'        => $proceedOrders,
                'proceed_cpp'           => $proceedCpp,
                'projected_profit'      => $projProfit,
                'proj_profit_per_order' => $projProfitPerOrder,
                // Trailing-window variants ending at end_date (inclusive). Null when
                // window has no slice for this page+item, or RTS/item_value missing.
                'projected_profit_last_day' => $projProfitLastDay,
                'proj_pct_last_day'         => $projPctLastDay,
                'orders_last_day'           => $ordersLastDay,
                'proceed_last_day'          => $procOrdersLastDay,
                'gross_sales_last_day'      => $grossSalesLastDay,
                'projected_profit_last_3d'  => $projProfitLast3Day,
                'proj_pct_last_3d'          => $projPctLast3Day,
                'orders_last_3d'            => $ordersLast3Day,
                'proceed_last_3d'           => $procOrdersLast3Day,
                'gross_sales_last_3d'       => $grossSalesLast3Day,
                'projected_profit_last_7d'  => $projProfitLast7Day,
                'proj_pct_last_7d'          => $projPctLast7Day,
                'orders_last_7d'            => $ordersLast7Day,
                'proceed_last_7d'           => $procOrdersLast7Day,
                'gross_sales_last_7d'       => $grossSalesLast7Day,
                'rts_pct'               => $rtsPct,
                'price'                 => $price > 0 ? $price : null,
                'price_min'             => ($priceIsRange && $priceMin > 0 && abs($priceMin - $price) > 0.01) ? $priceMin : null,
                'price_max'             => ($priceIsRange && abs($priceMax - $price) > 0.01) ? $priceMax : null,
                'price_is_range'        => $priceIsRange,
                // `item_value` ay always shows Marketing's value (from cogs) for
                // the existing "Item Val." column. CEO additionally gets
                // `item_value_ceo` (from cogs_ceo) for the new column.
                // Non-CEO responses NEVER include item_value_ceo (data-layer security).
                'item_value'            => $itemValueMarket,
                // CEO column is gated by BOTH actual role AND view toggle. CEO
                // viewing as Marketing gets null (mirrors what Marketing sees).
                'item_value_ceo'        => ($isCEO && $viewAs === 'ceo') ? $itemValueCeo : null,
                'item_value_source'     => $itemValueMarket !== null ? 'cogs' : null,
                'shipping_fee'          => $shippingFee,
                'cod_fee'               => $codFeePerDelivered,
                'settings_date'         => $settings ? $settings['effective_date'] : null,
                'has_settings'          => $settings !== null,
                'rts_comment'           => $rtsComment,
                'item_value_comment'    => $settings ? ($settings['item_value_comment'] ?: null) : null,
                'promo'                 => $settings ? ($settings['promo'] ?: null) : null,
                'jnt_rts_pct'           => $jntRtsPct,
                'jnt_rts_cnt'           => $jntRtsCnt,
                'jnt_del_pct'           => $jntDelPct,
                'jnt_del_cnt'           => $jntDelCnt,
                'jnt_transit_pct'       => $jntTransitPct,
                'jnt_transit_cnt'       => $jntTransitCnt,
                'jnt_total'             => $jntTotal,
                // Range-mode fields
                'is_range'               => !$isSingleDate,
                'is_single_date'         => $isSingleDate,
                'range_days'             => $rangeDays,
                'included_days'          => (int)($pg['included_days'] ?? 0),
                'excluded_days'          => (int)($pg['excluded_days'] ?? 0),
                'distinct_items_in_range'=> (int)($pg['distinct_items_in_range'] ?? 0),
                'mixed_primary'          => (bool)($pg['mixed_primary'] ?? false),
                'anchor_first_date'      => $pg['anchor_first_date'] ?? null,
                // Last date sa `cogs` table where this item's price was set
                // (latest effective_date ≤ end_date). Used as default
                // "Apply from" suggestion sa Edit Row modal's COGS section.
                'cogs_last_date'         => $cogsLastDateMap[$dominantKey] ?? null,
                'gross_sales'            => $grossSales > 0 ? $grossSales : null,
            ];
        }

        usort($result, fn($a, $b) => strcmp((string)$a['page_name'], (string)$b['page_name']));

        $payload = [
            'rows'           => $result,
            'date'           => $date, // back-compat: equals end_date
            'start_date'     => $startDate,
            'end_date'       => $endDate,
            'is_single_date' => $isSingleDate,
            'range_days'     => $rangeDays,
            'skipped_count'  => $skippedCount,
            'skipped_pages'  => $skippedPages,
            // CEO view toggle echo — UI uses this to sync the toggle button state.
            // Non-CEO viewers always get 'marketing' regardless of param.
            'view_as'        => $isCEO ? $viewAs : 'marketing',
            'is_ceo'         => $isCEO,
            'cached_at'      => now()->toIso8601String(),
            // Echo back partial_date param so frontend can sync UI state + show hint
            'partial_date'   => $partialDate,
        ];

        // Write to cache (read-through). Forever — invalidated only by
        // bumpCacheVersion() or explicit refresh from user.
        Cache::forever($cacheKey, $payload);

        return response()->json($payload + ['_cache' => $bypassCache ? 'refresh' : 'miss']);
    }

    public function saveItemSetting(Request $request)
    {
        // Mutates RTS / item_value — CEO + Marketing-OIC. Marketing role can
        // VIEW /owner/private but cannot edit data integrity fields.
        $this->checkWriteAccess();

        // Scope param controls which fields get validated + saved:
        //   'rts'   → only rts_pct + comment (preserves existing promo)
        //   'promo' → only promo (preserves existing rts + comment; requires existing row)
        //   'cogs'  → only item_value + item_value_ceo (touches cogs/cogs_ceo, NOT page_item_settings)
        //   'all'   → backward-compat (used by inline edit + matrix modal) — saves everything
        $scope = strtolower(trim((string) $request->input('scope', 'all')));
        if (!in_array($scope, ['all', 'rts', 'promo', 'cogs'], true)) $scope = 'all';

        // Conditional validation per scope.
        $rules = [
            'page_name'      => 'required|string|max:255',
            'item_name'      => 'required|string|max:255',
            'effective_date' => 'required|date',
            'apply_through'  => 'nullable|date',
            // Price tag — required for RTS+Promo saves (sila yung price-scoped).
            // Frontend sends rounded int from cell's mode_cod.
            'mode_cod_int'   => 'nullable|integer|min:0',
        ];
        if (in_array($scope, ['all', 'rts'], true)) {
            $rules['rts_pct'] = 'required|numeric|min:0|max:100';
            $rules['comment'] = 'required|string|min:1|max:500';
        }
        if (in_array($scope, ['all', 'cogs'], true)) {
            $rules['item_value']         = 'required|numeric|min:0';
            $rules['item_value_ceo']     = 'nullable|numeric|min:0';
            $rules['item_value_comment'] = 'nullable|string|max:500';
        }
        if (in_array($scope, ['all', 'promo'], true)) {
            // Promo free-text — required only when scope explicitly touches promo.
            // For scope=rts, server preserves existing promo (no client input needed).
            $rules['promo'] = 'required|string|min:1|max:255';
        }
        $validated = $request->validate($rules);
        // Resolve price tag — for RTS+Promo writes, this is required (otherwise
        // the row becomes orphaned). Cogs-only saves don't need it (item-global).
        $modeCodInt = isset($validated['mode_cod_int']) ? (int) $validated['mode_cod_int'] : null;
        $needsPriceTag = in_array($scope, ['all', 'rts', 'promo'], true);
        if ($needsPriceTag && $modeCodInt === null) {
            return response()->json([
                'ok' => false,
                'message' => 'mode_cod_int (cell price) is required for RTS/Promo saves. Refresh the page and try again.',
            ], 422);
        }

        $pageName  = trim($validated['page_name']);
        $itemName  = trim($validated['item_name']);
        $effDate   = $validated['effective_date'];

        // ── Capture old values for audit log (read BEFORE mutation) ──────────
        // Match the same composite key na ginagamit ng upsert below — so na-fe-fetch
        // natin yung right row (per-price for the current price tag).
        $oldRtsQuery = DB::table('page_item_settings')
            ->where('page_name', $pageName)
            ->where('item_name', $itemName)
            ->where('effective_date', $effDate);
        if ($needsPriceTag) {
            $oldRtsQuery->where('mode_cod_int', $modeCodInt);
        }
        $oldRtsRow = $oldRtsQuery->first(['rts_pct', 'promo', 'comment', 'item_value_comment']);
        $oldCogsRow = DB::table('cogs')
            ->where('item_name', $itemName)
            ->where('date', $effDate)
            ->first(['unit_cost']);
        $oldCogsCeoRow = Schema::hasTable('cogs_ceo')
            ? DB::table('cogs_ceo')
                ->where('item_name', $itemName)
                ->where('date', $effDate)
                ->first(['unit_cost'])
            : null;
        $oldRts     = $oldRtsRow     ? (float) $oldRtsRow->rts_pct      : null;
        $oldPromo   = $oldRtsRow     ? (string)($oldRtsRow->promo ?? '') : null;
        $oldCogs    = $oldCogsRow    ? (float) $oldCogsRow->unit_cost   : null;
        $oldCogsCeo = $oldCogsCeoRow ? (float) $oldCogsCeoRow->unit_cost : null;
        $applyThrough = !empty($validated['apply_through']) && $validated['apply_through'] > $effDate
            ? $validated['apply_through']
            : null;

        // Track new values for audit log — set only when scope writes them.
        $rtsPctNew       = null;
        $promoNew        = null;
        $itemValueNew    = null;
        $itemValueCeoNew = null;

        // -----------------------------------------------------------------
        // New clean model (2026-04-24):
        //   - COGS (item_value) is GLOBAL: stored only in `cogs` (item, date).
        //     This endpoint UPSERTS cogs when item_value > 0. It NEVER deletes
        //     a cogs row (cogs is global — deleting from a per-page form would
        //     silently wipe the cost basis for every other page on that date).
        //     To delete a cogs row, use the /item/cogs management page.
        //   - RTS% is PER-PAGE: stored in `page_item_settings`
        //     (page, item, effective_date). Saving rts_pct > 0 upserts that row;
        //     saving rts_pct = 0 deletes the (page, item, date) override so it
        //     falls back to the previous effective_date.
        //   - The two fields are independent: zeroing one doesn't force zeroing
        //     the other. Form still requires numeric values, but 0 means "clear
        //     my override" for that field's scope (see above).
        // -----------------------------------------------------------------
        try {
            // ─── RTS / Promo → page_item_settings ──────────────────────────────
            //
            // Scope-aware merge: only fields in the active scope are overwritten.
            // Outside-of-scope fields are preserved from the existing row.
            //
            //   scope=rts   → writes rts_pct + comment (preserves existing promo)
            //   scope=promo → writes promo only (preserves rts + comment; requires row)
            //   scope=all   → writes everything (backward-compat)
            if (in_array($scope, ['all', 'rts', 'promo'], true)) {
                // RTS and Promo are INDEPENDENT per (page, item, price) reference.
                // Either can be saved standalone. The other field is preserved
                // from existing row (if any) or left NULL.
                //
                // Resolve final values per scope:
                //   - scope=rts   → use validated rts_pct + comment; preserve promo
                //   - scope=promo → use validated promo; preserve rts_pct + comment
                //   - scope=all   → use all validated values (backward-compat)
                //
                // If no row exists at exact date, $oldRts/$oldPromo are NULL —
                // preserved field stays NULL (and that's OK now that rts_pct
                // column is nullable).
                $rtsToSave = in_array($scope, ['all', 'rts'], true)
                    ? (float) $validated['rts_pct']
                    : ($oldRts);  // null if no existing row — that's fine

                $promoToSave = in_array($scope, ['all', 'promo'], true)
                    ? trim((string) ($validated['promo'] ?? ''))
                    : ($oldPromo ?: 'NONE');

                $commentToSave = in_array($scope, ['all', 'rts'], true)
                    ? ($validated['comment'] ?? null)
                    : ($oldRtsRow->comment ?? null);

                $ivCommentToSave = in_array($scope, ['all', 'cogs'], true)
                    ? ($validated['item_value_comment'] ?? null)
                    : ($oldRtsRow->item_value_comment ?? null);

                // DELETE branch: only fires when user EXPLICITLY sets rts=0 via
                // scope=rts or scope=all (clearing the RTS override). Promo-only
                // saves never delete — they always upsert with rts preserved.
                $userExplicitlyClearedRts = in_array($scope, ['all', 'rts'], true)
                    && $rtsToSave !== null && (float)$rtsToSave === 0.0;

                if ($userExplicitlyClearedRts) {
                    // rts=0 (explicit clear) → DELETE override row for (page, item, price, date).
                    // Different-price rows on same date stay intact (own scope).
                    DB::table('page_item_settings')
                        ->where('page_name', $pageName)
                        ->where('item_name', $itemName)
                        ->where('mode_cod_int', $modeCodInt)
                        ->where('effective_date', $effDate)
                        ->delete();
                    if ($applyThrough) {
                        DB::table('page_item_settings')
                            ->where('page_name', $pageName)
                            ->where('item_name', $itemName)
                            ->where('mode_cod_int', $modeCodInt)
                            ->where('effective_date', '>', $effDate)
                            ->where('effective_date', '<=', $applyThrough)
                            ->delete();
                    }
                } else {
                    // Upsert keyed by (page, item, price, date) — different price
                    // creates a separate row, no cross-price overwrite.
                    // rts_pct may be null (promo-only save sa brand-new scope).
                    DB::table('page_item_settings')->updateOrInsert(
                        [
                            'page_name'      => $pageName,
                            'item_name'      => $itemName,
                            'effective_date' => $effDate,
                            'mode_cod_int'   => $modeCodInt,
                        ],
                        [
                            'rts_pct'            => $rtsToSave,  // nullable
                            'promo'              => $promoToSave,
                            'comment'            => $commentToSave,
                            'item_value_comment' => $ivCommentToSave,
                            'updated_at'         => now(),
                            'created_at'         => now(),
                        ]
                    );
                    // Cascade — only same-price rows ang ina-update. Different
                    // price periods sa target range stay untouched (own scope).
                    if ($applyThrough) {
                        $cascade = ['updated_at' => now()];
                        if (in_array($scope, ['all', 'rts'], true))      $cascade['rts_pct'] = $rtsToSave;
                        if (in_array($scope, ['all', 'promo'], true))    $cascade['promo']   = $promoToSave;
                        DB::table('page_item_settings')
                            ->where('page_name', $pageName)
                            ->where('item_name', $itemName)
                            ->where('mode_cod_int', $modeCodInt)
                            ->where('effective_date', '>', $effDate)
                            ->where('effective_date', '<=', $applyThrough)
                            ->update($cascade);
                    }
                    $rtsPctNew = $rtsToSave;
                    $promoNew  = $promoToSave;
                }
            }

            // ─── COGS / CEO COGS → cogs + cogs_ceo (global, upsert-only) ───────
            if (in_array($scope, ['all', 'cogs'], true)) {
                $itemValue = (float) ($validated['item_value'] ?? 0);
                if ($itemValue > 0) {
                    Cogs::updateOrCreate(
                        ['item_name' => $itemName, 'date' => $effDate],
                        ['unit_cost' => $itemValue]
                    );
                    if ($applyThrough) {
                        DB::table('cogs')
                            ->where('item_name', $itemName)
                            ->where('date', '>', $effDate)
                            ->where('date', '<=', $applyThrough)
                            ->update(['unit_cost' => $itemValue, 'updated_at' => now()]);
                    }
                    // Auto-mirror sa cogs_ceo: fill missing (item, date) pairs only;
                    // never overwrites existing CEO values.
                    $this->mirrorMissingCogsToCogsCeo($itemName);
                    $itemValueNew = $itemValue;
                }
                // item_value = 0 → intentionally no-op on cogs. (Delete via /item/cogs.)

                // CEO-only: write item_value_ceo if present + role check passes.
                if ($this->isCEO() && isset($validated['item_value_ceo'])) {
                    $itemValueCeo = (float) $validated['item_value_ceo'];
                    if ($itemValueCeo > 0) {
                        \App\Models\CogsCeo::updateOrCreate(
                            ['item_name' => $itemName, 'date' => $effDate],
                            ['unit_cost' => $itemValueCeo]
                        );
                        if ($applyThrough) {
                            DB::table('cogs_ceo')
                                ->where('item_name', $itemName)
                                ->where('date', '>', $effDate)
                                ->where('date', '<=', $applyThrough)
                                ->update(['unit_cost' => $itemValueCeo, 'updated_at' => now()]);
                        }
                        $itemValueCeoNew = $itemValueCeo;
                    }
                }
            }
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        // ── Audit log (best-effort — failure here doesn't block the save) ────
        try {
            $action = ($oldRts === null && $oldCogs === null && $oldCogsCeo === null) ? 'create' : 'update';
            $logRow = [
                'user_email'         => Auth::user()?->email,
                'action'             => $action,
                'page_name'          => $pageName,
                'item_name'          => $itemName,
                'effective_date'     => $effDate,
                'old_rts_pct'        => $oldRts,
                'new_rts_pct'        => $rtsPctNew,
                'old_item_value'     => $oldCogs,
                'new_item_value'     => $itemValueNew,
                'comment'            => $validated['comment'] ?? null,
                'item_value_comment' => $validated['item_value_comment'] ?? null,
                'created_at'         => now(),
                'updated_at'         => now(),
            ];
            if (Schema::hasColumn('page_item_settings_log', 'old_item_value_ceo')) {
                $logRow['old_item_value_ceo'] = $oldCogsCeo;
                $logRow['new_item_value_ceo'] = $itemValueCeoNew;
            }
            if (Schema::hasColumn('page_item_settings_log', 'old_promo')) {
                $logRow['old_promo'] = $oldPromo;
                $logRow['new_promo'] = $promoNew;
            }
            if (Schema::hasColumn('page_item_settings_log', 'scope')) {
                $logRow['scope'] = $scope;
            }
            DB::table('page_item_settings_log')->insert($logRow);
        } catch (\Throwable $e) {
            \Log::error('saveItemSetting audit log failed: ' . $e->getMessage());
        }

        // Invalidate cache — bagong values, all owner_private reads should
        // refresh on next query. Cheap operation: just bumps a sentinel.
        self::bumpCacheVersion();

        return response()->json([
            'ok'            => true,
            'scope'         => $scope,
            'rts_deleted'   => in_array($scope, ['all', 'rts'], true) && $rtsPctNew === null,
            'cogs_upserted' => $itemValueNew !== null,
            'new_rts_pct'   => $rtsPctNew,
            'new_promo'     => $promoNew,
            'new_item_value'=> $itemValueNew,
        ]);
    }

    /**
     * GET /owner/private/edit-logs — paginated audit trail of RTS/COGS edits.
     * CEO + Marketing-OIC only.
     */
    public function editLogs(Request $request)
    {
        $this->checkLogsAccess();

        $userFilter  = trim((string) $request->query('user', ''));
        $pageFilter  = trim((string) $request->query('page', ''));
        $itemFilter  = trim((string) $request->query('item', ''));
        $fromDate    = trim((string) $request->query('from_date', ''));
        $toDate      = trim((string) $request->query('to_date', ''));
        // Scope filter: 'rts' | 'promo' | 'cogs' | 'all' (saves all 3) | '' = no filter
        $scopeFilter = strtolower(trim((string) $request->query('scope', '')));
        $changedOnly = $request->boolean('changed_only', false);

        $hasScopeCol = Schema::hasColumn('page_item_settings_log', 'scope');

        $query = DB::table('page_item_settings_log as l')
            ->leftJoin('users as u', 'u.email', '=', 'l.user_email')
            ->leftJoin('employee_profiles as ep', 'ep.user_id', '=', 'u.id')
            ->select([
                'l.*',
                DB::raw('COALESCE(ep.name, u.name) AS user_name'),
            ])
            ->orderByDesc('l.id');

        if ($userFilter !== '') $query->where('l.user_email', $userFilter);
        if ($pageFilter !== '') $query->where('l.page_name', $pageFilter);
        if ($itemFilter !== '') $query->where('l.item_name', 'like', '%' . $itemFilter . '%');
        if ($fromDate   !== '') $query->where('l.created_at', '>=', $fromDate . ' 00:00:00');
        if ($toDate     !== '') $query->where('l.created_at', '<=', $toDate   . ' 23:59:59');

        if ($scopeFilter !== '' && $hasScopeCol && in_array($scopeFilter, ['rts', 'promo', 'cogs', 'cogs_ceo', 'all'], true)) {
            $query->where('l.scope', $scopeFilter);
        }

        // Optional: only rows where SOMETHING actually changed (skip no-ops).
        // A "change" = any of rts/promo/cogs/cogs_ceo old != new.
        if ($changedOnly) {
            $query->where(function ($q) {
                $q->whereRaw('(old_rts_pct IS NULL) <> (new_rts_pct IS NULL) OR old_rts_pct <> new_rts_pct')
                  ->orWhereRaw('(old_item_value IS NULL) <> (new_item_value IS NULL) OR old_item_value <> new_item_value');
                if (Schema::hasColumn('page_item_settings_log', 'old_promo')) {
                    $q->orWhereRaw('(old_promo IS NULL) <> (new_promo IS NULL) OR old_promo <> new_promo');
                }
                if (Schema::hasColumn('page_item_settings_log', 'old_item_value_ceo')) {
                    $q->orWhereRaw('(old_item_value_ceo IS NULL) <> (new_item_value_ceo IS NULL) OR old_item_value_ceo <> new_item_value_ceo');
                }
            });
        }

        $rows = $query->paginate(50)->appends($request->query());

        $allUsers = DB::table('page_item_settings_log')
            ->whereNotNull('user_email')->distinct()->orderBy('user_email')->pluck('user_email');
        $allPages = DB::table('page_item_settings_log')
            ->whereNotNull('page_name')->distinct()->orderBy('page_name')->pluck('page_name');

        return view('owner.edit_logs', [
            'rows'         => $rows,
            'allUsers'     => $allUsers,
            'allPages'     => $allPages,
            'userFilter'   => $userFilter,
            'pageFilter'   => $pageFilter,
            'itemFilter'   => $itemFilter,
            'fromDate'     => $fromDate,
            'toDate'       => $toDate,
            'scopeFilter'  => $scopeFilter,
            'changedOnly'  => $changedOnly,
            'hasScopeCol'  => $hasScopeCol,
            // CEO-only column gate. Non-CEO viewers (e.g., Marketing-OIC) never
            // see cogs_ceo deltas — they exist sa row pero hindi nire-render.
            'isCeoView'    => $this->isCEO(),
        ]);
    }

    /**
     * CEO-only manual refresh of daily_page_primary_item for a date range.
     * Accepts optional start_date / end_date — defaults to last 90 days.
     */
    public function refreshPrimaryItems(Request $request)
    {
        $this->checkWriteAccess();

        $tz    = new \DateTimeZone('Asia/Manila');
        $today = (new \DateTime('now', $tz))->format('Y-m-d');

        $from = $request->input('start_date');
        $to   = $request->input('end_date');

        if (!$from && !$to) {
            $to   = $today;
            $from = (new \DateTime($today, $tz))->modify('-89 days')->format('Y-m-d');
        } else {
            if (!$from) $from = $to;
            if (!$to)   $to   = $from;
        }

        if (strtotime($from) === false || strtotime($to) === false) {
            return response()->json(['ok' => false, 'message' => 'Invalid date(s)'], 422);
        }
        if ($from > $to) [$from, $to] = [$to, $from];

        try {
            $svc = app(\App\Services\DailyPrimaryItemService::class);
            $t0  = microtime(true);
            $summary = $svc->recomputeRange($from, $to);
            $summary['elapsed_s'] = round(microtime(true) - $t0, 2);
            $summary['from'] = $from;
            $summary['to']   = $to;

            return response()->json(['ok' => true, 'summary' => $summary]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Full-page breakdown view (separate route). Renders the blade template;
     * data is fetched client-side via pageRangeBreakdown() JSON endpoint.
     */
    public function breakdownPage(Request $request)
    {
        $this->checkAccess();

        $pageKey   = (string) $request->input('page_key', '');
        $startDate = (string) $request->input('start_date', '');
        $endDate   = (string) $request->input('end_date', '');

        $validDate = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
        $tz    = new \DateTimeZone('Asia/Manila');
        $today = (new \DateTime('now', $tz))->format('Y-m-d');
        // Default: this month (first day of current month → today, PH time)
        if (!$validDate($startDate) && !$validDate($endDate)) {
            $startDate = (new \DateTime('now', $tz))->modify('first day of this month')->format('Y-m-d');
            $endDate   = $today;
        } else {
            if (!$validDate($startDate)) $startDate = $endDate ?: $today;
            if (!$validDate($endDate))   $endDate   = $startDate;
        }
        if ($startDate > $endDate) [$startDate, $endDate] = [$endDate, $startDate];

        // No page_key → MATRIX view (all pages × all dates)
        if ($pageKey === '') {
            $itemsFilter = (array) $request->input('items', []);
            // Sanitize: string-only, trim, dedupe, lowercase for matching later
            $itemsFilter = array_values(array_unique(array_filter(array_map(
                fn($s) => is_string($s) ? trim($s) : '',
                $itemsFilter
            ), fn($s) => $s !== '')));
            return $this->renderBreakdownMatrix($startDate, $endDate, $itemsFilter);
        }

        // Single-page view: best-effort label lookup for <title>
        $pageLabel = $pageKey;
        $row = DB::table('daily_page_primary_item')
            ->where('page_key', $pageKey)
            ->orderByDesc('ts_date')
            ->first(['page_label']);
        if ($row) $pageLabel = (string)$row->page_label;

        return view('owner.private-breakdown', [
            'pageKey'    => $pageKey,
            'pageLabel'  => $pageLabel,
            'startDate'  => $startDate,
            'endDate'    => $endDate,
        ]);
    }

    /**
     * Matrix view: every page (rows) × every date in range (columns).
     * Cell = primary item on that date. Highlights anchor match (end_date primary) vs mismatch.
     */
    private function renderBreakdownMatrix(string $startDate, string $endDate, array $itemsFilter = [])
    {
        // Date spine
        $dates = [];
        $cursor = strtotime($startDate);
        $last   = strtotime($endDate);
        while ($cursor <= $last) {
            $dates[] = date('Y-m-d', $cursor);
            $cursor += 86400;
        }

        // All primary rows in range
        $rows = DB::table('daily_page_primary_item')
            ->whereBetween('ts_date', [$startDate, $endDate])
            ->orderBy('page_label')
            ->orderBy('ts_date')
            ->get([
                'ts_date', 'page_key', 'page_label',
                'primary_item', 'primary_item_key', 'primary_orders', 'primary_mode_cod',
            ]);

        // Excluded pages (managed via /jnt/supply/excluded-pages)
        $excludedSet = array_flip(SupplyExcludedPage::excludedSet());

        // RTS overrides + promo: all rows effective_date ≤ endDate, keyed for
        // per-cell resolution. Resolve per cell via "latest effective_date ≤ cell_date".
        //
        // Scope: (page_key, item_name_lower, mode_cod_int). Different price =
        // independent row in the index. NULL-price rows (orphaned, no backfill
        // hit) are skipped entirely sa lookup.
        $rtsRowsAll = [];
        $hasPromoCol     = Schema::hasColumn('page_item_settings', 'promo');
        $hasModeCodIntCol = Schema::hasColumn('page_item_settings', 'mode_cod_int');
        if (Schema::hasTable('page_item_settings')) {
            $cols = ['page_name', 'item_name', 'effective_date', 'rts_pct'];
            if ($hasPromoCol)      $cols[] = 'promo';
            if ($hasModeCodIntCol) $cols[] = 'mode_cod_int';
            $rtsRowsAll = DB::table('page_item_settings')
                ->where('effective_date', '<=', $endDate)
                ->orderBy('effective_date')
                ->get($cols)
                ->all();
        }
        // Index: $rtsIdx[page_key][item_key][price_int] = [ [date, rts, promo], ... ] sorted ASC by date
        $rtsIdx = [];
        foreach ($rtsRowsAll as $r) {
            $pk = strtolower(trim((string)$r->page_name));
            $ik = strtolower(trim((string)$r->item_name));
            if ($pk === '' || $ik === '') continue;
            $codInt = ($hasModeCodIntCol && $r->mode_cod_int !== null) ? (int) $r->mode_cod_int : null;
            // Orphan rows (NULL price) skipped — pre-migration data needs re-tag via recompute.
            if ($codInt === null) continue;
            $rtsIdx[$pk][$ik][$codInt][] = [
                'date'    => (string)$r->effective_date,
                'rts_pct' => $r->rts_pct !== null ? (float)$r->rts_pct : null,
                'promo'   => $hasPromoCol ? (string)($r->promo ?? '') : '',
            ];
        }
        // Helper: resolve RTS + promo for a (page_key, item_name, price, date)
        // Strict price match — different price returns null (no inheritance).
        $resolveRts = function(string $pk, string $itemName, ?int $priceInt, string $date) use (&$rtsIdx): ?array {
            if ($priceInt === null) return null;
            $ik = strtolower(trim($itemName));
            $list = $rtsIdx[$pk][$ik][$priceInt] ?? null;
            if (!$list) return null;
            $hit = null;
            foreach ($list as $row) {
                if ($row['date'] <= $date) $hit = $row;
                else break;
            }
            return $hit;
        };

        // COGS (global per-item-per-date) — Marketing's table.
        $cogsRows = Schema::hasTable('cogs')
            ? DB::table('cogs')
                ->where('date', '<=', $endDate)
                ->orderBy('date')
                ->get(['item_name', 'date', 'unit_cost'])
                ->all()
            : [];
        $cogsIdx = [];
        foreach ($cogsRows as $r) {
            $ik = strtolower(trim((string)$r->item_name));
            if ($ik === '') continue;
            $cogsIdx[$ik][] = [
                'date'      => (string)$r->date,
                'unit_cost' => (float)$r->unit_cost,
            ];
        }
        $resolveCogs = function(string $itemName, string $date) use (&$cogsIdx): ?float {
            $ik = strtolower(trim($itemName));
            $list = $cogsIdx[$ik] ?? null;
            if (!$list) return null;
            $hit = null;
            foreach ($list as $row) {
                if ($row['date'] <= $date) $hit = $row['unit_cost'];
                else break;
            }
            return $hit;
        };
        // Resolver para sa "kelan last na-update yung COGS for this item ≤ this date"
        // — used as the COGS section's default effective_date sa Edit Cell modal.
        $resolveCogsLastDate = function(string $itemName, string $date) use (&$cogsIdx): ?string {
            $ik = strtolower(trim($itemName));
            $list = $cogsIdx[$ik] ?? null;
            if (!$list) return null;
            $hit = null;
            foreach ($list as $row) {
                if ($row['date'] <= $date) $hit = $row['date'];
                else break;
            }
            return $hit;
        };

        // ── cogs_ceo (CEO's separate table) — only loaded if viewer is CEO.
        $isCeoView = $this->isCEO();
        $cogsCeoIdx = [];
        if ($isCeoView && Schema::hasTable('cogs_ceo')) {
            $cogsCeoRows = DB::table('cogs_ceo')
                ->where('date', '<=', $endDate)
                ->orderBy('date')
                ->get(['item_name', 'date', 'unit_cost'])
                ->all();
            foreach ($cogsCeoRows as $r) {
                $ik = strtolower(trim((string)$r->item_name));
                if ($ik === '') continue;
                $cogsCeoIdx[$ik][] = [
                    'date'      => (string)$r->date,
                    'unit_cost' => $r->unit_cost !== null ? (float)$r->unit_cost : null,
                ];
            }
        }
        $resolveCogsCeo = function(string $itemName, string $date) use (&$cogsCeoIdx): ?float {
            $ik = strtolower(trim($itemName));
            $list = $cogsCeoIdx[$ik] ?? null;
            if (!$list) return null;
            $hit = null;
            foreach ($list as $row) {
                if ($row['date'] <= $date) $hit = $row['unit_cost'];
                else break;
            }
            return $hit;
        };

        // ── Per-cell PROJ% plumbing ──────────────────────────────────────────
        // Cell-level profit% = slice profit / slice gross. Mirrors /jnt/supply formula:
        //   revenue  = proceed × mode_cod × (1 - rts/100)
        //   shipping = proceed × 37
        //   cogs     = proceed × (1 - rts/100) × unit_cost
        //   cod_fee  = proceed × (1 - rts/100) × mode_cod × 0.015 × 1.12
        //   net      = revenue − shipping − cogs − ads − cod_fee
        //   gross    = mode_cod × orders      (denominator, matches /owner/private)
        //   pct      = net / gross × 100
        //
        // Two extra queries: proceed per (page_key, date, item_norm) and ads per
        // (page_key, date). Both ranges are cheap (indexed by ts_date / day).

        // 1) Proceed orders from macro_output. Item key normalization must match
        //    what daily_page_primary_item uses (strip qty + lower).
        $driver = DB::getDriverName();
        $castMoneyAds = $driver === 'pgsql'
            ? "COALESCE(NULLIF(REGEXP_REPLACE(COALESCE((amount_spent_php)::text, ''), '[^0-9\\.\\-]', '', 'g'), '')::numeric, 0)"
            : "CAST(REPLACE(REPLACE(REPLACE(COALESCE(amount_spent_php,''), '₱',''), ',', ''), ' ', '') AS DECIMAL(18,2))";
        $trimFn = $driver === 'pgsql' ? 'BTRIM' : 'TRIM';

        $moPageCol   = Schema::hasColumn('macro_output', 'PAGE') ? '`PAGE`'
                     : (Schema::hasColumn('macro_output', 'page') ? 'page' : 'page_name');
        $moItemCol   = Schema::hasColumn('macro_output', 'ITEM_NAME') ? '`ITEM_NAME`'
                     : (Schema::hasColumn('macro_output', 'item_name') ? 'item_name' : '`ITEM`');
        $moStatusCol = Schema::hasColumn('macro_output', 'STATUS') ? '`STATUS`'
                     : (Schema::hasColumn('macro_output', 'status') ? 'status' : '`STATUS`');
        $moDateExpr  = Schema::hasColumn('macro_output', 'ts_date')
                         ? 'DATE(ts_date)' : 'DATE(`created_at`)';

        $moStatusNorm = "LOWER(REPLACE(REPLACE($trimFn({$moStatusCol}),' ',''),'_',''))";
        $moPageKey    = "LOWER($trimFn(COALESCE({$moPageCol},'')))";
        $moItemTrim   = "$trimFn(COALESCE({$moItemCol},''))";

        $proceedRows = DB::table('macro_output')
            ->whereRaw("$moDateExpr BETWEEN ? AND ?", [$startDate, $endDate])
            ->whereRaw("$moPageKey != ''")
            ->selectRaw("
                $moDateExpr AS d,
                $moPageKey  AS pg,
                $moItemTrim AS item_raw,
                SUM(CASE WHEN $moStatusNorm = 'proceed' THEN 1 ELSE 0 END) AS proceed
            ")
            ->groupByRaw("$moDateExpr, $moPageKey, $moItemTrim")
            ->get();

        // $proceedMap[page_key][date][canonical_key] = SUMmed proceed count
        // across aliased variants. Canonical key matches the alias-aware
        // primary_item_key stored in daily_page_primary_item, so per-cell
        // lookups resolve correctly post-aliasing. On hosts with zero
        // mappings, canonical == raw normalized → unchanged.
        $aliases = new \App\Services\ItemAliasResolver();
        $proceedMap = [];
        foreach ($proceedRows as $pr) {
            $pk = (string)$pr->pg;
            $d  = (string)$pr->d;
            $raw = (string)$pr->item_raw;
            if ($pk === '' || $raw === '') continue;
            $ck = $aliases->canonicalKey($raw);
            $proceedMap[$pk][$d][$ck] = ($proceedMap[$pk][$d][$ck] ?? 0) + (int)$pr->proceed;
        }

        // 2) Ads per (page_key, date)
        $adsRows = DB::table('ads_manager_reports')
            ->whereRaw('DATE(day) BETWEEN ? AND ?', [$startDate, $endDate])
            ->selectRaw("
                DATE(day) AS d,
                LOWER($trimFn(COALESCE(page_name,''))) AS pg,
                SUM($castMoneyAds) AS spent
            ")
            ->groupByRaw("DATE(day), LOWER($trimFn(COALESCE(page_name,'')))")
            ->get();
        $adsMap = [];
        foreach ($adsRows as $r) {
            $adsMap[(string)$r->pg][(string)$r->d] = (float)$r->spent;
        }

        // 3) Fee settings — no fallback, must be configured at /jnt/fee-settings
        $host    = strtolower((string) request()->getHost());
        $refDate = $endDate ?? $startDate;
        $SHIPPING_PER_SHIPPED = FeeSetting::getRate('shipping_fee_per_order', $host, $refDate);
        $COD_FEE_RATE         = FeeSetting::getRate('cod_fee_rate',           $host, $refDate);
        $COD_FEE_VAT_RATE     = FeeSetting::getRate('cod_fee_vat_rate',       $host, $refDate);
        if ($SHIPPING_PER_SHIPPED === null || $COD_FEE_RATE === null || $COD_FEE_VAT_RATE === null) {
            $missing = array_filter([
                $SHIPPING_PER_SHIPPED === null ? 'shipping_fee_per_order' : null,
                $COD_FEE_RATE         === null ? 'cod_fee_rate'           : null,
                $COD_FEE_VAT_RATE     === null ? 'cod_fee_vat_rate'       : null,
            ]);
            abort(422, "Missing fee_settings for {$refDate} (host: {$host}): " . implode(', ', $missing) . ". Configure at /jnt/fee-settings.");
        }

        // Group by page
        $pages = []; // page_key => [label, matrix[date] => row, anchor_item_key, distinct_items set]
        foreach ($rows as $r) {
            $pk = (string)$r->page_key;
            if (isset($excludedSet[$pk])) continue;
            if (!isset($pages[$pk])) {
                $pages[$pk] = [
                    'page_key'       => $pk,
                    'page_label'     => (string)$r->page_label,
                    'cells'          => [],
                    'distinct_items' => [],
                    'anchor_item'    => null,
                    'anchor_item_key'=> null,
                    'anchor_mode_cod'=> null,
                ];
            }
            $ik = (string)$r->primary_item_key;
            $cellDate = (string)$r->ts_date;
            $itemName = (string)$r->primary_item;
            // Per-cell price (rounded int) — used to match RTS+Promo rows by price.
            $cellPriceInt = $r->primary_mode_cod !== null
                ? (int) round((float) $r->primary_mode_cod)
                : null;
            $rtsHit       = $resolveRts($pk, $itemName, $cellPriceInt, $cellDate);
            $cogsVal      = $resolveCogs($itemName, $cellDate);
            // CEO's separate value — null for non-CEO viewers (map stays empty).
            $cogsCeoVal   = $resolveCogsCeo($itemName, $cellDate);
            // Cogs value used for profit calc — role-aware. CEO uses cogs_ceo;
            // non-CEO uses cogs. No fallback for CEO (null → profit shows —).
            $cogsForProfit = $isCeoView ? $cogsCeoVal : $cogsVal;

            // Per-cell PROJ% — only computable when all ingredients are present.
            // Look up proceed by canonical key (matches alias-aware $proceedMap above).
            $proceedHere = (int) ($proceedMap[$pk][$cellDate][$ik] ?? 0);
            $adsHere     = (float) ($adsMap[$pk][$cellDate] ?? 0.0);
            $profitPct   = null;
            if ($cogsForProfit !== null
                && ($rtsHit['rts_pct'] ?? null) !== null
                && $r->primary_mode_cod !== null
                && (int)$r->primary_orders > 0) {
                $modeCod = (float)$r->primary_mode_cod;
                $orders  = (int)$r->primary_orders;
                $rts     = (float)$rtsHit['rts_pct'];
                $deliver = max(0.0, 1.0 - $rts / 100.0);
                $revenue  = $proceedHere * $modeCod * $deliver;
                $shipping = $proceedHere * $SHIPPING_PER_SHIPPED;
                $cogsAmt  = $proceedHere * $deliver * (float)$cogsForProfit;
                $codFee   = $proceedHere * $deliver * $modeCod * $COD_FEE_RATE * (1 + $COD_FEE_VAT_RATE);
                $net      = $revenue - $shipping - $cogsAmt - $adsHere - $codFee;
                $gross    = $modeCod * $orders;
                if ($gross > 0) $profitPct = round($net / $gross * 100, 1);
            }

            $pages[$pk]['cells'][$cellDate] = [
                'item_name'       => $itemName,
                // Canonical family label — for the alias toggle. Equals $itemName
                // for non-aliased items so toggling shows no diff.
                'item_alias_label'=> $aliases->canonicalLabel($itemName),
                'item_key'        => $ik,
                'orders'          => (int)$r->primary_orders,
                'mode_cod'        => $r->primary_mode_cod !== null ? (float)$r->primary_mode_cod : null,
                // Editable RTS state
                'rts_pct'         => $rtsHit['rts_pct'] ?? null,
                'rts_eff_date'    => $rtsHit['date'] ?? null,
                // Resolved from effective_date ≤ cell_date. True if the override was set
                // on a different (earlier) date — so user knows they'd be overriding here.
                'rts_inherited'   => $rtsHit ? ($rtsHit['date'] !== $cellDate) : false,
                'unit_cost'       => $cogsVal,
                // Last date COGS was set for this item ≤ cell_date — used as the
                // COGS section's default effective_date sa Edit Cell modal.
                'cogs_last_date'  => $resolveCogsLastDate($itemName, $cellDate),
                // CEO-only column. Strip for non-CEO viewers (null sent → UI hides anyway).
                'unit_cost_ceo'   => $isCeoView ? $cogsCeoVal : null,
                // Promo (per-date inheritance like RTS). Empty string when no
                // override yet — UI shows badge as muted/missing.
                'promo'           => $rtsHit['promo'] ?? '',
                'promo_inherited' => $rtsHit ? ($rtsHit['date'] !== $cellDate) : false,
                'proceed'         => $proceedHere,
                'ads'             => $adsHere,
                'profit_pct'      => $profitPct,
            ];
            $pages[$pk]['distinct_items'][$ik] = true;
            if ((string)$r->ts_date === $endDate) {
                $pages[$pk]['anchor_item']      = (string)$r->primary_item;
                $pages[$pk]['anchor_item_key']  = $ik;
                $pages[$pk]['anchor_mode_cod']  = $r->primary_mode_cod !== null ? (float)$r->primary_mode_cod : null;
            }
        }

        // Finalize — stable sort by label, add summary
        $pagesList = array_values($pages);
        usort($pagesList, fn($a, $b) => strcmp($a['page_label'], $b['page_label']));
        foreach ($pagesList as &$p) {
            $p['distinct_count'] = count($p['distinct_items']);
            $p['mixed']          = $p['distinct_count'] >= 2;
            ksort($p['cells']);
            // Most recent uninterrupted streak start where primary == anchor.
            // Walks backward from end_date through daily_page_primary_item, tolerating
            // tie/missing days. May predate $startDate.
            $p['anchor_first_date'] = null;
            $p['anchor_included_days'] = 0;
            if ($p['anchor_item_key'] !== null) {
                $anchorCodInt = $p['anchor_mode_cod'] !== null
                    ? (int) round((float) $p['anchor_mode_cod'])
                    : null;
                $p['anchor_first_date'] = $this->resolveAnchorStreakStart(
                    $p['page_key'],
                    $p['anchor_item_key'],
                    $anchorCodInt,
                    $endDate
                );
                if ($p['anchor_first_date'] !== null) {
                    $metricsFrom = $p['anchor_first_date'] < $startDate
                        ? $startDate
                        : $p['anchor_first_date'];
                    foreach ($p['cells'] as $d => $c) {
                        if ($d < $metricsFrom) continue;
                        if ($c['item_key'] !== $p['anchor_item_key']) continue;
                        // Price-aware: also require mode_cod match within ₱1.
                        if ($anchorCodInt !== null && $c['mode_cod'] !== null) {
                            $cellCodInt = (int) round((float) $c['mode_cod']);
                            if (abs($cellCodInt - $anchorCodInt) > 1) continue;
                        }
                        $p['anchor_included_days']++;
                    }
                }
            }
            // Per-(item_key + mode_cod) occurrence count across this page's row.
            // Composite key so "1 x MINI FLASHLIGHT @ 219" vs "@ 199" are counted separately
            // (user expects count to reflect exact item+COD/SRP combo, matching the
            // "1 ITEM ONLY" filter semantics). Denominator = days this page had ANY
            // primary item recorded (not raw range length).
            $totalDateSlots = count($p['cells']);
            $itemCounts = [];
            $compKey = fn($c) => $c['item_key'] . '|' . (string)($c['mode_cod'] ?? '');
            foreach ($p['cells'] as $c) {
                $k = $compKey($c);
                $itemCounts[$k] = ($itemCounts[$k] ?? 0) + 1;
            }

            // Transition markers + attach count_same/total per cell.
            $prev = null;
            foreach ($p['cells'] as $d => &$c) {
                $c['item_changed']  = false;
                $c['price_changed'] = false;
                $c['price_delta']   = null;
                $c['count_same']    = $itemCounts[$compKey($c)] ?? 0;
                $c['count_total']   = $totalDateSlots;
                if ($prev !== null) {
                    if ($c['item_key'] !== $prev['item_key']) {
                        $c['item_changed'] = true;
                    } elseif ($c['mode_cod'] !== null && $prev['mode_cod'] !== null
                              && abs($c['mode_cod'] - $prev['mode_cod']) > 0.01) {
                        $c['price_changed'] = true;
                        $c['price_delta']   = $c['mode_cod'] - $prev['mode_cod'];
                    }
                }
                $prev = $c;
            }
            unset($c);

            // Per-cell anchor_first_date — walks back from each cell through
            // page cells, stops at item_key change OR mode_cod diff > ₱1.
            // Used by Edit Cell modal's "Apply from anchor" quick-pick so each
            // cell knows its OWN anchor period (page-level anchor only covers
            // end_date's anchor). In-memory walk = no extra DB calls.
            //
            // ⚠️ Use $cellDates (NOT $dates) to avoid shadowing the outer
            // $dates variable (full range spine) — the outer is passed sa view.
            $cellDates = array_keys($p['cells']);   // already ksort'd above
            $cellsArr  = $p['cells'];
            foreach ($cellDates as $i => $d) {
                $c = $cellsArr[$d];
                $cAnchorKey = $c['item_key'];
                $cAnchorCod = $c['mode_cod'] !== null
                    ? (int) round((float) $c['mode_cod'])
                    : null;
                $start = $d;
                for ($j = $i - 1; $j >= 0; $j--) {
                    $prevD = $cellDates[$j];
                    $pc = $cellsArr[$prevD];
                    if ($pc['item_key'] !== $cAnchorKey) break;
                    if ($cAnchorCod !== null && $pc['mode_cod'] !== null) {
                        $pcCodInt = (int) round((float) $pc['mode_cod']);
                        if (abs($pcCodInt - $cAnchorCod) > 1) break;
                    }
                    $start = $prevD;
                }
                $p['cells'][$d]['anchor_first_date'] = $start;
            }
            // Count transitions for header summary
            $itemChanges = 0; $priceChanges = 0;
            foreach ($p['cells'] as $c) {
                if (!empty($c['item_changed']))  $itemChanges++;
                if (!empty($c['price_changed'])) $priceChanges++;
            }
            $p['item_changes']  = $itemChanges;
            $p['price_changes'] = $priceChanges;
            // Flag pages that have any cell missing unit_cost or rts_pct — used by
            // the "ONLY MISSING" toggle in the view to hide fully-configured pages.
            $hasMissing = false;
            foreach ($p['cells'] as $c) {
                if ($c['unit_cost'] === null || $c['rts_pct'] === null) { $hasMissing = true; break; }
            }
            $p['has_missing'] = $hasMissing;
            unset($p['distinct_items']);
        }
        unset($p);

        // Collect the universe of distinct item names across ALL pages in range
        // (before any item filter is applied) — this feeds the dropdown so the user
        // can pick from the full catalog, not just what's already filtered in.
        $allItemsMap = []; // lower-trimmed => display name (first-seen casing)
        foreach ($pagesList as $pp) {
            foreach ($pp['cells'] as $c) {
                $nm = trim((string)($c['item_name'] ?? ''));
                if ($nm === '') continue;
                $k = strtolower($nm);
                if (!isset($allItemsMap[$k])) $allItemsMap[$k] = $nm;
            }
        }
        ksort($allItemsMap);
        $allItems = array_values($allItemsMap);

        // Apply item filter: keep pages that have AT LEAST 1 cell whose item_name
        // matches any of the selected names (case-insensitive).
        $selectedItemsLower = array_map(fn($s) => strtolower(trim($s)), $itemsFilter);
        if (!empty($selectedItemsLower)) {
            $wanted = array_flip($selectedItemsLower);
            $pagesList = array_values(array_filter($pagesList, function($p) use ($wanted) {
                foreach ($p['cells'] as $c) {
                    $nm = strtolower(trim((string)($c['item_name'] ?? '')));
                    if ($nm !== '' && isset($wanted[$nm])) return true;
                }
                return false;
            }));
        }

        return view('owner.private-breakdown-matrix', [
            'startDate'     => $startDate,
            'endDate'       => $endDate,
            'dates'         => $dates,
            'pages'         => $pagesList,
            'allItems'      => $allItems,
            'selectedItems' => $itemsFilter,
            // CEO-only flag — used sa view to render the additional
            // "Item Val. (CEO)" column + edit input for cogs_ceo.
            'isCeoView'     => $isCeoView,
        ]);
    }

    /**
     * Per-date primary-item breakdown for ONE page across a range.
     * Returns each date in [start_date, end_date] with that page's resolved
     * primary item + orders + mode_cod. Flags which rows match the anchor
     * (= primary at end_date), so the UI can show mixed-primary coordinates.
     */
    public function pageRangeBreakdown(Request $request)
    {
        $this->checkAccess();

        $pageKey   = trim((string) $request->input('page_key', ''));
        $startDate = (string) $request->input('start_date', '');
        $endDate   = (string) $request->input('end_date', $startDate);

        $validDate = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
        if ($pageKey === '' || !$validDate($startDate) || !$validDate($endDate)) {
            return response()->json(['ok' => false, 'message' => 'Invalid input'], 422);
        }
        if ($startDate > $endDate) [$startDate, $endDate] = [$endDate, $startDate];

        // Anchor row = primary on end_date for this page (may be null if tied/unresolved)
        $anchor = DB::table('daily_page_primary_item')
            ->where('ts_date', $endDate)
            ->where('page_key', $pageKey)
            ->first(['primary_item', 'primary_item_key', 'primary_orders', 'primary_mode_cod']);

        $anchorItemKey = $anchor ? (string)$anchor->primary_item_key : null;
        // Price-aware anchor: also pin to end_date's mode_cod (rounded int).
        // is_anchor flag below requires BOTH item_key match AND price within ₱1.
        $anchorCodInt = $anchor && $anchor->primary_mode_cod !== null
            ? (int) round((float) $anchor->primary_mode_cod)
            : null;

        // All primary rows for this page across the range
        $rows = DB::table('daily_page_primary_item')
            ->where('page_key', $pageKey)
            ->whereBetween('ts_date', [$startDate, $endDate])
            ->orderBy('ts_date')
            ->get([
                'ts_date', 'page_label', 'primary_item', 'primary_item_key',
                'primary_orders', 'total_orders_all', 'primary_mode_cod',
                'second_item', 'second_orders',
            ]);

        // Fill missing dates as "no data / tied"
        $byDate = [];
        foreach ($rows as $r) $byDate[(string)$r->ts_date] = $r;

        $pageLabel = $rows->count() ? (string)$rows[0]->page_label : $pageKey;

        // ── Per-date RTS% (page_item_settings) + Item Value (cogs) lookups ────
        // Both have effective_date semantics → resolve the value effective AS OF
        // each date. RTS is price-aware (item + mode_cod ±1); item_value is
        // item-only. Same source/logic as the main /owner/private view.
        $pageNameNorm = strtolower(trim($pageLabel));

        // RTS: itemLower → [ ['eff'=>date, 'rts'=>float|null, 'cod'=>int|null], ... ] desc by eff
        $rtsByItem = [];
        if (Schema::hasTable('page_item_settings')) {
            $hasCodInt = Schema::hasColumn('page_item_settings', 'mode_cod_int');
            $rcols = ['item_name', 'rts_pct', 'effective_date'];
            if ($hasCodInt) $rcols[] = 'mode_cod_int';
            $srows = DB::table('page_item_settings')
                ->whereRaw('LOWER(TRIM(page_name)) = ?', [$pageNameNorm])
                ->where('effective_date', '<=', $endDate)
                ->orderByDesc('effective_date')->orderByDesc('id')
                ->get($rcols);
            foreach ($srows as $s) {
                $itemLower = strtolower(trim((string)$s->item_name));
                $rtsByItem[$itemLower][] = [
                    'eff' => (string)$s->effective_date,
                    'rts' => $s->rts_pct !== null ? (float)$s->rts_pct : null,
                    'cod' => ($hasCodInt && $s->mode_cod_int !== null) ? (int)$s->mode_cod_int : null,
                ];
            }
        }
        $resolveRts = function (string $itemName, ?int $codInt, string $asOf) use ($rtsByItem): array {
            $itemLower = strtolower(trim($itemName));
            if (!isset($rtsByItem[$itemLower])) return ['rts' => null, 'eff' => null];
            $cands = array_values(array_filter($rtsByItem[$itemLower], fn ($e) => $e['eff'] <= $asOf));
            if (empty($cands)) return ['rts' => null, 'eff' => null];
            // Prefer price match (±1); list already sorted desc → first match = latest
            if ($codInt !== null) {
                foreach ($cands as $e) {
                    if ($e['cod'] !== null && abs($e['cod'] - $codInt) <= 1) {
                        return ['rts' => $e['rts'], 'eff' => $e['eff']];
                    }
                }
            }
            return ['rts' => $cands[0]['rts'], 'eff' => $cands[0]['eff']];
        };

        // Item value (cogs — Marketing's table, same as main "Item Val." column):
        // itemNorm → [ ['eff'=>date, 'cost'=>float], ... ] desc by eff
        $cogsItemCol = Schema::hasColumn('cogs', 'item_name') ? 'item_name'
                     : (Schema::hasColumn('cogs', 'ITEM_NAME') ? 'ITEM_NAME' : 'item_name');
        $cogsDateCol = Schema::hasColumn('cogs', 'effective_date') ? 'effective_date'
                     : (Schema::hasColumn('cogs', 'date') ? 'date' : 'effective_date');
        $cogsUnitCol = Schema::hasColumn('cogs', 'unit_cost') ? 'unit_cost'
                     : (Schema::hasColumn('cogs', 'cost') ? 'cost' : 'unit_cost');
        $normItem = fn (string $s): string => strtolower(preg_replace('/[\s\-_]/', '', trim($s)) ?? '');
        $cogsByItem = [];
        if (Schema::hasTable('cogs')) {
            $crows = DB::table('cogs')
                ->whereRaw("DATE($cogsDateCol) <= ?", [$endDate])
                ->orderByDesc($cogsDateCol)
                ->get([$cogsItemCol, $cogsDateCol, $cogsUnitCol]);
            foreach ($crows as $c) {
                $k = $normItem((string)($c->$cogsItemCol ?? ''));
                if ($k === '') continue;
                $cogsByItem[$k][] = [
                    'eff'  => substr((string)($c->$cogsDateCol ?? ''), 0, 10),
                    'cost' => (float)($c->$cogsUnitCol ?? 0),
                ];
            }
        }
        $resolveItemVal = function (string $itemName, string $asOf) use ($cogsByItem, $normItem): array {
            $k = $normItem($itemName);
            if (!isset($cogsByItem[$k])) return ['val' => null, 'eff' => null];
            foreach ($cogsByItem[$k] as $e) {
                if ($e['eff'] <= $asOf) return ['val' => $e['cost'], 'eff' => $e['eff']];
            }
            return ['val' => null, 'eff' => null];
        };

        $out = [];
        $cursor = strtotime($startDate);
        $last   = strtotime($endDate);
        while ($cursor <= $last) {
            $d = date('Y-m-d', $cursor);
            $r = $byDate[$d] ?? null;
            if ($r) {
                $ik = (string)$r->primary_item_key;
                $cellCodInt = $r->primary_mode_cod !== null
                    ? (int) round((float) $r->primary_mode_cod)
                    : null;
                // Price-aware: is_anchor requires item_key match AND mode_cod within ₱1.
                $itemMatches  = $anchorItemKey !== null && $ik === $anchorItemKey;
                $priceMatches = ($anchorCodInt === null || $cellCodInt === null)
                    ? true
                    : (abs($cellCodInt - $anchorCodInt) <= 1);

                // Resolve RTS% + Item Value effective AS OF this date for this
                // date's primary item (price-aware for RTS).
                $rtsRes = $resolveRts((string)$r->primary_item, $cellCodInt, $d);
                $ivRes  = $resolveItemVal((string)$r->primary_item, $d);

                $out[] = [
                    'date'             => $d,
                    'primary_item'     => (string)$r->primary_item,
                    'primary_item_key' => $ik,
                    'primary_orders'   => (int)$r->primary_orders,
                    'total_orders'     => (int)$r->total_orders_all,
                    'mode_cod'         => $r->primary_mode_cod !== null ? (float)$r->primary_mode_cod : null,
                    'second_item'      => $r->second_item ? (string)$r->second_item : null,
                    'second_orders'    => $r->second_orders !== null ? (int)$r->second_orders : null,
                    'rts_pct'          => $rtsRes['rts'],
                    'rts_eff_date'     => $rtsRes['eff'],
                    'item_value'       => $ivRes['val'],
                    'item_value_eff'   => $ivRes['eff'],
                    'is_anchor'        => $itemMatches && $priceMatches,
                    'is_anchor_date'   => ($d === $endDate),  // end-date row = anchor source
                    'has_data'         => true,
                ];
            } else {
                $out[] = [
                    'date'             => $d,
                    'primary_item'     => null,
                    'primary_item_key' => null,
                    'primary_orders'   => 0,
                    'total_orders'     => 0,
                    'mode_cod'         => null,
                    'second_item'      => null,
                    'second_orders'    => null,
                    'rts_pct'          => null,
                    'rts_eff_date'     => null,
                    'item_value'       => null,
                    'item_value_eff'   => null,
                    'is_anchor_date'   => ($d === $endDate),
                    'is_anchor'        => false,
                    'has_data'         => false,
                ];
            }
            $cursor += 86400;
        }

        return response()->json([
            'ok'               => true,
            'page_key'         => $pageKey,
            'page_label'       => $pageLabel,
            'start_date'       => $startDate,
            'end_date'         => $endDate,
            'anchor_item'      => $anchor ? (string)$anchor->primary_item : null,
            'anchor_item_key'  => $anchorItemKey,
            'anchor_mode_cod'  => $anchor && $anchor->primary_mode_cod !== null ? (float)$anchor->primary_mode_cod : null,
            'rows'             => $out,
        ]);
    }

    /** CEO-only gate (Daily Summary view). */
    private function checkCEOAccess(): void
    {
        $role = $this->getNormalizedRole();
        if ($role !== 'CEO') abort(404);
    }

    /**
     * GET /owner/private/daily — CEO-only Daily Summary view.
     * Renders the blade; data is fetched client-side via dailyData() JSON.
     */
    public function daily(Request $request)
    {
        $this->checkCEOAccess();

        $tz = new \DateTimeZone('Asia/Manila');
        $today    = (new \DateTime('now', $tz))->format('Y-m-d');
        $yesterday = (new \DateTime('now', $tz))->modify('-1 day')->format('Y-m-d');
        // Default: 30 days ending YESTERDAY (excluding today)
        $defaultStart = (new \DateTime('now', $tz))->modify('-30 days')->format('Y-m-d');
        $defaultEnd   = $yesterday;

        // Column visibility/order + conditional formatting (CEO-managed via
        // /owner/column-settings). Same resolver shape as the rest of the app.
        $colsCtrl     = new \App\Http\Controllers\OwnerColumnSettingsController();
        $colsConfig   = $colsCtrl->loadConfig('daily_summary');
        $colFormatRules = $colsCtrl->loadColFormat('daily_summary')['byCol'] ?? [];
        $catalog      = \App\Http\Controllers\OwnerColumnSettingsController::CATALOG['daily_summary'] ?? [];

        return view('owner.private-daily', [
            'defaultStartDate' => $defaultStart,
            'defaultEndDate'   => $defaultEnd,
            'colsConfig'       => $colsConfig,
            'colFormatRules'   => $colFormatRules,
            'catalog'          => $catalog,
        ]);
    }

    /**
     * GET /owner/private/daily/data — JSON per-day summary rows.
     *
     * Single-pass efficient version. Bypasses the recursive data() +
     * itemSummary() loop (which 504'd on 30-day ranges) and instead runs
     * ~8 batched queries for the entire range, then computes the
     * itemSummary slice-profit formula in PHP per (date, page) slice.
     */
    public function dailyData(Request $request)
    {
        $this->checkCEOAccess();
        @set_time_limit(300);

        try {
            return $this->dailyDataImpl($request);
        } catch (\Throwable $e) {
            return response()->json([
                'rows' => [],
                'totals' => [],
                'error' => get_class($e) . ': ' . $e->getMessage(),
                'file'  => basename($e->getFile()) . ':' . $e->getLine(),
                'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 6),
            ], 200);
        }
    }

    private function dailyDataImpl(Request $request)
    {
        $start = $request->input('start_date');
        $end   = $request->input('end_date');
        $tz    = new \DateTimeZone('Asia/Manila');
        $valid = fn($s) => is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
        if (!$valid($start)) $start = (new \DateTime('now', $tz))->modify('-30 days')->format('Y-m-d');
        if (!$valid($end))   $end   = (new \DateTime('now', $tz))->modify('-1 day')->format('Y-m-d');
        if ($start > $end) [$start, $end] = [$end, $start];

        $host = strtolower((string) $request->getHost());
        $COD_FEE_RATE         = FeeSetting::getRate('cod_fee_rate', $host, $end);
        $COD_FEE_VAT_RATE     = FeeSetting::getRate('cod_fee_vat_rate', $host, $end);
        $SHIPPING_PER_SHIPPED = FeeSetting::getRate('shipping_fee_per_order', $host, $end);
        if ($COD_FEE_RATE === null || $COD_FEE_VAT_RATE === null || $SHIPPING_PER_SHIPPED === null) {
            $missing = array_filter([
                $COD_FEE_RATE         === null ? 'cod_fee_rate'           : null,
                $COD_FEE_VAT_RATE     === null ? 'cod_fee_vat_rate'       : null,
                $SHIPPING_PER_SHIPPED === null ? 'shipping_fee_per_order' : null,
            ]);
            abort(422, "Missing fee_settings for {$end} (host: {$host}): " . implode(', ', $missing));
        }
        $DEFAULT_RTS_PCT = 30.0;

        $driver = DB::getDriverName();
        $trimFn = $driver === 'pgsql' ? 'BTRIM' : 'TRIM';
        $quote  = fn(string $col) => $driver === 'pgsql' ? '"' . $col . '"' : '`' . $col . '`';

        $pickCol = function (string $table, array $candidates) {
            foreach ($candidates as $c) if (Schema::hasColumn($table, $c)) return $c;
            return null;
        };

        $statusColName = $pickCol('macro_output', ['STATUS','status','Status']) ?? 'status';
        $statusNorm    = "LOWER(REPLACE(REPLACE($trimFn(mo." . $quote($statusColName) . "),' ',''),'_',''))";
        $wbColName     = $pickCol('macro_output', ['waybill','Waybill','WAYBILL']) ?? 'waybill';
        $moWaybillSql  = 'mo.' . $quote($wbColName);
        $hasTsDate     = Schema::hasColumn('macro_output', 'ts_date');
        $dateExpr      = $hasTsDate ? 'mo.ts_date' : "DATE(mo.`created_at`)";

        // cogs columns (table varies — discover real names)
        $cogsItemColName = $pickCol('cogs', ['item_name','ITEM_NAME','product','Product','Product_Name']) ?? 'item_name';
        $cogsDateColName = $pickCol('cogs', ['effective_date','date','valid_from','cogs_date']) ?? 'effective_date';
        $cogsUnitColName = $pickCol('cogs', ['unit_cost','cost','unitprice','unit_price','price']) ?? 'unit_cost';

        // Q1: per-date macro_output counts
        $orderRows = DB::table('macro_output as mo')
            ->whereRaw("$dateExpr BETWEEN ? AND ?", [$start, $end])
            ->selectRaw("$dateExpr AS d,
                COUNT(*) AS orders,
                SUM(CASE WHEN $statusNorm = 'proceed' THEN 1 ELSE 0 END) AS proceed,
                SUM(CASE WHEN $statusNorm = 'cannotproceed' THEN 1 ELSE 0 END) AS cannot,
                SUM(CASE WHEN $statusNorm = 'odz' THEN 1 ELSE 0 END) AS odz")
            ->groupByRaw($dateExpr)
            ->get();
        $ordersMap = $proceedMap = $cannotMap = $odzMap = [];
        foreach ($orderRows as $r) {
            $d = (string)$r->d;
            $ordersMap[$d]  = (int)$r->orders;
            $proceedMap[$d] = (int)$r->proceed;
            $cannotMap[$d]  = (int)$r->cannot;
            $odzMap[$d]     = (int)$r->odz;
        }

        // Q2 + Q3: temp table + shipping/delivery counts
        $shippedMap = $deliveredMap = $returnedMap = $forReturnMap = $inTransitMap = [];
        if ($driver === 'mysql') {
            DB::statement("DROP TEMPORARY TABLE IF EXISTS _jnt_agg_daily");
            DB::statement("
                CREATE TEMPORARY TABLE _jnt_agg_daily AS
                SELECT j.waybill_number AS wb,
                    MAX(CASE WHEN j.status LIKE 'Delivered%'  OR j.status LIKE 'DELIVERED%'  THEN 1 ELSE 0 END) AS is_delivered,
                    MAX(CASE WHEN j.status LIKE 'Returned%'   OR j.status LIKE 'RETURNED%'   THEN 1 ELSE 0 END) AS is_returned,
                    MAX(CASE WHEN j.status LIKE 'For Return%' OR j.status LIKE 'FOR RETURN%' THEN 1 ELSE 0 END) AS is_for_return,
                    MAX(CASE
                        WHEN j.status LIKE 'Delivered%'  OR j.status LIKE 'DELIVERED%'  THEN 0
                        WHEN j.status LIKE 'Returned%'   OR j.status LIKE 'RETURNED%'   THEN 0
                        WHEN j.status LIKE 'For Return%' OR j.status LIKE 'FOR RETURN%' THEN 0
                        ELSE 1
                    END) AS is_in_transit
                FROM from_jnts j
                WHERE j.waybill_number IN (
                    SELECT DISTINCT $moWaybillSql FROM macro_output mo
                    WHERE $dateExpr BETWEEN ? AND ?
                      AND $moWaybillSql IS NOT NULL AND $moWaybillSql != ''
                )
                GROUP BY j.waybill_number
            ", [$start, $end]);
            DB::statement("ALTER TABLE _jnt_agg_daily ADD PRIMARY KEY (wb)");

            $shipRows = DB::table('macro_output as mo')
                ->whereRaw("$dateExpr BETWEEN ? AND ?", [$start, $end])
                ->whereNotNull('mo.' . $wbColName)
                ->where('mo.' . $wbColName, '!=', '')
                ->join('_jnt_agg_daily as ja', 'mo.' . $wbColName, '=', 'ja.wb')
                ->selectRaw("$dateExpr AS d,
                    COUNT(DISTINCT $moWaybillSql) AS shipped,
                    COUNT(DISTINCT CASE WHEN ja.is_delivered  = 1 THEN $moWaybillSql END) AS delivered,
                    COUNT(DISTINCT CASE WHEN ja.is_returned   = 1 THEN $moWaybillSql END) AS returned,
                    COUNT(DISTINCT CASE WHEN ja.is_for_return = 1 THEN $moWaybillSql END) AS for_return,
                    COUNT(DISTINCT CASE WHEN ja.is_in_transit = 1 THEN $moWaybillSql END) AS in_transit")
                ->groupByRaw($dateExpr)
                ->get();
            foreach ($shipRows as $r) {
                $d = (string)$r->d;
                $shippedMap[$d]   = (int)$r->shipped;
                $deliveredMap[$d] = (int)$r->delivered;
                $returnedMap[$d]  = (int)$r->returned;
                $forReturnMap[$d] = (int)$r->for_return;
                $inTransitMap[$d] = (int)$r->in_transit;
            }
        }

        // Q4: per-date adspent + messages
        $adsRows = DB::table('ads_manager_reports')
            ->whereRaw('DATE(day) BETWEEN ? AND ?', [$start, $end])
            ->selectRaw("DATE(day) AS d,
                COALESCE(SUM(amount_spent_php),0) AS adspent,
                COALESCE(SUM(messaging_conversations_started),0) AS messages")
            ->groupByRaw('DATE(day)')
            ->get();
        $adspentMap = $messagesMap = [];
        foreach ($adsRows as $r) {
            $adspentMap[(string)$r->d]  = (float)$r->adspent;
            $messagesMap[(string)$r->d] = (int)$r->messages;
        }

        // Q5: per (date, page_key) adspent for slice formula
        $adsByPageRows = DB::table('ads_manager_reports')
            ->whereRaw('DATE(day) BETWEEN ? AND ?', [$start, $end])
            ->selectRaw("DATE(day) AS d, LOWER($trimFn(COALESCE(page_name,''))) AS page_key,
                COALESCE(SUM(amount_spent_php),0) AS adspent")
            ->groupByRaw("DATE(day), LOWER($trimFn(COALESCE(page_name,'')))")
            ->get();
        $adsByDatePage = [];
        foreach ($adsByPageRows as $r) {
            $adsByDatePage[(string)$r->d][(string)$r->page_key] = (float)$r->adspent;
        }

        // Q6: daily_page_primary_item — pre-aggregated per (date, page) primary item
        $primaryRows = DB::table('daily_page_primary_item')
            ->whereBetween('ts_date', [$start, $end])
            ->get(['ts_date', 'page_key', 'primary_item', 'primary_item_key', 'primary_orders', 'primary_mode_cod']);

        // Q6b: per (date, page_key, raw item label) PROCEED ORDERS from macro_output.
        // Same logic na ginagamit ng itemSummary's $statRows. Yung sliceProfit
        // formula gumagamit ng proceed count (status='proceed' lang), HINDI ng
        // primary_orders (which counts all statuses). Canonicalized in PHP below
        // so aliased variants collapse into the same bucket as primary_item_key.
        $itemColName = $pickCol('macro_output', ['ITEM_NAME','item_name','Product','product_name','ITEM','item']) ?? 'item_name';
        $moItemQ     = 'mo.' . $quote($itemColName);
        $itemTrimExpr = "$trimFn(COALESCE($moItemQ,''))";
        $pageColName = $pickCol('macro_output', ['PAGE','page','page_name','Page','Page_Name']) ?? 'page_name';
        $moPageQ     = 'mo.' . $quote($pageColName);
        $pageKeyExpr = "LOWER($trimFn(COALESCE($moPageQ,'')))";

        $procStatRows = DB::table('macro_output as mo')
            ->whereRaw("$dateExpr BETWEEN ? AND ?", [$start, $end])
            ->selectRaw("$dateExpr AS d,
                $pageKeyExpr AS page_key,
                $itemTrimExpr AS item_raw,
                SUM(CASE WHEN $statusNorm = 'proceed' THEN 1 ELSE 0 END) AS proceed_orders")
            ->groupByRaw("$dateExpr, $pageKeyExpr, $itemTrimExpr")
            ->get();
        // procStatMap[date||page_key||canonical_item_key] = SUMmed proceed count.
        // Canonical key matches alias-aware primary_item_key. On hosts with zero
        // mappings, canonical == raw normalized → behavior unchanged.
        $aliasResolver = new \App\Services\ItemAliasResolver();
        $procStatMap = [];
        foreach ($procStatRows as $r) {
            $ck = $aliasResolver->canonicalKey((string)$r->item_raw);
            $k = (string)$r->d . '||' . (string)$r->page_key . '||' . $ck;
            $procStatMap[$k] = ($procStatMap[$k] ?? 0) + (int)$r->proceed_orders;
        }

        // Q7: cogs lookup (uses discovered column names)
        $cogsItemQ = $quote($cogsItemColName);
        $cogsDateQ = $quote($cogsDateColName);
        $cogsUnitQ = $quote($cogsUnitColName);
        $cogsAll = DB::table('cogs')
            ->selectRaw("
                LOWER(REPLACE(REPLACE(REPLACE($trimFn(COALESCE($cogsItemQ,'')),' ',''),'-',''),'_','')) AS item_key,
                DATE($cogsDateQ) AS eff_date,
                CAST(REPLACE(REPLACE(REPLACE(COALESCE($cogsUnitQ,''), '₱',''), ',', ''), ' ', '') AS DECIMAL(18,2)) AS unit_cost
            ")
            ->orderByRaw("LOWER(REPLACE(REPLACE(REPLACE($trimFn(COALESCE($cogsItemQ,'')),' ',''),'-',''),'_','')) ASC, DATE($cogsDateQ) DESC")
            ->get();
        $cogsLookup = [];
        foreach ($cogsAll as $r) {
            $cogsLookup[(string)$r->item_key][] = ['date' => (string)$r->eff_date, 'cost' => (float)$r->unit_cost];
        }
        $findUnitCost = function(string $itemKey, string $orderDate) use ($cogsLookup): float {
            if (!isset($cogsLookup[$itemKey])) return 0.0;
            foreach ($cogsLookup[$itemKey] as $entry) {
                if ($entry['date'] <= $orderDate) return $entry['cost'];
            }
            return 0.0;
        };

        // Q7b: page_item_settings — manually-set RTS% per (page, item, price).
        // ITO ang gamit ng /owner/private's projected_profit (consistent sa main
        // /owner/private). Keyed by lower(page) || lower(item) || price_int.
        // NULL-price (orphan) rows skipped.
        $settingsMap = [];
        $hasModeCodIntColD = Schema::hasColumn('page_item_settings', 'mode_cod_int');
        if (Schema::hasTable('page_item_settings')) {
            $cols = ['page_name', 'item_name', 'rts_pct'];
            if ($hasModeCodIntColD) $cols[] = 'mode_cod_int';
            $settingRows = DB::table('page_item_settings')
                ->where('effective_date', '<=', $end)
                ->orderBy('page_name')
                ->orderBy('item_name')
                ->orderByDesc('effective_date')
                ->orderByDesc('id')
                ->get($cols);
            foreach ($settingRows as $s) {
                $codInt = ($hasModeCodIntColD && $s->mode_cod_int !== null) ? (int) $s->mode_cod_int : null;
                if ($codInt === null) continue;  // orphan row, skip
                $k = strtolower(trim((string)$s->page_name))
                   . '||' . strtolower(trim((string)$s->item_name))
                   . '||' . $codInt;
                if (!isset($settingsMap[$k])) {
                    // rts_pct nullable post-2026-05-21 — preserve null
                    $settingsMap[$k] = $s->rts_pct !== null ? (float)$s->rts_pct : null;
                }
            }
        }

        // Q8: JNT 60-day RTS stats per (page, item, cod) — cached by end_date.
        // Note: ginagamit ito for DISPLAY columns sa main /owner/private (RTS%/Del%/Transit%),
        // pero PROJECTED PROFIT formula ay gumagamit ng page_item_settings (above).
        // We still query for consistency, pero hindi na ginagamit sa formula.
        $jntFrom = date('Y-m-d', strtotime("$end -60 days"));
        $jntTo   = date('Y-m-d', strtotime("$end -1 day"));
        $jntCacheKey = "owner_daily_jnt_v2:" . $host . ":" . $end;
        $jntStatsMap = \Illuminate\Support\Facades\Cache::remember($jntCacheKey, 3600, function () use ($jntFrom, $jntTo) {
            $map = [];
            try {
                $jntCodClean = "CAST(REPLACE(REPLACE(REPLACE(COALESCE(fj.cod,''), '₱',''), ',', ''), ' ', '') AS DECIMAL(18,2))";
                $jntRows = DB::table('from_jnts as fj')
                    ->join('page_sender_mappings as psm', function ($join) {
                        $join->on(
                            DB::raw("LOWER(TRIM(COALESCE(fj.sender,'')))"),
                            '=',
                            DB::raw("LOWER(TRIM(COALESCE(psm.`SENDER_NAME`,'')))")
                        );
                    })
                    ->whereRaw("DATE(fj.submission_time) BETWEEN ? AND ?", [$jntFrom, $jntTo])
                    ->whereRaw("TRIM(COALESCE(fj.sender,'')) != ''")
                    ->whereRaw("TRIM(COALESCE(fj.item_name,'')) != ''")
                    ->whereNotNull('fj.status')
                    ->whereRaw("TRIM(COALESCE(psm.`PAGE`,'')) != ''")
                    ->selectRaw("
                        LOWER(TRIM(COALESCE(psm.`PAGE`,''))) AS page_key,
                        LOWER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(fj.item_name,'')),' ',''),'-',''),'_','')) AS item_key,
                        ROUND($jntCodClean) AS cod_val,
                        COUNT(*) AS total,
                        SUM(CASE WHEN LOWER(fj.status) LIKE '%return%' OR LOWER(fj.status) LIKE '%rts%' THEN 1 ELSE 0 END) AS rts_cnt
                    ")
                    ->groupByRaw("LOWER(TRIM(COALESCE(psm.`PAGE`,''))), LOWER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(fj.item_name,'')),' ',''),'-',''),'_','')), ROUND($jntCodClean)")
                    ->get();
                foreach ($jntRows as $r) {
                    $k = (string)$r->page_key . '||' . (string)$r->item_key . '||' . (int)round((float)$r->cod_val);
                    if (isset($map[$k])) {
                        $map[$k]['total']   += (int)$r->total;
                        $map[$k]['rts_cnt'] += (int)$r->rts_cnt;
                    } else {
                        $map[$k] = ['total' => (int)$r->total, 'rts_cnt' => (int)$r->rts_cnt];
                    }
                }
            } catch (\Throwable $e) { /* tolerate */ }
            return $map;
        });

        $lookupRtsPct = function(string $pageKey, string $itemKey, float $modeCod) use ($jntStatsMap, $DEFAULT_RTS_PCT): float {
            $k = $pageKey . '||' . $itemKey . '||' . (int)round($modeCod);
            $s = $jntStatsMap[$k] ?? null;
            if ($s && $s['total'] > 0) return ($s['rts_cnt'] / $s['total']) * 100.0;
            return $DEFAULT_RTS_PCT;
        };

        // Excluded pages — never participate in profit (matches /owner/private)
        $excludedSet = array_flip(SupplyExcludedPage::excludedSet());

        // Compute per-date projections from primary rows (slice formula).
        // KEY: use $proceed_orders from macro_output (status='proceed' count),
        // NOT primary_orders (which counts all statuses). Matches itemSummary's
        // sliceProfit formula exactly.
        $perDateProjNet = $perDateProjGross = $perDateProjShip = $perDateProjCogs = [];
        foreach ($primaryRows as $pr) {
            $d         = (string)$pr->ts_date;
            $pageKey   = (string)$pr->page_key;
            $modeCod   = (float)$pr->primary_mode_cod;
            $itemKey   = (string)$pr->primary_item_key;
            $itemName  = (string)$pr->primary_item;

            // Skip excluded pages (per /jnt/supply/excluded-pages)
            if (isset($excludedSet[$pageKey])) continue;

            // Look up proceed count for this exact (date, page, primary_item)
            $procKey = $d . '||' . $pageKey . '||' . $itemKey;
            $proceed = (int)($procStatMap[$procKey] ?? 0);

            if ($proceed <= 0 || $modeCod <= 0) continue;

            // RTS% from page_item_settings (NOT JNT 60-day stats).
            // Same lookup shape as itemSummary: lower(page)||lower(item)||price_int.
            // Strict price match — different price for same (page, item) = no settings hit.
            $setKey = $pageKey . '||' . strtolower(trim($itemName)) . '||' . (int) round($modeCod);
            if (!isset($settingsMap[$setKey])) continue;
            $rtsPct        = $settingsMap[$setKey];
            // rts_pct nullable post-2026-05-21 — skip slice if walang RTS pa
            // (promo-only saves; profit calc requires RTS). Same as itemSummary.
            if ($rtsPct === null) continue;
            $deliverFactor = max(0.0, min(1.0, 1.0 - ($rtsPct / 100.0)));
            $itemValue     = $findUnitCost($itemKey, $d);
            $adspent       = $adsByDatePage[$d][$pageKey] ?? 0.0;
            $codFeeDay     = $modeCod * $COD_FEE_RATE * (1.0 + $COD_FEE_VAT_RATE);

            $sliceRev   = $proceed * $modeCod * $deliverFactor;
            $sliceShip  = $proceed * $SHIPPING_PER_SHIPPED;
            $sliceCogs  = $proceed * $deliverFactor * $itemValue;
            $sliceCod   = $proceed * $deliverFactor * $codFeeDay;
            $sliceProf  = $sliceRev - $sliceShip - $sliceCogs - $adspent - $sliceCod;

            $perDateProjNet[$d]   = ($perDateProjNet[$d]   ?? 0.0) + $sliceProf;
            $perDateProjGross[$d] = ($perDateProjGross[$d] ?? 0.0) + $sliceRev;
            $perDateProjShip[$d]  = ($perDateProjShip[$d]  ?? 0.0) + $sliceShip;
            $perDateProjCogs[$d]  = ($perDateProjCogs[$d]  ?? 0.0) + $sliceCogs;
        }

        // Build per-date rows + totals
        $rows = [];
        $totals = [
            'adspent' => 0.0, 'messages' => 0,
            'orders' => 0, 'proceed' => 0, 'cannot' => 0, 'odz' => 0,
            'shipped' => 0, 'delivered' => 0, 'returned' => 0, 'for_return' => 0, 'in_transit' => 0,
            'proj_gross' => 0.0, 'proj_shipping' => 0.0, 'proj_cogs' => 0.0,
            'proj_net_profit' => 0.0,
        ];

        $cursor = new \DateTime($start);
        $endDt  = new \DateTime($end);
        while ($cursor <= $endDt) {
            $d = $cursor->format('Y-m-d');
            $cursor->modify('+1 day');

            $adspent  = $adspentMap[$d]  ?? 0.0;
            $messages = $messagesMap[$d] ?? 0;
            $orders   = $ordersMap[$d]   ?? 0;
            $proceed  = $proceedMap[$d]  ?? 0;
            if ($adspent <= 0 && $orders <= 0) continue;

            $cannot    = $cannotMap[$d]      ?? 0;
            $odz       = $odzMap[$d]         ?? 0;
            $shipped   = $shippedMap[$d]     ?? 0;
            $delivered = $deliveredMap[$d]   ?? 0;
            $returned  = $returnedMap[$d]    ?? 0;
            $forRet    = $forReturnMap[$d]   ?? 0;
            $inTrans   = $inTransitMap[$d]   ?? 0;

            $proj_net   = $perDateProjNet[$d]   ?? 0.0;
            $proj_gross = $perDateProjGross[$d] ?? 0.0;
            $proj_ship  = $perDateProjShip[$d]  ?? 0.0;
            $proj_cogs  = $perDateProjCogs[$d]  ?? 0.0;

            $rows[] = [
                'date'           => $d,
                'adspent'        => round($adspent, 2),
                'messages'       => $messages,
                'orders'         => $orders,
                'proceed'        => $proceed,
                'cannot'         => $cannot,
                'odz'            => $odz,
                'shipped'        => $shipped,
                'delivered'      => $delivered,
                'returned'       => $returned,
                'for_return'     => $forRet,
                'in_transit'     => $inTrans,
                'cpp'            => $orders   > 0 ? round($adspent / $orders,  2) : null,
                'proceed_cpp'    => $proceed  > 0 ? round($adspent / $proceed, 2) : null,
                'cpm'            => $messages > 0 ? round($adspent / $messages, 2) : null,
                'tcpr_pct'       => $orders   > 0 ? round((1 - ($proceed / $orders)) * 100.0, 1) : null,
                'proj_gross'     => round($proj_gross, 2),
                'proj_shipping'  => round($proj_ship,  2),
                'proj_cogs'      => round($proj_cogs,  2),
                'proj_net_profit'=> round($proj_net,   2),
                'proj_net_pct'   => $proj_gross > 0 ? round(($proj_net / $proj_gross) * 100.0, 1) : null,
            ];

            $totals['adspent']         += $adspent;
            $totals['messages']        += $messages;
            $totals['orders']          += $orders;
            $totals['proceed']         += $proceed;
            $totals['cannot']          += $cannot;
            $totals['odz']             += $odz;
            $totals['shipped']         += $shipped;
            $totals['delivered']       += $delivered;
            $totals['returned']        += $returned;
            $totals['for_return']      += $forRet;
            $totals['in_transit']      += $inTrans;
            $totals['proj_gross']      += $proj_gross;
            $totals['proj_shipping']   += $proj_ship;
            $totals['proj_cogs']       += $proj_cogs;
            $totals['proj_net_profit'] += $proj_net;
        }

        usort($rows, fn($a, $b) => strcmp($b['date'], $a['date']));

        foreach (['adspent','proj_gross','proj_shipping','proj_cogs','proj_net_profit'] as $k) {
            $totals[$k] = round($totals[$k], 2);
        }
        $totals['cpp']         = $totals['orders']  > 0 ? round($totals['adspent'] / $totals['orders'],  2) : null;
        $totals['proceed_cpp'] = $totals['proceed'] > 0 ? round($totals['adspent'] / $totals['proceed'], 2) : null;
        $totals['cpm']         = $totals['messages'] > 0 ? round($totals['adspent'] / $totals['messages'], 2) : null;
        $totals['tcpr_pct']    = $totals['orders']  > 0 ? round((1 - ($totals['proceed'] / $totals['orders'])) * 100.0, 1) : null;
        $totals['proj_net_pct'] = $totals['proj_gross'] > 0
            ? round(($totals['proj_net_profit'] / $totals['proj_gross']) * 100.0, 1)
            : null;

        return response()->json([
            'rows'       => $rows,
            'totals'     => $totals,
            'start_date' => $start,
            'end_date'   => $end,
        ]);
    }

}
