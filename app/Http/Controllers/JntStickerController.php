<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class JntStickerController extends Controller
{
    private const SESS_COMMIT = 'jnt_stickers_commit';

    public function index(Request $request)
    {
        // Optional: allow query prefill (keeps your old behavior if you want)
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
            'waybills' => $waybills, // keep raw list (duplicates preserved if present)
        ]);
    }

    /**
     * Start a commit session.
     * Client sends summary counts + chosen date + some metadata.
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
        ];

        session([self::SESS_COMMIT => $payload]);

        // Directory for this commit
        Storage::disk('local')->makeDirectory("jnt_stickers/commits/{$commitId}/pdf");

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
     * Upload one chunk of files (<= max_file_uploads).
     * Stores files to storage/app/jnt_stickers/commits/{commitId}/pdf
     */
    public function commitUploadChunk(Request $request)
    {
        $request->validate([
            'commit_id' => ['required', 'string'],
            'pdf_files' => ['required', 'array'],
            'pdf_files.*' => ['required', 'file', 'mimes:pdf', 'max:51200'], // 50MB each (adjust)
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

        foreach ($request->file('pdf_files') as $file) {
            $orig = $file->getClientOriginalName();

            // Prevent overwrite: prefix with short uuid
            $safeName = Str::uuid()->toString() . '-' . $orig;

            $path = $file->storeAs("jnt_stickers/commits/{$commitId}/pdf", $safeName, 'local');

            $stored++;
            $bytes += (int)$file->getSize();

            $filesMeta[] = [
                'original' => $orig,
                'stored_as' => $safeName,
                'path' => $path,
                'size' => (int)$file->getSize(),
            ];
        }

        $sess['received_files'] = (int)($sess['received_files'] ?? 0) + $stored;
        $sess['received_bytes'] = (int)($sess['received_bytes'] ?? 0) + $bytes;

        // Append chunk files list for audit
        $sess['uploaded_files'] = array_merge($sess['uploaded_files'] ?? [], $filesMeta);

        session([self::SESS_COMMIT => $sess]);

        return response()->json([
            'ok' => true,
            'stored' => $stored,
            'total_received_files' => (int)$sess['received_files'],
            'total_received_bytes' => (int)$sess['received_bytes'],
        ]);
    }

    /**
     * Finalize commit: store JSON audit file (summary + compare rows).
     * This is your “commit = save on disk” checkpoint.
     */
    public function commitFinalize(Request $request)
    {
        $request->validate([
            'commit_id' => ['required', 'string'],
            'filter_date' => ['nullable', 'date'],
            'client_summary' => ['nullable', 'array'],
            'client_compare' => ['nullable', 'array'],
            'table_rows' => ['nullable', 'array'],
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
        $sess['finalized_at'] = now()->toDateTimeString();

        // Write audit JSON
        $jsonPath = "jnt_stickers/commits/{$commitId}/audit.json";
        Storage::disk('local')->put($jsonPath, json_encode($sess, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Keep session (optional) or clear it
        // session()->forget(self::SESS_COMMIT);

        return response()->json([
            'ok' => true,
            'commit_id' => $commitId,
            'received_files' => (int)($sess['received_files'] ?? 0),
            'audit_json' => $jsonPath,
        ]);
    }
}
