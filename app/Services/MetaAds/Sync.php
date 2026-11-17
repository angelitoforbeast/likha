<?php

namespace App\Services\MetaAds;

use App\Jobs\ProcessAdsManagerReportsUpload;
use App\Models\AppSetting;
use App\Models\UploadLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ang pagsulat ng Ads Manager report mula sa API sa ads_manager_reports (day + ad_id), sa parehong upsert ng XLSX upload
 * (kasama ang proteksyon na hindi bumababa ang spend, reach, …). Gumagana lang kapag naka-on ang write mode (CEO).
 * Bawat run ay isang upload_logs row (type ads_manager_api).
 */
class Sync
{
    public const WRITE_MODE = 'meta_ads.write_mode';
    public const SCHEDULE = 'meta_ads.schedule';
    /**
     * Ang mga ad account na binabasa: JSON {selected: [ID…], seen: [ID…]} (CEO, Marketing at Marketing - OIC ang pumipili).
     * Ang account na wala pa sa seen (bago) ay naka-check; ang inalisan ng check ay nananatiling wala. Walang naka-save =
     * lahat naka-check. Ang lumang anyo (listahan lang ng ID) = ang pili, at lahat ng account ay nakita na.
     * Lahat naka-uncheck = walang Sync; Compare = lahat.
     */
    public const ACCOUNTS = 'meta_ads.accounts';
    public const NO_ACCOUNTS = 'Choose the ad accounts first';
    public const DEFAULT_SCHEDULE = '10:00,15:00,18:45';
    public const LOG_TYPE = 'ads_manager_api';

    public function __construct(private Connections $connections, private Insights $insights)
    {
    }

    public static function writeMode(): bool
    {
        return strtolower(trim((string) self::setting(self::WRITE_MODE))) === 'on';
    }

    public static function setWriteMode(bool $on): void
    {
        AppSetting::set(self::WRITE_MODE, $on ? 'on' : 'off');
    }

    /** Ang mga oras ng schedule (H:i, Manila). */
    public static function schedule(): array
    {
        $raw = trim((string) self::setting(self::SCHEDULE)) ?: self::DEFAULT_SCHEDULE;
        $out = [];
        foreach (explode(',', $raw) as $t) {
            $t = trim($t);
            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) $out[] = $t;
        }
        sort($out);

