<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class JntWaybillFilesController extends Controller
{
    private string $baseDir = 'jnt_waybills/bulk_runs';

    public function index()
    {
        Storage::disk('local')->makeDirectory($this->baseDir);

        $files = collect(Storage::disk('local')->allFiles($this->baseDir))
            ->filter(function ($rel) {
                $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
                return in_array($ext, ['pdf', 'zip', 'txt'], true);
            })
            ->map(function ($rel) {
                // rel example: jnt_waybills/bulk_runs/run_9/part_001.pdf
                $afterBase = Str::after($rel, $this->baseDir . '/'); // run_9/part_001.pdf

                if (!preg_match('#^run_(\d+)/(.*)$#', $afterBase, $m)) {
                    return null;
                }

                $runId = (int) $m[1];
                $filename = $m[2];

                return [
                    'run_id'   => $runId,
                    'filename' => $filename,
                    'name'     => basename($filename),
                    'ext'      => strtolower(pathinfo($filename, PATHINFO_EXTENSION)),
                    'size'     => Storage::disk('local')->size($rel),
                    'mtime'    => Storage::disk('local')->lastModified($rel),
                    'rel'      => $rel,
                ];
            })
            ->filter()
            ->sortByDesc('mtime')
            ->values();

        return view('jnt.waybills.files', [
            'files' => $files,
            'baseDir' => $this->baseDir,
        ]);
    }

    public function download(int $runId, string $filename)
    {
        $filename = $this->safeFilename($filename);

        $rel = "{$this->baseDir}/run_{$runId}/{$filename}";

        if (!Storage::disk('local')->exists($rel)) {
            abort(404, "File not found.");
        }

        return response()->download(
            Storage::disk('local')->path($rel),
            basename($filename)
        );
    }

    public function destroy(Request $request, int $runId, string $filename)
    {
        $filename = $this->safeFilename($filename);

        $rel = "{$this->baseDir}/run_{$runId}/{$filename}";

        if (!Storage::disk('local')->exists($rel)) {
            return back()->with('error', 'File not found (baka nabura na).');
        }

        $abs = Storage::disk('local')->path($rel);

        Storage::disk('local')->delete($rel);

        // cleanup empty run folder
        $runAbs = dirname($abs);
        if (File::isDirectory($runAbs) && count(File::allFiles($runAbs)) === 0) {
            File::deleteDirectory($runAbs);
        }

        return back()->with('success', 'Deleted: ' . basename($filename));
    }

    private function safeFilename(string $filename): string
    {
        $filename = str_replace('\\', '/', $filename);
        $filename = ltrim($filename, '/');

        // block traversal
        if ($filename === '' || str_contains($filename, '..')) {
            abort(404, 'Invalid filename.');
        }

        return $filename;
    }
}
