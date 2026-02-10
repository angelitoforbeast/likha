<?php

namespace App\Jobs;

use App\Models\JntWaybillPrintRun;
use App\Models\JntWaybillPrintRunItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class CleanupJntWaybillBulkRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $runId) {}

    public function handle(): void
    {
        $run = JntWaybillPrintRun::find($this->runId);
        if (!$run) return;

        // not yet expired => skip
        if ($run->expires_at && now()->lt($run->expires_at)) return;

        $baseDir = "jnt_waybills/bulk_runs/run_{$run->id}";
        Storage::disk('local')->deleteDirectory($baseDir);

        // optional: delete run items to save DB space
        JntWaybillPrintRunItem::where('run_id', $run->id)->delete();

        // keep run record but clear output_path
        $run->output_path = null;
        $run->message = trim(($run->message ?? '') . ' (cleaned)');
        $run->save();
    }
}
