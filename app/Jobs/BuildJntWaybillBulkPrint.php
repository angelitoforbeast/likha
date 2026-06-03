<?php

namespace App\Jobs;

use App\Models\JntWaybillPrintRun;
use App\Models\JntWaybillPrintRunItem;
use App\Services\Jnt\JntClient;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use setasign\Fpdi\Fpdi;

class BuildJntWaybillBulkPrint implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600; // 1 hour
    public int $tries = 1;

    public function __construct(public int $runId) {}

    public function handle(): void
    {
        set_time_limit(0);

        $run = JntWaybillPrintRun::query()->find($this->runId);
        if (!$run) return;

        if (in_array((string)$run->status, ['finished','failed','cancelled'], true)) return;

        $run->status = 'building';
        $run->started_at = now();
        $run->message = 'Preparing list...';
        $run->save();

        // If mode=all, build the mailno list here (so controller stays instant and never 504)
        if ($run->mode === 'all') {
            $this->buildItemsForAll($run);
        } else {
            // selected mode must already have items inserted by controller
            $run->total = (int) JntWaybillPrintRunItem::query()->where('run_id', $run->id)->count();
            $run->save();
        }

        if ((int)$run->total <= 0) {
            $run->status = 'failed';
            $run->message = 'No mailno found to print.';
            $run->finished_at = now();
            $run->save();
            return;
        }

        $run->status = 'running';
        $run->message = 'Generating PDFs...';
        $run->processed = 0;
        $run->ok_count = 0;
        $run->fail_count = 0;
        $run->pages = 0;
        $run->save();

        \Illuminate\Support\Facades\Log::info('Waybill bulk print START', [
            'run_id' => $run->id,
            'total'  => (int)$run->total,
            'started_at' => optional($run->started_at)->toDateTimeString(),
        ]);

        // Per-page J&T client cache. Each waybill picks its account via
        // JntClient::fromPageOrConfig($page). Items with no resolvable page
        // (mailno not sa jnt_shipments) fall back to env credentials.
        // Keyed by string page name; '' key = env-config fallback.
        $clientsByPage = [];
        $getClient = function (?string $page) use (&$clientsByPage): JntClient {
            $key = trim((string) $page);
            if (!isset($clientsByPage[$key])) {
                $clientsByPage[$key] = $key !== ''
                    ? JntClient::fromPageOrConfig($key)
                    : JntClient::fromConfig();
            }
            return $clientsByPage[$key];
        };

        // Resolve page-per-mailno via JOIN through jnt_shipments → macro_output.
        // ONE batch query at start of run; cached in $pageByMailno for the
        // entire print loop. Mailnos with no match → no entry → env fallback.
        $allMailnos = JntWaybillPrintRunItem::query()
            ->where('run_id', $run->id)
            ->where('status', 'pending')
            ->pluck('mailno')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $pageByMailno = [];
        if (!empty($allMailnos)) {
            $pageByMailno = DB::table('jnt_shipments as s')
                ->join('macro_output as mo', 'mo.id', '=', 's.macro_output_id')
                ->whereIn('s.mailno', $allMailnos)
                ->whereNotNull('mo.PAGE')
                ->select('s.mailno', 'mo.PAGE as page')
                ->pluck('page', 'mailno')
                ->all();
        }

        // Chunk size for PDF parts to avoid RAM blowups
        $PART_SIZE = 1000;

        $baseDir = "jnt_waybills/bulk_runs/run_{$run->id}";

        // ✅ ensure directory exists
        Storage::disk('local')->makeDirectory($baseDir);

        // ✅ get absolute dir path + ensure exists
        $baseAbs = Storage::disk('local')->path($baseDir);
        File::ensureDirectoryExists($baseAbs);

        $partPaths = [];
        $partNo = 0;

        // Process items in seq order, in groups of PART_SIZE
        while (true) {
            // allow cancel
            $fresh = JntWaybillPrintRun::query()->find($run->id);
            if ($fresh && (string)$fresh->status === 'cancelled') {
                return;
            }

            $items = JntWaybillPrintRunItem::query()
                ->where('run_id', $run->id)
                ->where('status', 'pending')
                ->orderBy('seq')
                ->limit($PART_SIZE)
                ->get();

            if ($items->isEmpty()) break;

            $partNo++;
            $pdf = new Fpdi();
            $pdf->SetAutoPageBreak(false);

            $okInThisPart = 0;

            foreach ($items as $it) {
                $mailno = (string) $it->mailno;

                try {
                    // Pick the right J&T account based on this waybill's source
                    // page (resolved via pre-built $pageByMailno map). No
                    // match → fallback to env credentials.
                    $page   = $pageByMailno[$mailno] ?? null;
                    $client = $getClient($page);
                    $res = $client->printWaybill($mailno);

                    $b64 = data_get($res, 'responseitems.base64Url')
                        ?? data_get($res, 'responseitems.0.base64Url');

                    if (!$b64) {
                        $this->markFail($run, $it, "{$mailno} | no base64Url");
                        continue;
                    }

                    $bytes = base64_decode(preg_replace('/\s+/', '', (string) $b64), true);
                    if ($bytes === false) {
                        $this->markFail($run, $it, "{$mailno} | invalid base64");
                        continue;
                    }

                    // FPDI needs a temp file
                    $tmp = tempnam(sys_get_temp_dir(), 'wb_');
                    file_put_contents($tmp, $bytes);

                    $pageCount = $pdf->setSourceFile($tmp);
                    for ($p = 1; $p <= $pageCount; $p++) {
                        $tpl = $pdf->importPage($p);
                        $size = $pdf->getTemplateSize($tpl);
                        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                        $pdf->useTemplate($tpl);
                    }

                    @unlink($tmp);

                    // mark ok
                    $it->status = 'ok';
                    $it->error = null;
                    $it->save();

                    $run->processed++;
                    $run->ok_count++;
                    $run->pages += (int) $pageCount;  // total PDF pages (for pages/min)
                    $okInThisPart++;

                } catch (\Throwable $e) {
                    $this->markFail($run, $it, "{$mailno} | " . $e->getMessage());
                }

                // light progress saves (every 10)
                if (($run->processed % 10) === 0) {
                    $run->save();
                }
            }

            // write this part only if it has at least 1 ok
            if ($okInThisPart > 0) {
                $partRel = "{$baseDir}/part_" . str_pad((string)$partNo, 3, '0', STR_PAD_LEFT) . ".pdf";
                $partAbs = Storage::disk('local')->path($partRel);
                File::ensureDirectoryExists(dirname($partAbs));

                // ✅ correct FPDF call: Output(dest, name)
                $pdf->Output('F', $partAbs);

                $partPaths[] = $partRel;
            }

            // save after each part
            $run->save();
        }

        // If nothing succeeded
        if ((int)$run->ok_count <= 0) {
            $run->status = 'failed';
            $run->message = 'No waybills were generated (all failed).';
            $run->finished_at = now();
            $run->save();
            return;
        }

        // Create FAILED.txt if needed
        $failedLines = JntWaybillPrintRunItem::query()
            ->where('run_id', $run->id)
            ->where('status', 'fail')
            ->orderBy('seq')
            ->pluck('error')
            ->filter()
            ->values()
            ->all();

        $failedTxtRel = null;
        if (!empty($failedLines)) {
            $failedTxtRel = "{$baseDir}/FAILED.txt";
            Storage::disk('local')->put($failedTxtRel, implode("\n", $failedLines));
        }

        // ✅ Build output base filename (FILTER DATE + FILTER VALUE + time generated)
        $filterDate = $run->date
            ? Carbon::parse($run->date, 'Asia/Manila')
            : now('Asia/Manila')->subDay();

        $filterDatePart = strtoupper($filterDate->format('M')) . '_' . $filterDate->format('d') . '_' . $filterDate->format('Y'); // FEB_09_2026

        $rawName = trim((string)($run->filter_value ?? ''));
        if ($rawName === '') {
            $rawName = ((string)($run->filter_by ?? 'page') === 'item') ? 'ALL_ITEMS' : 'ALL_PAGES';
        }

        $namePart = (string) Str::of($rawName)
            ->replaceMatches('/[^A-Za-z0-9]+/', '_')
            ->replaceMatches('/_+/', '_')
            ->trim('_');

        $namePart = Str::limit($namePart, 60, '');

        $timePart = now('Asia/Manila')->format('mdy_Hi'); // 021026_2344
        $baseName = "{$filterDatePart}_{$namePart}_{$timePart}";

        // Decide output:
        // - if only 1 part -> single PDF (rename to baseName)
        // - if multiple parts -> ZIP the parts (+ FAILED.txt) named baseName
        if (count($partPaths) === 1) {
            $finalRel = "{$baseDir}/{$baseName}.pdf";

            // rename part_001.pdf -> baseName.pdf
            Storage::disk('local')->move($partPaths[0], $finalRel);

            $run->output_type = 'pdf';
            $run->output_path = $finalRel;

        } else {
            $zipRel = "{$baseDir}/{$baseName}.zip";
            $zipAbs = storage_path("app/{$zipRel}");

            $zip = new \ZipArchive();
            $okOpen = $zip->open($zipAbs, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            if ($okOpen !== true) {
                $run->status = 'failed';
                $run->message = "Cannot create ZIP output.";
                $run->finished_at = now();
                $run->save();
                return;
            }

            foreach ($partPaths as $rel) {
                $zip->addFile(storage_path("app/{$rel}"), basename($rel));
            }
            if ($failedTxtRel) {
                $zip->addFile(storage_path("app/{$failedTxtRel}"), 'FAILED.txt');
            }

            $zip->close();

            $run->output_type = 'zip';
            $run->output_path = $zipRel;

            // optional cleanup parts
            foreach ($partPaths as $rel) {
                @unlink(storage_path("app/{$rel}"));
            }
        }

        $run->status = 'finished';
        $run->message = 'Done.';
        $run->finished_at = now();
        $run->save();

        // ── Timing log (how long the print took + throughput) ───────────────
        $durSec = ($run->started_at && $run->finished_at)
            ? max(0, $run->finished_at->diffInSeconds($run->started_at))
            : null;
        $ppm = ($durSec && $durSec > 0) ? round(((int)$run->pages) / ($durSec / 60), 1) : null;
        \Illuminate\Support\Facades\Log::info('Waybill bulk print DONE', [
            'run_id'        => $run->id,
            'total'         => (int)$run->total,
            'ok'            => (int)$run->ok_count,
            'fail'          => (int)$run->fail_count,
            'pages'         => (int)$run->pages,
            'duration_sec'  => $durSec,
            'pages_per_min' => $ppm,
            'started_at'    => optional($run->started_at)->toDateTimeString(),
            'finished_at'   => optional($run->finished_at)->toDateTimeString(),
        ]);
    }

    private function markFail(JntWaybillPrintRun $run, JntWaybillPrintRunItem $it, string $msg): void
    {
        $it->status = 'fail';
        $it->error = $msg;
        $it->save();

        $run->processed++;
        $run->fail_count++;
    }

    private function buildItemsForAll(JntWaybillPrintRun $run): void
    {
        $date = $run->date
            ? Carbon::parse($run->date, 'Asia/Manila')->toDateString()
            : now('Asia/Manila')->subDay()->toDateString();

        $start = $date;
        $end   = Carbon::parse($date, 'Asia/Manila')->addDay()->toDateString();

        $filterBy = $run->filter_by ?: 'page';
        $filterValue = (string)($run->filter_value ?? '');

        $macroBase = DB::table('macro_output')
            ->where('ts_date', '>=', $start)
            ->where('ts_date', '<', $end);

        // For item_type, resolve to a list of item_names first
        $itemNamesForType = collect();
        if ($filterValue !== '' && $filterBy === 'item_type') {
            $itemNamesForType = DB::table('item_type_mappings')
                ->where('item_type', $filterValue)
                ->pluck('item_name');
        }

        if ($filterValue !== '') {
            if ($filterBy === 'page')      $macroBase->where('PAGE', $filterValue);
            elseif ($filterBy === 'item_type') $macroBase->whereIn('ITEM_NAME', $itemNamesForType);
            else                           $macroBase->where('ITEM_NAME', $filterValue);
        }

        $macroIdsSub = (clone $macroBase)->select('id');

        $latestShipments = DB::table('jnt_shipments')
            ->selectRaw('macro_output_id, MAX(id) as shipment_id')
            ->whereIn('macro_output_id', $macroIdsSub)
            ->whereNotNull('mailno')->where('mailno', '!=', '')
            ->groupBy('macro_output_id');

        // Pull (macro_id, mailno) in chunks
        $seq = 1;

        DB::table('macro_output as m')
            ->leftJoinSub($latestShipments, 'ls', fn($join) => $join->on('ls.macro_output_id', '=', 'm.id'))
            ->leftJoin('jnt_shipments as s', 's.id', '=', 'ls.shipment_id')
            ->where('m.ts_date', '>=', $start)
            ->where('m.ts_date', '<', $end)
            ->whereNotNull('s.mailno')->where('s.mailno', '!=', '')
            ->when($filterValue !== '', function ($q) use ($filterBy, $filterValue, $itemNamesForType) {
                if ($filterBy === 'page')           $q->where('m.PAGE', $filterValue);
                elseif ($filterBy === 'item_type')  $q->whereIn('m.ITEM_NAME', $itemNamesForType);
                else                                $q->where('m.ITEM_NAME', $filterValue);
            })
            ->select(['m.id as macro_id', 's.mailno as mailno'])
            ->orderBy('m.id')
            ->chunk(1000, function ($chunk) use ($run, &$seq) {
                $toInsert = [];
                foreach ($chunk as $row) {
                    $toInsert[] = [
                        'run_id' => $run->id,
                        'macro_output_id' => (int)$row->macro_id,
                        'mailno' => (string)$row->mailno,
                        'seq' => $seq++,
                        'status' => 'pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                if (!empty($toInsert)) {
                    DB::table('jnt_waybill_print_run_items')->insertOrIgnore($toInsert);
                }
            });

        $run->total = (int) JntWaybillPrintRunItem::query()->where('run_id', $run->id)->count();
        $run->message = "Prepared {$run->total} mailnos.";
        $run->save();
    }
}