        return array_values(array_unique($out));
    }

    public static function setSchedule(string $times): void
    {
        AppSetting::set(self::SCHEDULE, implode(',', array_map('trim', explode(',', $times))));
    }

    /**
     * @param list<string>|null $current ang mga account ID ng lahat ng connection (null = basahin ngayon)
     * @return list<string> ang mga naka-check na ad account ID (ayos, walang ulit)
     */
    public static function accounts(?array $current = null): array
    {
        $current ??= self::current();
        $state = self::accountState();
        if ($state === null) return $current;

        return self::ids(array_merge($state['selected'], array_diff($current, $state['seen'] ?? $current)));
    }

    /** Ang pili ay eksaktong $ids; ang lahat ng account ngayon (at ang nakita na dati) ay "seen". */
    public static function setAccounts(array $ids, ?array $current = null): void
    {
        $seen = array_merge(self::accountState()['seen'] ?? [], $current ?? self::current());
        AppSetting::set(self::ACCOUNTS, json_encode(['selected' => self::ids($ids), 'seen' => self::ids($seen)]));
    }

    /** @return list<string> ang mga account ID ng lahat ng connection */
    public static function current(?array $connections = null): array
    {
        return self::ids(array_map('strval', array_keys(app(Connections::class)->accounts($connections))));
    }

    /** @return array{selected: list<string>, seen: list<string>|null}|null null = walang naka-save; seen null = lumang anyo */
    private static function accountState(): ?array
    {
        $v = json_decode((string) self::setting(self::ACCOUNTS), true);
        if (!is_array($v)) return null;
        if (array_is_list($v)) return ['selected' => self::ids($v), 'seen' => null];

        return ['selected' => self::ids((array) ($v['selected'] ?? [])), 'seen' => self::ids((array) ($v['seen'] ?? []))];
    }

    /** Ang bagong account (wala pa sa seen) ay isinusulat sa selected at seen, para manatili ang check hanggang alisin. */
    private static function rememberNew(array $current, array $selected): void
    {
        $state = self::accountState();
        if ($state === null || $state['seen'] === null || array_diff($current, $state['seen'])) self::setAccounts($selected, $current);
    }

    /**
     * Ang bagong pili (web at meta-ads:accounts): tanggap lang ang ID na nasa isang connection. Kapag tama, isinusulat at
     * itinatala (before / after). @return list<string> ang mga ID na hindi kilala (walang isinulat kapag may laman)
     */
    public function chooseAccounts(array $ids, ?int $userId): array
    {
        $ids     = self::ids($ids);
        $unknown = array_values(array_diff($ids, array_map('strval', array_keys($this->connections->accounts()))));
        if ($unknown) return $unknown;

        $before = self::accounts();
        self::setAccounts($ids);
        Connections::event('ad accounts: ' . count($ids) . ' selected (was ' . count($before) . ')', $userId, ['before' => $before, 'after' => $ids]);

        return [];
    }

    /** @return list<string> */
    public static function ids(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($id) => trim((string) $id), $ids), fn ($id) => $id !== '')));
        sort($ids);

        return $ids;
    }

    /** from..to (Y-m-d). Default: ang huling 7 araw kasama ngayon (Manila), gaya ng export na inu-upload. */
    public static function range(?string $from, ?string $to): array
    {
        $today = Carbon::now('Asia/Manila')->startOfDay();
        $t = $to ? Carbon::parse($to, 'Asia/Manila') : $today->copy();
        $f = $from ? Carbon::parse($from, 'Asia/Manila') : $t->copy()->subDays(6);
        if ($f->gt($t)) $f = $t->copy();
        if ($f->diffInDays($t) > 30) $f = $t->copy()->subDays(30);

        return [$f->format('Y-m-d'), $t->format('Y-m-d')];
    }

    public static function startLog(string $from, string $to, ?int $userId): UploadLog
    {
        return UploadLog::create([
            'type' => self::LOG_TYPE, 'disk' => 'api', 'path' => 'meta', 'original_name' => "Meta API {$from} to {$to}",
            'status' => 'queued', 'user_id' => $userId,
            'processed_rows' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'error_rows' => 0,
        ]);
    }

    /**
     * Isang run: bawat naka-connect na tao, bawat piniling ad account niya (isang beses lang ang bawat account). 190 = expired ang
     * token ng taong iyon (Reconnect), tuloy ang iba; rate limit = laktaw ang account, "partial" ang run.
     */
    public function run(UploadLog $log, string $from, string $to): UploadLog
    {
        if (!self::writeMode()) {
            $log->update(['status' => 'failed', 'error_message' => 'Write mode is off.', 'finished_at' => now()]);
            return $log;
        }
        $log->update(['status' => 'processing', 'started_at' => now()]);
        $error = null;
        try {
            return $this->runAccounts($log, $from, $to);
        } catch (Throwable $e) {
            $error = $e;
            throw $e;
        } finally {
            self::endUnfinished($log, $error);
        }
    }

    private function runAccounts(UploadLog $log, string $from, string $to): UploadLog
    {
        $importer = new ProcessAdsManagerReportsUpload('');
        $problems = [];   // connection lines ("<who>: needs Reconnect")
        $accLines = [];   // account id => its one line: the days without data and the last error
        $seen     = [];
        $all      = $this->connections->all();
        $current  = self::current($all);
        $selected = self::accounts($current);
        if (!$selected) {
            $log->update(['status' => 'failed', 'error_message' => self::NO_ACCOUNTS, 'finished_at' => now()]);
            return $log;
        }
        self::rememberNew($current, $selected);
        $days     = self::days($from, $to);
        $progress = ['accounts_total' => count($selected), 'accounts_done' => 0, 'days_total' => count($days), 'account' => null];
        self::progress($log->id, $progress);
        $selected = array_flip($selected);
        $tot      = ['processed' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'protected' => []];
        $noPage   = []; // ad id => page id ng mga row na nilaktawan dahil walang page_name
        $refused  = []; // ang mga row na tinanggihan dahil may numerong bumaba (DataHealth)

        foreach ($all as $c) {
            $who   = (string) ($c['fb_user_name'] ?? ('user ' . $c['user_id']));
            $token = $this->connections->token($c);
            if (($c['status'] ?? 'ok') === 'expired' || $token === null) {
                $problems[] = "{$who}: needs Reconnect";
                continue;
            }

            $synced = false;
            foreach ($c['ad_accounts'] ?? [] as $acc) {
                $accId = (string) ($acc['id'] ?? '');
                if ($accId === '' || isset($seen[$accId]) || !isset($selected[$accId])) continue;
                $progress['account'] = (string) ($acc['name'] ?? '') ?: "act_{$accId}";
                self::progress($log->id, $progress);

                $adCache = [];
                $missing = [];
                $error   = null;
                $expired = false;
                foreach ($days as $i => $day) {
                    try {
                        $rows = $this->withPageNames($this->insights->dayRows($accId, $day, $token, $adCache));
                        $res  = $importer->importRows($rows['rows']);
                        $tot['processed'] += count($rows['rows']);
                        $tot['skipped']   += count($rows['skipped']) + $res['skipped'];
                        $noPage += $rows['skipped'];
                        $tot['inserted']  += $res['inserted'];
                        $tot['updated']   += $res['updated'];
                        foreach ($res['protected'] as $f => $n) $tot['protected'][$f] = ($tot['protected'][$f] ?? 0) + $n;
                        foreach ($res['refused'] as $x) $refused[] = ['account' => $x['account'] ?? $accId] + $x;
                        $seen[$accId] = true;
                        $synced = true;
                        // Rows so far, for the live status on the report page.
                        $log->update(['processed_rows' => $tot['processed'], 'inserted' => $tot['inserted'], 'updated' => $tot['updated'], 'skipped' => $tot['skipped']]);
                    } catch (GraphException $e) {
                        if ($e->tokenExpired()) {
                            $this->connections->update($c['ref'], ['status' => 'expired', 'last_error' => $e->getMessage()]);
                            $problems[] = "{$who}: token expired (Reconnect)";
                            $expired = true;
                            break;
                        }
                        if ($e->transient()) {
                            // Already tried again and as an async job: this day stays without data, the next day may work.
                            $missing[] = $day;
                            $error = $e->getMessage();
                            continue;
                        }
                        // A rate limit (after its waits), a block or a permanent error (400, permission) would answer the
                        // same for the other days, so they are not asked and are named as without data.
                        $missing = array_merge($missing, array_slice($days, $i));
                        $error = $e->rateLimited() ? 'rate limited, skipped' : $e->getMessage();
                        break;
                    } catch (Throwable $e) {
                        Log::error('MetaAds sync: account day failed', ['account' => $accId, 'day' => $day, 'error' => Graph::clean($e->getMessage())]);
                        $missing[] = $day;
                        $error = 'failed';
                    }
                }
                if ($expired) break; // the account may still be read through another connection, as before

                if ($missing) $accLines[$accId] = self::accountLine($accId, $missing, $days, (string) $error);
                else unset($accLines[$accId]); // read in full through this connection after another one failed
                $progress['accounts_done'] = min($progress['accounts_total'], $progress['accounts_done'] + 1);
                self::progress($log->id, $progress);
            }
            if ($synced) $this->connections->update($c['ref'], ['last_sync_at' => now()->toIso8601String()]);
        }

        $problems = array_merge($problems, array_values($accLines));
        self::progress($log->id, ['accounts_done' => $progress['accounts_total'], 'account' => null] + $progress);
        $log->update([
            'status'            => $problems ? 'partial' : 'done',
            'processed_rows'    => $tot['processed'],
            'inserted'          => $tot['inserted'],
            'updated'           => $tot['updated'],
            'skipped'           => $tot['skipped'],
            'protected_details' => $tot['protected'] ?: null,
            // One line per account (or per Facebook page for the skipped ads), so a long run reads as a short list.
            'error_message'     => ($problems || $noPage) ? mb_substr(implode("\n", array_merge($problems, self::noPageLines($noPage))), 0, 2000) : null,
            'finished_at'       => now(),
        ]);
        DataHealth::recordRefusals($log, 'sync', $refused);
        if ($tot['inserted'] + $tot['updated'] > 0) \App\Http\Controllers\OwnerPrivateController::bumpCacheVersion();

        return $log;
    }

    /** @return list<string> every day from..to (Y-m-d) */
    public static function days(string $from, string $to): array
    {
        $out = [];
        for ($d = Carbon::parse($from); $d->lte(Carbon::parse($to)); $d->addDay()) $out[] = $d->format('Y-m-d');

        return $out;
    }

    /**
     * "act_<id>: <reason>" for an account with days without data. A rate limit over the whole range keeps the short
     * "rate limited, skipped"; otherwise the days are named ("no data for Nov 12, Nov 13" or "Nov 11 to Nov 17").
     */
    public static function accountLine(string $accId, array $missing, array $days, string $error): string
    {
        $all = count($missing) === count($days);
        if ($all && $error === 'rate limited, skipped') return "act_{$accId}: {$error}";
        $fmt  = fn ($d) => Carbon::parse($d)->format('M j');
        $when = $all && count($days) > 2 ? $fmt($days[0]) . ' to ' . $fmt(end($days)) : implode(', ', array_map($fmt, $missing));

        return "act_{$accId}: no data for {$when} ({$error})";
    }

    /** @param array<string, string> $noPage ad id => page id @return list<string> one line per Facebook page */
    private static function noPageLines(array $noPage): array
    {
        $byPage = [];
        foreach ($noPage as $ad => $page) $byPage[$page][] = (string) $ad;
        $out = [];
        foreach ($byPage as $page => $ads) {
            $out[] = 'No page name, skipped: ' . (count($ads) === 1 ? "ad {$ads[0]} (page {$page})"
                : count($ads) . " ads of page {$page} (" . implode(', ', array_slice($ads, 0, 3)) . (count($ads) > 3 ? ', …' : '') . ')');
        }

        return $out;
    }

    public const PROGRESS_KEY = 'meta_ads.sync.progress.';

    /**
     * The live progress of a run (accounts done of total, the account being read), for the status poll. Kept in the
     * cache, which is the database on both sites, so the queue worker writes what the web request reads. Best effort:
     * the run never fails over it.
     */
    public static function progress(int $logId, ?array $set = null): ?array
    {
        try {
            if ($set !== null) Cache::put(self::PROGRESS_KEY . $logId, $set, now()->addHours(2));

            return Cache::get(self::PROGRESS_KEY . $logId);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Ang run na hindi natapos (may ibinato, o pinatay ng queue dahil sa timeout: tinatawag ng failed() ng job) ay "failed",
     * hindi naiiwang "processing" o "queued". Ang tapos na ay hindi ginagalaw.
     */
    public static function endUnfinished(UploadLog $log, ?Throwable $e): void
    {
        $log->refresh();
        if (!in_array($log->status, ['queued', 'processing'], true)) return;
        $log->update([
            'status'        => 'failed',
            'error_message' => 'Stopped: ' . ($e ? mb_substr(Graph::clean($e->getMessage()), 0, 500) : 'did not finish'),
            'finished_at'   => now(),
        ]);
    }

    /**
     * Ang row na walang page_name (walang Likha page na may ganitong Facebook page ID) ay kumukuha ng page_name ng dating
     * row ng parehong ad; kung wala, laktaw (page_name ay NOT NULL sa ads_manager_reports), at nakalista sa run log para
     * maayos (hal. idagdag ang botcake.page_id sa /page-settings). @return array{rows: array, skipped: array<string, string>} (skipped: ad id => page id)
     */
    private function withPageNames(array $rows): array
    {
        $missing = array_values(array_unique(array_map(fn ($r) => $r['ad_id'], array_filter($rows, fn ($r) => empty($r['page_name'])))));
        $known = [];
        foreach (array_chunk($missing, 500) as $chunk) {
            $known += DB::table('ads_manager_reports')->whereIn('ad_id', $chunk)->whereNotNull('page_name')
                ->orderBy('day')->pluck('page_name', 'ad_id')->all();
        }

        $out = $skipped = [];
        foreach ($rows as $r) {
            $pageId = $r['page_id'] ?? null;
            unset($r['page_id']);
            if (empty($r['page_name'])) $r['page_name'] = $known[$r['ad_id']] ?? null;
            if (empty($r['page_name'])) { $skipped[(string) $r['ad_id']] = (string) ($pageId ?? 'unknown'); continue; }
            $out[] = $r;
        }

        return ['rows' => $out, 'skipped' => $skipped];
    }

    private static function setting(string $key): ?string
    {
        try {
            return AppSetting::get($key);
        } catch (Throwable) {
            return null; // wala pang app_settings table
        }
    }
}
