<?php

namespace App\Services\MetaAds;

use App\Models\AppSetting;
use App\Models\UploadLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Ang kalusugan ng data ng Ads Manager report (ads_manager_reports):
 * - ang coverage: bawat ad account at kung may data kahapon (evaluate; ang 12:00 na check ay record, nasa app_settings);
 * - ang mga row na tinanggihan dahil bumaba ang numero. Ang bawat upload o Sync run na may tinanggihan ay may isang
 *   upload_logs row (type ads_manager_refused, path "log:<id>" ng pinanggalingan, error_rows = ilan,
 *   protected_details = source, log_id, count, rows).
 * Walang bagong table.
 */
class DataHealth
{
    public const REFUSED_TYPE = 'ads_manager_refused';
    /** Ilang tinanggihang row ang itinatabi ang detalye kada upload o run (lahat ay binibilang pa rin). */
    public const LIST_MAX = 200;

    /** Ang huling naitalang check (JSON ng evaluate + trigger) at ang unang kita ng bawat account sa connection (id => Y-m-d). */
    public const LAST = 'meta_ads.data_health';
    public const FIRST_SEEN = 'meta_ads.first_seen';

    public const OK = 'OK';
    public const MISSING = 'Missing yesterday';
    public const NEW = 'New';
    public const NOT_READABLE = 'Not readable by API';
    public const UNTICKED = 'Not synced (unticked)';
    public const QUIET = 'No recent spend';
    /** Ang mga status na nagpapakita ng pulang paalala. */
    public const ATTENTION = [self::MISSING, self::NEW, self::NOT_READABLE];

    /** "Missing yesterday": may spend sa alinman sa 3 araw bago kahapon. "New": unang kita sa huling 3 araw (kasama ngayon). */
    public const MISSING_DAYS = 3;
    public const NEW_DAYS = 3;
    /** Ilang araw pabalik ang binabasa sa ads_manager_reports (latest day, unang kita mula sa upload). */
    public const WINDOW_DAYS = 60;

