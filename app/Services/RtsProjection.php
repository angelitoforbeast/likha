<?php

namespace App\Services;

use App\Models\AppSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Projected RTS% ng bawat page+item, kasama ang mga order na nasa biyahe pa.
 *
 * Projected = (Returned + For Return + Σ in-transit × p(edad, item)) ÷ (mga order na may J&T state).
 * Ang p(edad) = tsansang magtatapos na Returned ang order na In Transit/Delivering pa sa edad na iyon (araw mula
 * submission_time). Natututunan ito ng `rts:learn` gabi-gabi mula sa status_logs ng mga order na tapos na
 * (huling 8 linggo) at naka-save sa app_settings; item rate lang kapag may sapat na sample, kung hindi pooled.
 */
class RtsProjection
{
    public const KEY = 'rts_projection_rates';
    public const MAX_AGE = 14;            // edad (araw) na may sariling p; mas matanda = p ng 14
    public const MIN_ITEM_SAMPLES = 30;   // sample ng isang item sa isang edad bago gamitin ang sariling p
    public const LEARN_WEEKS = 8;
    public const MIN_MATCHED_FLAG = 30;   // kulang sa order = walang flag/feed
    public const FLAG_POINTS = 5;
    private const TZ = 'Asia/Manila';
    private const MAX_JSON = 60000;       // app_settings.value ay TEXT (64 KB sa MySQL)

    /** D delivered, R returned, F for return, T in transit / delivering / iba pa; null = walang status. */
    public static function statusClass(?string $status): ?string
    {
        $s = preg_replace('/\s+/u', ' ', trim(mb_strtolower((string) $status)));
        if ($s === '') return null;
        if (str_contains($s, 'returned')) return 'R';
        if (str_contains($s, 'for return') || preg_match('/\brts\b/', $s)) return 'F';
        if (str_contains($s, 'delivered')) return 'D';

        return 'T';
    }

    public static function itemKey(string $page, string $item, ItemAliasResolver $aliases): string
    {
        return strtolower(trim($page)) . '||' . $aliases->canonicalKey($item);
    }

    /** @return array{learned_at?:string,pooled:array,items:array} */
    public function rates(): array
    {
        if (!Schema::hasTable('app_settings')) return ['pooled' => [], 'items' => []];
        $j = json_decode((string) AppSetting::get(self::KEY, ''), true);

        return is_array($j) ? $j + ['pooled' => [], 'items' => []] : ['pooled' => [], 'items' => []];
    }

    /** Kinukuwenta ulit ang rate table at sine-save. Mga order na tapos (Delivered/Returned/For Return) lang, may status_logs. */
    public function learn(): array
    {
        $now = Carbon::now(self::TZ);
        $since = $now->copy()->subWeeks(self::LEARN_WEEKS)->toDateTimeString();
        $aliases = new ItemAliasResolver();
        $pooled = [];
        $items = [];
        $seen = [];

        DB::table('from_jnts as j')
            ->join('macro_output as mo', 'mo.waybill', '=', 'j.waybill_number')
            ->where('j.submission_time', '>=', $since)
            ->whereNotNull('j.status_logs')
            ->select('j.id', 'j.status', 'j.submission_time', 'j.status_logs', 'mo.PAGE', 'mo.ITEM_NAME')
            ->chunkById(1000, function ($chunk) use (&$pooled, &$items, &$seen, $now, $aliases) {
                foreach ($chunk as $o) {
                    if (isset($seen[$o->id])) continue;
                    $seen[$o->id] = true;

                    $outcome = self::statusClass($o->status);
                    if (!in_array($outcome, ['D', 'R', 'F'], true)) continue;
                    $logs = $this->logs($o->status_logs);
                    if (!$logs) continue;   // walang history = hindi alam ang state sa bawat edad

                    $sub = Carbon::parse($o->submission_time, self::TZ);
                    $key = self::itemKey((string) $o->PAGE, (string) $o->ITEM_NAME, $aliases);
                    for ($age = 0; $age <= self::MAX_AGE; $age++) {
                        $cut = $sub->copy()->addDays($age);
                        if ($cut->gt($now)) break;
                        if ($this->stateAt($logs, $cut->toDateTimeString()) !== 'T') continue;
                        $rts = $outcome === 'D' ? 0 : 1;
                        $pooled[$age][0] = ($pooled[$age][0] ?? 0) + 1;
                        $pooled[$age][1] = ($pooled[$age][1] ?? 0) + $rts;
                        $items[$key][$age][0] = ($items[$key][$age][0] ?? 0) + 1;
                        $items[$key][$age][1] = ($items[$key][$age][1] ?? 0) + $rts;
                    }
                }
            }, 'j.id', 'id');

        $cell = fn ($c) => ['p' => round($c[1] / $c[0], 4), 'n' => $c[0]];
        $outItems = [];
        foreach ($items as $key => $byAge) {
            foreach ($byAge as $age => $c) {
                if ($c[0] >= self::MIN_ITEM_SAMPLES) $outItems[$key][$age] = $cell($c);
            }
        }
        $table = ['learned_at' => $now->toDateTimeString(), 'pooled' => array_map($cell, $pooled), 'items' => $outItems];

        // Kasya sa TEXT: ibaba muna ang mga item na pinakakaunti ang sample.
        while (strlen(json_encode($table)) > self::MAX_JSON && $table['items']) {
            $least = array_keys($table['items']);
            usort($least, fn ($a, $b) => array_sum(array_column($table['items'][$a], 'n')) <=> array_sum(array_column($table['items'][$b], 'n')));
            unset($table['items'][$least[0]]);
        }
        AppSetting::set(self::KEY, json_encode($table));

        return $table;
    }

