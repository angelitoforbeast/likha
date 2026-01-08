<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MacroGsheetSetting;
use App\Models\MacroImportRun;
use App\Jobs\ImportMacroFromGoogleSheet;

class MacroImportController extends Controller
{
    // GET /macro/gsheet/import  (UI page)
    public function index()
    {
        $settings = MacroGsheetSetting::all();
        return view('macro.gsheet.import', compact('settings'));
    }

    // POST /macro/gsheet/import/start (start job)
    public function start(Request $request)
    {
        $run = MacroImportRun::create([
            'status' => 'queued',
            'message' => 'Queued…',
        ]);

        dispatch(new ImportMacroFromGoogleSheet($run->id));

        return response()->json([
            'ok' => true,
            'runId' => $run->id,
        ]);
    }

    // GET /macro/gsheet/import/status/{runId} (polling)
    public function status($runId)
    {
        $run = MacroImportRun::findOrFail($runId);

        return response()->json([
            'ok' => true,
            'run' => $run,
        ]);
    }
}
