<?php

namespace App\Services\Stock;

use App\Services\HoldService;
use App\Services\ItemStockService;
use App\Support\ItemBaseKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Returned = J&T: from_jnts.status = 'Returned', ayon sa signingtime. Isang grouped query, kada item at araw.
 *
 * - Ang status ng waybill = ang from_jnts row nito na may pinakamataas na id (iyon ang ina-update ng J&T track sync),
 *   kaya isang beses lang bilang ang waybill, at nawawala agad kapag lumipat ang status.
 * - Kahit kailan na-ship: ang bumalik pagkatapos ng simula ng item ay bilang (ang /item ang nagsasala kada item, ayon sa
 *   araw ng signingtime at sa unang Nexus stock-in ng item).
 * - Kada macro_output row (hindi cancelled) × ItemBaseKey::parse qty, gaya ng shipped.
 * - Ang query ay "signingtime mula–hanggang" lang, para dumaan sa index na (signingtime, status). Ang walang signingtime
 *   at ang signingtime sa hinaharap ay binibilang nang hiwalay (flags(), para sa lahat ng item, hindi kada simula).
 */
class JntReturnedSource implements ReturnedSource
{
    public const STATUS = 'Returned';

    public function key(): string
    {
        return 'jnt';
    }

    /** $start = Y-m-d (simula, kasama); $until = Y-m-d H:i:s (dulo, kasama). */
    public function returned(string $start, string $until): ReturnedResult
    {
        return $this->fromRows($this->rows($start, $until));
    }

    /** Ang SQL (para sa EXPLAIN): bindings() ang ayos ng mga value. */
    public static function sql(): string
    {
        $item = ItemStockService::col('ITEM_NAME');

        return "SELECT $item AS item_name, DATE(fj.signingtime) AS day, COUNT(*) AS returned_cnt
            FROM from_jnts fj
            JOIN macro_output mo ON mo.waybill = fj.waybill_number
            WHERE fj.status = ? AND fj.signingtime >= ? AND fj.signingtime <= ?
              AND NOT EXISTS (SELECT 1 FROM from_jnts n WHERE n.waybill_number = fj.waybill_number AND n.id > fj.id)
              AND " . ItemStockService::notCancelledSql() . "
            GROUP BY $item, DATE(fj.signingtime)";
    }

    /** @return array<int,mixed> */
    public static function bindings(string $start, string $until): array
    {
        return array_merge([self::STATUS, $start . ' 00:00:00', $until], HoldService::CANCELLED_STATUSES);
    }

    /** Raw grouped rows: [[item_name, day, returned_cnt], ...] */
    public function rows(string $start, string $until): array
    {
        return array_map(
            fn ($r) => [(string) ($r->item_name ?? ''), substr((string) $r->day, 0, 10), (int) $r->returned_cnt],
            DB::select(self::sql(), self::bindings($start, $until))
        );
    }

    public function fromRows(array $rows): ReturnedResult
    {
        $pieces = [];
        $byDay  = [];
        foreach ($rows as [$name, $day, $returned]) {
            $raw = trim($name);
            if ($raw === '' || $returned <= 0) continue;
            $p = ItemBaseKey::parse($raw);
            if ($p['key'] === '') continue;
            $pieces[$p['key']] = ($pieces[$p['key']] ?? 0) + $returned * $p['qty'];
            $byDay[$p['key']][$day] = ($byDay[$p['key']][$day] ?? 0) + $returned * $p['qty'];
        }
        ksort($pieces);
        ksort($byDay);

        return new ReturnedResult(true, $pieces, true, [], null, $byDay);
    }

    /** Ang SQL ng flags: dalawang COUNT, bawat isa sa sariling index (signingtime IS NULL; signingtime > ngayon). */
    public static function flagsSql(): string
    {
        $one = fn (string $cond) => "(SELECT COUNT(*) FROM from_jnts fj
                JOIN macro_output mo ON mo.waybill = fj.waybill_number
                WHERE $cond AND fj.status = ?
                  AND NOT EXISTS (SELECT 1 FROM from_jnts n WHERE n.waybill_number = fj.waybill_number AND n.id > fj.id)
                  AND " . ItemStockService::notCancelledSql() . ')';

        return 'SELECT ' . $one('fj.signingtime IS NULL') . ' AS unsigned_cnt, ' . $one('fj.signingtime > ?') . ' AS future_cnt';
    }

    /**
     * Ang mga Returned na hindi binibilang, para makita: walang signingtime, o signingtime sa hinaharap. Para sa LAHAT ng
     * item at araw (hindi kada simula ng item). Naka-log kapag may laman.
     *
     * @return array{unsigned: int, future: int}
     */
    public function flags(string $until): array
    {
        $c = HoldService::CANCELLED_STATUSES;
        $r = DB::selectOne(self::flagsSql(), array_merge([self::STATUS], $c, [$until, self::STATUS], $c));
        $flags = ['unsigned' => (int) ($r->unsigned_cnt ?? 0), 'future' => (int) ($r->future_cnt ?? 0)];
        if ($flags['unsigned'] > 0 || $flags['future'] > 0) {
            Log::info('item stock: J&T Returned rows not counted (no signingtime or a future signingtime)', $flags);
        }

        return $flags;
    }
}
