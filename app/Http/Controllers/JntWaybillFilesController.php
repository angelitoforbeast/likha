<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\JntWaybillPrintRun;

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

        // ── Attach per-run timing metrics (start / end / duration / pages / ppm).
        $runIds = $files->pluck('run_id')->unique()->all();
        $runs = JntWaybillPrintRun::query()->whereIn('id', $runIds)->get()->keyBy('id');

        $files = $files->map(function ($f) use ($runs) {
            $run   = $runs->get($f['run_id']);
            $start = $run?->started_at;
            $end   = $run?->finished_at;
            $durSec = ($start && $end) ? max(0, $start->diffInSeconds($end)) : null;
            $pages  = $run ? (int) ($run->pages ?? 0) : null;
            $ppm    = ($pages && $durSec && $durSec > 0)
                ? round($pages / ($durSec / 60), 1)
                : null;

            $f['started_at']   = $start ? $start->copy()->timezone('Asia/Manila') : null;
            $f['finished_at']  = $end ? $end->copy()->timezone('Asia/Manila') : null;
            $f['duration_sec'] = $durSec;
            $f['pages']        = $pages;
            $f['ppm']          = $ppm;
            $f['ok_count']     = $run ? (int) $run->ok_count : null;
            $f['fail_count']   = $run ? (int) $run->fail_count : null;
            $f['total']        = $run ? (int) $run->total : null;
            return $f;
        });

        return view('jnt.waybills.files', [
            'files' => $files,
            'baseDir' => $this->baseDir,
            'totalBytes' => $files->sum('size'),
            'totalCount' => $files->count(),
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

    public function destroyOld(Request $request)
    {
        Storage::disk('local')->makeDirectory($this->baseDir);

        $cutoff = \Carbon\Carbon::now('Asia/Manila')->subDay()->timestamp; // older than 1 day

        $allFiles = collect(Storage::disk('local')->allFiles($this->baseDir))
            ->filter(function ($rel) {
                $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
                return in_array($ext, ['pdf', 'zip', 'txt'], true);
            });

        $deleted = 0;
        $runDirs = [];

        foreach ($allFiles as $rel) {
            $mtime = Storage::disk('local')->lastModified($rel);
            if ($mtime < $cutoff) {
                $abs = Storage::disk('local')->path($rel);
                $runDirs[] = dirname($abs);
                Storage::disk('local')->delete($rel);
                $deleted++;
            }
        }

        // Clean up empty run folders
        foreach (array_unique($runDirs) as $dir) {
            if (File::isDirectory($dir) && count(File::allFiles($dir)) === 0) {
                File::deleteDirectory($dir);
            }
        }

        return back()->with('success', "Deleted {$deleted} file(s) older than 1 day.");
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
