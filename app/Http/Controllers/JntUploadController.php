<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\UploadLog;
use App\Jobs\ProcessJntUpload;
use Carbon\Carbon;

class JntUploadController extends Controller
{
    /**
     * Upload (store): Marketing, Marketing - OIC at CEO lang. Other roles → 404
     * (Data Encoders → 403). Mirrors yung nav-link visibility para consistent
     * kahit i-share/i-direct-access yung URL.
     */
    private function checkAccess(): void
    {
        if (!$this->canUpload()) abort(404);
    }

    private function canUpload(): bool
    {
        $norm = $this->normalizedRole();
        $isCEO       = preg_match('/^ceo$/iu', $norm) === 1;
        $isMOIC      = preg_match('/^marketing\s*[-–—]\s*oic$/iu', $norm) === 1;
        $isMarketing = preg_match('/^marketing$/iu', $norm) === 1;
        return $isCEO || $isMOIC || $isMarketing;
    }

    /**
     * Data Encoder at Data Encoder - OIC: tingin lang (page, status, history).
     * Sa upload sila ay 403, hindi 404, dahil alam na nila na may page ito.
     */
    private function isViewOnly(): bool
    {
        return preg_match('/^data encoder(\s*[-–—]\s*oic)?$/iu', $this->normalizedRole()) === 1;
    }

    private function normalizedRole(): string
    {
        $raw = Auth::user()?->employeeProfile?->role ?? '';
        return preg_replace('/\s+/u', ' ', trim((string) $raw));
    }

    /** Para sa read routes: uploaders + view-only roles. Ibang role → 404. */
    private function checkViewAccess(): void
    {
        if (!($this->canUpload() || $this->isViewOnly())) abort(404);
    }

    public function store(Request $request)
    {
        if ($this->isViewOnly()) abort(403);
        $this->checkAccess();

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

            $disk = config('filesystems.default'); // uses FILESYSTEM_DISK
$path = $file->storeAs($folder, $filename, $disk);


            // 4) Create UploadLog (status: queued) + ✅ save batch_at + user info
            $log = UploadLog::create([
                'type'          => 'jnt',
                'disk'          => $disk,
                'path'          => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type'     => $file->getMimeType(),
                'size'          => $file->getSize(),
                'status'        => 'queued',
                'batch_at'      => $batchAt ? $batchAt->format('Y-m-d H:i:s') : null,
                // Track who uploaded so the history table can show it.
                // user_email persists kahit yung user record ay ma-delete later.
                'user_id'       => Auth::id(),
                'user_email'    => Auth::user()?->email,
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
        $this->checkViewAccess();

        // Latest 50 JNT uploads — visible sa lahat. Always renders sa bottom ng page,
        // hindi nawawala kahit may ongoing upload. Updates after own upload via
        // /jnt_upload/history JSON endpoint.
        $recentUploads = UploadLog::query()
            ->where('type', 'jnt')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get([
                'id', 'original_name', 'status', 'total_rows', 'processed_rows',
                'inserted', 'updated', 'skipped', 'error_rows',
                'started_at', 'finished_at', 'batch_at', 'created_at',
                'user_id', 'user_email',
            ]);

        $canUpload = $this->canUpload();

        return view('jnt_upload', compact('recentUploads', 'canUpload'));
    }

    /**
     * JSON endpoint para sa client-side refresh ng history table pagkatapos
     * ng sariling upload. Same query shape as index(), pero JSON na lang.
     */
    public function history()
    {
        $this->checkViewAccess();

        $rows = UploadLog::query()
            ->where('type', 'jnt')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get([
                'id', 'original_name', 'status', 'total_rows', 'processed_rows',
                'inserted', 'updated', 'skipped', 'error_rows',
                'started_at', 'finished_at', 'batch_at', 'created_at',
                'user_id', 'user_email',
            ]);

        return response()->json(['rows' => $rows]);
    }

    public function status(UploadLog $uploadLog)
    {
        $this->checkViewAccess();

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
