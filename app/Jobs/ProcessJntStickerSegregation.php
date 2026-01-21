<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

use ZipArchive;
use Symfony\Component\Process\Process;

class ProcessJntStickerSegregation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600; // 1 hour
    public int $tries = 1;

    public function __construct(public string $commitId) {}

    public function handle(): void
    {
        $commitId = $this->commitId;
        $baseDirRel = "jnt_stickers/commits/{$commitId}";
        $auditRel   = "{$baseDirRel}/audit.json";
        $statusRel  = "{$baseDirRel}/status.json";
        $segRelDir  = "{$baseDirRel}/segregated";
        $tmpRelDir  = "{$baseDirRel}/tmp";

        $this->writeStatus($statusRel, 'processing', 20, 'Starting segregation...');

        if (!Storage::disk('local')->exists($auditRel)) {
            $this->writeStatus($statusRel, 'failed', 0, 'audit.json not found.');
            return;
        }

        $audit = json_decode(Storage::disk('local')->get($auditRel), true) ?: [];
        $filterDate = (string)($audit['filter_date'] ?? '');

        $uploadedFiles = $audit['uploaded_files'] ?? [];
        $pdfItems      = $audit['pdf_items'] ?? [];

        if (!is_array($uploadedFiles) || !count($uploadedFiles)) {
            $this->writeStatus($statusRel, 'failed', 0, 'No uploaded_files found in audit.json.');
            return;
        }

        if (!is_array($pdfItems) || !count($pdfItems)) {
            $this->writeStatus($statusRel, 'failed', 0, 'No pdf_items found in audit.json. (Client must send pdfItems on finalize)');
            return;
        }

        // Prepare directories
        Storage::disk('local')->makeDirectory($segRelDir);
        Storage::disk('local')->makeDirectory($tmpRelDir);

        // Map client_index -> stored file path (relative)
        $indexToPdfRelPath = [];
        foreach ($uploadedFiles as $f) {
            $ci = (int)($f['client_index'] ?? 0);
            $path = (string)($f['path'] ?? '');
            if ($ci > 0 && $path !== '') {
                $indexToPdfRelPath[$ci] = $path; // e.g. jnt_stickers/commits/<id>/pdf/<uuid>-orig.pdf
            }
        }

        if (!count($indexToPdfRelPath)) {
            $this->writeStatus($statusRel, 'failed', 0, 'Cannot map client_index to uploaded pdf path. Ensure file_indexes[] are sent on upload.');
            return;
        }

        // Detect item column name in macro_output
        $itemCol = collect([
            'item_name', 'ITEM_NAME', 'item', 'ITEM', 'product', 'PRODUCT', 'product_name', 'PRODUCT_NAME'
        ])->first(fn($c) => Schema::hasColumn('macro_output', $c));

        if (!$itemCol) {
            // fallback; will likely fail on query if truly absent
            $itemCol = 'item_name';
        }

        $hasTimestamp = Schema::hasColumn('macro_output', 'TIMESTAMP');
        $hasWaybill   = Schema::hasColumn('macro_output', 'waybill');

        if (!$hasWaybill) {
            $this->writeStatus($statusRel, 'failed', 0, 'macro_output.waybill column not found.');
            return;
        }

        $this->writeStatus($statusRel, 'processing', 25, "Grouping pages by ITEM using DB column: {$itemCol}");

        // Cache waybill -> item name
        $wbToItem = [];
        $groups = []; // itemKey => list of ['src_rel'=>..., 'page'=>int, 'waybill'=>...]
        $total = count($pdfItems);
        $done = 0;

        foreach ($pdfItems as $it) {
            $done++;

            $wb = trim((string)($it['waybill'] ?? ''));
            $page = (int)($it['page'] ?? 0);
            $fileIndex = (int)($it['file_index'] ?? 0);

            if ($wb === '' || $page <= 0 || $fileIndex <= 0) {
                continue;
            }

            $srcRel = $indexToPdfRelPath[$fileIndex] ?? '';
            if ($srcRel === '' || !Storage::disk('local')->exists($srcRel)) {
                // missing uploaded file mapping
                continue;
            }

            if (!array_key_exists($wb, $wbToItem)) {
                $q = DB::table('macro_output')
                    ->select([$itemCol])
                    ->where('waybill', $wb);

                if ($filterDate !== '' && $hasTimestamp) {
                    $q->whereRaw("DATE(STR_TO_DATE(`TIMESTAMP`, '%H:%i %d-%m-%Y')) = ?", [$filterDate]);
                }

                // Prefer latest if TIMESTAMP exists
                if ($hasTimestamp) {
                    $q->orderByRaw("STR_TO_DATE(`TIMESTAMP`, '%H:%i %d-%m-%Y') DESC");
                }

                $row = $q->first();
                $item = $row ? trim((string)($row->{$itemCol} ?? '')) : '';

                if ($item === '') $item = 'UNKNOWN';
                $wbToItem[$wb] = $item;
            }

            $itemName = $wbToItem[$wb];
            $key = $this->safeKey($itemName);

            if (!isset($groups[$key])) $groups[$key] = [
                'label' => $itemName,
                'items' => [],
            ];

            $groups[$key]['items'][] = [
                'src_rel' => $srcRel,
                'page' => $page,
                'waybill' => $wb,
            ];

            if ($done % 50 === 0) {
                $pct = 25 + (int)round(25 * ($done / max(1, $total))); // 25->50
                $this->writeStatus($statusRel, 'processing', $pct, "Grouping... {$done}/{$total}");
            }
        }

        if (!count($groups)) {
            $this->writeStatus($statusRel, 'failed', 0, 'No groups created. Check pdf_items content and DB item lookup.');
            return;
        }

        $this->writeStatus($statusRel, 'processing', 55, 'Generating output PDFs per ITEM...');

        // Generate PDFs per group
        $outputFilesRel = [];
        $groupKeys = array_keys($groups);
        $gTotal = count($groupKeys);
        $gDone = 0;

        foreach ($groupKeys as $gk) {
            $gDone++;

            $label = (string)($groups[$gk]['label'] ?? $gk);
            $items = $groups[$gk]['items'] ?? [];
            if (!is_array($items) || !count($items)) continue;

            // Sort by src then page for consistent output
            usort($items, function($a, $b) {
                $c = strcmp((string)$a['src_rel'], (string)$b['src_rel']);
                if ($c !== 0) return $c;
                return ((int)$a['page']) <=> ((int)$b['page']);
            });

            // Extract each page to a temp single-page PDF
            $tmpSingles = [];
            $i = 0;

            foreach ($items as $pi) {
                $i++;
                $srcRel = (string)$pi['src_rel'];
                $page = (int)$pi['page'];

                $srcAbs = storage_path('app/' . $srcRel);
                $tmpRel = "{$tmpRelDir}/{$gk}-p{$i}.pdf";
                $tmpAbs = storage_path('app/' . $tmpRel);

                // qpdf src --pages src <page> -- out
                $proc = new Process([
                    'qpdf',
                    $srcAbs,
                    '--pages',
                    $srcAbs,
                    (string)$page,
                    '--',
                    $tmpAbs
                ]);

                $proc->setTimeout(300);
                $proc->run();

                if (!$proc->isSuccessful()) {
                    Log::error("qpdf extract failed commit={$commitId} item={$gk} page={$page}: ".$proc->getErrorOutput());
                    continue;
                }

                $tmpSingles[] = $tmpAbs;
            }

            if (!count($tmpSingles)) continue;

            // Merge singles into one PDF
            $outName = $this->safeFilename($label) . '.pdf';
            $outRel  = "{$segRelDir}/{$outName}";
            $outAbs  = storage_path('app/' . $outRel);

            $args = array_merge(
                ['qpdf', '--empty', '--pages'],
                $tmpSingles,
                ['--', $outAbs]
            );

            $merge = new Process($args);
            $merge->setTimeout(600);
            $merge->run();

            if (!$merge->isSuccessful()) {
                Log::error("qpdf merge failed commit={$commitId} item={$gk}: ".$merge->getErrorOutput());
                continue;
            }

            $outputFilesRel[] = $outRel;

            $pct = 55 + (int)round(35 * ($gDone / max(1, $gTotal))); // 55->90
            $this->writeStatus($statusRel, 'processing', $pct, "Generated {$gDone}/{$gTotal}: {$outName}");
        }

        if (!count($outputFilesRel)) {
            $this->writeStatus($statusRel, 'failed', 0, 'No output PDFs generated. Ensure qpdf is installed and pages exist.');
            return;
        }

        // Create ZIP
        $this->writeStatus($statusRel, 'processing', 92, 'Creating outputs.zip...');

        $zipRel = "{$segRelDir}/outputs.zip";
        $zipAbs = storage_path('app/' . $zipRel);

        $zip = new ZipArchive();
        if ($zip->open($zipAbs, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->writeStatus($statusRel, 'failed', 0, 'Failed to create ZIP file.');
            return;
        }

        foreach ($outputFilesRel as $rel) {
            $abs = storage_path('app/' . $rel);
            if (is_file($abs)) {
                $zip->addFile($abs, basename($abs));
            }
        }
        $zip->close();

        $this->writeStatus($statusRel, 'done', 100, 'Segregation complete.', $outputFilesRel, $zipRel);

        // Optional: cleanup tmp
        try {
            Storage::disk('local')->deleteDirectory($tmpRelDir);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    private function safeKey(string $s): string
    {
        $s = trim($s);
        if ($s === '') return 'UNKNOWN';
        $s = preg_replace('/\s+/u', ' ', $s);
        $s = mb_substr($s, 0, 80);
        return strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $s));
    }

    private function safeFilename(string $s): string
    {
        $s = trim($s);
        if ($s === '') $s = 'UNKNOWN';
        $s = preg_replace('/\s+/u', ' ', $s);
        $s = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $s);
        $s = trim($s, " .-_");
        if ($s === '') $s = 'UNKNOWN';
        return mb_substr($s, 0, 90);
    }

    private function writeStatus(string $statusRel, string $status, int $progress, string $message, array $files = [], ?string $zip = null): void
    {
        $payload = [
            'status' => $status,
            'progress' => $progress,
            'message' => $message,
            'files' => $files,
            'zip' => $zip,
            'updated_at' => now()->toDateTimeString(),
        ];

        Storage::disk('local')->put(
            $statusRel,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
}
