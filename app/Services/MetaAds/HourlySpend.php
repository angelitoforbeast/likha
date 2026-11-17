<?php

namespace App\Services\MetaAds;

use App\Models\UploadLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Spec 288. Spend per page per hour from Meta's hourly breakdown, for the hourly view of /ads_manager/cpp/timeline.
 * Stored in cpp_snapshots (no new table) under the buckets H00..H23: one row per (date, hour, page), cumulative from
 * 00:00 to the end of that hour, so CPI and CPM are exact for any hour (counts are summed before dividing) and one
 * hour's own spend is the difference of two rows. Orders are not stored here: the page counts them live from
 * macro_output, as the bucket view does. Each run is one upload_logs row (type meta_ads_hourly).
 */
class HourlySpend
{
    public const LOG_TYPE = 'meta_ads_hourly';
    public const UNKNOWN_PAGE = 'Unknown ad';

    public function __construct(private Connections $connections, private Insights $insights)
    {
    }

    /** "H00".."H23" */
    public static function bucket(int $hour): string
    {
        return sprintf('H%02d', $hour);
    }

    /** @return list<string> the 24 hourly bucket labels, so other readers of cpp_snapshots can leave them out */
    public static function buckets(): array
    {
        return array_map([self::class, 'bucket'], range(0, 23));
    }

    /**
     * Reads and stores the given days. Runs only when write mode is on and ad accounts are selected, like the sync.
     * One request per selected ad account per day (plus pages). A rate limit or block on any account stops the run
     * ("skipped"): asking again within the hour would only extend it, and the next hourly run tries again.
     * $pause: seconds between requests, for a backfill over many days.
     * @param list<string> $days Y-m-d
     */
    public function run(array $days, int $pause = 0): UploadLog
    {
        $log = UploadLog::create([
            'type' => self::LOG_TYPE, 'disk' => 'api', 'path' => 'meta', 'status' => 'processing', 'started_at' => now(),
            'original_name' => 'Meta hourly ' . ($days ? reset($days) . (count($days) > 1 ? ' to ' . end($days) : '') : ''),
            'processed_rows' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'error_rows' => 0,
        ]);
        if (!Sync::writeMode() || !Sync::accounts()) {
            $log->update(['status' => 'skipped', 'error_message' => !Sync::writeMode() ? 'Write mode is off.' : Sync::NO_ACCOUNTS, 'finished_at' => now()]);
            return $log;
        }

        $selected = array_flip(Sync::accounts());
        $problems = [];
        $written  = 0;
        $calls    = 0;
        try {
            foreach ($days as $day) {
                $rows = [];
                $complete = true;
                $seen = [];
                foreach ($this->connections->all() as $c) {
                    $token = $this->connections->token($c);
                    if (($c['status'] ?? 'ok') === 'expired' || $token === null) continue;
                    foreach ($c['ad_accounts'] ?? [] as $acc) {
                        $accId = (string) ($acc['id'] ?? '');
                        if ($accId === '' || isset($seen[$accId]) || !isset($selected[$accId])) continue;
                        if ($pause > 0 && $calls > 0) Sleep::for($pause)->seconds();
                        $calls++;
                        try {
                            $rows = array_merge($rows, $this->insights->hourly($accId, $day, $token));
                            $seen[$accId] = true;
                        } catch (GraphException $e) {
                            if ($e->rateLimited() || $e->blocked()) {
                                $log->update(['status' => 'skipped', 'error_message' => "act_{$accId} {$day}: " . ($e->rateLimited() ? 'rate limited' : 'blocked')
                                    . ', run skipped (' . $e->getMessage() . ')', 'processed_rows' => $written, 'finished_at' => now()]);
                                Log::warning('MetaAds hourly: run skipped', ['account' => $accId, 'day' => $day, 'error' => $e->getMessage()]);
                                return $log;
                            }
                            $complete = false;
                            $problems[] = "act_{$accId} {$day}: " . $e->getMessage();
                        }
                    }
                }
                $written += $this->store($day, $rows, $complete);
            }
        } catch (Throwable $e) {
            $log->update(['status' => 'failed', 'error_message' => 'Stopped: ' . mb_substr(Graph::clean($e->getMessage()), 0, 500), 'processed_rows' => $written, 'finished_at' => now()]);
            throw $e;
        }

        $log->update([
            'status' => $problems ? 'partial' : 'done', 'processed_rows' => $written, 'inserted' => $written,
            'error_message' => $problems ? mb_substr(implode("\n", $problems), 0, 2000) : null, 'finished_at' => now(),
        ]);

        return $log;
    }

