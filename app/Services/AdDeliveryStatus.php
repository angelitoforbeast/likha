<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * J&T delivery status kada ad / ad set / campaign ng mga page, para sa expand ng /owner/private.
 * Kapareho ng page row ng OwnerPrivateController::data(): ts_date sa range, LOWER(TRIM(PAGE)) na page match,
 * from_jnts sa waybill = waybill_number, parehong per-waybill flags (jntFlagsSql), shipped = distinct waybill
 * na may J&T row, RTS% = (returned + for return) / shipped. Read-only.
 */
class AdDeliveryStatus
{
    private const KEYS = ['shipped', 'delivered', 'in_transit', 'for_return', 'returned'];

    /**
     * Ang apat na per-waybill flag ng J&T rows (alias j), MAX sa lahat ng row ng waybill.
     * Ginagamit din ng page row (_jnt_agg sa data()), kaya iisa ang patakaran ng dalawa.
     */
    public static function jntFlagsSql(string $driver): string
    {
        $int = $driver === 'pgsql' ? '::int' : '';

        return "MAX(CASE WHEN j.status LIKE 'Delivered%'  OR j.status LIKE 'DELIVERED%'  THEN 1 ELSE 0 END)$int AS is_delivered,
                    MAX(CASE WHEN j.status LIKE 'Returned%'   OR j.status LIKE 'RETURNED%'   THEN 1 ELSE 0 END)$int AS is_returned,
                    MAX(CASE
                        WHEN j.status LIKE 'For Return%' OR j.status LIKE 'FOR RETURN%' THEN 1
                        WHEN TRIM(COALESCE(j.rts_reason,'')) <> ''
                             AND LOWER(j.status) NOT LIKE '%delivered%'
                             AND LOWER(j.status) NOT LIKE '%returned%' THEN 1
                        ELSE 0
                    END)$int AS is_for_return,
                    MAX(CASE
                        WHEN j.status LIKE 'Delivered%'  OR j.status LIKE 'DELIVERED%'  THEN 0
                        WHEN j.status LIKE 'Returned%'   OR j.status LIKE 'RETURNED%'   THEN 0
                        WHEN j.status LIKE 'For Return%' OR j.status LIKE 'FOR RETURN%' THEN 0
                        WHEN TRIM(COALESCE(j.rts_reason,'')) <> ''
                             AND LOWER(j.status) NOT LIKE '%delivered%'
                             AND LOWER(j.status) NOT LIKE '%returned%' THEN 0
                        ELSE 1
                    END)$int AS is_in_transit";
    }

    /**
     * Kada page (susi = pangalan gaya ng ibinigay): ads / adsets = [id => counts], campaigns = [id => [name, counts]],
     * no_ad = walang ad id o ad id na wala sa ads_manager_reports, page = kabuuan (= page row).
     * Dalawang query lang: ang status counts (grouped) at ang ad → ad set / campaign mapping.
     *
     * @param  string[]  $pageNames
     */
    public function forPages(array $pageNames, string $start, string $end): array
    {
        $byKey = [];
        foreach ($pageNames as $name) {
            // espasyo lang ang tinatanggal, gaya ng TRIM ng SQL, para tugma ang susi sa page_key ng query
            $key = mb_strtolower(trim((string) $name, ' '));
            if ($key !== '') $byKey[$key] ??= (string) $name;
        }
        if ($byKey === []) return [];

        $groups = $this->statusGroups(array_keys($byKey), $start, $end);
        $adMap  = $this->adMap(array_values(array_unique(array_filter(array_column($groups, 'ad_id'), fn ($id) => $id !== null && $id !== ''))));

        $out = [];
        foreach ($groups as $g) {
            $name = $byKey[$g['page_key']] ?? null;
            if ($name === null) continue;
            $p = &$out[$name];
            $p ??= ['campaigns' => [], 'adsets' => [], 'ads' => [], 'no_ad' => self::zero(), 'page' => self::zero()];

            $ad = $g['ad_id'] !== null ? ($adMap[$g['ad_id']] ?? null) : null;
            if ($ad === null) {
                $p['no_ad'] = self::add($p['no_ad'], $g);
            } else {
                $p['ads'][$g['ad_id']] = self::add($p['ads'][$g['ad_id']] ?? self::zero(), $g);
                if ($ad['ad_set_id'] !== '') {
                    $p['adsets'][$ad['ad_set_id']] = self::add($p['adsets'][$ad['ad_set_id']] ?? self::zero(), $g);
                }
                $c = $p['campaigns'][$ad['campaign_id']] ?? ['name' => $ad['campaign_name'], 'counts' => self::zero()];
                $c['counts'] = self::add($c['counts'], $g);
                $p['campaigns'][$ad['campaign_id']] = $c;
            }
            $p['page'] = self::add($p['page'], $g);
            unset($p);
        }

        foreach ($out as &$p) {
            $p['no_ad'] = self::withRts($p['no_ad']);
            $p['page']  = self::withRts($p['page']);
            foreach (['ads', 'adsets'] as $level) {
                $p[$level] = array_map([self::class, 'withRts'], $p[$level]);
            }
            foreach ($p['campaigns'] as &$c) $c['counts'] = self::withRts($c['counts']);
            unset($c);
        }
        unset($p);

        return $out;
    }

