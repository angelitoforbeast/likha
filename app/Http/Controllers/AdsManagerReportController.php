<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Models\UploadLog;
use App\Jobs\ProcessAdsManagerReportsUpload;

class AdsManagerReportController extends Controller
{
    /**
     * Extract the SOURCE FILE's internal created/modified dates from an XLSX
     * (or .xlsm) file by reading docProps/core.xml inside the zip wrapper.
     *
     * Returns ['created' => Carbon|null, 'modified' => Carbon|null]. Both
     * null if the file isn't a zip-based Office format, the doc props don't
     * exist, or parsing fails — caller treats null as "unknown".
     *
     * Used para malaman if the Meta Ads Manager export is fresh (e.g., file
     * created an hour ago) or stale (file dated 3 days ago).
     */
    public static function extractXlsxMetadata(string $absolutePath): array
    {
        $out = ['created' => null, 'modified' => null];
        if (!is_file($absolutePath) || !class_exists(\ZipArchive::class)) {
            return $out;
        }

        $zip = new \ZipArchive();
        if ($zip->open($absolutePath) !== true) return $out;

        try {
            $xml = $zip->getFromName('docProps/core.xml');
            if ($xml === false) return $out;

            // Use SimpleXML with namespace handling
            libxml_use_internal_errors(true);
            $doc = simplexml_load_string($xml);
            if ($doc === false) return $out;

            $ns       = $doc->getNamespaces(true);
            $dcterms  = $ns['dcterms']  ?? 'http://purl.org/dc/terms/';
            $children = $doc->children($dcterms);

            $created  = (string) ($children->created  ?? '');
            $modified = (string) ($children->modified ?? '');

            if ($created !== '')  $out['created']  = static::safeCarbon($created);
            if ($modified !== '') $out['modified'] = static::safeCarbon($modified);
        } catch (\Throwable $e) {
            Log::warning('extractXlsxMetadata failed', ['file' => basename($absolutePath), 'err' => $e->getMessage()]);
        } finally {
            $zip->close();
        }
        return $out;
    }

    /** Tolerant ISO-8601 → Carbon helper. Returns null kung di parsable. */
    private static function safeCarbon(string $iso): ?\Carbon\Carbon
    {
        try {
            return \Carbon\Carbon::parse($iso);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function index()
    {
        $logs = UploadLog::where('type', 'ads_manager_reports')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $latest = $logs->first();

        return view('ads_manager.report', [
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
            'files.*' => 'required|file|mimes:csv,txt,xlsx,xls',
        ]);

        $disk = config('filesystems.default'); // local/offline OR s3/heroku

        $queued = [];
        foreach ($request->file('files') as $i => $file) {
            $originalName = $file->getClientOriginalName();
            $safeName = preg_replace('/\s+/', '_', $originalName);

            // add counter to avoid collisions kapag sabay-sabay
            $storedPath = $file->storeAs(
                'uploads/ads_manager_reports',
                now()->format('Ymd_His').'__'.($i+1).'__'.$safeName,
                $disk
            );

            // Extract the SOURCE FILE's internal created/modified timestamps —
            // helps spot stale uploads (Meta Ads Manager export from yesterday
            // uploaded today). Best-effort only — non-XLSX/non-readable files
            // just leave the fields null.
            $absForMeta = Storage::disk($disk)->path($storedPath);
            $fileMeta   = self::extractXlsxMetadata($absForMeta);

            $log = UploadLog::create([
                'type'             => 'ads_manager_reports',
                'disk'             => $disk,
                'path'             => $storedPath,
                'original_name'    => $originalName,
                'status'           => 'queued',
                'processed_rows'   => 0,
                'inserted'         => 0,
                'updated'          => 0,
                'skipped'          => 0,
                'error_rows'       => 0,
                'user_id'          => auth()->id(),
                'file_created_at'  => $fileMeta['created']?->toDateTimeString(),
                'file_modified_at' => $fileMeta['modified']?->toDateTimeString(),
            ]);

            ProcessAdsManagerReportsUpload::dispatch($storedPath, auth()->id(), $log->id);

            $queued[] = "#{$log->id} ".basename($storedPath);
        }

        return redirect()
            ->route('ads_manager.report')
            ->with('status', '📤 Queued '.count($queued).' file(s): '.implode(', ', $queued));
    }

    // JSON status for front-end polling (returns latest logs)
    public function status()
    {
        $logs = UploadLog::where('type', 'ads_manager_reports')
            ->orderByDesc('id')
            ->limit(50)
            ->get([
                'id','status','original_name','processed_rows','inserted','updated','skipped','error_rows',
                'error_message','started_at','finished_at','created_at',
                'file_created_at','file_modified_at',
            ]);

        return response()->json([
            'logs' => $logs->map(fn($l) => [
                'id'               => (int) $l->id,
                'status'           => (string) ($l->status ?? 'idle'),
                'original_name'    => (string) ($l->original_name ?? ''),
                'processed_rows'   => (int) ($l->processed_rows ?? 0),
                'inserted'         => (int) ($l->inserted ?? 0),
                'updated'          => (int) ($l->updated ?? 0),
                'skipped'          => (int) ($l->skipped ?? 0),
                'error_rows'       => (int) ($l->error_rows ?? 0),
                'error_message'    => (string) ($l->error_message ?? ''),
                'started_at'       => $l->started_at?->toDateTimeString(),
                'finished_at'      => $l->finished_at?->toDateTimeString(),
                'created_at'       => $l->created_at?->toDateTimeString(),
                'file_created_at'  => $l->file_created_at?->setTimezone('Asia/Manila')->format('M j, Y g:i A'),
                'file_modified_at' => $l->file_modified_at?->setTimezone('Asia/Manila')->format('M j, Y g:i A'),
            ]),
        ]);
    }
}
