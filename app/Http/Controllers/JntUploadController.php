<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\UploadLog;
use App\Jobs\ProcessJntUpload;
use Carbon\Carbon;

class JntUploadController extends Controller
{
    public function store(Request $request)
    {
        try {
            // 1) Validate file + optional batch_at
            $request->validate([
                'file'     => 'required|file|mimes:zip,csv,xlsx,xls|max:204800', // ~200MB (adjust as needed)
                'batch_at' => 'nullable|string', // datetime-local galing UI
            ]);

            $file = $request->file('file');

            // 2) Parse optional batch_at (datetime-local: "2025-12-10T02:00")
            $rawBatchAt = $request->input('batch_at');
            $batchAt    = null;

            if (!empty($rawBatchAt)) {
                try {
                    // kung datetime-local, ito na safe:
                    // Y-m-d\TH:i  (ex: 2025-12-10T02:00)
                    $batchAt = Carbon::createFromFormat('Y-m-d\TH:i', $rawBatchAt, 'Asia/Manila');
                } catch (\Throwable $e) {
                    // fallback generic parse; kung di pa rin kaya, mananatiling null
                    try {
                        $batchAt = Carbon::parse($rawBatchAt, 'Asia/Manila');
                    } catch (\Throwable $e2) {
                        $batchAt = null;
                    }
                }
            }

            // 3) Persist file to storage (local disk)
            $folder   = 'uploads/jnt/' . now()->format('Y-m-d');
            $basename = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
            $filename = $basename . '__' . now()->format('His') . '.' . $file->getClientOriginalExtension();

            $path = $file->storeAs($folder, $filename, 'local');

            // 4) Create UploadLog (status: queued) + ✅ save batch_at
            $log = UploadLog::create([
                'type'          => 'jnt',
                'disk'          => 'local',
                'path'          => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type'     => $file->getMimeType(),
                'size'          => $file->getSize(),
                'status'        => 'queued',
                'batch_at'      => $batchAt ? $batchAt->format('Y-m-d H:i:s') : null,
            ]);

            // 5) Dispatch background job
            ProcessJntUpload::dispatch($log->id);

            // 6) Return JSON (front-end can poll /status)
            return response()->json([
                'id'     => $log->id,
                'status' => $log->status,
                'path'   => $log->path,
            ], 201);
        } catch (\Throwable $e) {
            \Log::error('Upload error: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'error'   => true,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function index()
    {
        return view('jnt_upload'); // resources/views/jnt_upload.blade.php
    }

    public function status(UploadLog $uploadLog)
    {
        // Simple JSON status (front-end can poll every 2–5s)
        return response()->json([
            'id'              => $uploadLog->id,
            'status'          => $uploadLog->status, // queued|processing|done|failed
            'processed_rows'  => $uploadLog->processed_rows,
            'total_rows'      => $uploadLog->total_rows,
            'inserted'        => $uploadLog->inserted,
            'updated'         => $uploadLog->updated,
            'skipped'         => $uploadLog->skipped,
            'error_rows'      => $uploadLog->error_rows,
            'errors_path'     => $uploadLog->errors_path,
            'started_at'      => optional($uploadLog->started_at)?->toDateTimeString(),
            'finished_at'     => optional($uploadLog->finished_at)?->toDateTimeString(),
        ]);
    }
}
