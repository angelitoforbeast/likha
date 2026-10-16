<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves the single "primary" item per (date, page) slice from macro_output
 * and materializes it into daily_page_primary_item.
 *
 * Rules (locked):
 *  - Order counting: raw row count (1 row = 1 order).
 *  - Tie on top count: the day is kept. Among the tied items: more proceed rows wins;
 *    still tied, the page's previous-day main item if it is one of them; still tied,
 *    the higher mode COD; then the item key alphabetically. The row is flagged is_tie.
 *  - Mode COD: most-frequent COD value among primary rows only.
 */
class DailyPrimaryItemService
{
    /**
     * Recompute all (date, page) slices within [$from, $to] inclusive.
     *
     * @return array{dates:int,pages:int,rows_upserted:int,ties_resolved:int}
     */
    public function recomputeRange(string $from, string $to): array
    {
        return $this->recomputeWhere(['range' => [$from, $to]]);
    }

    /**
     * Recompute only the specified ts_dates.
     *
     * @param  array<int,string> $dates  list of YYYY-MM-DD strings
     * @return array{dates:int,pages:int,rows_upserted:int,ties_resolved:int}
     */
    public function recomputeDates(array $dates): array
    {
        $dates = array_values(array_unique(array_filter(array_map(
            fn($d) => is_string($d) ? substr($d, 0, 10) : null,
            $dates
        ))));
        if (empty($dates)) {
            return ['dates' => 0, 'pages' => 0, 'rows_upserted' => 0, 'ties_resolved' => 0];
        }
        return $this->recomputeWhere(['dates' => $dates]);
    }

    /**
     * Recompute the ts_dates (last $days, Manila) that have rows of these item names — for an
     * alias mapping change, which moves those rows to another item family.
     *
     * @param  array<int,string> $itemNames raw item labels
     * @return array{dates:int,pages:int,rows_upserted:int,ties_resolved:int}
     */
    public function recomputeForItemNames(array $itemNames, int $days = 90): array
    {
        $keys = array_values(array_unique(array_filter(array_map(
            fn($n) => ItemAliasResolver::normalize((string)$n),
            $itemNames
        ))));
        $empty = ['dates' => 0, 'pages' => 0, 'rows_upserted' => 0, 'ties_resolved' => 0];
        if (empty($keys) || !Schema::hasColumn('macro_output', 'ts_date')) return $empty;

        $driver = DB::getDriverName();
        $trimFn = $driver === 'pgsql' ? 'BTRIM' : 'TRIM';
        $quote  = fn(string $col) => $driver === 'pgsql' ? '"' . $col . '"' : '`' . $col . '`';
        $itemCol = null;
        foreach (['ITEM_NAME','item_name','Product','product_name','ITEM','item'] as $c) {
            if (Schema::hasColumn('macro_output', $c)) { $itemCol = $c; break; }
        }
        if ($itemCol === null) return $empty;
        $itemKey = "LOWER(REPLACE(REPLACE(REPLACE($trimFn(COALESCE(" . $quote($itemCol) . ",'')),' ',''),'-',''),'_',''))";

        $from  = now('Asia/Manila')->subDays(max(1, $days) - 1)->toDateString();
        $dates = DB::table('macro_output')
            ->where('ts_date', '>=', $from)
            ->whereIn(DB::raw($itemKey), $keys)
            ->distinct()
            ->pluck('ts_date')
            ->map(fn($d) => substr((string)$d, 0, 10))
            ->all();

        return $this->recomputeDates($dates);
    }

    // ------------------------------------------------------------------

