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
use setasign\Fpdi\Fpdi;
use Illuminate\Support\Facades\File;


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
        $run->save();

        $client = JntClient::fromConfig();

        // Chunk size for PDF parts to avoid RAM blowups (NOT a “limit”, it just splits output)
        $PART_SIZE = 200;

        $baseDir = "jnt_waybills/bulk_runs/run_{$run->id}";

// ✅ ensure directory exists
Storage::disk('local')->makeDirectory($baseDir);

// ✅ get absolute dir path (Windows safe) + ensure exists
$baseAbs = Storage::disk('local')->path($baseDir);
File::ensureDirectoryExists($baseAbs);


        $partPaths = [];
        $partNo = 0;

        // Process items in seq order, in groups of PART_SIZE
        $offset = 0;
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
                    $tmp = tempnam(sys_get_temp_dir(), 'wb_'); // ✅ no ".pdf" append
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
            $offset += $PART_SIZE;
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

        // Decide output:
        // - if only 1 part -> single PDF
        // - if multiple parts -> ZIP the parts (+ FAILED.txt)
        if (count($partPaths) === 1) {
            $run->output_type = 'pdf';
            $run->output_path = $partPaths[0];
        } else {
            $zipRel = "{$baseDir}/waybills-run-{$run->id}.zip";
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

            // optional cleanup parts (keep if you want)
            foreach ($partPaths as $rel) {
                @unlink(storage_path("app/{$rel}"));
            }
        }

        $run->status = 'finished';
        $run->message = 'Done.';
        $run->finished_at = now();
        $run->save();
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
        $date = $run->date ? $run->date->toDateString() : now('Asia/Manila')->subDay()->toDateString();
        $start = $date;
        $end   = Carbon::parse($date, 'Asia/Manila')->addDay()->toDateString();

        $filterBy = $run->filter_by ?: 'page';
        $filterValue = (string)($run->filter_value ?? '');

        $macroBase = DB::table('macro_output')
            ->where('ts_date', '>=', $start)
            ->where('ts_date', '<', $end);

        if ($filterValue !== '') {
            if ($filterBy === 'page') $macroBase->where('PAGE', $filterValue);
            else $macroBase->where('ITEM_NAME', $filterValue);
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
            ->when($filterValue !== '', function ($q) use ($filterBy, $filterValue) {
                if ($filterBy === 'page') $q->where('m.PAGE', $filterValue);
                else $q->where('m.ITEM_NAME', $filterValue);
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
