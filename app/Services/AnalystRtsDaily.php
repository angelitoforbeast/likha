<?php

namespace App\Services;

use App\Http\Controllers\OwnerPrivateController;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Ang Mira RTS araw-araw: ang tantya ng FINAL RTS% ng bawat page+item (RtsProjection) ng mga order mula sa unang araw
 * ng nakaraang buwan hanggang ngayon, isinusulat bilang value ng analyst (source "auto") na may dahilan, at ang accuracy
 * ng tantya 7 at 14 araw na ang nakalipas laban sa totoong RTS ng parehong mga order ngayon.
 *
 * Walang bagong table: bawat araw ay may isang "rts_day:<item>" row (JSON: araw, range, tantya, mga input at accuracy)
 * sa page_settings ng analyst, katabi ng rts_pct at rts_reason; ang mga pinalitang row ang history.
 */
class AnalystRtsDaily
{
    public const DAY = 'rts_day:';
    public const RUN_AT = '06:00';
    public const AGES = [7, 14];          // ilang araw na ang tantyang sinusukat
    public const HISTORY_DAYS = 7;        // ang hover at ang overall ng feed: huling 7 araw
    private const TREND_DAYS = 21;
    private const TREND_PTS = 3.0;        // sinasabi lang ang huling 21 araw kapag lampas dito ang layo
    private const GAP_PTS = 3.0;          // sinasabi lang ang agwat sa Set RTS kapag ganito o higit
    private const NIGHT = ['03:00', '03:45'];   // night Astra step: hindi tumatakbo rito
    private const TZ = 'Asia/Manila';

    public function __construct(private ?RtsProjection $projection = null)
    {
        $this->projection ??= new RtsProjection();
    }

    public static function duringNightStep(Carbon $at): bool
    {
        $hm = $at->copy()->setTimezone(self::TZ)->format('H:i');

        return $hm >= self::NIGHT[0] && $hm < self::NIGHT[1];
    }

    /** Range ng tantya sa araw na iyon: unang araw ng nakaraang buwan hanggang sa araw na iyon. */
    public static function range(Carbon $day): array
    {
        return [$day->copy()->startOfMonth()->subMonthNoOverflow()->toDateString(), $day->toDateString()];
    }

    /**
     * Tantya bawat page+item sa pinakabagong presyo nito, walang isinusulat. $asOf: ang alam lang noong oras na iyon
     * (para sa replay), kasama ang pinakabagong presyo noon. Kulang sa order sa presyong iyon = estimate null.
     */
    public function estimates(?Carbon $asOf = null): array
    {
        $at = $asOf ?? Carbon::now(self::TZ);
        [$from, $to] = self::range($at);
        $out = [];
        foreach ($this->projection->rows($from, $to, $asOf, 'latest') as $key => $r) {
            if (self::enough($r) && $r['projected_rts'] === null) continue;
            $out[$key] = $r + ['estimate' => self::enough($r) ? self::half($r['projected_rts']) : null];
        }

        return $out;
    }

    /**
     * Sapat ang order sa pinakabagong presyo: kasing dami ng kailangan ng feed at ng kulay (MIN_MATCHED_FLAG). Kapag
     * kulang, walang tantya: mas masama ang maling numero kaysa "not enough yet", kaya walang balik sa lahat ng presyo.
     */
    public static function enough(array $r): bool
    {
        return $r['matched'] >= RtsProjection::MIN_MATCHED_FLAG;
    }

    /** Ang hover ng walang-tantya na cell. */
    public static function lowNote(?int $price, int $matched): string
    {
        return sprintf('Latest price %s: only %d orders, not enough yet', $price !== null ? '₱' . number_format($price) : 'unknown', $matched);
    }

