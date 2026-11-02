<?php

namespace App\Jobs;

use App\Services\Jnt\JntClient;
use App\Services\Jnt\JntTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * /jnt/track-sync — i-refresh ang from_jnts.status gamit ang J&T TRACKQUERY.
 *
 *  - Source: from_jnts na NON-FINAL (status NOT IN Delivered/Returned), sa loob ng
 *    date range (submission_time). Ang final = skip (di na gagalawin).
 *  - Batch TRACKQUERY (comma-separated billcodes) → map latest scan → upsert
 *    status + status_logs (from→to) + signingtime. Itinatago ang unmapped
 *    (Problematic/unknown) para sa review (hindi isinusulat).
 *  - dry_run = preview lang (walang isusulat).
 *  - Ang pag-track (20 kada tawag, isa-isa ang nalaglag, 2 tawag kada segundo) at ang isusulat kada row ay nasa
 *    JntTracker, na ginagamit din ng chatblast.
 */
class SyncFromJntStatusByTrack implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600; // 1 oras
    public int $tries = 1;

    private const API_CHUNK   = 20;   // billcodes kada TRACKQUERY call (may cap ang J&T ~20)
    private const SAMPLE_MAX  = 300;  // rows na itatabi para sa preview UI
    private const FINAL       = ['Delivered', 'Returned'];

    public function __construct(
        public int $runId,
        public string $dateFrom,
        public string $dateTo,
        public bool $dryRun = true,
    ) {}

    public function handle(): void
    {
        $now = now('Asia/Manila');
        $runs = fn () => DB::table('jnt_track_sync_runs')->where('id', $this->runId);

        $base = DB::table('from_jnts')
            ->whereNotIn('status', self::FINAL)
            ->whereNotNull('waybill_number')->where('waybill_number', '!=', '')
            ->whereBetween('submission_time', [$this->dateFrom . ' 00:00:00', $this->dateTo . ' 23:59:59']);

        $total = (clone $base)->count();
        $runs()->update(['status' => 'running', 'started_at' => $now, 'total' => $total, 'last_error' => null, 'updated_at' => $now]);

        $client  = JntClient::fromConfig();
        $tracker = app(JntTracker::class);

        $processed = $updated = $unchanged = $skipped = $unmapped = $failed = 0;
        $cat = ['updated' => [], 'unchanged' => [], 'skipped' => [], 'unmapped' => []]; // per-category lists (clickable UI)
        $unmappedTypes = [];

        try {
            (clone $base)
                ->select('id', 'waybill_number', 'status', 'status_logs', 'rider_name', 'rider_phone')
                ->orderBy('id')
                ->chunkById(200, function ($rows) use (
                    $client, $tracker, &$processed, &$updated, &$unchanged, &$skipped, &$unmapped, &$failed,
                    &$cat, &$unmappedTypes, $runs
                ) {
                    // waybill → row
                    $map = [];
                    foreach ($rows as $r) $map[trim((string) $r->waybill_number)] = $r;
                    $waybills = array_keys($map);

                    foreach (array_chunk($waybills, self::API_CHUNK) as $sub) {
                        // Batch track + auto-retry ng mga na-drop (J&T cap ~20/call).
                        $parsed = $tracker->trackSet($client, $sub);

                        foreach ($sub as $wb) {
                            $row = $map[$wb];
                            $processed++;

                            $details = $parsed[$wb] ?? null;
                            if (!is_array($details) || empty($details)) {
                                $skipped++; // walang track / success:false
                                $this->pushCat($cat, 'skipped', ['waybill' => $wb, 'status' => $row->status]);
                                continue;
                            }

                            // Status + status_logs (kapag nagbago), signingtime, rider: kapareho ng chatblast.
                            $c    = $tracker->changesFor($row, $details, 'track_sync', $this->runId);
                            $info = $c['info'];
                            $upd  = $c['update'];

                            if ($info['unmapped']) {
                                $unmapped++;
                                $st = $info['scantype'] !== '' ? $info['scantype'] : '(blank)';
                                $unmappedTypes[$st] = ($unmappedTypes[$st] ?? 0) + 1;
                                $this->pushCat($cat, 'unmapped', ['waybill' => $wb, 'status' => $row->status, 'scantype' => $info['scantype']]);
                            } elseif (!$c['changed']) {
                                $unchanged++;
                                $this->pushCat($cat, 'unchanged', ['waybill' => $wb, 'status' => (string) $info['status']]);
                            } else {
                                $updated++;
                                $this->pushCat($cat, 'updated', ['waybill' => $wb, 'from' => (string) $row->status, 'to' => (string) $info['status'], 'scantype' => $info['scantype']]);
                            }

                            if (!empty($upd) && !$this->dryRun) {
                                $upd['updated_at'] = now('Asia/Manila');
                                DB::table('from_jnts')->where('id', $row->id)->update($upd);
                            }
                        }

                        $runs()->update([
                            'processed' => $processed, 'updated' => $updated, 'unchanged' => $unchanged,
                            'skipped' => $skipped, 'unmapped' => $unmapped, 'failed' => $failed,
                            'updated_at' => now('Asia/Manila'),
                        ]);
                    }
                });

            arsort($unmappedTypes);
            $runs()->update([
                'status'        => 'done',
                'processed'     => $processed, 'updated' => $updated, 'unchanged' => $unchanged,
                'skipped'       => $skipped, 'unmapped' => $unmapped, 'failed' => $failed,
                'result_sample' => json_encode($cat + ['unmapped_scantypes' => $unmappedTypes], JSON_UNESCAPED_UNICODE),
                'finished_at'   => now('Asia/Manila'),
                'updated_at'    => now('Asia/Manila'),
            ]);
        } catch (Throwable $e) {
            $runs()->update([
                'status' => 'failed', 'last_error' => $e->getMessage(),
                'finished_at' => now('Asia/Manila'), 'updated_at' => now('Asia/Manila'),
            ]);
            throw $e;
        }
    }


    /**
     * Idagdag sa per-category list (naka-cap para di lumaki masyado ang JSON).
     * @param array<string,array<int,array<string,mixed>>> $cat
     * @param array<string,mixed> $row
     */
    private function pushCat(array &$cat, string $key, array $row): void
    {
        if (!isset($cat[$key])) $cat[$key] = [];
        if (count($cat[$key]) < self::SAMPLE_MAX) {
            $cat[$key][] = $row;
        }
    }
}
