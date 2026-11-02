<?php

namespace App\Services\Nexus;

use App\Services\ItemStockService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Nexus stock-in → nexus_stock_ins (isang row kada araw at pangalan). Isang GET /api/item-stock?date=D kada araw.
 *
 * Ang sagot ng Nexus (app/Services/ItemStock.php) ay naglilista ng bawat item na may stock-in sa araw na iyon O may laman
 * sa shelf ngayon; ang stock_in ng item ay listahan ng {date, lots, pieces} kada araw (o, sa lumang anyo, isang bilang).
 * Kaya ang item na wala sa sagot ng araw D ay walang dumating noong D: 0 para sa D, tamang datos kada araw.
 *
 * Mga araw na tinatanong: mula sa nexus_stock_synced_through (inuulit: "ngayon" pa iyon nang i-store) o mula sa
 * item_stock_start sa unang takbo, hanggang ngayon (Manila); laging kasama ang kahapon, at ang mga araw na bumagsak sa
 * naunang --from / --days na takbo (nexus_stock_pending_days). Ang sagot ng isang araw ang totoo para sa araw na iyon:
 * overwrite (hindi dagdag), at ang naka-store na item na wala sa sagot ay ginagawang 0 — maliban sa item na nilaktawan
 * dahil sira ang numero (nananatili ang dati). Sirang sagot (pati puro sirang item) = babala, walang nagbabago.
 *
 * Walang laman ang sagot (items: []) ng araw na may naka-store: HINDI kailanman kusang ginagawang 0. Nananatili ang dati,
 * itinatala ang araw sa nexus_stock_unconfirmed_empty (babala sa /item), at tuloy ang takbo (hindi ito pagkabigo).
 * Ang owner lang ang nagpapa-0 nito: nexus:sync-stock --accept-empty=YYYY-MM-DD. Walang binubura kahit kailan.
 *
 * Ang token ay nasa Authorization header lang: hindi ito nilo-log, iniimbak o ibinabalik.
 */
class StockInSync
{
    public const TABLE          = 'nexus_stock_ins';
    public const LOCK           = 'nexus:sync-stock';
    public const LAST_SYNCED    = 'nexus_stock_last_synced_at';
    public const SYNCED_THROUGH = 'nexus_stock_synced_through';
    public const LAST_ERROR     = 'nexus_stock_last_error';
    /** JSON na listahan ng mga araw na bumagsak (hal. sa --from o --days) at itatanong ulit ng susunod na takbo. */
    public const PENDING_DAYS   = 'nexus_stock_pending_days';
    /** JSON na listahan ng mga araw na may naka-store pero walang laman ang sagot ng Nexus (hindi pa tinatanggap). */
    public const UNCONFIRMED_EMPTY = 'nexus_stock_unconfirmed_empty';
    /** JSON {at, status: ok | skipped_locked} ng huling araw-araw na 7-day pass. */
    public const DAILY_PASS     = 'nexus_stock_daily_pass';
    private const MAX_COUNT     = 2147483647;
    private const TZ            = 'Asia/Manila';

    public static function configured(): bool
    {
        return trim((string) config('services.nexus.token')) !== '';
    }

