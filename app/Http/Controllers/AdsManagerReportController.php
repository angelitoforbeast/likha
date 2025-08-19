<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\UploadLog;
use App\Jobs\ProcessAdsManagerReportsUpload;

class AdsManagerReportController extends Controller
{
    public function index()
    {
        $latest = UploadLog::where('type', 'ads_manager_reports')
            ->orderByDesc('id')
            ->first();

        return view('ads_manager.report', [
            'status'   => $latest?->status ?? 'idle',
            'inserted' => $latest?->inserted ?? 0,
            'updated'  => $latest?->updated ?? 0,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx,xls',
        ]);

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();

        // save to storage/app (local)
        $storedPath = $file->storeAs(
            'uploads/ads_manager_reports',
            now()->format('Ymd_His').'__'.preg_replace('/\s+/', '_', $originalName),
            'local'
        );

        // create upload log (ensure 'original_name' column exists and is fillable)
        $log = UploadLog::create([
            'type'           => 'ads_manager_reports',
            'path'           => $storedPath,
            'original_name'  => $originalName,
            'status'         => 'queued',
            'processed_rows' => 0,
            'inserted'       => 0,
            'updated'        => 0,
            'skipped'        => 0,
            'error_rows'     => 0,
            'user_id'        => auth()->id(),
        ]);

        // dispatch the job (pass log id so it can update status/counters)
        ProcessAdsManagerReportsUpload::dispatch($storedPath, auth()->id(), $log->id);

        return redirect()
            ->route('ads_manager.report')
            ->with('status', '📤 Queued: '.basename($storedPath).' (Log #'.$log->id.')');
    }

    // Lightweight JSON status for front-end polling
    public function status()
    {
        $latest = UploadLog::where('type', 'ads_manager_reports')
            ->orderByDesc('id')
            ->first();

        if (!$latest) {
            return response()->json([
                'status'   => 'idle',
                'inserted' => 0,
                'updated'  => 0,
            ]);
        }

        return response()->json([
            'status'   => $latest->status,
            'inserted' => (int) ($latest->inserted ?? 0),
            'updated'  => (int) ($latest->updated ?? 0),
        ]);
    }
}
