<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\SegregateJntStickersJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class JntStickerSegregatorController extends Controller
{
    private const BASE_DIR = 'jnt-sticker-runs';

    public function show()
    {
        // First load (no run yet)
        return view('jnt.segregate-stickers', [
            'run' => null,
        ]);
    }

    public function submit(Request $request)
    {
        $validated = $request->validate([
            'pdfs' => ['required', 'array', 'min:1', 'max:80'],
            'pdfs.*' => ['required', 'file', 'mimetypes:application/pdf', 'max:51200'], // 50MB each
        ]);

        $runId = now()->format('Ymd_His') . '_' . Str::random(10);

        $runDir = $this->runDir($runId);
        $inDir  = $runDir . '/inputs';
        $outDir = $runDir . '/outputs';

        Storage::makeDirectory($inDir);
        Storage::makeDirectory($outDir);

        $stored = [];
        foreach ($validated['pdfs'] as $file) {
            /** @var \Illuminate\Http\UploadedFile $file */
            $base = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $base = Str::slug($base);
            $base = $base !== '' ? $base : 'input';

            $filename = $base . '_' . Str::random(6) . '.pdf';
            $stored[] = $file->storeAs($inDir, $filename);
        }

        // meta.json (status tracking)
        $meta = [
            'run_id' => $runId,
            'status' => 'queued',     // queued | processing | done | failed
            'error' => null,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
            'input_files' => array_map(fn ($p) => ['path' => $p], $stored),
            'outputs' => [],
        ];

        $this->writeMeta($runId, $meta);

        // Dispatch background job
        SegregateJntStickersJob::dispatch($runId, $stored);

        // Redirect to status page (auto-refresh will show progress)
        return redirect()->route('jnt.stickers.status', ['runId' => $runId]);
    }

    public function status(string $runId)
    {
        $meta = $this->readMeta($runId);

        if (!$meta) {
            abort(404, 'Run not found.');
        }

        return view('jnt.segregate-stickers', [
            'run' => $meta,
        ]);
    }

    public function download(string $runId, string $file)
    {
        $meta = $this->readMeta($runId);
        if (!$meta) abort(404, 'Run not found.');

        // Allow download only if file exists in meta outputs list
        $allowed = collect($meta['outputs'] ?? [])
            ->pluck('filename')
            ->contains($file);

        if (!$allowed) abort(403, 'File not allowed.');

        $rel = $this->runDir($runId) . '/outputs/' . $file;
        if (!Storage::exists($rel)) abort(404, 'File missing.');

        return Storage::download($rel, $file);
    }

    public function downloadZip(string $runId)
    {
        $meta = $this->readMeta($runId);
        if (!$meta) abort(404, 'Run not found.');

        $outputs = $meta['outputs'] ?? [];
        if (empty($outputs)) abort(400, 'No outputs available.');

        $zipName = "jnt_outputs_{$runId}.zip";
        $zipRel  = $this->runDir($runId) . "/outputs/{$zipName}";
        $zipAbs  = Storage::path($zipRel);

        // recreate zip
        if (file_exists($zipAbs)) @unlink($zipAbs);

        $zip = new ZipArchive();
        if ($zip->open($zipAbs, ZipArchive::CREATE) !== true) {
            abort(500, 'Failed to create zip.');
        }

        foreach ($outputs as $o) {
            $fn = $o['filename'] ?? null;
            if (!$fn) continue;

            $pdfRel = $this->runDir($runId) . "/outputs/{$fn}";
            if (!Storage::exists($pdfRel)) continue;

            $pdfAbs = Storage::path($pdfRel);
            $zip->addFile($pdfAbs, $fn);
        }

        $zip->close();

        if (!file_exists($zipAbs)) abort(500, 'Zip not created.');

        return response()->download($zipAbs, $zipName)->deleteFileAfterSend(true);
    }

    // ------------------------
    // Meta helpers
    // ------------------------

    private function runDir(string $runId): string
    {
        return self::BASE_DIR . '/' . $runId;
    }

    private function metaPath(string $runId): string
    {
        return $this->runDir($runId) . '/meta.json';
    }

    private function readMeta(string $runId): ?array
    {
        $path = $this->metaPath($runId);
        if (!Storage::exists($path)) return null;

        $raw = Storage::get($path);
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    private function writeMeta(string $runId, array $meta): void
    {
        $meta['updated_at'] = now()->toDateTimeString();
        Storage::put($this->metaPath($runId), json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