    /**
     * Isang grouped query: kada page + waybill muna (flags = MAX ng J&T rows, ad id = MAX na hindi blangko),
     * para isang beses lang mabilang ang waybill kada page gaya ng COUNT(DISTINCT waybill) ng page row;
     * saka kada page + ad id.
     *
     * @return array<int, array{page_key:string, ad_id:?string, shipped:int, delivered:int, in_transit:int, for_return:int, returned:int}>
     */
    private function statusGroups(array $pageKeys, string $start, string $end): array
    {
        $driver  = DB::getDriverName();
        $trim    = $driver === 'pgsql' ? 'BTRIM' : 'TRIM';
        $pageCol = $driver === 'mysql' ? 'mo.`PAGE`' : 'mo."PAGE"';
        $pageKey = "LOWER($trim(COALESCE($pageCol,'')))";
        $marks   = implode(',', array_fill(0, count($pageKeys), '?'));

        // Iisang bucket kada waybill (returned, saka delivered, saka for return, saka in transit), para hindi
        // lumampas sa shipped ang suma kahit may ilang J&T row ang waybill. Sa isang row kada waybill,
        // pareho ito ng bilang ng data().
        $rows = DB::select("
            SELECT w.page_key, w.ad_id,
                   COUNT(*) AS shipped,
                   SUM(CASE WHEN w.is_returned = 1 THEN 1 ELSE 0 END) AS returned,
                   SUM(CASE WHEN w.is_returned = 0 AND w.is_delivered = 1 THEN 1 ELSE 0 END) AS delivered,
                   SUM(CASE WHEN w.is_returned = 0 AND w.is_delivered = 0 AND w.is_for_return = 1 THEN 1 ELSE 0 END) AS for_return,
                   SUM(CASE WHEN w.is_returned = 0 AND w.is_delivered = 0 AND w.is_for_return = 0 THEN 1 ELSE 0 END) AS in_transit
            FROM (
                SELECT $pageKey AS page_key, mo.waybill AS wb,
                       MAX(NULLIF($trim(COALESCE(mo.botcake_ad_id,'')), '')) AS ad_id,
                       " . self::jntFlagsSql($driver) . "
                FROM macro_output mo
                JOIN from_jnts j ON j.waybill_number = mo.waybill
                WHERE mo.ts_date BETWEEN ? AND ?
                  AND $pageKey IN ($marks)
                  AND mo.waybill IS NOT NULL AND mo.waybill <> ''
                GROUP BY $pageKey, mo.waybill
            ) w
            GROUP BY w.page_key, w.ad_id
        ", array_merge([$start, $end], $pageKeys));

        return array_map(fn ($r) => [
            'page_key'   => (string) $r->page_key,
            'ad_id'      => $r->ad_id !== null ? (string) $r->ad_id : null,
            'shipped'    => (int) $r->shipped,
            'delivered'  => (int) $r->delivered,
            'in_transit' => (int) $r->in_transit,
            'for_return' => (int) $r->for_return,
            'returned'   => (int) $r->returned,
        ], $rows);
    }

    /**
     * Ad id → ad set, campaign (at pangalan nito) mula sa ads_manager_reports; kapag ilan ang kombinasyon,
     * ang may pinakahuling day ang panalo.
     *
     * @return array<string, array{ad_set_id:string, campaign_id:string, campaign_name:string}>
     */
    private function adMap(array $adIds): array
    {
        if ($adIds === []) return [];

        $rows = DB::table('ads_manager_reports')
            ->whereIn('ad_id', $adIds)
            ->whereNotNull('campaign_id')
            ->groupBy('ad_id', 'ad_set_id', 'campaign_id')
            ->selectRaw('ad_id, ad_set_id, campaign_id, MAX(campaign_name) AS campaign_name, MAX(day) AS last_day')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $id   = (string) $r->ad_id;
            $last = (string) ($r->last_day ?? '');
            if (isset($map[$id]) && $map[$id]['last_day'] >= $last) continue;
            $map[$id] = [
                'ad_set_id'     => (string) ($r->ad_set_id ?? ''),
                'campaign_id'   => (string) $r->campaign_id,
                'campaign_name' => (string) ($r->campaign_name ?? ''),
                'last_day'      => $last,
            ];
        }

        return $map;
    }

    private static function zero(): array
    {
        return array_fill_keys(self::KEYS, 0);
    }

    private static function add(array $sum, array $g): array
    {
        foreach (self::KEYS as $k) $sum[$k] += $g[$k];
        return $sum;
    }

    private static function withRts(array $c): array
    {
        $c['rts_pct'] = $c['shipped'] > 0 ? ($c['returned'] + $c['for_return']) / $c['shipped'] * 100.0 : null;
        return $c;
    }
}
