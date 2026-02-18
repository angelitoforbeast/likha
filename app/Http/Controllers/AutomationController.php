<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\MacroImportRun;
use App\Jobs\ImportMacroFromGoogleSheet;

class AutomationController extends Controller
{
    private function authorizeAutomation(Request $request): void
    {
        $key = (string) $request->header('X-AUTOMATION-KEY');
        abort_unless(
            $key !== '' && hash_equals((string) config('services.automation.key'), $key),
            403,
            'Forbidden'
        );
    }

    // ✅ replicate the "Run Import Now" button
    public function macroImport(Request $request)
    {
        $this->authorizeAutomation($request);

        // ✅ one run at a time (same logic you already use)
        $running = MacroImportRun::whereIn('status', ['queued', 'running'])->latest('id')->first();
        if ($running) {
            return response()->json([
                'ok' => false,
                'message' => "May running import pa (Run #{$running->id}).",
                'run_id' => $running->id,
            ], 409);
        }

        // If you want, you can use a fixed "system user id"
        $startedBy = null;

        $run = MacroImportRun::create([
            'started_by'         => $startedBy,
            'status'             => 'queued',
            'started_at'         => now(),
            'total_settings'     => \App\Models\MacroGsheetSetting::count(),
            'processed_settings' => 0,
            'total_processed'    => 0,
            'total_inserted'     => 0,
            'total_updated'      => 0,
            'total_skipped'      => 0,
            'message'            => 'Triggered via n8n',
        ]);

        // create run items same as MacroGsheetController does
        $settings = \App\Models\MacroGsheetSetting::all();
        foreach ($settings as $s) {
            \App\Models\MacroImportRunItem::create([
                'run_id'      => $run->id,
                'setting_id'  => $s->id,
                'gsheet_name' => $s->gsheet_name,
                'sheet_url'   => $s->sheet_url,
                'sheet_range' => $s->sheet_range,
                'status'      => 'queued',
                'processed'   => 0,
                'inserted'    => 0,
                'updated'     => 0,
                'skipped'     => 0,
                'message'     => null,
                'started_at'  => null,
                'finished_at' => null,
            ]);
        }

        ImportMacroFromGoogleSheet::dispatch($run->id, $startedBy);

        return response()->json([
            'ok' => true,
            'message' => 'Import started',
            'run_id' => $run->id,
            'status_url' => url('/macro/gsheet/import/status?run_id='.$run->id),
        ]);
    }
}