    /**
     * Isang row bawat page+item ng mga order na ts_date nasa $from..$to. Key: "page||canonical item".
     */
    public function rows(string $from, string $to): array
    {
        $now = Carbon::now(self::TZ);
        $rates = $this->rates();
        $aliases = new ItemAliasResolver();
        $rows = [];
        $seen = [];

        $q = DB::table('macro_output as mo')
            ->leftJoin('from_jnts as j', 'j.waybill_number', '=', 'mo.waybill')
            ->whereBetween('mo.ts_date', [$from, $to])
            ->whereNotNull('mo.waybill')->where('mo.waybill', '!=', '')
            ->select('mo.waybill', 'mo.PAGE', 'mo.ITEM_NAME', 'j.status', 'j.submission_time');

        foreach ($q->cursor() as $o) {
            if (isset($seen[$o->waybill])) continue;
            $seen[$o->waybill] = true;

            $key = self::itemKey((string) $o->PAGE, (string) $o->ITEM_NAME, $aliases);
            $r = &$rows[$key];
            $r ??= [
                'page' => trim((string) $o->PAGE), 'item' => trim((string) $o->ITEM_NAME),
                'orders' => 0, 'unmatched' => 0, 'matched' => 0, 'delivered' => 0, 'returned' => 0, 'for_return' => 0,
                'in_transit' => 0, 'transit_age_sum' => 0, 'resolved_old' => 0, 'returned_old' => 0, 'transit_rts' => 0.0, 'no_rate' => false,
            ];
            $r['orders']++;

            $class = self::statusClass($o->status);
            if ($class === null) {
                $r['unmatched']++;
                unset($r);
                continue;
            }
            $r['matched']++;
            $age = $o->submission_time ? max(0, (int) floor(($now->timestamp - Carbon::parse($o->submission_time, self::TZ)->timestamp) / 86400)) : 0;
            if ($class === 'D') $r['delivered']++;
            if ($class === 'R') $r['returned']++;
            if ($class === 'F') $r['for_return']++;
            if ($class === 'T') {
                $r['in_transit']++;
                $r['transit_age_sum'] += $age;
                $p = $this->p($rates, $key, $age);
                if ($p === null) $r['no_rate'] = true; else $r['transit_rts'] += $p;
            }
            if (($class === 'D' || $class === 'R') && $age >= self::MAX_AGE) {
                $r['resolved_old']++;
                if ($class === 'R') $r['returned_old']++;
            }
            unset($r);
        }

        $set = $this->setRts();
        $by = $this->lastSetBy();
        foreach ($rows as $key => &$r) {
            $m = $r['matched'];
            $r['projected_rts'] = ($m > 0 && !$r['no_rate'])
                ? round(($r['returned'] + $r['for_return'] + $r['transit_rts']) / $m * 100, 2) : null;
            $r['in_transit_pct'] = $m > 0 ? round($r['in_transit'] / $m * 100, 2) : null;
            $r['actual_rts'] = $r['resolved_old'] > 0 ? round($r['returned_old'] / $r['resolved_old'] * 100, 2) : null;
            $r['error_pts'] = self::errorPts($r['in_transit'] > 0 ? $r['transit_age_sum'] / $r['in_transit'] : self::MAX_AGE);
            $r['set_rts'] = $set[$key] ?? null;
            $r['gap'] = ($r['set_rts'] !== null && $r['projected_rts'] !== null) ? round($r['set_rts'] - $r['projected_rts'], 2) : null;
            $r['flag'] = $r['gap'] !== null && abs($r['gap']) > self::FLAG_POINTS && $m >= self::MIN_MATCHED_FLAG;
            $r['set_by'] = $by[$key]['by'] ?? null;
            $r['set_at'] = $by[$key]['at'] ?? null;
            unset($r['transit_age_sum'], $r['resolved_old'], $r['returned_old'], $r['transit_rts'], $r['no_rate']);
        }
        unset($r);

        return $rows;
    }

