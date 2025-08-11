<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\UploadLog;
use App\Jobs\ProcessJntUpload;

class JntUploadController extends Controller
{
    public function store(Request $request)
    {
        // 1) Validate file
        try {
        $request->validate([
            'file' => 'required|file|mimes:zip,csv,xlsx,xls|max:204800', // ~200MB (adjust as needed)
        ]);

        $file = $request->file('file');

        // 2) Persist file to storage (local disk)
        $folder   = 'uploads/jnt/' . now()->format('Y-m-d');
        $basename = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $filename = $basename . '__' . now()->format('His') . '.' . $file->getClientOriginalExtension();

        $path = $file->storeAs($folder, $filename, 'local');

        // 3) Create UploadLog (status: queued)
        $log = UploadLog::create([
            'type'          => 'jnt',
            'disk'          => 'local',
            'path'          => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type'     => $file->getMimeType(),
            'size'          => $file->getSize(),
            'status'        => 'queued',
        ]);

        // 4) Dispatch background job
        ProcessJntUpload::dispatch($log->id);

        // 5) Return JSON (front-end can poll /status)
        return response()->json([
            'id'     => $log->id,
            'status' => $log->status,
            'path'   => $log->path,
        ], 201);
        } catch (\Throwable $e) {
        \Log::error('Upload error: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);
        return response()->json([
            'error' => true,
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