    /**
     * Writes the cumulative rows of one day. Hours after the current Manila hour (today) are not written. When every
     * account was read, the day's hourly rows of pages that no longer have spend are removed; when one failed, the
     * pages already stored keep their last figures. @return int rows written
     */
    public function store(string $day, array $rows, bool $complete = true): int
    {
        $now  = Carbon::now('Asia/Manila');
        $last = $day === $now->toDateString() ? (int) $now->format('G') : ($day > $now->toDateString() ? -1 : 23);
        if ($last < 0) return 0;

        $pageOf = $this->pageNames($day, array_values(array_unique(array_column($rows, 'ad_id'))));
        $sum = []; // page => hour => [spend, impressions, messages]
        foreach ($rows as $r) {
            if ($r['hour'] > $last) continue;
            $page = $pageOf[$r['ad_id']] ?? self::UNKNOWN_PAGE;
            $cell = $sum[$page][$r['hour']] ?? [0.0, 0, 0];
            $sum[$page][$r['hour']] = [$cell[0] + $r['spend'], $cell[1] + $r['impressions'], $cell[2] + $r['messages']];
        }

        $normalize = fn ($s) => strtolower(preg_replace('/\s+/', '', (string) $s));
        $at = $now->toDateTimeString();
        $out = [];
        foreach ($sum as $page => $hours) {
            [$spend, $impr, $msgs] = [0.0, 0, 0];
            for ($h = 0; $h <= $last; $h++) {
                [$s, $i, $m] = $hours[$h] ?? [0.0, 0, 0];
                $spend += $s; $impr += $i; $msgs += $m;
                if ($spend <= 0) continue;
                $out[] = [
                    'snapshot_date' => $day, 'snapshot_bucket' => self::bucket($h), 'page_name' => (string) $page,
                    'snapshot_at' => $at, 'page_key' => $normalize($page), 'item_names' => null,
                    'amount_spent' => round($spend, 2), 'orders' => 0, 'proceed_orders' => 0, 'cpp' => null,
                    'cpi' => $impr > 0 ? round($spend * 1000 / $impr, 2) : null,   // ₱ per 1,000 impressions (this page's CPI)
                    'cpm' => $msgs > 0 ? round($spend / $msgs, 2) : null,          // ₱ per message (this page's CPM)
                    'tcpr_pct' => null, 'saved_by_user_id' => null, 'saved_by_user_email' => null,
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
        }

        DB::transaction(function () use ($day, $out, $complete) {
            if ($complete) {
                DB::table('cpp_snapshots')->where('snapshot_date', $day)->whereIn('snapshot_bucket', self::buckets())->delete();
                foreach (array_chunk($out, 500) as $chunk) DB::table('cpp_snapshots')->insert($chunk);
                return;
            }
            foreach ($out as $row) {
                $key = ['snapshot_date' => $row['snapshot_date'], 'snapshot_bucket' => $row['snapshot_bucket'], 'page_name' => $row['page_name']];
                $values = array_diff_key($row, $key + ['created_at' => 1]);
                if (DB::table('cpp_snapshots')->where($key)->exists()) DB::table('cpp_snapshots')->where($key)->update($values);
                else DB::table('cpp_snapshots')->insert($row);
            }
        });

        return count($out);
    }

    /**
     * ad id => Likha page name, from the synced report rows: the same day's first, then the ad's latest known page.
     * The hourly breakdown carries no page, and asking Meta for every ad's creative each hour would cost far more.
     */
    private function pageNames(string $day, array $adIds): array
    {
        $out = [];
        foreach (array_chunk($adIds, 500) as $chunk) {
            $out += DB::table('ads_manager_reports')->whereIn('ad_id', $chunk)->where('day', $day)->whereNotNull('page_name')
                ->pluck('page_name', 'ad_id')->all();
            $rest = array_values(array_diff($chunk, array_map('strval', array_keys($out))));
            if ($rest) {
                $out += DB::table('ads_manager_reports')->whereIn('ad_id', $rest)->whereNotNull('page_name')
                    ->orderBy('day')->pluck('page_name', 'ad_id')->all(); // the latest day wins in pluck
            }
        }

        return array_map('strval', array_filter($out, fn ($p) => trim((string) $p) !== ''));
    }
}