    /** NFC, trim, isang space (kasama ang NBSP at iba pang Unicode space), lowercase: batayan ng name_key. */
    public static function normalizeName(string $name): string
    {
        $s = self::trimName($name);
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_C);
            if (is_string($n)) $s = $n;
        }
        $s = preg_replace('/[\s\p{Z}]+/u', ' ', $s) ?? $s;

        return mb_strtolower(trim($s), 'UTF-8');
    }

    /** trim() na kasama ang NBSP at iba pang Unicode space (\p{Z}); ang loob ng pangalan ay hindi ginagalaw. */
    public static function trimName(string $name): string
    {
        return preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $name) ?? trim($name);
    }

    public static function nameKey(string $name): string
    {
        return sha1(self::normalizeName($name));
    }

    /**
     * @return array{status:string, lines:array<int,string>}
     *   status: not_configured | no_table | no_start | bad_from | locked | ok | failed
     */
    public function run(?string $from = null, ?int $days = null): array
    {
        $guard = $this->guard($from);
        if ($guard !== null) return $guard;
        $start = (string) ItemStockService::startSetting();

        $lock = Cache::lock(self::LOCK, 1800);
        if (!$lock->get()) {
            if ($days !== null) {
                // Ang araw-araw na pass ay hindi tahimik na nawawala: itinatala, at lumalabas sa /item.
                $this->put(self::DAILY_PASS, json_encode(['at' => Carbon::now(self::TZ)->format('Y-m-d H:i:s'), 'status' => 'skipped_locked']));
                Log::warning('nexus:sync-stock daily pass skipped: another run holds the lock');
            }
            return ['status' => 'locked', 'lines' => ['Another nexus:sync-stock is already running: nothing asked.']];
        }
        try {
            $res = $this->sync($start, $from, $days);
            if ($days !== null) {
                $this->put(self::DAILY_PASS, json_encode(['at' => Carbon::now(self::TZ)->format('Y-m-d H:i:s'), 'status' => $res['status']]));
            }
            return $res;
        } finally {
            $lock->release();
        }
    }

    /**
     * --accept-empty: itanong ulit ang isang araw; kapag wala pa ring laman ang sagot, gawing 0 ang mga row ng araw na iyon
     * (bawat isa naka-log) at alisin sa listahan ng hindi kumpirmadong walang laman. May laman na = store gaya ng dati.
     *
     * @return array{status:string, lines:array<int,string>}
     */
    public function acceptEmpty(string $day): array
    {
        if (!self::validDate($day)) {
            return ['status' => 'bad_from', 'lines' => ['--accept-empty must be a real date (Y-m-d). Nothing asked.']];
        }
        $guard = $this->guard(null);
        if ($guard !== null) return $guard;
        $start = (string) ItemStockService::startSetting();
        if ($day < $start || $day > Carbon::now(self::TZ)->toDateString()) {
            return ['status' => 'bad_from', 'lines' => ["$day is outside the stock start ($start) to today. Nothing asked."]];
        }

        $lock = Cache::lock(self::LOCK, 1800);
        if (!$lock->get()) {
            return ['status' => 'locked', 'lines' => ['Another nexus:sync-stock is already running: nothing asked.']];
        }
        try {
            $res = $this->day($day);
            if (!$res['ok']) {
                return ['status' => 'failed', 'lines' => ["$day: {$res['message']}. Nothing changed; the day stays on the list."]];
            }
            if (!empty($res['empty_stored'])) {
                $n = DB::transaction(fn () => $this->store($day, [], null, []));
                $this->dropUnconfirmed([$day]);
                return ['status' => 'ok', 'lines' => ["$day: Nexus still answers empty; $n stored items set to 0."]];
            }
            $this->dropUnconfirmed([$day]);
            return ['status' => 'ok', 'lines' => ["$day: Nexus answered {$res['rows']} items; stored as usual, nothing set to 0 for being empty."]];
        } finally {
            $lock->release();
        }
    }

    /** Ang mga araw na walang laman ang sagot pero may naka-store (para sa /item). @return array<int,string> */
    public static function unconfirmedEmptyDays(?string $raw): array
    {
        $days = array_values(array_filter((array) json_decode((string) $raw, true), fn ($d) => is_string($d) && self::validDate($d)));
        sort($days);

        return $days;
    }

    private function guard(?string $from): ?array
    {
        if (!self::configured()) {
            return ['status' => 'not_configured', 'lines' => ['Nexus not configured (services.nexus.token is empty): nothing asked, nothing changed.']];
        }
        if (!Schema::hasTable(self::TABLE) || !Schema::hasTable('app_settings')) {
            return ['status' => 'no_table', 'lines' => ['The nexus_stock_ins table is missing: run the migration first. Nothing asked.']];
        }
        if (ItemStockService::startSetting() === null) {
            return ['status' => 'no_start', 'lines' => ['No valid item_stock_start in app_settings: nothing asked, nothing changed.']];
        }
        if ($from !== null && !self::validDate($from)) {
            return ['status' => 'bad_from', 'lines' => ['--from must be a real date (Y-m-d). Nothing asked.']];
        }

        return null;
    }

    private function sync(string $start, ?string $from, ?int $days): array
    {
        $now       = Carbon::now(self::TZ);
        $today     = $now->toDateString();
        $yesterday = $now->copy()->subDay()->toDateString();
        $through   = $this->setting(self::SYNCED_THROUGH);
        $through   = self::validDate((string) $through) ? (string) $through : null;

        $first = $from ?? ($through !== null ? min($through, $yesterday) : $start);
        // --days=N: ang huling N araw bago ngayon (para sa stock-in na inilagay ng warehouse sa nakaraang petsa).
        if ($days !== null && $days > 0) $first = min($first, $now->copy()->subDays($days)->toDateString());
        $first = max($first, $start);
        if ($first > $today) {
            return ['status' => 'ok', 'lines' => ["The stock start ($start) is after today: nothing to ask."]];
        }

        // Mga araw na bumagsak sa naunang takbo (labas sa karaniwang hanay) ay itinatanong muna.
        $pending = array_values(array_filter(
            (array) json_decode((string) $this->setting(self::PENDING_DAYS), true),
            fn ($d) => is_string($d) && self::validDate($d) && $d >= $start && $d < $first
        ));
        $askDays = array_values(array_unique(array_merge(
            $pending, array_map(fn ($d) => $d->toDateString(), iterator_to_array(CarbonPeriod::create($first, $today)))
        )));
        sort($askDays);

        $lines      = [];
        $failure    = null;      // unang error (ito ang itinatala)
        $mainFailed = false;     // may bumagsak na sa karaniwang hanay ($first..ngayon)
        $contiguous = null;      // huling araw na tuloy-tuloy na OK mula sa $first
        $stored     = 0;
        $failedDays = [];
        $okDays     = [];
        $emptyDays  = [];        // may naka-store pero walang laman ang sagot: nananatili ang dati
        $filledDays = [];        // may laman ang sagot: hindi na "walang laman"
        $skipped    = 0;         // nilaktawang item (sirang numero o pangalan) sa buong takbo
        $skipNames  = [];

        foreach ($askDays as $i => $day) {
            $res = $this->day($day);
            if ($res['ok']) {
                $okDays[] = $day;
                $stored += $res['rows'];
                $skipped += $res['skipped'];
                foreach ($res['skipped_names'] ?? [] as $n) {
                    if (count($skipNames) < 5 && !in_array($n, $skipNames, true)) $skipNames[] = $n;
                }
                if ($day >= $first && !$mainFailed) $contiguous = $day;
                if (!empty($res['empty_stored'])) {
                    $emptyDays[] = $day;
                    $lines[] = "$day: Nexus returned nothing; the stored numbers are kept (unconfirmed empty day)";
                } else {
                    if ($res['rows'] > 0) $filledDays[] = $day;
                    $lines[] = "$day: {$res['rows']} items" . ($res['skipped'] ? ", {$res['skipped']} skipped" : '') . ($res['zeroed'] ? ", {$res['zeroed']} set to 0" : '');
                }
                continue;
            }
            $failedDays[] = $day;
            if ($day >= $first) $mainFailed = true;
            $failure ??= $res + ['day' => $day];
            $lines[] = "$day: {$res['message']}";
            if ($res['stop']) {
                // Ang mga araw na hindi na naitanong (hal. pagkatapos ng 429) ay bumabagsak din, para mapunta sa pending.
                $failedDays = array_merge($failedDays, array_slice($askDays, $i + 1));
                break;
            }
        }

        // Ang listahan ng hindi kumpirmadong walang laman: dagdag ang bago, alis ang nagkaroon na ng laman.
        $list = array_values(array_diff(
            array_unique(array_merge(self::unconfirmedEmptyDays($this->setting(self::UNCONFIRMED_EMPTY)), $emptyDays)),
            $filledDays
        ));
        sort($list);
        $this->put(self::UNCONFIRMED_EMPTY, json_encode($list));
        if ($emptyDays) {
            Log::warning('nexus:sync-stock Nexus returned nothing for days that have stored items; kept', ['days' => $emptyDays]);
        }

        $newThrough = $through;
        if ($contiguous !== null && ($through === null || $contiguous > $through)) {
            $newThrough = $contiguous;
            $this->put(self::SYNCED_THROUGH, $contiguous);
        }
        // Ang bumagsak na araw na hindi na sakop ng karaniwang hanay ng susunod na takbo ay itinatabi para itanong ulit.
        $nextFirst = $newThrough === null ? $start : min($newThrough, $yesterday);
        $keep = array_values(array_filter(
            array_diff(array_unique(array_merge($pending, $failedDays)), $okDays),
            fn ($d) => $d < $nextFirst
        ));
        sort($keep);
        $this->put(self::PENDING_DAYS, json_encode($keep));
        if ($failure === null) {
            $this->put(self::LAST_SYNCED, $now->format('Y-m-d H:i:s'));
            // Matagumpay ang takbo; pero ang nilaktawang item ay babala na makikita sa /item (nawawala sa takbong walang laktaw).
            $this->put(self::LAST_ERROR, $skipped > 0 ? json_encode([
                'at' => $now->format('Y-m-d H:i:s'), 'kind' => 'skipped', 'count' => $skipped, 'names' => $skipNames,
                'message' => "$skipped Nexus items skipped for bad values" . ($skipNames ? ': ' . implode(', ', $skipNames) : ''),
            ], JSON_UNESCAPED_UNICODE) : '');
            $lines[] = "Nexus stock-in synced $first to $today ($stored rows" . ($skipped ? ", $skipped skipped" : '') . ').';
            return ['status' => 'ok', 'lines' => $lines];
        }

        $this->put(self::LAST_ERROR, json_encode([
            'at' => $now->format('Y-m-d H:i:s'), 'kind' => $failure['kind'], 'message' => $failure['message'], 'day' => $failure['day'],
        ]));
        Log::warning('nexus:sync-stock not complete', ['kind' => $failure['kind'], 'day' => $failure['day'], 'from' => $first, 'to' => $today]);
        $lines[] = "Not complete: {$failure['kind']} on {$failure['day']}; the failed days are asked again next run.";

        return ['status' => 'failed', 'lines' => $lines];
    }

    /** @param array<int,string> $days */
    private function dropUnconfirmed(array $days): void
    {
        $list = array_values(array_diff(self::unconfirmedEmptyDays($this->setting(self::UNCONFIRMED_EMPTY)), $days));
        $this->put(self::UNCONFIRMED_EMPTY, json_encode($list));
    }

    /** Isang araw: tanong, suri, at store. Ang walang-lamang sagot ng naka-store na araw ay hindi ginagalaw (empty_stored). */
    private function day(string $day): array
    {
        $fail = fn (string $kind, string $message, bool $stop = false) => ['ok' => false, 'kind' => $kind, 'message' => $message, 'stop' => $stop];

        try {
            $resp = Http::withToken((string) config('services.nexus.token'))
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(max(1, (int) config('services.nexus.timeout', 20)))
                ->get(rtrim((string) config('services.nexus.url'), '/') . '/api/item-stock', ['date' => $day]);
        } catch (ConnectionException $e) {
            return $fail('timeout', 'Nexus did not answer (timeout or no connection)');
        }

        $status = $resp->status();
        if ($status === 401 || $status === 403) return $fail('unauthorized', "Nexus refused the token (HTTP $status)", true);
        // 60 tawag kada oras kada token sa Nexus: ang kasunod na araw ay tatanggihan din, kaya titigil na.
        if ($status === 429) return $fail('rate_limited', 'Nexus says too many calls (HTTP 429); the rest is asked next run', true);
        if ($status !== 200) return $fail('http_error', "Nexus answered HTTP $status");

        $json = json_decode($resp->body(), true);
        if (!is_array($json) || !isset($json['items']) || !is_array($json['items']) || !array_is_list($json['items'])) {
            return $fail('malformed', 'Nexus answer is not the expected JSON');
        }
        foreach (['from', 'to', 'date'] as $f) {
            if (isset($json[$f]) && substr((string) $json[$f], 0, 10) !== $day) {
                return $fail('malformed', "Nexus answered for another date ($f)");
            }
        }

        [$items, $skipped, $protected, $skipNames] = $this->parseItems($json['items'], $day);
        if ($json['items'] !== [] && !$items) {
            // May laman pero lahat ay sira: hindi ito "walang dumating".
            return $fail('malformed', 'Every item in the Nexus answer is invalid');
        }
        if (!$items) {
            $stored = DB::table(self::TABLE)->where('stock_date', $day)
                ->where(fn ($q) => $q->where('stock_in_pieces', '>', 0)->orWhere('stock_in_lots', '>', 0))->exists();

            // Walang naka-store: normal na araw. May naka-store: nananatili (owner lang ang nagpapa-0, --accept-empty).
            return ['ok' => true, 'rows' => 0, 'skipped' => 0, 'zeroed' => 0, 'empty_stored' => $stored];
        }

        $asOf   = $this->asOf($json['as_of'] ?? null);
        $zeroed = DB::transaction(fn () => $this->store($day, $items, $asOf, $protected));

        return ['ok' => true, 'rows' => count($items), 'skipped' => $skipped, 'skipped_names' => $skipNames, 'zeroed' => $zeroed];
    }

    /**
     * @return array{0: array<string,array>, 1: int, 2: array<int,string>, 3: array<int,string>}
     *   name_key => row; ilan ang nilaktawan; name_key ng mga nilaktawan na may maayos na pangalan (hindi ginagawang 0);
     *   ang mga pangalan ng nilaktawan (para sa babala)
     */
    private function parseItems(array $raw, string $day): array
    {
        $out = [];
        $skipped = 0;
        $protected = [];
        $names = [];
        foreach ($raw as $i => $it) {
            $name = is_array($it) && is_string($it['item'] ?? null) ? self::trimName($it['item']) : '';
            $pieces = is_array($it) ? self::count($it['stock_in_pieces'] ?? null) : null;
            $lots   = is_array($it) ? self::lots($it['stock_in'] ?? null, $day) : null;
            $badName = $name === '' || mb_strlen($name) > 255;
            if ($badName || $pieces === null || $lots === null) {
                $skipped++;
                $names[] = $badName ? '(no name)' : mb_substr($name, 0, 60);
                if (!$badName) $protected[] = self::nameKey($name);
                Log::warning('nexus:sync-stock item skipped', [
                    'day' => $day, 'index' => $i, 'item' => mb_substr($name, 0, 80),
                    'reason' => $badName ? 'name' : 'count',
                ]);
                continue;
            }
            $key = self::nameKey($name);
            if (isset($out[$key])) {
                Log::info('nexus:sync-stock same name twice in one answer, summed', ['day' => $day, 'name_key' => $key]);
                $out[$key]['stock_in_pieces'] = min(self::MAX_COUNT, $out[$key]['stock_in_pieces'] + $pieces);
                $out[$key]['stock_in_lots']   = min(self::MAX_COUNT, $out[$key]['stock_in_lots'] + $lots);
                $rem = self::remaining($it['remaining'] ?? null);
                if ($rem !== null) $out[$key]['remaining'] = ($out[$key]['remaining'] ?? 0) + $rem;
                continue;
            }
            $out[$key] = [
                'name_key' => $key, 'item_name' => $name, 'stock_in_pieces' => $pieces, 'stock_in_lots' => $lots,
                'remaining' => self::remaining($it['remaining'] ?? null),
            ];
        }

        return [$out, $skipped, array_values(array_unique($protected)), array_values(array_unique($names))];
    }

    /**
     * Lots ng araw: ang stock_in ng Nexus ay listahan ng {date, lots, pieces} kada araw (ang araw lang na tinanong ang
     * dapat nandiyan); ang lumang anyo ay isang bilang. null / [] = 0. Iba = null (sira, laktawan ang item).
     */
    private static function lots(mixed $v, string $day): ?int
    {
        if (!is_array($v)) return self::count($v);
        if (!array_is_list($v)) return null;
        $sum = 0;
        foreach ($v as $entry) {
            if (!is_array($entry) || self::manilaDate($entry['date'] ?? null) !== $day) return null;
            $n = self::count($entry['lots'] ?? null);
            if ($n === null) return null;
            $sum = min(self::MAX_COUNT, $sum + $n);
        }

        return $sum;
    }

    /**
     * Ang petsa (Y-m-d, Manila) ng stock_in[].date. Ngayon ay "Y-m-d" ang Nexus; kung maging datetime ito balang araw
     * (hal. may oras o offset), binabasa pa rin sa Manila imbes na laktawan nang tahimik. Hindi mabasa = null.
     */
    private static function manilaDate(mixed $v): ?string
    {
        if (!is_string($v) || trim($v) === '' || strlen($v) > 40) return null;
        $v = trim($v);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return self::validDate($v) ? $v : null;
        try {
            return Carbon::parse($v, self::TZ)->setTimezone(self::TZ)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }


    /**
     * Upsert ng sagot ng araw; ang naka-store na wala sa sagot = 0 (naka-log), maliban sa nilaktawang item ($protected).
     * Returns ilan ang ginawang 0.
     */
    private function store(string $day, array $items, ?string $asOf, array $protected): int
    {
        $now  = Carbon::now(self::TZ);
        $rows = [];
        foreach ($items as $r) {
            $rows[] = $r + ['stock_date' => $day, 'as_of' => $asOf, 'created_at' => $now, 'updated_at' => $now];
        }
        if ($rows) {
            DB::table(self::TABLE)->upsert(
                $rows, ['stock_date', 'name_key'],
                ['item_name', 'stock_in_lots', 'stock_in_pieces', 'remaining', 'as_of', 'updated_at']
            );
        }

        $zeroed = 0;
        $keep = array_merge(array_keys($items), $protected);
        $missing = DB::table(self::TABLE)->where('stock_date', $day)
            ->when($keep, fn ($q) => $q->whereNotIn('name_key', $keep))
            ->where(fn ($q) => $q->where('stock_in_pieces', '>', 0)->orWhere('stock_in_lots', '>', 0))
            ->get(['id', 'name_key', 'stock_in_pieces']);
        foreach ($missing as $m) {
            DB::table(self::TABLE)->where('id', $m->id)->update(['stock_in_pieces' => 0, 'stock_in_lots' => 0, 'updated_at' => $now]);
            Log::info('nexus:sync-stock item missing from the answer, zeroed', [
                'day' => $day, 'name_key' => $m->name_key, 'old_pieces' => (int) $m->stock_in_pieces,
            ]);
            $zeroed++;
        }

        return $zeroed;
    }

    /** null/0 → 0; buong numero 0..2^31−1 (int, "12", 12.0) → int; iba → null (laktawan). */
    private static function count(mixed $v): ?int
    {
        if ($v === null) return 0;
        if (is_int($v)) return $v >= 0 && $v <= self::MAX_COUNT ? $v : null;
        if (is_float($v)) return floor($v) === $v && $v >= 0 && $v <= self::MAX_COUNT ? (int) $v : null;
        if (is_string($v) && preg_match('/^\d{1,10}$/', trim($v))) {
            $n = (int) trim($v);
            return $n <= self::MAX_COUNT ? $n : null;
        }
        return null;
    }

    private static function remaining(mixed $v): ?int
    {
        if (is_int($v) && abs($v) <= self::MAX_COUNT) return $v;
        if (is_float($v) && floor($v) === $v && abs($v) <= self::MAX_COUNT) return (int) $v;
        if (is_string($v) && preg_match('/^-?\d{1,10}$/', trim($v)) && abs((int) trim($v)) <= self::MAX_COUNT) return (int) trim($v);
        return null;
    }

    private function asOf(mixed $v): ?string
    {
        if (!is_string($v) || trim($v) === '' || strlen($v) > 40) return null;
        try {
            return Carbon::parse($v, self::TZ)->setTimezone(self::TZ)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function validDate(string $s): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)
            && checkdate((int) substr($s, 5, 2), (int) substr($s, 8, 2), (int) substr($s, 0, 4));
    }

    private function setting(string $key): ?string
    {
        $v = DB::table('app_settings')->where('key', $key)->value('value');
        return is_string($v) ? trim($v) : null;
    }

    private function put(string $key, string $value): void
    {
        $now = Carbon::now(self::TZ);
        $updated = DB::table('app_settings')->where('key', $key)->update(['value' => $value, 'updated_at' => $now]);
        if (!$updated && !DB::table('app_settings')->where('key', $key)->exists()) {
            DB::table('app_settings')->insert(['key' => $key, 'value' => $value, 'created_at' => $now, 'updated_at' => $now]);
        }
    }
}
