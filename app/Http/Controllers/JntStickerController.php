<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessJntStickerSegregation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class JntStickerController extends Controller
{
    private const SESS_COMMIT = 'jnt_stickers_commit';

    public function index(Request $request)
    {
        $filterDate = (string)($request->query('filter_date') ?? '');

        return view('jnt.stickers', [
            'filter_date' => $filterDate,
            'server_limits' => [
                'max_file_uploads' => (int)ini_get('max_file_uploads'),
                'upload_max_filesize' => (string)ini_get('upload_max_filesize'),
                'post_max_size' => (string)ini_get('post_max_size'),
            ],
        ]);
    }

    /**
     * AJAX: Load DB waybills for a given date (YYYY-MM-DD)
     * macro_output.TIMESTAMP format: "21:44 09-06-2025"
     */
    public function dbWaybills(Request $request)
    {
        $request->validate([
            'filter_date' => ['required', 'date'],
        ]);

        $filterDate = (string)$request->input('filter_date');

        $waybills = DB::table('macro_output')
            ->select('waybill')
            ->whereNotNull('waybill')
            ->whereRaw("DATE(STR_TO_DATE(`TIMESTAMP`, '%H:%i %d-%m-%Y')) = ?", [$filterDate])
            ->pluck('waybill')
            ->map(fn ($v) => trim((string)$v))
            ->filter(fn ($v) => $v !== '')
            ->values()
            ->all();

        return response()->json([
            'ok' => true,
            'filter_date' => $filterDate,
            'count' => count($waybills),
            'waybills' => $waybills, // duplicates preserved
        ]);
    }

    /**
     * Start a commit session.
     */
    public function commitInit(Request $request)
    {
        $request->validate([
            'filter_date' => ['nullable', 'date'],
            'client_summary' => ['nullable', 'array'],
            'client_compare' => ['nullable', 'array'],
        ]);

        $commitId = (string) Str::uuid();
        $filterDate = (string)($request->input('filter_date') ?? '');

        $payload = [
            'commit_id' => $commitId,
            'filter_date' => $filterDate,
            'started_at' => now()->toDateTimeString(),
            'received_files' => 0,
            'received_bytes' => 0,
            'client_summary' => $request->input('client_summary', []),
            'client_compare' => $request->input('client_compare', []),
            'uploaded_files' => [],   // will append during uploads
            'pdf_items' => [],        // will be stored on finalize
        ];

        session([self::SESS_COMMIT => $payload]);

        Storage::disk('local')->makeDirectory("jnt_stickers/commits/{$commitId}/pdf");
        Storage::disk('local')->makeDirectory("jnt_stickers/commits/{$commitId}/segregated");

        // Initialize status file so UI polling can show immediately
        Storage::disk('local')->put(
            "jnt_stickers/commits/{$commitId}/status.json",
            json_encode([
                'status' => 'queued',
                'progress' => 0,
                'message' => 'Commit started.',
                'files' => [],
                'zip' => null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return response()->json([
            'ok' => true,
            'commit_id' => $commitId,
            'server_limits' => [
                'max_file_uploads' => (int)ini_get('max_file_uploads'),
                'upload_max_filesize' => (string)ini_get('upload_max_filesize'),
                'post_max_size' => (string)ini_get('post_max_size'),
            ],
        ]);
    }

    /**
     * Upload one chunk of files.
     * Stores to storage/app/jnt_stickers/commits/{commitId}/pdf
     *
     * IMPORTANT:
     * - We accept file_indexes[] aligned with pdf_files[]
     * - This provides reliable mapping for later segregation
     */
    public function commitUploadChunk(Request $request)
    {
        $request->validate([
            'commit_id' => ['required', 'string'],
            'pdf_files' => ['required', 'array'],
            'pdf_files.*' => ['required', 'file', 'mimes:pdf', 'max:51200'], // 50MB each
            'file_indexes' => ['required', 'array'],
            'file_indexes.*' => ['required', 'integer', 'min:1'],
        ]);

        $commitId = (string)$request->input('commit_id');

        $sess = session(self::SESS_COMMIT);
        if (!is_array($sess) || ($sess['commit_id'] ?? null) !== $commitId) {
            return response()->json([
                'ok' => false,
                'message' => 'Invalid or expired commit session.',
            ], 422);
        }

        $stored = 0;
        $bytes = 0;
        $filesMeta = [];

        $files = $request->file('pdf_files');
        $idxs  = $request->input('file_indexes', []);

        foreach ($files as $i => $file) {
            $orig = $file->getClientOriginalName();
            $clientIndex = (int)($idxs[$i] ?? 0);

            // Prevent overwrite: prefix with uuid
            $safeName = Str::uuid()->toString() . '-' . $orig;

            $path = $file->storeAs("jnt_stickers/commits/{$commitId}/pdf", $safeName, 'local');

            $stored++;
            $bytes += (int)$file->getSize();

            $filesMeta[] = [
                'client_index' => $clientIndex,
                'original' => $orig,
                'stored_as' => $safeName,
                'path' => $path,
                'size' => (int)$file->getSize(),
            ];
        }

        $sess['received_files'] = (int)($sess['received_files'] ?? 0) + $stored;
        $sess['received_bytes'] = (int)($sess['received_bytes'] ?? 0) + $bytes;
        $sess['uploaded_files'] = array_merge($sess['uploaded_files'] ?? [], $filesMeta);

        session([self::SESS_COMMIT => $sess]);

        // Update status.json (upload progress)
        $statusPath = "jnt_stickers/commits/{$commitId}/status.json";
        $status = [
            'status' => 'uploading',
            'progress' => 10,
            'message' => "Uploaded {$sess['received_files']} file(s).",
            'files' => [],
            'zip' => null,
        ];
        Storage::disk('local')->put($statusPath, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return response()->json([
            'ok' => true,
            'stored' => $stored,
            'total_received_files' => (int)$sess['received_files'],
            'total_received_bytes' => (int)$sess['received_bytes'],
        ]);
    }

    /**
     * Finalize commit:
     * - store audit.json
     * - DISPATCH Job immediately to segregate by ITEM NAME
     */
    public function commitFinalize(Request $request)
    {
        $request->validate([
            'commit_id' => ['required', 'string'],
            'filter_date' => ['nullable', 'date'],
            'client_summary' => ['nullable', 'array'],
            'client_compare' => ['nullable', 'array'],
            'table_rows' => ['nullable', 'array'],
            'pdf_items' => ['nullable', 'array'], // ✅ page map for segregation
        ]);

        $commitId = (string)$request->input('commit_id');

        $sess = session(self::SESS_COMMIT);
        if (!is_array($sess) || ($sess['commit_id'] ?? null) !== $commitId) {
            return response()->json([
                'ok' => false,
                'message' => 'Invalid or expired commit session.',
            ], 422);
        }

        $sess['filter_date'] = (string)($request->input('filter_date') ?? ($sess['filter_date'] ?? ''));
        $sess['client_summary'] = $request->input('client_summary', $sess['client_summary'] ?? []);
        $sess['client_compare'] = $request->input('client_compare', $sess['client_compare'] ?? []);
        $sess['table_rows'] = $request->input('table_rows', []);
        $sess['pdf_items'] = $request->input('pdf_items', []); // ✅
        $sess['finalized_at'] = now()->toDateTimeString();

        // Write audit JSON
        $jsonPath = "jnt_stickers/commits/{$commitId}/audit.json";
        Storage::disk('local')->put($jsonPath, json_encode($sess, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Update status to queued processing
        Storage::disk('local')->put(
            "jnt_stickers/commits/{$commitId}/status.json",
            json_encode([
                'status' => 'queued',
                'progress' => 15,
                'message' => 'Queued for segregation...',
                'files' => [],
                'zip' => null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // ✅ Dispatch Job immediately
        try {
            ProcessJntStickerSegregation::dispatch($commitId);
        } catch (\Throwable $e) {
            Log::error("Failed dispatch segregation job for commit {$commitId}: ".$e->getMessage());

            Storage::disk('local')->put(
                "jnt_stickers/commits/{$commitId}/status.json",
                json_encode([
                    'status' => 'failed',
                    'progress' => 0,
                    'message' => 'Failed to dispatch segregation job: '.$e->getMessage(),
                    'files' => [],
                    'zip' => null,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );

            return response()->json([
                'ok' => false,
                'message' => 'Commit finalized but segregation job failed to dispatch.',
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'commit_id' => $commitId,
            'received_files' => (int)($sess['received_files'] ?? 0),
            'audit_json' => $jsonPath,
            'segregation' => 'queued',
        ]);
    }

    /**
     * Poll status for UI to show downloadable outputs when ready.
     */
    public function commitStatus(Request $request)
    {
        $request->validate([
            'commit_id' => ['required', 'string'],
        ]);

        $commitId = (string)$request->input('commit_id');

        $statusPath = "jnt_stickers/commits/{$commitId}/status.json";
        if (!Storage::disk('local')->exists($statusPath)) {
            return response()->json([
                'ok' => true,
                'status' => 'queued',
                'progress' => 0,
                'message' => 'Waiting for status...',
                'files' => [],
                'zip' => null,
            ]);
        }

        $json = json_decode(Storage::disk('local')->get($statusPath), true) ?: [];

        return response()->json([
            'ok' => true,
            'status' => $json['status'] ?? 'processing',
            'progress' => (int)($json['progress'] ?? 0),
            'message' => $json['message'] ?? null,
            'files' => $json['files'] ?? [],
            'zip' => $json['zip'] ?? null,
        ]);
    }

    /**
     * Download generated output file safely.
     * Usage:
     * /jnt/stickers/commit/download?commit_id=...&file=jnt_stickers/commits/<id>/segregated/outputs.zip
     */
    public function commitDownload(Request $request)
    {
        $request->validate([
            'commit_id' => ['required', 'string'],
            'file' => ['required', 'string'],
        ]);

        $commitId = (string)$request->input('commit_id');
        $file = (string)$request->input('file');

        $base = "jnt_stickers/commits/{$commitId}/";
        if (!str_starts_with($file, $base)) {
            abort(403);
        }

        if (!Storage::disk('local')->exists($file)) {
            abort(404);
        }

        return Storage::disk('local')->download($file);
    }
}