    /**
     * Ang run ng 06:00. Bawat page+item na may tantya: ang day row ng araw na ito (isang beses lang bawat araw), at ang
     * auto na value at dahilan, maliban kung ang analyst ay nag-type na ngayong araw mula 06:00 (iyon ang naiiwan hanggang
     * sa susunod na run). Mga order lang sa pinakabagong presyo ng page+item; kulang = walang value (ang dating value at
     * dahilan ay hindi na active) at isang day row na may presyo at bilang lang.
     * @return array{written:int, kept:int, low:int, skipped:int, failed:int}
     */
    public function run(): array
    {
        $now = Carbon::now(self::TZ);
        $today = $now->toDateString();
        [$from, $to] = self::range($now);
        $sum = ['written' => 0, 'kept' => 0, 'low' => 0, 'skipped' => 0, 'failed' => 0];
        if (!Schema::hasTable('page_settings')) return $sum;

        $rows = $this->projection->rows($from, $to, null, 'latest');
        // Ang huling 21 araw ay sa parehong presyo, para pareho ang pinaghahambing ng dahilan.
        $trend = $this->projection->rows($now->copy()->subDays(self::TREND_DAYS - 1)->toDateString(), $to, null, array_map(fn ($r) => $r['price'], $rows));
        $acc = $this->accuracyFor($now);
        $doneToday = $this->dayRowsOn($today);
        $analystToday = $this->pctWrittenSince($now->copy()->setTimeFromTimeString(self::RUN_AT));
        $settings = new PageSettings();
        $shown = (new AnalystRts())->all();

        foreach ($rows as $key => $r) {
            $low = !self::enough($r);
            // Walang order na may J&T status at walang value na aalisin: walang masasabi, kaya walang row araw-araw.
            $nothing = $low && $r['matched'] === 0 && !isset($shown[$key]);
            if ((!$low && $r['projected_rts'] === null) || $nothing || isset($doneToday[$key])) {
                $sum['skipped']++;
                continue;
            }
            $canon = explode('||', $key, 2)[1];
            $m = $r['matched'];
            $day = $low ? [
                'd' => $today, 'from' => $from, 'to' => $to, 'price' => $r['price'], 'est' => null, 'm' => $m,
                'acc' => $acc[$key] ?? (object) [],   // ang sukat ng mga lumang tantya ay itinatala pa rin
            ] : [
                'd' => $today, 'from' => $from, 'to' => $to, 'price' => $r['price'], 'est' => self::half($r['projected_rts']),
                'proj' => $r['projected_rts'], 'm' => $m,
                'fin' => round(($m - $r['in_transit']) / $m * 100, 2),
                'rts' => round(($r['returned'] + $r['for_return']) / $m * 100, 2),
                'del' => round($r['delivered'] / $m * 100, 2),
                't' => $r['in_transit_pct'], 'age' => $r['in_transit_age'],
                'acc' => $acc[$key] ?? (object) [],
            ];
            $keep = isset($analystToday[$key]) && ($analystToday[$key]->source ?? null) !== 'auto';
            try {
                DB::transaction(function () use ($settings, $r, $canon, $day, $keep, $low, $trend, $key) {
                    $page = $r['page'];
                    $settings->set($page, AnalystRts::PROVIDER, self::DAY . $canon, json_encode($day), system: true, source: 'auto');
                    if ($keep) return;
                    if ($low) {
                        // Walang tantya sa kulang na order: ang lumang value (hal. mula sa lahat ng presyo) ay hindi na ipinapakita.
                        $old = $settings->pageQuery($page)->where('provider', AnalystRts::PROVIDER)
                            ->whereIn('key', [AnalystRts::PCT . $canon, AnalystRts::REASON . $canon])->where('active', true)->get();
                        foreach ($old as $s) $settings->deactivate($s, source: 'auto');
                        return;
                    }
                    $settings->set($page, AnalystRts::PROVIDER, AnalystRts::PCT . $canon, AnalystRts::format($day['est']), system: true, source: 'auto');
                    $settings->set($page, AnalystRts::PROVIDER, AnalystRts::REASON . $canon, self::reason($day, $trend[$key]['projected_rts'] ?? null, $r['set_rts']), system: true, source: 'auto');
                });
                $sum[$keep ? 'kept' : ($low ? 'low' : 'written')]++;
            } catch (\Throwable $e) {
                // Isang item lang ang pumalya (hal. lampas 100 character ang key): itinatala, at tuloy ang iba.
                $sum['failed']++;
                Log::warning('rts:daily-estimate: item failed', ['page' => $r['page'], 'item' => $r['item'], 'error' => $e->getMessage()]);
            }
        }
        if ($sum['written'] + $sum['kept'] + $sum['low'] > 0) OwnerPrivateController::bumpCacheVersion();

        return $sum;
    }

