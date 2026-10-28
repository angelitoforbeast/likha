<?php

namespace App\Services\MetaAds;

use App\Jobs\ProcessAdsManagerReportsUpload;
use App\Models\AppSetting;
use App\Models\UploadLog;
use Carbon\Carbon;
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
     * Isang run: bawat naka-connect na tao, bawat ad account niya (isang beses lang ang bawat account). 190 = expired ang
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
        $problems = [];
        $seen     = [];
        $tot      = ['processed' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'protected' => []];
        $noPage   = []; // "ad <id> (page <id>)" ng mga row na nilaktawan dahil walang page_name

        foreach ($this->connections->all() as $userId => $c) {
            $who   = (string) ($c['fb_user_name'] ?? ('user ' . $userId));
            $token = $this->connections->token($c);
            if (($c['status'] ?? 'ok') === 'expired' || $token === null) {
                $problems[] = "{$who}: needs Reconnect";
                continue;
            }

            $synced = false;
            foreach ($c['ad_accounts'] ?? [] as $acc) {
                $accId = (string) ($acc['id'] ?? '');
                if ($accId === '' || isset($seen[$accId])) continue;
                try {
                    $rows = $this->withPageNames($this->insights->rows($accId, $from, $to, $token, true));
                    $seen[$accId] = true;
                    $res  = $importer->importRows($rows['rows']);
                    $tot['processed'] += count($rows['rows']);
                    $tot['skipped']   += count($rows['skipped']) + $res['skipped'];
                    $noPage = array_merge($noPage, $rows['skipped']);
                    $tot['inserted']  += $res['inserted'];
                    $tot['updated']   += $res['updated'];
                    foreach ($res['protected'] as $f => $n) $tot['protected'][$f] = ($tot['protected'][$f] ?? 0) + $n;
                    $synced = true;
                } catch (GraphException $e) {
                    if ($e->tokenExpired()) {
                        $this->connections->update($userId, ['status' => 'expired', 'last_error' => $e->getMessage()]);
                        $problems[] = "{$who}: token expired (Reconnect)";
                        break;
                    }
                    $problems[] = "act_{$accId}: " . ($e->rateLimited() ? 'rate limited, skipped' : $e->getMessage());
                } catch (Throwable $e) {
                    Log::error('MetaAds sync: account failed', ['account' => $accId, 'error' => Graph::clean($e->getMessage())]);
                    $problems[] = "act_{$accId}: failed";
                }
            }
            if ($synced) $this->connections->update($userId, ['last_sync_at' => now()->toIso8601String()]);
        }

        $log->update([
            'status'            => $problems ? 'partial' : 'done',
            'processed_rows'    => $tot['processed'],
            'inserted'          => $tot['inserted'],
            'updated'           => $tot['updated'],
            'skipped'           => $tot['skipped'],
            'protected_details' => $tot['protected'] ?: null,
            'error_message'     => ($problems || $noPage) ? mb_substr(implode('; ', array_merge($problems,
                $noPage ? ['No page name, skipped: ' . implode(', ', array_unique($noPage))] : [])), 0, 2000) : null,
            'finished_at'       => now(),
        ]);
        if ($tot['inserted'] + $tot['updated'] > 0) \App\Http\Controllers\OwnerPrivateController::bumpCacheVersion();

        return $log;
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
     * maayos (hal. idagdag ang botcake.page_id sa /page-settings). @return array{rows: array, skipped: list<string>}
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
            if (empty($r['page_name'])) { $skipped[] = 'ad ' . $r['ad_id'] . ' (page ' . ($pageId ?? 'unknown') . ')'; continue; }
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
