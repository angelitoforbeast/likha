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
 * Nexus stock-in → nexus_stock_ins (isang row kada araw at pangalan). Isang GET /api/item-stock?date=D kada araw:
 * ang ?from=&to= ng Nexus ay kabuuan ng buong range (walang hati kada araw), kaya hindi ito mailalagay kada araw.
 *
 * Mga araw na tinatanong: mula sa nexus_stock_synced_through (inuulit: "ngayon" pa iyon nang i-store) o mula sa
 * item_stock_start sa unang takbo, hanggang ngayon (Manila); laging kasama ang kahapon. Ang sagot ng isang araw ang
 * totoo para sa araw na iyon: overwrite (hindi dagdag), at ang naka-store na item na wala sa sagot ay ginagawang 0.
 * Ang walang laman o sirang sagot ay hindi gumagalaw sa naka-store; walang binubura kahit kailan.
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
    private const MAX_COUNT     = 2147483647;
    private const TZ            = 'Asia/Manila';

    public static function configured(): bool
    {
        return trim((string) config('services.nexus.token')) !== '';
    }

    /** NFC, trim, isang space, lowercase: ang batayan ng name_key (at ng hindi-tugmang listahan). */
    public static function normalizeName(string $name): string
    {
        $s = trim($name);
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_C);
            if (is_string($n)) $s = $n;
        }
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return mb_strtolower(trim($s), 'UTF-8');
    }

    public static function nameKey(string $name): string
    {
        return sha1(self::normalizeName($name));
    }

    /**
     * @return array{status:string, lines:array<int,string>}
     *   status: not_configured | no_table | no_start | bad_from | locked | ok | failed
     */
    public function run(?string $from = null): array
    {
        if (!self::configured()) {
            return ['status' => 'not_configured', 'lines' => ['Nexus not configured (services.nexus.token is empty): nothing asked, nothing changed.']];
        }
        if (!Schema::hasTable(self::TABLE) || !Schema::hasTable('app_settings')) {
            return ['status' => 'no_table', 'lines' => ['The nexus_stock_ins table is missing: run the migration first. Nothing asked.']];
        }
        $start = ItemStockService::startSetting();
        if ($start === null) {
            return ['status' => 'no_start', 'lines' => ['No valid item_stock_start in app_settings: nothing asked, nothing changed.']];
        }
        if ($from !== null && !self::validDate($from)) {
            return ['status' => 'bad_from', 'lines' => ['--from must be a real date (Y-m-d). Nothing asked.']];
        }

        $lock = Cache::lock(self::LOCK, 1800);
        if (!$lock->get()) {
            return ['status' => 'locked', 'lines' => ['Another nexus:sync-stock is already running: nothing asked.']];
        }
        try {
            return $this->sync($start, $from);
        } finally {
            $lock->release();
        }
    }

    private function sync(string $start, ?string $from): array
    {
        $now       = Carbon::now(self::TZ);
        $today     = $now->toDateString();
        $yesterday = $now->copy()->subDay()->toDateString();
        $through   = $this->setting(self::SYNCED_THROUGH);
        $through   = self::validDate((string) $through) ? (string) $through : null;

        $first = $from ?? ($through !== null ? min($through, $yesterday) : $start);
        $first = max($first, $start);
        if ($first > $today) {
            return ['status' => 'ok', 'lines' => ["The stock start ($start) is after today: nothing to ask."]];
        }

        $lines      = [];
        $failure    = null;      // unang error (ito ang itinatala)
        $contiguous = null;      // huling araw na tuloy-tuloy na OK mula sa $first
        $stored     = 0;

        foreach (CarbonPeriod::create($first, $today) as $d) {
            $day = $d->toDateString();
            $res = $this->day($day);
            if ($res['ok']) {
                $stored += $res['rows'];
                if ($failure === null) $contiguous = $day;
                $lines[] = "$day: {$res['rows']} items" . ($res['skipped'] ? ", {$res['skipped']} skipped" : '') . ($res['zeroed'] ? ", {$res['zeroed']} set to 0" : '');
                continue;
            }
            $failure ??= $res + ['day' => $day];
            $lines[] = "$day: {$res['message']}";
            if ($res['stop']) break;
        }

        if ($contiguous !== null && ($through === null || $contiguous > $through)) {
            $this->put(self::SYNCED_THROUGH, $contiguous);
        }
        if ($failure === null) {
            $this->put(self::LAST_SYNCED, $now->format('Y-m-d H:i:s'));
            $this->put(self::LAST_ERROR, '');
            $lines[] = "Nexus stock-in synced $first to $today ($stored rows).";
            return ['status' => 'ok', 'lines' => $lines];
        }

        $this->put(self::LAST_ERROR, json_encode([
            'at' => $now->format('Y-m-d H:i:s'), 'kind' => $failure['kind'], 'message' => $failure['message'], 'day' => $failure['day'],
        ]));
        Log::warning('nexus:sync-stock not complete', ['kind' => $failure['kind'], 'day' => $failure['day'], 'from' => $first, 'to' => $today]);
        $lines[] = "Not complete: {$failure['kind']} on {$failure['day']}; the failed days are asked again next run.";

        return ['status' => 'failed', 'lines' => $lines];
    }

    /** Isang araw: tanong, suri, at store. */
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

        [$items, $skipped] = $this->parseItems($json['items'], $day);
        if (!$items) {
            // Walang dumating = normal na araw, kung wala pang naka-store. May naka-store pero walang laman = kahina-hinala.
            if ($json['items'] === [] && DB::table(self::TABLE)->where('stock_date', $day)->exists()) {
                return $fail('empty_for_stored_day', 'Nexus answered with no items for a day that has stored items');
            }
            return ['ok' => true, 'rows' => 0, 'skipped' => $skipped, 'zeroed' => 0];
        }

        $asOf   = $this->asOf($json['as_of'] ?? null);
        $zeroed = DB::transaction(fn () => $this->store($day, $items, $asOf));

        return ['ok' => true, 'rows' => count($items), 'skipped' => $skipped, 'zeroed' => $zeroed];
    }

    /** @return array{0: array<string,array>, 1: int} name_key => row; skipped count */
    private function parseItems(array $raw, string $day): array
    {
        $out = [];
        $skipped = 0;
        foreach ($raw as $i => $it) {
            $name = is_array($it) && is_string($it['item'] ?? null) ? trim($it['item']) : '';
            $pieces = is_array($it) ? self::count($it['stock_in_pieces'] ?? null) : null;
            $lots   = is_array($it) ? self::count($it['stock_in'] ?? null) : null;
            if ($name === '' || mb_strlen($name) > 255 || $pieces === null || $lots === null) {
                $skipped++;
                Log::warning('nexus:sync-stock item skipped', [
                    'day' => $day, 'index' => $i, 'item' => mb_substr($name, 0, 80),
                    'reason' => $name === '' || mb_strlen($name) > 255 ? 'name' : 'count',
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

        return [$out, $skipped];
    }

    /** Upsert ng sagot ng araw; ang naka-store na wala sa sagot = 0 (naka-log). Returns ilan ang ginawang 0. */
    private function store(string $day, array $items, ?string $asOf): int
    {
        $now  = Carbon::now(self::TZ);
        $rows = [];
        foreach ($items as $r) {
            $rows[] = $r + ['stock_date' => $day, 'as_of' => $asOf, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table(self::TABLE)->upsert(
            $rows, ['stock_date', 'name_key'],
            ['item_name', 'stock_in_lots', 'stock_in_pieces', 'remaining', 'as_of', 'updated_at']
        );

        $zeroed = 0;
        $missing = DB::table(self::TABLE)->where('stock_date', $day)
            ->whereNotIn('name_key', array_keys($items))
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