    /**
     * Ang coverage ng isang araw ($day, default kahapon sa Manila): bawat ad account na kilala mula sa mga connection, sa
     * ad_accounts (/ads_manager/ad_account) at sa mga upload (ads_manager_reports.ad_id → ad_campaign_creatives.account_id;
     * ang hindi alam ay "Account unknown", id ''). $remember = itabi ang unang kita ng bagong account sa connection
     * (FIRST_SEEN); false para sa read-only na `ads:data-health`.
     * @return array{day:string, checked_at:string, attention:int, accounts:list<array>, refused:array{yesterday:int, today:int}}
     */
    public static function evaluate(?string $day = null, bool $remember = true): array
    {
        $today = Carbon::now('Asia/Manila')->startOfDay();
        $d     = $day ? Carbon::parse($day, 'Asia/Manila')->startOfDay() : $today->copy()->subDay();
        $D     = $d->format('Y-m-d');

        $connections = app(Connections::class);
        $all      = $connections->all();
        $api      = $connections->accounts($all); // id => [name (176), who]
        $selected = array_flip(Sync::accounts(Sync::current($all)));
        $upload   = self::uploads($d);
        [$book, $bookSince] = self::book();
        $firstSeen = self::firstSeen($all, $today, $remember);

        $apiIds = array_map('strval', array_keys($api));
        $ids    = array_values(array_unique(array_merge($apiIds, array_map('strval', array_keys($book)), array_map('strval', array_keys($upload)))));
        $names  = Connections::names(array_fill_keys(array_values(array_diff($ids, $apiIds, [''])), ''));
        $newFrom = $today->copy()->subDays(self::NEW_DAYS - 1)->format('Y-m-d');
        // Incepxion: walang Meta Ads API connection (upload lang ang ads data), kaya walang "Not readable by API".
        $apiOn   = $all !== [];

        $out = [];
        foreach ($ids as $id) {
            $u     = $upload[$id] ?? null;
            $inApi = isset($api[$id]);
            $first = min(array_filter([$u['first_day'] ?? null, $bookSince[$id] ?? null, $firstSeen[$id] ?? null]) ?: [$today->format('Y-m-d')]);
            $hasY  = ($u['rows'] ?? 0) > 0;

            // Ang upload ang batayan: "Not readable by API" lang kapag may upload rows sa araw na iyon at hindi ito mabasa ng
            // API (bago man); ang hindi mabasa na walang row ay "No recent spend", walang paalala (kahit bagong idinagdag sa
            // /ads_manager/ad_account).
            $status = match (true) {
                !$hasY && ($u['prior'] ?? 0) > 0      => self::MISSING,
                $hasY && !$inApi && $id !== '' && $apiOn => self::NOT_READABLE,
                $inApi && $first >= $newFrom          => self::NEW,
                $hasY                                 => self::OK,
                $inApi && !isset($selected[$id])      => self::UNTICKED,
                default                               => self::QUIET,
            };
            $out[] = [
                'id'         => $id,
                'name'       => $id === '' ? 'Account unknown' : ($inApi ? $api[$id]['name'] : ($names[$id] ?? $id)),
                'source'     => $inApi ? (isset($selected[$id]) ? 'API selected' : 'API unticked') : ($u ? 'upload only' : 'none'),
                'latest_day' => $u['last_day'] ?? null,
                'rows'       => (int) ($u['rows'] ?? 0),
                'spend'      => round((float) ($u['spend'] ?? 0), 2),
                'first_seen' => $first,
                'status'     => $status,
            ];
        }
        $rank = array_flip([self::MISSING, self::NOT_READABLE, self::NEW, self::UNTICKED, self::QUIET, self::OK]);
        usort($out, fn ($a, $b) => [$rank[$a['status']], $a['id'] === '', $a['name']] <=> [$rank[$b['status']], $b['id'] === '', $b['name']]);

        return [
            'day'        => $D,
            'checked_at' => Carbon::now('Asia/Manila')->format('Y-m-d H:i'),
            'attention'  => count(array_filter($out, fn ($a) => in_array($a['status'], self::ATTENTION, true))),
            'accounts'   => $out,
            'refused'    => ['yesterday' => self::refusedOn($today->copy()->subDay()), 'today' => self::refusedOn($today)],
        ];
    }

    /** Ang pinagmulan ng naitabing check: ang 12:00 na schedule (o ang command), o pagkatapos ng isang natapos na upload. */
    public const TRIGGER_SCHEDULED = 'scheduled';
    public const TRIGGER_UPLOAD = 'upload';

    /**
     * Ang naitatalang check: sinusuri ang kahapon at itinatabi sa app_settings (LAST), kasama ang trigger. Tumatakbo sa
     * 12:00 (scheduled) at pagkatapos ng bawat natapos na Ads Manager upload (upload); ang huli ang nananaig.
     */
    public static function record(?string $day = null, string $trigger = self::TRIGGER_SCHEDULED): array
    {
        $result = self::evaluate($day) + ['trigger' => $trigger];
        AppSetting::set(self::LAST, json_encode($result));

        return $result;
    }

    /** Ang huling naitabing check, o null. */
    public static function last(): ?array
    {
        try {
            $v = json_decode((string) AppSetting::get(self::LAST), true);
        } catch (Throwable) {
            return null; // wala pang app_settings table
        }

        return is_array($v) ? $v : null;
    }