    private function recomputeWhere(array $filter): array
    {
        $driver = DB::getDriverName();
        $trimFn = $driver === 'pgsql' ? 'BTRIM' : 'TRIM';
        $quote  = fn(string $col) => $driver === 'pgsql' ? '"' . $col . '"' : '`' . $col . '`';

        // Alias resolver — collapses item_name variants under a canonical
        // item_type (one row per alias-family per slice). On hosts with zero
        // mappings (e.g. likha), canonicalKey() returns the raw key → behavior
        // identical to pre-aliasing.
        $aliases = new ItemAliasResolver();

        // ---- Column detection (mirrors OwnerPrivateController) ------------
        $pickCol = function (string $table, array $candidates) use ($driver) {
            if ($driver === 'pgsql') {
                $rows = DB::select(
                    "SELECT column_name FROM information_schema.columns
                     WHERE table_schema = current_schema() AND table_name = ?",
                    [$table]
                );
                $cols = array_map(fn($r) => $r->column_name, $rows);
                foreach ($candidates as $c) if (in_array($c, $cols, true)) return $c;
                return null;
            }
            foreach ($candidates as $c) if (Schema::hasColumn($table, $c)) return $c;
            return null;
        };

        $castMoney = function (string $expr) use ($driver) {
            return $driver === 'pgsql'
                ? "COALESCE(NULLIF(REGEXP_REPLACE(COALESCE(($expr)::text, ''), '[^0-9\\.\\-]', '', 'g'), '')::numeric, 0)"
                : "CAST(REPLACE(REPLACE(REPLACE(COALESCE($expr,''), '₱',''), ',', ''), ' ', '') AS DECIMAL(18,2))";
        };

        $pageColName = $pickCol('macro_output', ['PAGE','page','page_name','Page','Page_Name'])
            ?? throw new \RuntimeException('macro_output: page column not found');
        $itemColName = $pickCol('macro_output', ['ITEM_NAME','item_name','Product','product_name','ITEM','item'])
            ?? throw new \RuntimeException('macro_output: item column not found');
        $moCodColName = $pickCol('macro_output', ['COD','cod','Cod']) ?? 'COD';
        $moStatusColName = $pickCol('macro_output', ['STATUS','status','Status']) ?? 'STATUS';

        $moPageSql   = 'mo.' . $quote($pageColName);
        $moItemSql   = 'mo.' . $quote($itemColName);
        $moCodSql    = 'mo.' . $quote($moCodColName);

        $pageLabel   = "$trimFn(COALESCE($moPageSql,''))";
        $pageKey     = "LOWER($pageLabel)";
        $itemLabel   = "$trimFn(COALESCE($moItemSql,''))";
        $itemKey     = "LOWER(REPLACE(REPLACE(REPLACE($itemLabel,' ',''),'-',''),'_',''))";
        $codClean    = $castMoney($moCodSql);
        // Same proceed rule as /owner/private (status 'proceed', spaces/underscores ignored).
        $statusNorm  = "LOWER(REPLACE(REPLACE($trimFn(mo." . $quote($moStatusColName) . "),' ',''),'_',''))";

        // ---- Date expression ---------------------------------------------
        $hasTsDate = Schema::hasColumn('macro_output', 'ts_date');
        if ($hasTsDate) {
            $dateExpr = 'mo.ts_date';
        } else {
            $tsCol = $pickCol('macro_output', ['TIMESTAMP','timestamp']);
            if ($driver === 'mysql') {
                if ($tsCol) {
                    $ts = 'mo.' . $quote($tsCol);
                    $dateExpr = "COALESCE(
                        DATE(STR_TO_DATE($ts, '%H:%i %d-%m-%Y')),
                        DATE(STR_TO_DATE($ts, '%H:%i %m-%d-%Y')),
                        DATE(mo.`created_at`)
                    )";
                } else {
                    $dateExpr = 'DATE(mo.`created_at`)';
                }
            } else {
                $parts = [];
                if ($tsCol) {
                    $ref = 'mo.' . $quote($tsCol);
                    $parts[] = "TO_TIMESTAMP(NULLIF($ref, ''), 'HH24:MI DD-MM-YYYY')";
                    $parts[] = "TO_TIMESTAMP(NULLIF($ref, ''), 'HH24:MI MM-DD-YYYY')";
                }
                $parts[] = 'mo."created_at"';
                $dateExpr = 'DATE(COALESCE(' . implode(', ', $parts) . '))';
            }
        }

