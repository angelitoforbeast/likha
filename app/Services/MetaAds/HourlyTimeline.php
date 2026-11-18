<?php

namespace App\Services\MetaAds;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The hourly CPP figures of /ads_manager/cpp/timeline (spec 288), for the Hourly view (one day, rows per page) and
 * the Hourly totals view (spec 291, rows per date). Both read this one copy of the rules, so two views of the same day
 * never disagree.
 *
 * Every figure is cumulative from 00:00 to the end of the hour: a single hour usually has 0–1 orders, so its own CPP is
 * mostly blank or extreme, while the cumulative CPP at 15:00 means the same as the 3PM bucket. Each cell also carries
 * what that hour added.
 * - Pages of a day: those with hourly spend rows plus those with report spend that day (synced ads_manager_reports),
 *   so a page shows with its orders before its hourly spend is stored.
 * - Spend, CPI and CPM: cpp_snapshots H00..H23 (meta-ads:hourly, from Meta's hourly breakdown), carried forward from
 *   the page's latest stored hour. On a past day, not past the day's last stored hour (spec 297): when the sync stopped
 *   early (blocked, failing), the later hours have orders only, never the spend of an earlier hour.
 * - Orders: macro_output by order time (TIMESTAMP 'H:i d-m-Y', Manila wall clock), live, for those pages only.
 *
 * Three queries for the whole range (spend, ad-spend pages, orders), so a 31-day range costs the same round trips as
 * one day.
 */
class HourlyTimeline
{
    /** TIMESTAMP shape the order hour is read from; the MySQL query checks the same shape with REGEXP. */
    private const TS_PATTERN = '/^(\d{1,2}):\d{2}\s+(\d{2}-\d{2}-\d{4})$/';
    private const TS_SQL_PATTERN = '^[0-9]{1,2}:[0-9]{2}[[:space:]]+[0-9]{2}-[0-9]{2}-[0-9]{4}$';

    /**
     * @param bool|null $ordersInSql count orders per (date, page, hour) in the database (MySQL), instead of reading
     *                               every order row into PHP; null = on MySQL with macro_output.ts_date.
     */
    public function __construct(private Carbon $now, private ?bool $ordersInSql = null)
    {
    }

    /** Page names match across tables in lower case without whitespace. */
    public static function pageKey($name): string
    {
        return strtolower(preg_replace('/\s+/', '', (string) $name));
    }

    /**
     * @param list<string> $dates Y-m-d
     * @return array<string, array{last_hour:int, stored_at:?string, asof_hours:int, pages:list<array{page:string, cells:list<?array>}>, total:list<?array>}>
     *         keyed by date, in the order given
     */
    public function days(array $dates): array
    {
        if (!$dates) return [];
        $start = min($dates);
        $end   = max($dates);
        $normalize = [self::class, 'pageKey'];

        // Spend per page and hour (cumulative), from the hourly rows only.
        $pages = [];    // date => page_key => ['page' => name, 'spend' => [h => row]]
        $storedAt = []; // date => latest snapshot_at
        $lastBucket = []; // date => the latest hour stored for any page
        $hourly = DB::table('cpp_snapshots')->whereBetween('snapshot_date', [$start, $end])
            ->whereIn('snapshot_bucket', HourlySpend::buckets())
            ->get(['snapshot_date', 'snapshot_bucket', 'page_name', 'page_key', 'amount_spent', 'cpi', 'cpm', 'snapshot_at']);
        foreach ($hourly as $r) {
            $d = substr((string) $r->snapshot_date, 0, 10);
            $key = $normalize($r->page_name);
            $pages[$d][$key]['page'] ??= (string) $r->page_name;
            $pages[$d][$key]['spend'][(int) substr((string) $r->snapshot_bucket, 1)] = $r;
            if (!isset($storedAt[$d]) || $r->snapshot_at > $storedAt[$d]) $storedAt[$d] = (string) $r->snapshot_at;
            $lastBucket[$d] = max($lastBucket[$d] ?? -1, (int) substr((string) $r->snapshot_bucket, 1));
        }

        // Pages with ad spend that day (synced report).
        $castMoney = DB::connection()->getDriverName() === 'pgsql'
            ? "COALESCE(NULLIF(REGEXP_REPLACE(COALESCE((amount_spent_php)::text, ''), '[^0-9\\.\\-]', '', 'g'), '')::numeric, 0)"
            : "CAST(REPLACE(REPLACE(REPLACE(COALESCE(amount_spent_php,''), '₱',''), ',', ''), ' ', '') AS DECIMAL(18,2))";
        if (Schema::hasTable('ads_manager_reports')) {
            $adPages = DB::table('ads_manager_reports')->whereBetween('day', [$start, $end])
                ->selectRaw("day, page_name, SUM($castMoney) AS spent")->groupBy('day', 'page_name')->havingRaw("SUM($castMoney) > 0")->get();
            foreach ($adPages as $r) {
                $key = $normalize($r->page_name);
                if ($key !== '') $pages[substr((string) $r->day, 0, 10)][$key]['page'] ??= (string) $r->page_name;
            }
        }

        $orders = $pages ? $this->orders($dates, $start, $end) : [];

        $out = [];
        foreach ($dates as $d) {
            $last = $d === $this->now->toDateString() ? (int) $this->now->format('G') : ($d > $this->now->toDateString() ? -1 : 23);
            $out[$d] = ['last_hour' => $last, 'stored_at' => $storedAt[$d] ?? null, 'asof_hours' => $this->asOfHours($d, $storedAt[$d] ?? null)]
                + $this->day($pages[$d] ?? [], $orders[$d] ?? [], $last, $d < $this->now->toDateString() ? ($lastBucket[$d] ?? -1) : $last);
        }

        return $out;
    }

    /**
     * Spec 297: how many "as of" columns of the day are filled. Column k (1..24) is the day as of k:00, which is the
     * Total cell of hour k−1 (spend from H(k−1), orders before k:00).
     * - A past day: all 24; a bucket never stored shows its orders without spend, as before.
     * - A future day: none.
     * - Today: column k only once k:00 has passed and the day's hourly spend was stored at or after k:00. Each run
     *   rewrites the day's H-rows with one snapshot_at, so the latest one is the time of the latest run that stored the
     *   day, and a run at or after k:00 read Meta's breakdown with hour k−1 finished. The run at :10 writes the
     *   current hour with about ten minutes of spend; that hour's column waits for the next run. Orders alone never
     *   fill a column.
     */
    public function asOfHours(string $date, ?string $storedAt): int
    {
        $today = $this->now->toDateString();
        if ($date !== $today) return $date < $today ? 24 : 0;
        if ($storedAt === null) return 0;

        $start  = Carbon::parse($date, 'Asia/Manila')->startOfDay();
        $stored = Carbon::parse($storedAt, 'Asia/Manila');
        $n = 0;
        for ($k = 1; $k <= 24; $k++) {
            $at = $start->copy()->addHours($k);
            if ($at->gt($this->now) || $at->gt($stored)) break;
            $n = $k;
        }

        return $n;
    }

    /**
     * Orders per date, page key and hour, by order time.
     * @return array<string, array<string, array<int, int>>> date => page_key => hour => count
     */
    private function orders(array $dates, string $start, string $end): array
    {
        $pageCol = null;
        foreach (['PAGE', 'page', 'page_name', 'Page', 'Page_Name'] as $cand) {
            if (Schema::hasColumn('macro_output', $cand)) { $pageCol = $cand; break; }
        }
        if (!$pageCol || !Schema::hasColumn('macro_output', 'TIMESTAMP')) return [];

        $dmyOf = []; // date => 'd-m-Y' the TIMESTAMP of that day ends with
        foreach ($dates as $d) $dmyOf[$d] = Carbon::parse($d)->format('d-m-Y');
        $byTsDate = Schema::hasColumn('macro_output', 'ts_date');
        $inSql = $this->ordersInSql ?? (DB::connection()->getDriverName() === 'mysql' && $byTsDate);
        $orders = [];

        if ($inSql) {
            // One row per (ts_date, page, hour) over the indexed ts_date range. The hour and the date part are cut from
            // TIMESTAMP and only the 'H:i d-m-Y' shape counts, as below; the page is grouped as stored and normalised here.
            $ts   = 'TRIM(`TIMESTAMP`)';
            $hh   = "SUBSTRING_INDEX($ts, ':', 1)";
            $dmy  = "SUBSTRING_INDEX($ts, ' ', -1)";
            $page = '`' . $pageCol . '`';
            $rows = DB::table('macro_output')
                ->selectRaw("ts_date AS d, $page AS pg, $hh AS hh, $dmy AS dmy, COUNT(*) AS n")
                ->whereBetween('ts_date', [$start, $end])
                ->whereRaw("$ts REGEXP ?", [self::TS_SQL_PATTERN])
                ->groupByRaw("ts_date, $page, $hh, $dmy")
                ->get();
            foreach ($rows as $r) {
                $d = substr((string) $r->d, 0, 10);
                $h = (int) $r->hh;
                if (!isset($dmyOf[$d]) || (string) $r->dmy !== $dmyOf[$d] || $h > 23) continue;
                $key = self::pageKey($r->pg);
                $orders[$d][$key][$h] = ($orders[$d][$key][$h] ?? 0) + (int) $r->n;
            }
            return $orders;
        }

        // Parsed here so the same code reads Postgres and sqlite.
        $dateOfDmy = array_flip($dmyOf);
        $rows = DB::table('macro_output')
            ->when($byTsDate,
                fn ($q) => $q->whereBetween('ts_date', [$start, $end]),
                fn ($q) => $q->where(function ($w) use ($dmyOf) {
                    foreach ($dmyOf as $dmy) $w->orWhere('TIMESTAMP', 'like', '% ' . $dmy);
                }))
            ->get(array_merge([$pageCol . ' as pg', 'TIMESTAMP as ts'], $byTsDate ? ['ts_date'] : []));
        foreach ($rows as $o) {
            if (!preg_match(self::TS_PATTERN, trim((string) $o->ts), $m)) continue;
            $d = $byTsDate ? substr((string) $o->ts_date, 0, 10) : ($dateOfDmy[$m[2]] ?? null);
            if ($d === null || !isset($dmyOf[$d]) || $m[2] !== $dmyOf[$d]) continue;
            $h = (int) $m[1];
            if ($h <= 23) {
                $key = self::pageKey($o->pg);
                $orders[$d][$key][$h] = ($orders[$d][$key][$h] ?? 0) + 1;
            }
        }

        return $orders;
    }

    /**
     * One day's page rows and Total row. Hours after $last are null; hours after $spendUntil have no spend (orders only).
     * Impressions and messages are recovered from the stored CPI / CPM to add up the Total row.
     */
    private function day(array $pages, array $orders, int $last, int $spendUntil): array
    {
        $out = [];
        $tot = array_fill(0, 24, ['spend' => 0.0, 'impr' => 0.0, 'msgs' => 0.0, 'orders' => 0, 'has_spend' => false]);
        foreach ($pages as $key => $p) {
            $cells = [];
            $prev  = ['spend' => 0.0, 'orders' => 0];
            $spend = null;
            $cumOrders = 0;
            $cpi = $cpm = null;
            for ($h = 0; $h < 24; $h++) {
                if ($h > $last) { $cells[] = null; continue; }
                if ($h > $spendUntil) {
                    $spend = $cpi = $cpm = null; // the sync never stored this hour of the day
                } elseif (isset($p['spend'][$h])) {
                    $row = $p['spend'][$h];
                    $spend = (float) $row->amount_spent;
                    $cpi = $row->cpi !== null ? (float) $row->cpi : null;
                    $cpm = $row->cpm !== null ? (float) $row->cpm : null;
                }
                $cumOrders += $orders[$key][$h] ?? 0;
                if ($spend === null && $cumOrders === 0) { $cells[] = null; continue; }
                $cells[] = [
                    'spend'      => $spend,
                    'orders'     => $cumOrders,
                    'cpp'        => $spend !== null && $cumOrders > 0 ? round($spend / $cumOrders, 2) : null,
                    'cpi'        => $cpi,
                    'cpm'        => $cpm,
                    'add_spend'  => $spend !== null ? round($spend - $prev['spend'], 2) : null,
                    'add_orders' => $cumOrders - $prev['orders'],
                    'source'     => $spend !== null ? 'meta' : 'orders',
                ];
                $prev = ['spend' => (float) ($spend ?? 0), 'orders' => $cumOrders];

                $tot[$h]['orders'] += $cumOrders;
                if ($spend !== null) {
                    $tot[$h]['spend'] += $spend;
                    $tot[$h]['has_spend'] = true;
                    if ($cpi) $tot[$h]['impr'] += $spend * 1000 / $cpi;
                    if ($cpm) $tot[$h]['msgs'] += $spend / $cpm;
                }
            }
            if (array_filter($cells)) $out[] = ['page' => $p['page'], 'cells' => $cells];
        }
        usort($out, fn ($a, $b) => strcasecmp($a['page'], $b['page']));

        $total = [];
        $prev = ['spend' => 0.0, 'orders' => 0];
        for ($h = 0; $h < 24; $h++) {
            $t = $tot[$h];
            if ($h > $last || (!$t['has_spend'] && $t['orders'] === 0)) { $total[] = null; continue; }
            $spend = $t['has_spend'] ? round($t['spend'], 2) : null;
            $total[] = [
                'spend'      => $spend,
                'orders'     => $t['orders'],
                'cpp'        => $spend !== null && $t['orders'] > 0 ? round($spend / $t['orders'], 2) : null,
                'cpi'        => $t['impr'] > 0 ? round($t['spend'] * 1000 / $t['impr'], 2) : null,
                'cpm'        => $t['msgs'] > 0 ? round($t['spend'] / $t['msgs'], 2) : null,
                'add_spend'  => $spend !== null ? round($spend - $prev['spend'], 2) : null,
                'add_orders' => $t['orders'] - $prev['orders'],
                'source'     => $spend !== null ? 'meta' : 'orders',
            ];
            $prev = ['spend' => (float) ($spend ?? 0), 'orders' => $t['orders']];
        }

        return ['pages' => $out, 'total' => $total];
    }
}
