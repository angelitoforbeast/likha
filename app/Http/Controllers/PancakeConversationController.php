<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\UploadLog;
use App\Jobs\ProcessPancakeConversationsUpload;

class PancakeConversationController extends Controller
{
    public function index()
    {
        $logs = UploadLog::where('type', 'pancake_conversations')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $latest = $logs->first();

        return view('pancake.conversations', [
            'status'   => $latest?->status ?? 'idle',
            'inserted' => (int) ($latest?->inserted ?? 0),
            'updated'  => (int) ($latest?->updated ?? 0),
            'logs'     => $logs,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'files'   => 'required|array|min:1',
            'files.*' => 'required|file|mimes:csv,txt,xlsx,xls,zip',
        ]);

        $disk = config('filesystems.default'); // local/offline OR s3/heroku

        $queued = [];
        foreach ($request->file('files') as $i => $file) {
            $originalName = $file->getClientOriginalName();
            $safeName = preg_replace('/\s+/', '_', $originalName);

            $storedPath = $file->storeAs(
                'uploads/pancake_conversations',
                now()->format('Ymd_His').'__'.($i+1).'__'.$safeName,
                $disk
            );

            $log = UploadLog::create([
                'type'           => 'pancake_conversations',
                'disk'           => $disk,
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

            ProcessPancakeConversationsUpload::dispatch($storedPath, auth()->id(), $log->id);

            $queued[] = "#{$log->id} ".basename($storedPath);
        }

        return redirect()
            ->route('pancake.conversations')
            ->with('status', 'Queued '.count($queued).' file(s): '.implode(', ', $queued));
    }

    public function status()
    {
        $logs = UploadLog::where('type', 'pancake_conversations')
            ->orderByDesc('id')
            ->limit(50)
            ->get([
                'id','status','original_name','processed_rows','inserted','updated','skipped','error_rows',
                'started_at','finished_at','created_at'
            ]);

        return response()->json([
            'logs' => $logs->map(fn($l) => [
                'id'             => (int) $l->id,
                'status'         => (string) ($l->status ?? 'idle'),
                'original_name'  => (string) ($l->original_name ?? ''),
                'processed_rows' => (int) ($l->processed_rows ?? 0),
                'inserted'       => (int) ($l->inserted ?? 0),
                'updated'        => (int) ($l->updated ?? 0),
                'skipped'        => (int) ($l->skipped ?? 0),
                'error_rows'     => (int) ($l->error_rows ?? 0),
                'started_at'     => $l->started_at?->toDateTimeString(),
                'finished_at'    => $l->finished_at?->toDateTimeString(),
                'created_at'     => $l->created_at?->toDateTimeString(),
            ]),
        ]);
    }
}
