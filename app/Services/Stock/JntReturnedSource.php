<?php

namespace App\Services\Stock;

use App\Services\HoldService;
use App\Services\ItemStockService;
use App\Support\ItemBaseKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Returned = J&T: from_jnts.status = 'Returned', ayon sa signingtime. Isang grouped query.
 *
 * - Ang status ng waybill = ang from_jnts row nito na may pinakamataas na id (iyon ang ina-update ng J&T track sync),
 *   kaya isang beses lang bilang ang waybill, at nawawala agad kapag lumipat ang status.
 * - Bilang lang kung bilang din bilang "umalis" (shipped) mula START: may J&T record na submission_time >= START at
 *   wala bago ang START — eksaktong rule ng shipped sa ItemStockService.
 * - Kada macro_output row (hindi cancelled) × ItemBaseKey::parse qty, gaya ng shipped.
 * - Walang signingtime o signingtime sa hinaharap: hindi bilang, pero binibilang sa flags at nilo-log.
 */
class JntReturnedSource implements ReturnedSource
{
    public const STATUS = 'Returned';

    public function key(): string
    {
        return 'jnt';
    }

    public function returned(string $start, string $until): ReturnedResult
    {
        return $this->fromRows($this->rows($start, $until));
    }

    /**
     * Ang SQL (para sa EXPLAIN): bindings() ang ayos ng mga value.
     * "fj.submission_time >= START OR EXISTS (...)": kadalasang may submission_time ang Returned row mismo, kaya bihirang
     * kailanganin ang EXISTS. Kung EXISTS lang, ginagawa itong semijoin ng MySQL na nagsisimula sa buong scan ng from_jnts
     * imbes na sa mga Returned row (index ng status).
     */
    public static function sql(): string
    {
        $item = ItemStockService::col('ITEM_NAME');

        return "SELECT $item AS item_name,
                   SUM(CASE WHEN r.signingtime IS NOT NULL AND r.signingtime <= ? THEN 1 ELSE 0 END) AS returned_cnt,
                   SUM(CASE WHEN r.signingtime IS NULL THEN 1 ELSE 0 END) AS unsigned_cnt,
                   SUM(CASE WHEN r.signingtime > ? THEN 1 ELSE 0 END) AS future_cnt
            FROM macro_output mo
            JOIN (SELECT fj.waybill_number, fj.signingtime
                  FROM from_jnts fj
                  WHERE fj.status = ?
                    AND (fj.signingtime IS NULL OR fj.signingtime >= ?)
                    AND NOT EXISTS (SELECT 1 FROM from_jnts n WHERE n.waybill_number = fj.waybill_number AND n.id > fj.id)
                    AND NOT EXISTS (SELECT 1 FROM from_jnts b WHERE b.waybill_number = fj.waybill_number AND b.submission_time < ?)
                    AND (fj.submission_time >= ?
                         OR EXISTS (SELECT 1 FROM from_jnts a WHERE a.waybill_number = fj.waybill_number AND a.submission_time >= ?))) r
              ON r.waybill_number = mo.waybill
            WHERE " . ItemStockService::notCancelledSql() . "
            GROUP BY $item";
    }

    /** @return array<int,mixed> */
    public static function bindings(string $start, string $until): array
    {
        $from = $start . ' 00:00:00';

        return array_merge([$until, $until, self::STATUS, $from, $from, $from, $from], HoldService::CANCELLED_STATUSES);
    }

    /** Raw grouped rows (para sa cache): [[item_name, returned_cnt, unsigned_cnt, future_cnt], ...] */
    public function rows(string $start, string $until): array
    {
        return array_map(
            fn ($r) => [(string) ($r->item_name ?? ''), (int) $r->returned_cnt, (int) $r->unsigned_cnt, (int) $r->future_cnt],
            DB::select(self::sql(), self::bindings($start, $until))
        );
    }

    public function fromRows(array $rows): ReturnedResult
    {
        $pieces = [];
        $flags  = ['unsigned' => 0, 'future' => 0];
        foreach ($rows as [$name, $returned, $unsigned, $future]) {
            $flags['unsigned'] += $unsigned;
            $flags['future']   += $future;
            $raw = trim($name);
            if ($raw === '' || $returned <= 0) continue;
            $p = ItemBaseKey::parse($raw);
            if ($p['key'] === '') continue;
            $pieces[$p['key']] = ($pieces[$p['key']] ?? 0) + $returned * $p['qty'];
        }
        if ($flags['unsigned'] > 0 || $flags['future'] > 0) {
            Log::info('item stock: J&T Returned rows not counted (no signingtime or a future signingtime)', $flags);
        }
        ksort($pieces);

        return new ReturnedResult(true, $pieces, true, $flags);
    }
}