    /** Ang dahilan ng auto na value, Taglish, hanggang 500 character. */
    public static function reason(array $day, ?float $trendProj, ?float $setRts): string
    {
        $n = fn (float $v) => rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
        $s = sprintf('Auto %s: %d order na may J&T status%s (%s–%s). %s%% tapos na (RTS %s%%, delivered %s%%); %s%% nasa biyahe pa',
            self::RUN_AT, $day['m'], isset($day['price']) ? ' sa ₱' . number_format((int) $day['price']) : '', Carbon::parse($day['from'])->format('M j'), Carbon::parse($day['to'])->format('M j'),
            $n($day['fin']), $n($day['rts']), $n($day['del']), $n((float) $day['t']));
        if ($day['age'] !== null) $s .= ', avg ' . $n($day['age']) . ' araw';
        $s .= '. Tantya ng final RTS: ' . $n($day['est']) . '%.';
        if ($trendProj !== null && abs($trendProj - $day['proj']) > self::TREND_PTS) {
            $s .= sprintf(' Huling %d araw %s%% vs buong range %s%% (%s pts).', self::TREND_DAYS, $n($trendProj), $n($day['proj']),
                sprintf('%+.1f', $trendProj - $day['proj']));
        }
        if ($setRts !== null && abs($setRts - $day['est']) >= self::GAP_PTS) {
            $s .= sprintf(' Set RTS %s%%: %s pts ang agwat.', $n($setRts), sprintf('%+.1f', $setRts - $day['est']));
        }

        return mb_substr($s, 0, 500);
    }

