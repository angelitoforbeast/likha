<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\MacroImportRun;
use App\Jobs\ImportMacroFromGoogleSheet;
use App\Services\Imports\MacroImportStarter;

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

        // ✅ one run at a time — parehong starter ng button (atomic guard, archived settings hindi kasama).
        // started_by = null (walang system user).
        $result = app(MacroImportStarter::class)->start(null, 'Triggered via n8n');
        if (!$result['started']) {
            $running = $result['run'];
            return response()->json([
                'ok' => false,
                'message' => $running ? "May running import pa (Run #{$running->id})." : 'May running import pa.',
                'run_id' => $running?->id,
            ], 409);
        }

        $run = $result['run'];

        return response()->json([
            'ok' => true,
            'message' => 'Import started',
            'run_id' => $run->id,
            'status_url' => url('/macro/gsheet/import/status?run_id='.$run->id),
        ]);
    }
}