    /** Karaniwang error (puntos) ng projection ayon sa average na edad ng mga in transit, mula sa backtest: ~3 sa day 3, ~1 sa day 5, ~0.5 sa day 7 pataas. */
    public static function errorPts(float $meanAge): float
    {
        return $meanAge <= 4 ? 3.0 : ($meanAge <= 6 ? 1.0 : 0.5);
    }

    /** p ng RTS para sa In Transit na order na $age araw na: rate ng item, kung wala ay pooled (pinakamalapit na mas bata). */
    private function p(array $rates, string $key, int $age): ?float
    {
        $age = min($age, self::MAX_AGE);
        if (isset($rates['items'][$key][$age])) return (float) $rates['items'][$key][$age]['p'];
        for ($a = $age; $a >= 0; $a--) {
            if (isset($rates['pooled'][$a])) return (float) $rates['pooled'][$a]['p'];
        }
        for ($a = $age + 1; $a <= self::MAX_AGE; $a++) {
            if (isset($rates['pooled'][$a])) return (float) $rates['pooled'][$a]['p'];
        }

        return null;
    }

    /** status_logs → listahan ng [to, batch_at] na nakaayos ayon sa batch_at. */
    private function logs(?string $json): array
    {
        $j = json_decode((string) $json, true);
        $out = [];
        foreach (is_array($j) ? $j : [] as $e) {
            if (is_array($e) && !empty($e['batch_at']) && !empty($e['to'])) $out[] = [(string) $e['to'], (string) $e['batch_at']];
        }
        usort($out, fn ($a, $b) => strcmp($a[1], $b[1]));

        return $out;
    }

    /** State (D/R/F/T) sa oras na $at: ang huling log na hindi lampas dito; walang log pa = T (kakahulog pa lang). */
    private function stateAt(array $logs, string $at): string
    {
        $state = 'T';
        foreach ($logs as [$to, $batchAt]) {
            if ($batchAt > $at) break;
            $state = self::statusClass($to) ?? $state;
        }

        return $state;
    }

    /** Kasalukuyang Set RTS% bawat page+item (pinakabagong effective_date na ≤ ngayon, may RTS). */
    private function setRts(): array
    {
        if (!Schema::hasTable('page_item_settings')) return [];
        $aliases = new ItemAliasResolver();
        $out = [];
        $q = DB::table('page_item_settings')->whereNotNull('rts_pct')
            ->where('effective_date', '<=', Carbon::now(self::TZ)->toDateString())
            ->orderByDesc('effective_date')->orderByDesc('id')
            ->select('page_name', 'item_name', 'rts_pct');
        foreach ($q->cursor() as $s) {
            $out[self::itemKey((string) $s->page_name, (string) $s->item_name, $aliases)] ??= (float) $s->rts_pct;
        }

        return $out;
    }

    /** Huling nag-set (tunay na nagbago ang RTS, hindi carry ng promo/remark save) bawat page+item, mula sa log. */
    private function lastSetBy(): array
    {
        if (!Schema::hasTable('page_item_settings_log')) return [];
        $aliases = new ItemAliasResolver();
        $hasScope = Schema::hasColumn('page_item_settings_log', 'scope');
        $select = ['l.page_name', 'l.item_name', 'l.old_rts_pct', 'l.new_rts_pct', 'l.user_email', 'l.created_at',
            DB::raw('COALESCE(ep.name, u.name) AS user_name')];
        if ($hasScope) $select[] = 'l.scope';

        $out = [];
        $q = DB::table('page_item_settings_log as l')
            ->leftJoin('users as u', 'u.email', '=', 'l.user_email')
            ->leftJoin('employee_profiles as ep', 'ep.user_id', '=', 'u.id')
            ->whereNotNull('l.new_rts_pct')
            ->orderByDesc('l.id')
            ->select($select);
        foreach ($q->cursor() as $l) {
            if ($l->old_rts_pct !== null && (float) $l->old_rts_pct === (float) $l->new_rts_pct) continue;
            if ($hasScope && in_array((string) ($l->scope ?? ''), ['promo', 'cogs'], true)) continue;
            $key = self::itemKey((string) $l->page_name, (string) $l->item_name, $aliases);
            if (isset($out[$key])) continue;
            $name = trim((string) ($l->user_name ?? ''));
            if ($name === '') $name = trim(explode('@', (string) $l->user_email)[0]);
            $out[$key] = ['by' => $name, 'at' => (string) $l->created_at];
        }

        return $out;
    }
}