    /**
     * Accuracy ng mga tantya 7 at 14 araw na ang nakalipas: itemKey => ["7" => entry, "14" => entry]. Ang tantya ng araw
     * na iyon = ang Mira RTS na nakatayo sa dulo ng araw na iyon (ang inayos ng analyst kung inayos, kung hindi ang auto);
     * ang actual = (Returned + For Return) ÷ mga tapos na, ng parehong mga order (ts_date sa range ng araw na iyon), ngayon.
     */
    private function accuracyFor(Carbon $now): array
    {
        $out = [];
        foreach (self::AGES as $age) {
            $d = $now->copy()->subDays($age)->toDateString();
            $days = $this->dayRowsOn($d);
            if (!$days) continue;
            $inForce = $this->pctWrittenOn($d);
            $sets = [];
            foreach ($days as $key => $day) $sets[$day['from'] . '|' . $day['to']][$key] = $day;
            foreach ($sets as $range => $group) {
                [$from, $to] = explode('|', $range);
                // Sinusukat sa presyong ginamit ng day row; ang mga row bago nagkaroon ng presyo ay sa lahat, gaya noong isinulat.
                $prices = [];
                foreach ($group as $key => $day) {
                    if (array_key_exists('price', $day)) $prices[$key] = $day['price'];
                }
                $current = $this->projection->rows($from, $to, null, $prices);
                foreach ($group as $key => $day) {
                    $r = $current[$key] ?? null;
                    if (!$r || $r['matched'] === 0) continue;
                    $est = isset($inForce[$key]) ? $inForce[$key]['pct'] : ($day['est'] ?? null);
                    if ($est === null) continue;   // walang tantya noong araw na iyon (kulang sa order)
                    $est = (float) $est;
                    $done = $r['delivered'] + $r['returned'] + $r['for_return'];
                    $act = $done > 0 ? round(($r['returned'] + $r['for_return']) / $done * 100, 2) : null;
                    $out[$key][(string) $age] = [
                        'd' => $d, 'est' => $est, 'src' => isset($inForce[$key]) ? $inForce[$key]['src'] : 'auto',
                        'act' => $act, 'fin' => round($done / $r['matched'] * 100, 2), 'm' => $r['matched'],
                        'err' => $act !== null ? round($est - $act, 2) : null,
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * Para sa /owner/private: itemKey => [e7, e14 (ng pinakabagong araw), hist = mga araw na may accuracy, pinakabago muna,
     * hanggang HISTORY_DAYS, price = ang presyong ginamit ng pinakabagong day row, note = ang "not enough yet" kapag
     * kulang ang order noon].
     */
    public function recent(): array
    {
        $out = [];
        foreach ($this->dayRowsSince(Carbon::now(self::TZ)->subDays(self::HISTORY_DAYS - 1)->startOfDay()) as $key => $byDay) {
            krsort($byDay);
            $hist = [];
            foreach ($byDay as $d => $day) {
                $acc = (array) ($day['acc'] ?? []);
                if (!$acc) continue;
                $hist[] = ['d' => $d] + array_combine(array_map(fn ($a) => 'a' . $a, array_keys($acc)), array_values($acc));
            }
            $last = reset($byDay);
            $price = isset($last['price']) ? (int) $last['price'] : null;
            $note = array_key_exists('price', $last) && ($last['est'] ?? null) === null ? self::lowNote($price, (int) ($last['m'] ?? 0)) : null;
            if (!$hist && $price === null && $note === null) continue;
            $out[$key] = ['e7' => $hist[0]['a7']['err'] ?? null, 'e14' => $hist[0]['a14']['err'] ?? null, 'hist' => $hist,
                'price' => $price, 'note' => $note];
        }

        return $out;
    }

    /** Para sa feed: bawat araw (huling HISTORY_DAYS), ang average na absolute error ng mga page+item na may ≥30 order sa set. */
    public function overall(): array
    {
        $byDate = [];
        foreach ($this->dayRowsSince(Carbon::now(self::TZ)->subDays(self::HISTORY_DAYS - 1)->startOfDay()) as $byDay) {
            foreach ($byDay as $d => $day) {
                foreach (self::AGES as $age) {
                    $a = ((array) ($day['acc'] ?? []))[(string) $age] ?? null;
                    if (!$a || $a['err'] === null || $a['m'] < RtsProjection::MIN_MATCHED_FLAG) continue;
                    $byDate[$d][$age][] = abs($a['err']);
                }
            }
        }
        krsort($byDate);
        $out = [];
        foreach ($byDate as $d => $ages) {
            $line = ['d' => (string) $d];
            foreach (self::AGES as $age) {
                $e = $ages[$age] ?? [];
                $line['mae_' . $age] = $e ? round(array_sum($e) / count($e), 2) : null;
                $line['n_' . $age] = count($e);
            }
            $out[] = $line;
        }

        return $out;
    }

    public static function half(float $pct): float
    {
        return round($pct * 2) / 2;
    }

    /** Mga day row (active man o pinalitan) na naisulat sa araw na iyon: itemKey => JSON. */
    private function dayRowsOn(string $date): array
    {
        $out = [];
        foreach ($this->dayRowsSince(Carbon::parse($date, self::TZ)->startOfDay(), Carbon::parse($date, self::TZ)->endOfDay()) as $key => $byDay) {
            if (isset($byDay[$date])) $out[$key] = $byDay[$date];
        }

        return $out;
    }

    /** itemKey => [d => JSON] ng mga day row na naisulat mula $since (hanggang $until). */
    private function dayRowsSince(Carbon $since, ?Carbon $until = null): array
    {
        if (!Schema::hasTable('page_settings')) return [];
        $q = DB::table('page_settings')->where('provider', AnalystRts::PROVIDER)->where('key', 'like', self::DAY . '%')
            ->where('created_at', '>=', $since->toDateTimeString());
        if ($until) $q->where('created_at', '<=', $until->toDateTimeString());
        $out = [];
        foreach ($q->orderBy('id')->get(['page_name', 'key', 'value']) as $r) {
            $j = json_decode((string) $r->value, true);
            if (!is_array($j) || empty($j['d'])) continue;
            $out[strtolower(trim((string) $r->page_name)) . '||' . substr($r->key, strlen(self::DAY))][$j['d']] = $j;
        }

        return $out;
    }

    /**
     * Ang Mira RTS na isinulat sa araw na iyon at nakatayo pa sa dulo nito, bawat page+item: itemKey => [pct, src]. Ang
     * pinalitan o inalis sa parehong araw (hal. inalis ng run dahil kulang ang order) ay hindi ang tantya ng araw na iyon.
     */
    private function pctWrittenOn(string $date): array
    {
        $out = [];
        $end = Carbon::parse($date, self::TZ)->endOfDay();
        foreach ($this->pctRows(Carbon::parse($date, self::TZ)->startOfDay(), $end, false, $end) as $key => $r) {
            $out[$key] = ['pct' => (float) $r->value, 'src' => $r->source ?? 'mira'];
        }

        return $out;
    }

    /** Mga page+item na ang ACTIVE na Mira RTS ay isinulat mula $since (ang tinype ng analyst ngayong araw). */
    private function pctWrittenSince(Carbon $since): array
    {
        return $this->pctRows($since, null, true);
    }

    /** itemKey => pinakabagong rts_pct row na isinulat sa pagitan. */
    private function pctRows(Carbon $since, ?Carbon $until = null, bool $activeOnly = false, ?Carbon $standingAt = null): array
    {
        $cols = ['page_name', 'key', 'value'];
        if (Schema::hasColumn('page_settings', 'source')) $cols[] = 'source';
        $q = DB::table('page_settings')->where('provider', AnalystRts::PROVIDER)->where('key', 'like', AnalystRts::PCT . '%')
            ->where('created_at', '>=', $since->toDateTimeString());
        if ($until) $q->where('created_at', '<=', $until->toDateTimeString());
        if ($activeOnly) $q->where('active', true);
        if ($standingAt) $q->where(fn ($w) => $w->whereNull('replaced_at')->orWhere('replaced_at', '>', $standingAt->toDateTimeString()));
        $out = [];
        foreach ($q->orderBy('id')->get($cols) as $r) {
            $out[strtolower(trim((string) $r->page_name)) . '||' . substr($r->key, strlen(AnalystRts::PCT))] = $r;
        }

        return $out;
    }
}
