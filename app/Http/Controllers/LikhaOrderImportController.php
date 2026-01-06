<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Jobs\ImportLikhaFromGoogleSheet;
use App\Models\LikhaOrder;
use App\Models\LikhaOrderSetting;
use App\Models\LikhaImportRun;
use App\Models\LikhaImportRunSheet;

class LikhaOrderImportController extends Controller
{
    public function index(Request $request)
    {
        $settings = LikhaOrderSetting::orderBy('id')->get();
        return view('likha_order.import', compact('settings'));
    }

    // AJAX start import
    public function start(Request $request)
    {
        $settings = LikhaOrderSetting::orderBy('id')->get();

        $run = LikhaImportRun::create([
            'status' => 'running',
            'total_settings' => $settings->count(),
            'started_at' => now(),
        ]);

        foreach ($settings as $s) {
            LikhaImportRunSheet::create([
                'run_id' => $run->id,
                'setting_id' => $s->id,
                'status' => 'queued',
            ]);
        }

        ImportLikhaFromGoogleSheet::dispatch($run->id);

        return response()->json([
            'ok' => true,
            'run_id' => $run->id,
        ]);
    }

    // AJAX polling
    public function status(Request $request)
    {
        $runId = (int) $request->query('run_id');
        $run = LikhaImportRun::with(['sheets.setting'])->findOrFail($runId);

        return response()->json([
            'run' => [
                'id' => $run->id,
                'status' => $run->status,
                'total_settings' => $run->total_settings,
                'total_processed' => $run->total_processed,
                'total_inserted' => $run->total_inserted,
                'total_updated' => $run->total_updated,
                'total_skipped' => $run->total_skipped,
                'total_failed' => $run->total_failed,
                'message' => $run->message,
                'started_at' => optional($run->started_at)->toDateTimeString(),
                'finished_at' => optional($run->finished_at)->toDateTimeString(),
            ],
            'sheets' => $run->sheets->map(function ($rs) {
                return [
                    'setting_id' => $rs->setting_id,
                    'status' => $rs->status,
                    'processed' => $rs->processed_count,
                    'inserted' => $rs->inserted_count,
                    'updated' => $rs->updated_count,
                    'skipped' => $rs->skipped_count,
                    'message' => $rs->message,
                    'spreadsheet_title' => $rs->setting?->spreadsheet_title,
                    'sheet_url' => $rs->setting?->sheet_url,
                    'sheet_id' => $rs->setting?->sheet_id,
                    'range' => $rs->setting?->range,
                ];
            }),
        ]);
    }

    public function view(Request $request)
    {
        if ($request->isMethod('delete')) {
            LikhaOrder::truncate();
            return redirect('/likha_order/view')->with('status', '🗑️ All records deleted.');
        }

        $query = LikhaOrder::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%$search%")
                  ->orWhere('phone_number', 'like', "%$search%")
                  ->orWhere('page_name', 'like', "%$search%");
            });
        }

        if ($request->filled('date')) {
            $query->whereDate('date', $request->input('date'));
        }

        if ($request->filled('page_name')) {
            $query->where('page_name', $request->input('page_name'));
        }

        $orders = $query->latest()->paginate(100);
        $pages = LikhaOrder::select('page_name')->distinct()->pluck('page_name');

        return view('likha_order.view', compact('orders', 'pages'));
    }
}