        // ---- Load (date, page, item) -> count + cod histogram -----------
        $q = DB::table('macro_output as mo')
            ->selectRaw("
                $dateExpr            AS ts_date,
                MIN($pageLabel)      AS page_label,
                $pageKey             AS page_key,
                MIN($itemLabel)      AS item_label,
                $itemKey             AS item_key,
                $codClean            AS cod_val,
                COUNT(*)             AS n,
                SUM(CASE WHEN $statusNorm = 'proceed' THEN 1 ELSE 0 END) AS p
            ")
            ->whereRaw("$pageLabel <> ''")
            ->whereRaw("$itemLabel <> ''")
            ->groupByRaw("$dateExpr, $pageKey, $itemKey, $codClean");

        if (isset($filter['range'])) {
            [$from, $to] = $filter['range'];
            $q->whereRaw("$dateExpr BETWEEN ? AND ?", [$from, $to]);
        } elseif (isset($filter['dates'])) {
            $q->whereIn(DB::raw($dateExpr), $filter['dates']);
        }

        $rows = $q->get();

        // ---- Aggregate in PHP: per (date, page_key) -----------------------
        // slice[date][page_key] = [
        //   'page_label' => ..,
        //   'items' => [canonical_key => ['label'=>..,'count'=>n,'cods'=>[val=>n,...],
        //                                 'variant_counts'=>[raw_label=>n,...]]]
        // ]
        // Canonical key: alias-family key (item_type) if mapped, else raw key.
        // Per-variant counts are tracked so we can pick the most-representative
        // variant label for display once aggregation finishes.
        $slice = [];
        foreach ($rows as $r) {
            $d        = (string)$r->ts_date;
            $pk       = (string)$r->page_key;
            $rawKey   = (string)$r->item_key;
            $rawLabel = (string)$r->item_label;
            $n        = (int)$r->n;
            $cod      = $r->cod_val !== null ? (float)$r->cod_val : null;

            // Canonical bucket — collapses aliased variants together.
            $ik = $aliases->canonicalKey($rawLabel);
            // Defensive: if raw key differs from canonical normalize() (no
            // alias hit), prefer the SQL-computed rawKey to stay byte-identical
            // with pre-aliasing — both should match for non-aliased items.
            if ($ik === ItemAliasResolver::normalize($rawLabel)) {
                $ik = $rawKey;
            }

            if (!isset($slice[$d][$pk])) {
                $slice[$d][$pk] = [
                    'page_label' => (string)$r->page_label,
                    'items'      => [],
                ];
            }
            if (!isset($slice[$d][$pk]['items'][$ik])) {
                $slice[$d][$pk]['items'][$ik] = [
                    'label'          => $rawLabel,
                    'count'          => 0,
                    'proceed'        => 0,
                    'cods'           => [],
                    'variant_counts' => [],
                ];
            }
            $slice[$d][$pk]['items'][$ik]['count'] += $n;
            $slice[$d][$pk]['items'][$ik]['proceed'] += (int)$r->p;
            $slice[$d][$pk]['items'][$ik]['variant_counts'][$rawLabel]
                = ($slice[$d][$pk]['items'][$ik]['variant_counts'][$rawLabel] ?? 0) + $n;
            if ($cod !== null && $cod > 0) {
                $key = number_format($cod, 2, '.', '');
                $slice[$d][$pk]['items'][$ik]['cods'][$key]
                    = ($slice[$d][$pk]['items'][$ik]['cods'][$key] ?? 0) + $n;
            }
        }

        // After aggregation, pick the most-representative variant label per
        // bucket — the variant with the most orders on that day. Ties break
        // alphabetically by raw label for determinism. This keeps the per-day
        // matrix showing actual variant names while sharing one canonical key.
        foreach ($slice as $d => &$pgInfo) {
            foreach ($pgInfo as $pk => &$info) {
                foreach ($info['items'] as $ik => &$it) {
                    if (!empty($it['variant_counts'])) {
                        $vc = $it['variant_counts'];
                        arsort($vc); // count DESC
                        $topN = reset($vc);
                        // Among variants tied at top count, pick alphabetically last
                        // (typically the most recent variant — VII > II).
                        $tops = array_keys(array_filter($vc, fn($c) => $c === $topN));
                        sort($tops);
                        $it['label'] = end($tops);
                    }
                    unset($it['variant_counts']);
                }
                unset($it);
            }
            unset($info);
        }
        unset($pgInfo);

        // ---- Resolve primary per slice -----------------------------------
        // Mode COD per item (most-frequent COD among that item's rows).
        $modeOf = function (array $it): ?float {
            if (empty($it['cods'])) return null;
            arsort($it['cods']);
            return (float) array_key_first($it['cods']);
        };

        // Previous-day main item per page — the tie rule's second step. A day inside this
        // recompute's scope uses the pick made in this run (dates go oldest first); a day
        // outside it uses the stored row.
        $inScope = isset($filter['range'])
            ? fn(string $d) => $d >= $filter['range'][0] && $d <= $filter['range'][1]
            : fn(string $d) => in_array($d, $filter['dates'] ?? [], true);
        $prevOf = fn(string $d) => date('Y-m-d', strtotime($d . ' -1 day'));
        $storedPrev = [];
        $prevDates = array_values(array_filter(array_map($prevOf, array_keys($slice)), fn($d) => !$inScope($d)));
        if (!empty($prevDates)) {
            foreach (DB::table('daily_page_primary_item')->whereIn('ts_date', array_unique($prevDates))
                         ->get(['ts_date', 'page_key', 'primary_item_key']) as $pr) {
                $storedPrev[substr((string)$pr->ts_date, 0, 10)][(string)$pr->page_key] = (string)$pr->primary_item_key;
            }
        }
        ksort($slice);
        $picked = []; // [date][page_key] => item key chosen in this run

        $now = now();
        $toUpsert = [];
        $tiesResolved = 0;
        $pageCount = 0;
        $dateSet = [];

        foreach ($slice as $d => $pages) {
            $dateSet[$d] = true;
            $prevD = $prevOf((string)$d);
            foreach ($pages as $pk => $info) {
                $pageCount++;
                $items = $info['items'];
                if (empty($items)) continue;

                $topCount = max(array_column($items, 'count'));
                $tied = array_keys(array_filter($items, fn($it) => $it['count'] === $topCount));
                $isTie = count($tied) >= 2;

                if ($isTie) {
                    $tiesResolved++;
                    $prevKey = $inScope($prevD)
                        ? ($picked[$prevD][$pk] ?? null)
                        : ($storedPrev[$prevD][$pk] ?? null);
                    usort($tied, function ($a, $b) use ($items, $prevKey, $modeOf) {
                        return [$items[$b]['proceed'], $b === $prevKey, $modeOf($items[$b]) ?? 0.0, $a]
                           <=> [$items[$a]['proceed'], $a === $prevKey, $modeOf($items[$a]) ?? 0.0, $b];
                    });
                }
                $topKey = $tied[0];
                $top    = $items[$topKey];
                $picked[$d][$pk] = (string)$topKey;

                // Second = the next item by count (ties by key), for display only.
                $rest = $items;
                unset($rest[$topKey]);
                uksort($rest, fn($a, $b) => [$rest[$b]['count'], $a] <=> [$rest[$a]['count'], $b]);
                $secKey = array_key_first($rest);
                $second = $secKey !== null ? $rest[$secKey] : null;

                $totalAll = array_sum(array_column($items, 'count'));

                $toUpsert[] = [
                    'ts_date'          => $d,
                    'page_label'       => $info['page_label'],
                    'page_key'         => $pk,
                    'primary_item'     => $top['label'],
                    'primary_item_key' => $topKey,
                    'primary_orders'   => $top['count'],
                    'total_orders_all' => $totalAll,
                    'primary_mode_cod' => $modeOf($top),
                    'second_item'      => $second['label'] ?? null,
                    'second_orders'    => $second['count'] ?? null,
                    'is_tie'           => $isTie,
                    'computed_at'      => $now,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ];
            }
        }

        // Before the is_tie migration has run, write rows without the flag.
        if (!Schema::hasColumn('daily_page_primary_item', 'is_tie')) {
            foreach ($toUpsert as &$u) unset($u['is_tie']);
            unset($u);
        }

        // ---- Persist: delete the scope + insert the resolved primaries ---
        DB::transaction(function () use ($filter, $toUpsert) {
            // 1) Wipe everything that falls inside our scope first — then re-insert
            //    only the resolved slices. This guarantees vanished slices (no orders
            //    left on that page-day) leave no stale row behind.
            $del = DB::table('daily_page_primary_item');
            if (isset($filter['range'])) {
                [$from, $to] = $filter['range'];
                $del->whereBetween('ts_date', [$from, $to]);
            } elseif (isset($filter['dates'])) {
                $del->whereIn('ts_date', $filter['dates']);
            }
            $del->delete();

            if (!empty($toUpsert)) {
                foreach (array_chunk($toUpsert, 500) as $chunk) {
                    DB::table('daily_page_primary_item')->insert($chunk);
                }
            }
        });

        // ---- Backfill page_item_settings.mode_cod_int for NULL rows --------
        // Uses freshly-computed daily_page_primary_item.primary_mode_cod as the
        // price reference. Only NULLs sa same date range are touched — already-
        // tagged rows stay as-is. Rows whose (page, item, date) doesn't match
        // any primary stay NULL → orphaned (won't apply sa lookups).
        $backfilled = $this->backfillSettingsPrices($filter);

        return [
            'dates'         => count($dateSet),
            'pages'         => $pageCount,
            'rows_upserted' => count($toUpsert),
            'ties_resolved' => $tiesResolved,
            'prices_backfilled' => $backfilled,
        ];
    }

