<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\ItemHoldSnapshot;

/**
 * HoldService — single source para sa HOLD computation (mula sa /jnt/hold).
 *
 * HOLD = macro_output rows na may waybill PERO wala sa from_jnts (= pending/
 * held, hindi pa na-process ng J&T). `group=item` → units per base item
 * (count × qty na naka-prefix na "N x"). Current-state ito (depende sa current
 * laman ng from_jnts), kaya kino-snapshot araw-araw para may history.
 */
class HoldService
{
    private const MO_ITEM_COL    = 'ITEM_NAME';
    private const MO_WAYBILL_COL = 'waybill';
    private const FJ_WAYBILL_COL = 'waybill_number';

    /**
     * Held UNITS per base item para sa orders na ang macro_output TIMESTAMP ay
     * nasa loob ng [$start, $end]. Returns [ item_key => ['name'=>, 'units'=>] ].
     */
    public function unitsByBaseItem(Carbon $start, Carbon $end): array
    {
        $driver    = DB::getDriverName();
        $moItemRef = $driver === 'pgsql' ? 'mo."' . self::MO_ITEM_COL . '"' : 'mo.`' . self::MO_ITEM_COL . '`';
        $moTsCol   = $driver === 'pgsql' ? 'mo."TIMESTAMP"' : 'mo.`TIMESTAMP`';
        $tsExpr    = $driver === 'pgsql'
            ? "to_timestamp($moTsCol, 'HH24:MI DD-MM-YYYY')"
            : "STR_TO_DATE($moTsCol, '%H:%i %d-%m-%Y')";

        $rows = DB::table('macro_output as mo')
            ->leftJoin('from_jnts as fj', 'fj.' . self::FJ_WAYBILL_COL, '=', 'mo.' . self::MO_WAYBILL_COL)
            ->whereNull('fj.' . self::FJ_WAYBILL_COL)                              // not yet in J&T = HELD
            ->whereRaw("NULLIF(TRIM(mo." . self::MO_WAYBILL_COL . "), '') IS NOT NULL") // has waybill
            ->whereBetween(DB::raw($tsExpr), [
                $start->copy()->startOfDay()->format('Y-m-d H:i:s'),
                $end->copy()->endOfDay()->format('Y-m-d H:i:s'),
            ])
            ->selectRaw("$moItemRef as item_name, COUNT(*) as hold_count")
            ->groupByRaw($moItemRef)
            ->get();

        $map = []; // item_key => ['name'=>baseName, 'units'=>int]
        foreach ($rows as $r) {
            $name  = trim((string) ($r->item_name ?? ''));
            $count = (int) $r->hold_count;
            if ($name === '') continue;

            // Strip "N x" / "N ×" qty prefix → base item + qty.
            if (preg_match('/^\s*(\d+)\s*[x×]\s*(.+)$/iu', $name, $m)) {
                $qty      = max(1, (int) $m[1]);
                $baseName = trim($m[2]);
            } else {
                $qty      = 1;
                $baseName = $name;
            }
            if ($baseName === '') continue;

            $key = $this->itemKey($baseName);
            if (!isset($map[$key])) $map[$key] = ['name' => $baseName, 'units' => 0];
            $map[$key]['units'] += $count * $qty;
        }
        return $map;
    }

    /**
     * Compute + persist a snapshot para sa $snapshotDate (Y-m-d). Window =
     * [$snapshotDate - $windowDays, $snapshotDate]. Overwrites kung naka-snapshot
     * na ang petsang yon (idempotent re-run). Returns ['items'=>n, 'units'=>sum].
     */
    public function snapshot(string $snapshotDate, int $windowDays = 60): array
    {
        $end   = Carbon::parse($snapshotDate, 'Asia/Manila');
        $start = $end->copy()->subDays(max(1, $windowDays));
        $map   = $this->unitsByBaseItem($start, $end);

        $now = now();
        $totalUnits = 0;
        foreach ($map as $key => $info) {
            $totalUnits += (int) $info['units'];
            ItemHoldSnapshot::query()->updateOrCreate(
                ['item_key' => $key, 'snapshot_date' => $end->toDateString()],
                ['item_name' => $info['name'], 'hold_units' => (int) $info['units'], 'captured_at' => $now]
            );
        }

        return ['items' => count($map), 'units' => $totalUnits, 'date' => $end->toDateString()];
    }

    /**
     * Wrapper sa snapshot() na NAGLO-LOG ng bawat takbo sa hold_snapshot_logs
     * (para may history kung tumakbo ba ang cron / manual at kung kailan tumigil).
     * Sini-save ang success O error; ang error ay ire-rethrow pagkatapos i-log.
     *
     * $source = 'cron' (scheduled command) | 'manual' (UI button / CLI).
     */
    public function snapshotWithLog(string $snapshotDate, int $windowDays, string $source): array
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $snapshotDate)
            ? $snapshotDate
            : Carbon::now('Asia/Manila')->subDay()->toDateString();
        if ($windowDays < 1) $windowDays = 60;

        $t0 = microtime(true);
        try {
            $res = $this->snapshot($date, $windowDays);
            $this->writeLog([
                'snapshot_date' => $res['date'],
                'window'        => $windowDays,
                'source'        => $source,
                'status'        => 'success',
                'items'         => (int) $res['items'],
                'units'         => (int) $res['units'],
                'duration_ms'   => (int) round((microtime(true) - $t0) * 1000),
                'message'       => "{$res['items']} item(s), {$res['units']} held units (window {$windowDays}d).",
            ]);
            return $res;
        } catch (\Throwable $e) {
            $this->writeLog([
                'snapshot_date' => $date,
                'window'        => $windowDays,
                'source'        => $source,
                'status'        => 'error',
                'items'         => 0,
                'units'         => 0,
                'duration_ms'   => (int) round((microtime(true) - $t0) * 1000),
                'message'       => mb_substr($e->getMessage(), 0, 1000),
            ]);
            throw $e;
        }
    }

    /** Insert ng isang run-log row — best-effort (di nito sisirain ang snapshot). */
    private function writeLog(array $data): void
    {
        try {
            DB::table('hold_snapshot_logs')->insert(array_merge($data, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        } catch (\Throwable $e) {
            // Wala pa siguro ang hold_snapshot_logs table (di pa na-migrate) —
            // huwag ipa-fail ang snapshot dahil lang dito.
        }
    }

    /** Held units map for a snapshot_date (item_key => hold_units). */
    public function snapshotMap(string $snapshotDate): array
    {
        return ItemHoldSnapshot::query()
            ->where('snapshot_date', $snapshotDate)
            ->pluck('hold_units', 'item_key')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** Normalize a base item label → key (lower + collapse spaces). */
    public function itemKey(string $baseName): string
    {
        $s = mb_strtolower(trim($baseName));
        return preg_replace('/\s+/u', ' ', $s) ?? $s;
    }
}
