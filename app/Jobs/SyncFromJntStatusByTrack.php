<?php

namespace App\Jobs;

use App\Services\Jnt\JntClient;
use App\Support\JntScanStatusMapper;
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

        $client = JntClient::fromConfig();

        $processed = $updated = $unchanged = $skipped = $unmapped = $failed = 0;
        $sample = [];
        $unmappedTypes = [];

        try {
            (clone $base)
                ->select('id', 'waybill_number', 'status', 'status_logs')
                ->orderBy('id')
                ->chunkById(200, function ($rows) use (
                    $client, &$processed, &$updated, &$unchanged, &$skipped, &$unmapped, &$failed,
                    &$sample, &$unmappedTypes, $runs
                ) {
                    // waybill → row
                    $map = [];
                    foreach ($rows as $r) $map[trim((string) $r->waybill_number)] = $r;
                    $waybills = array_keys($map);

                    foreach (array_chunk($waybills, self::API_CHUNK) as $sub) {
                        // Batch track + auto-retry ng mga na-drop (J&T cap ~20/call).
                        $parsed = $this->trackSet($client, $sub);

                        foreach ($sub as $wb) {
                            $row = $map[$wb];
                            $processed++;

                            $details = $parsed[$wb] ?? null;
                            if (!is_array($details) || empty($details)) {
                                $skipped++; // walang track / success:false
                                continue;
                            }

                            $info = JntScanStatusMapper::fromDetails($details);

                            if ($info['unmapped']) {
                                $unmapped++;
                                $st = $info['scantype'] !== '' ? $info['scantype'] : '(blank)';
                                $unmappedTypes[$st] = ($unmappedTypes[$st] ?? 0) + 1;
                                $this->pushSample($sample, [$wb, $row->status, '(unmapped)', $info['scantype']]);
                                continue;
                            }

                            $old = (string) $row->status;
                            $new = (string) $info['status'];

                            if ($new === $old) {
                                $unchanged++;
                                continue;
                            }

                            // CHANGE
                            $this->pushSample($sample, [$wb, $old, $new, $info['scantype']]);
                            $updated++;

                            if (!$this->dryRun) {
                                $logs = json_decode((string) $row->status_logs, true);
                                if (!is_array($logs)) $logs = [];
                                $logs[] = [
                                    'batch_at'      => now('Asia/Manila')->toDateTimeString(),
                                    'upload_log_id' => null,
                                    'from'          => $old ?: null,
                                    'to'            => $new,
                                    'src'           => 'track_sync',
                                    'run_id'        => $this->runId,
                                ];

                                $upd = [
                                    'status'      => $new,
                                    'status_logs' => json_encode($logs, JSON_UNESCAPED_UNICODE),
                                    'updated_at'  => now('Asia/Manila'),
                                ];
                                if (!empty($info['signingtime'])) $upd['signingtime'] = $info['signingtime'];

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
                'result_sample' => json_encode(['rows' => $sample, 'unmapped_scantypes' => $unmappedTypes], JSON_UNESCAPED_UNICODE),
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
     * Track ng isang set ng waybill: batch muna, tapos i-retry nang ISA-ISA
     * ang mga hindi bumalik (J&T TRACKQUERY may cap ~20/call — nadi-drop ang labis).
     * Returns [ waybill => detailsArray ] (yung may laman lang).
     *
     * @param  string[] $waybills
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function trackSet(JntClient $client, array $waybills): array
    {
        $found = [];
        try {
            $resp  = $client->trackForJson(implode(',', $waybills), 'en');
            $found = JntScanStatusMapper::parseResponse($resp);
        } catch (Throwable $e) { $found = []; }

        // Retry ang mga na-drop (wala sa batch response) nang isa-isa.
        $missing = array_values(array_diff($waybills, array_keys($found)));
        foreach ($missing as $wb) {
            try {
                $p = JntScanStatusMapper::parseResponse($client->trackForJson($wb, 'en'));
                if (isset($p[$wb]) && !empty($p[$wb])) $found[$wb] = $p[$wb];
            } catch (Throwable $e) { /* stays missing → tunay na skip */ }
        }

        return $found;
    }

    /** @param array<int,array<int,string>> $sample */
    private function pushSample(array &$sample, array $row): void
    {
        if (count($sample) < self::SAMPLE_MAX) {
            $sample[] = ['waybill' => $row[0], 'from' => $row[1], 'to' => $row[2], 'scantype' => $row[3]];
        }
    }
}