    /**
     * Backfill page_item_settings.mode_cod_int for rows where the column is
     * NULL and effective_date falls within the recompute range.
     *
     * Source: daily_page_primary_item.primary_mode_cod (rounded int).
     * Match join: ts_date = effective_date AND page_key + canonical item_key.
     *
     * Returns count of rows updated.
     */
    private function backfillSettingsPrices(array $filter): int
    {
        if (!Schema::hasTable('page_item_settings')) return 0;
        if (!Schema::hasColumn('page_item_settings', 'mode_cod_int')) return 0;

        // Date range filter — only touch rows na effective_date is sa same scope
        // as the recompute.
        if (isset($filter['range'])) {
            [$from, $to] = $filter['range'];
            $dateFilter = "pis.effective_date BETWEEN '" . addslashes($from) . "' AND '" . addslashes($to) . "'";
        } elseif (isset($filter['dates'])) {
            $dates = array_map(fn($d) => "'" . addslashes($d) . "'", $filter['dates']);
            $dateFilter = "pis.effective_date IN (" . implode(',', $dates) . ")";
        } else {
            return 0;
        }

        // Alias-aware canonical key resolution. Sa hosts with item_type_mappings
        // (e.g., incepxion), pis.item_name (raw variant like "ALAGAPAMILYA-II")
        // gets canonicalized to its alias key (e.g., "1alagangpamilya") na
        // matches daily_page_primary_item.primary_item_key. Sa hosts without
        // mappings (likha), falls back to raw normalized key.
        $driver = DB::getDriverName();
        $hasAliases = Schema::hasTable('item_type_mappings');

        if ($driver === 'pgsql') {
            $rawNorm = "LOWER(REGEXP_REPLACE(BTRIM(pis.item_name), '[ _-]+', '', 'g'))";
            $pageKeyExpr = "LOWER(BTRIM(pis.page_name))";

            $itemKeyExpr = $hasAliases
                ? "COALESCE(
                       (SELECT LOWER(REGEXP_REPLACE(BTRIM(itm.item_type), '[ _-]+', '', 'g'))
                        FROM item_type_mappings itm
                        WHERE LOWER(REGEXP_REPLACE(BTRIM(itm.item_name), '[ _-]+', '', 'g'))
                            = {$rawNorm}
                        LIMIT 1),
                       {$rawNorm}
                   )"
                : $rawNorm;

            $sql = "
                UPDATE page_item_settings AS pis
                SET mode_cod_int = ROUND(dpi.primary_mode_cod)
                FROM daily_page_primary_item AS dpi
                WHERE dpi.ts_date = pis.effective_date
                  AND dpi.page_key = {$pageKeyExpr}
                  AND dpi.primary_item_key = {$itemKeyExpr}
                  AND pis.mode_cod_int IS NULL
                  AND dpi.primary_mode_cod IS NOT NULL
                  AND {$dateFilter}
            ";
        } else {
            $rawNorm = "LOWER(REPLACE(REPLACE(REPLACE(TRIM(pis.item_name),' ',''),'-',''),'_',''))";
            $pageKeyExpr = "LOWER(TRIM(pis.page_name))";

            $itemKeyExpr = $hasAliases
                ? "COALESCE(
                       (SELECT LOWER(REPLACE(REPLACE(REPLACE(TRIM(itm.item_type),' ',''),'-',''),'_',''))
                        FROM item_type_mappings itm
                        WHERE LOWER(REPLACE(REPLACE(REPLACE(TRIM(itm.item_name),' ',''),'-',''),'_',''))
                            = {$rawNorm}
                        LIMIT 1),
                       {$rawNorm}
                   )"
                : $rawNorm;

            $sql = "
                UPDATE page_item_settings pis
                INNER JOIN daily_page_primary_item dpi
                    ON dpi.ts_date = pis.effective_date
                   AND dpi.page_key = {$pageKeyExpr}
                   AND dpi.primary_item_key = {$itemKeyExpr}
                SET pis.mode_cod_int = ROUND(dpi.primary_mode_cod)
                WHERE pis.mode_cod_int IS NULL
                  AND dpi.primary_mode_cod IS NOT NULL
                  AND {$dateFilter}
            ";
        }

        try {
            return (int) DB::affectingStatement($sql);
        } catch (\Throwable $e) {
            Log::warning('backfillSettingsPrices failed (non-fatal): ' . $e->getMessage());
            return 0;
        }
    }
}