    /**
     * Ang mga upload sa huling WINDOW_DAYS na araw hanggang $d, kada account (walang act_):
     * @return array<string, array{first_day:string, last_day:string, rows:int, spend:float, prior:float}> rows/spend = sa $d;
     *         prior = spend sa MISSING_DAYS na araw bago ang $d
     */
    private static function uploads(Carbon $d): array
    {
        $D     = $d->format('Y-m-d');
        $prior = $d->copy()->subDays(self::MISSING_DAYS)->format('Y-m-d');
        // Ang account ng row: ang ad_campaign_creatives ng ad, saka ang ng campaign nito (unique ang campaign_id doon, kaya
        // isang ad lang kada campaign ang may sariling row). Kada row muna sa subquery, saka group sa labas (mysql at pgsql).
        $inner = DB::table('ads_manager_reports as r')
            ->where('r.day', '>=', $d->copy()->subDays(self::WINDOW_DAYS)->format('Y-m-d'))
            ->where('r.day', '<', $d->copy()->addDay()->format('Y-m-d'))
            ->select(['r.day', 'r.amount_spent_php']);
        if (Schema::hasTable('ad_campaign_creatives')) {
            $inner->selectRaw("COALESCE((SELECT MAX(c.account_id) FROM ad_campaign_creatives c WHERE c.ad_id = r.ad_id),
                (SELECT MAX(c2.account_id) FROM ad_campaign_creatives c2 WHERE c2.campaign_id = r.campaign_id), '') AS acc");
        } else {
            $inner->selectRaw("'' AS acc");
        }
        $rows = DB::query()->fromSub($inner, 'x')->selectRaw("acc, MIN(day) AS first_day, MAX(day) AS last_day,
                SUM(CASE WHEN day >= ? THEN 1 ELSE 0 END) AS y_rows,
                SUM(CASE WHEN day >= ? THEN COALESCE(amount_spent_php, 0) ELSE 0 END) AS y_spend,
                SUM(CASE WHEN day >= ? AND day < ? THEN COALESCE(amount_spent_php, 0) ELSE 0 END) AS prior_spend", [$D, $D, $prior, $D])
            ->groupBy('acc')->get();

        $out = [];
        foreach ($rows as $r) {
            $id = self::bare($r->acc);
            $f  = substr((string) $r->first_day, 0, 10);
            $l  = substr((string) $r->last_day, 0, 10);
            $u  = $out[$id] ?? ['first_day' => $f, 'last_day' => $l, 'rows' => 0, 'spend' => 0.0, 'prior' => 0.0];
            $out[$id] = [
                'first_day' => min($u['first_day'], $f),
                'last_day'  => max($u['last_day'], $l),
                'rows'      => $u['rows'] + (int) $r->y_rows,
                'spend'     => $u['spend'] + (float) $r->y_spend,
                'prior'     => $u['prior'] + (float) $r->prior_spend,
            ];
        }

        return $out;
    }

    /** @return array{0: array<string, string>, 1: array<string, string>} active ad_accounts id => pangalan; id => pinakaunang petsa */
    private static function book(): array
    {
        $names = $since = [];
        if (!Schema::hasTable('ad_accounts')) return [$names, $since];
        foreach (DB::table('ad_accounts')->orderBy('id')->get(['ad_account_id', 'name', 'active', 'created_at']) as $r) {
            $id = self::bare($r->ad_account_id);
            if ($id === '') continue;
            $day = substr((string) $r->created_at, 0, 10);
            if ($day !== '' && (!isset($since[$id]) || $day < $since[$id])) $since[$id] = $day;
            if ($r->active) $names[$id] ??= trim((string) $r->name);
        }

        return [$names, $since];
    }

    /**
     * Unang kita ng bawat account sa connection: ang naitabi; ang bago ay ngayon. Sa pinakaunang check (wala pang naitabi),
     * ang connected_at ng connection na nakakabasa nito, para hindi "New" ang lahat sa unang araw.
     * @return array<string, string> id => Y-m-d
     */
    private static function firstSeen(array $all, Carbon $today, bool $remember): array
    {
        $stored = json_decode((string) AppSetting::get(self::FIRST_SEEN), true);
        $seen = is_array($stored) ? $stored : [];
        $changed = false;
        foreach ($all as $c) {
            $since = !is_array($stored) && !empty($c['connected_at'])
                ? Carbon::parse($c['connected_at'])->timezone('Asia/Manila')->format('Y-m-d') : $today->format('Y-m-d');
            foreach ($c['ad_accounts'] ?? [] as $a) {
                $id = self::bare($a['id'] ?? '');
                if ($id === '' || (isset($seen[$id]) && $seen[$id] <= $since)) continue;
                $seen[$id] = $since;
                $changed = true;
            }
        }
        if ($remember && $changed) AppSetting::set(self::FIRST_SEEN, json_encode($seen));

        return $seen;
    }

    /** Ilang row ang tinanggihan (lower values) sa araw na iyon (Manila, kung kailan dumating ang upload o run). */
    private static function refusedOn(Carbon $day): int
    {
        $tz = (string) config('app.timezone');

        return (int) UploadLog::where('type', self::REFUSED_TYPE)
            ->where('created_at', '>=', $day->copy()->timezone($tz)->format('Y-m-d H:i:s'))
            ->where('created_at', '<', $day->copy()->addDay()->timezone($tz)->format('Y-m-d H:i:s'))
            ->sum('error_rows');
    }

    private static function bare(mixed $id): string
    {
        return (string) preg_replace('/^act_/', '', trim((string) $id));
    }

    /**
     * @param string $kind upload | sync
     * @param list<array{day:string, ad_id:string, account:?string, metrics:array}> $rows ang refused ng importRows
     */
    public static function recordRefusals(UploadLog $source, string $kind, array $rows): void
    {
        if (!$rows) return;

        // Ang account na wala sa row (upload na walang Account ID): mula sa ad_campaign_creatives ng ad na iyon.
        $missing = array_values(array_unique(array_column(array_filter($rows, fn ($r) => ($r['account'] ?? null) === null), 'ad_id')));
        $known = [];
        if ($missing && Schema::hasTable('ad_campaign_creatives')) {
            foreach (array_chunk($missing, 500) as $chunk) {
                $known += DB::table('ad_campaign_creatives')->whereIn('ad_id', $chunk)->whereNotNull('account_id')->pluck('account_id', 'ad_id')->all();
            }
        }
        foreach ($rows as $i => $r) $rows[$i]['account'] = $r['account'] ?? (isset($known[$r['ad_id']]) ? (string) $known[$r['ad_id']] : null);

        $n = count($rows);
        UploadLog::create([
            'type' => self::REFUSED_TYPE, 'disk' => $kind === 'sync' ? 'api' : 'upload', 'path' => 'log:' . $source->id,
            'original_name' => mb_substr(self::label($n) . ' · #' . $source->id . ' ' . $source->original_name, 0, 250),
            'status' => 'done', 'user_id' => $source->user_id, 'processed_rows' => $n, 'error_rows' => $n,
            'started_at' => now(), 'finished_at' => now(),
            'protected_details' => ['source' => $kind, 'log_id' => $source->id, 'count' => $n, 'rows' => array_slice($rows, 0, self::LIST_MAX)],
        ]);
    }

    /** @return array<int, array{count:int, rows:list<array>}> source log id => ang mga tinanggihan (wala = walang key) */
    public static function refusals(array $logIds): array
    {
        $logIds = array_values(array_filter(array_map('intval', $logIds)));
        if (!$logIds) return [];

        $out = [];
        foreach (UploadLog::where('type', self::REFUSED_TYPE)->whereIn('path', array_map(fn ($id) => 'log:' . $id, $logIds))->get() as $r) {
            $d = (array) $r->protected_details;
            $out[(int) ($d['log_id'] ?? 0)] = ['count' => (int) $r->error_rows, 'rows' => (array) ($d['rows'] ?? [])];
        }

        return $out;
    }

    /** "1 row refused (lower values)" / "3 rows refused (lower values)" */
    public static function label(int $n): string
    {
        return $n . ' ' . ($n === 1 ? 'row' : 'rows') . ' refused (lower values)';
    }

    /** 1500 → "1,500"; 99.5 → "99.50" */
    public static function num(int|float $n): string
    {
        return number_format($n, floor($n) == $n ? 0 : 2);
    }
}
