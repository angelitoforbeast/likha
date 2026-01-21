<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class SegregateJntStickersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 mins

    private const RUN_BASE_DIR = 'jnt-sticker-runs';

    /** Throttle meta writes */
    private float $lastProgressWrite = 0.0;

    /**
     * @param  string  $runId
     * @param  array<int,string>  $inputStoragePaths  storage-relative paths
     */
    public function __construct(
        public string $runId,
        public array $inputStoragePaths
    ) {}

    public function handle(): void
    {
        $meta = $this->readMeta() ?? [];
        $meta['status'] = 'processing';
        $meta['error'] = null;

        // Precompute pages per file for better progress %
        $fileAbsList = [];
        $pagesPerFile = [];
        $totalPagesEstimate = 0;

        foreach ($this->inputStoragePaths as $storagePath) {
            $abs = Storage::path($storagePath);
            if (!is_file($abs)) continue;
            $fileAbsList[] = $abs;

            $pc = $this->getPdfPageCount($abs);
            $pagesPerFile[$abs] = $pc;
            $totalPagesEstimate += $pc;
        }

        $meta['progress'] = [
            'stage' => 'extracting',
            'current_file' => 0,
            'total_files' => count($fileAbsList),
            'current_page' => 0,
            'pages_in_current_file' => 0,
            'processed_pages_total' => 0,
            'total_pages_estimate' => $totalPagesEstimate,
            'percent' => 0,
            'message' => 'Queued…',
        ];

        $this->writeMeta($meta);

        try {
            $groups = $this->buildGroupsFromInputs($fileAbsList, $pagesPerFile);

            $outputs = $this->generateOutputs($groups);

            $meta = $this->readMeta() ?? $meta;
            $meta['status'] = 'done';
            $meta['error'] = null;
            $meta['outputs'] = $outputs;

            $meta['progress'] = $meta['progress'] ?? [];
            $meta['progress']['stage'] = 'done';
            $meta['progress']['percent'] = 100;
            $meta['progress']['message'] = 'Done.';
            $this->writeMeta($meta);

        } catch (Throwable $e) {
            $meta = $this->readMeta() ?? $meta;
            $meta['status'] = 'failed';
            $meta['error'] = $e->getMessage();

            $meta['progress'] = $meta['progress'] ?? [];
            $meta['progress']['stage'] = 'failed';
            $meta['progress']['message'] = 'Failed.';
            $this->writeMeta($meta);

            throw $e;
        }
    }

    /**
     * Build grouping: goods_key => selections (file,page)
     *
     * @param  array<int,string> $fileAbsList
     * @param  array<string,int> $pagesPerFile
     * @return array<string, array{label:string, selections: array<int, array{file:string, page:int}>}>
     */
    private function buildGroupsFromInputs(array $fileAbsList, array $pagesPerFile): array
    {
        $groups = [];
        $processedTotal = 0;

        $totalFiles = count($fileAbsList);

        for ($i = 0; $i < $totalFiles; $i++) {
            $absPdf = $fileAbsList[$i];
            $currentFileIndex = $i + 1;

            $pageCount = (int)($pagesPerFile[$absPdf] ?? $this->getPdfPageCount($absPdf));

            // progress: file start
            $this->updateProgress([
                'stage' => 'extracting',
                'current_file' => $currentFileIndex,
                'total_files' => $totalFiles,
                'current_page' => 0,
                'pages_in_current_file' => $pageCount,
                'processed_pages_total' => $processedTotal,
                'message' => "Extracting text — file {$currentFileIndex}/{$totalFiles}",
            ], true);

            for ($page = 1; $page <= $pageCount; $page++) {
                $rawText = $this->extractPageText($absPdf, $page);
                $goods = $this->extractGoodsFromText($rawText);

                $label = $goods !== '' ? $goods : 'UNKNOWN';
                $key = $this->normalizeGoodsKey($label);

                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'label' => $label,
                        'selections' => [],
                    ];
                }

                $groups[$key]['selections'][] = [
                    'file' => $absPdf,
                    'page' => $page,
                ];

                $processedTotal++;

                // update progress every 10 pages or ~0.6 sec
                if ($page % 10 === 0) {
                    $this->updateProgress([
                        'stage' => 'extracting',
                        'current_file' => $currentFileIndex,
                        'total_files' => $totalFiles,
                        'current_page' => $page,
                        'pages_in_current_file' => $pageCount,
                        'processed_pages_total' => $processedTotal,
                        'message' => "Extracting text — file {$currentFileIndex}/{$totalFiles}, page {$page}/{$pageCount}",
                    ]);
                }
            }

            // file end update
            $this->updateProgress([
                'stage' => 'extracting',
                'current_file' => $currentFileIndex,
                'total_files' => $totalFiles,
                'current_page' => $pageCount,
                'pages_in_current_file' => $pageCount,
                'processed_pages_total' => $processedTotal,
                'message' => "Finished file {$currentFileIndex}/{$totalFiles}",
            ], true);
        }

        ksort($groups);

        // stage: grouping done
        $this->updateProgress([
            'stage' => 'grouping',
            'message' => 'Grouping pages by Goods…',
        ], true);

        return $groups;
    }

    /**
     * Generate output PDFs (one per goods group) using qpdf page-spec tokens.
     * IMPORTANT: For multiple non-contiguous ranges in the same file, qpdf expects ONE token like "1-44,90-100".
     *
     * @param  array<string, array{label:string, selections: array<int, array{file:string, page:int}>}>  $groups
     * @return array<int, array{goods:string, filename:string, page_count:int}>
     */
    private function generateOutputs(array $groups): array
    {
        $outputsDirRel = self::RUN_BASE_DIR . '/' . $this->runId . '/outputs';
        Storage::makeDirectory($outputsDirRel);

        $qpdf = $this->qpdfBin();

        $outputs = [];
        $totalGroups = count($groups);
        $groupIndex = 0;

        foreach ($groups as $goodsKey => $group) {
            $groupIndex++;

            $label = $group['label'];
            $selections = $group['selections'];
            if (empty($selections)) continue;

            // progress: writing
            $this->updateProgress([
                'stage' => 'writing',
                'message' => "Writing outputs — group {$groupIndex}/{$totalGroups}",
            ], true);

            // Group selections by source file -> pages[]
            $byFile = [];
            foreach ($selections as $sel) {
                $byFile[$sel['file']][] = (int) $sel['page'];
            }

            // Filename
            $slug = Str::slug($label);
            $slug = $slug !== '' ? $slug : 'group';
            $slug = Str::limit($slug, 60, '');
            $filename = $slug . '__' . substr(sha1($goodsKey), 0, 10) . '.pdf';

            $outRel = $outputsDirRel . '/' . $filename;
            $outAbs = Storage::path($outRel);

            // qpdf --empty --pages <file> <page-spec> <file2> <page-spec> -- <out.pdf>
            $args = [$qpdf, '--empty', '--pages'];

            foreach ($byFile as $filePath => $pages) {
                sort($pages);
                $pages = array_values(array_unique($pages));

                $pageSpec = $this->pagesToPageSpecToken($pages); // ✅ ONE TOKEN e.g. "1-44,90-100"
                if ($pageSpec === '') continue;

                $args[] = $filePath;
                $args[] = $pageSpec;
            }

            $args[] = '--';
            $args[] = $outAbs;

            $this->runProcess($args, 1200);

            $outputs[] = [
                'goods' => $label,
                'filename' => $filename,
                'page_count' => count($selections),
            ];
        }

        usort($outputs, fn ($a, $b) => strcmp((string)$a['goods'], (string)$b['goods']));

        $this->updateProgress([
            'stage' => 'writing',
            'message' => 'Finished writing outputs.',
        ], true);

        return $outputs;
    }

    /**
     * Convert sorted unique pages to ONE qpdf page-spec token.
     * Example: [1,2,3,5,7,8] => "1-3,5,7-8"
     *
     * @param  array<int,int> $pages
     * @return string
     */
    private function pagesToPageSpecToken(array $pages): string
    {
        if (empty($pages)) return '';

        sort($pages);
        $pages = array_values(array_unique($pages));

        $parts = [];
        $start = $pages[0];
        $prev  = $pages[0];

        for ($i = 1; $i < count($pages); $i++) {
            $p = $pages[$i];

            if ($p === $prev + 1) {
                $prev = $p;
                continue;
            }

            $parts[] = ($start === $prev) ? (string)$start : ($start . '-' . $prev);
            $start = $p;
            $prev  = $p;
        }

        $parts[] = ($start === $prev) ? (string)$start : ($start . '-' . $prev);

        return implode(',', $parts);
    }

    private function getPdfPageCount(string $absPdf): int
    {
        $pdfinfo = $this->pdfInfoBin();
        $res = $this->runProcess([$pdfinfo, $absPdf], 60, true);

        $out = ($res['stdout'] ?? '') . "\n" . ($res['stderr'] ?? '');
        if (preg_match('/^\s*Pages:\s*(\d+)\s*$/mi', $out, $m)) {
            return max(1, (int)$m[1]);
        }

        return 1;
    }

    private function extractPageText(string $absPdf, int $page): string
    {
        $pdftotext = $this->pdfToTextBin();

        // Output to stdout ("-") so no temp files
        $res = $this->runProcess(
            [$pdftotext, '-enc', 'UTF-8', '-f', (string)$page, '-l', (string)$page, '-layout', $absPdf, '-'],
            60,
            true
        );

        return (string)($res['stdout'] ?? '');
    }

    private function extractGoodsFromText(string $raw): string
    {
        $raw = str_replace("\xC2\xA0", ' ', $raw); // NBSP
        $clean = preg_replace('/\s+/u', ' ', $raw) ?? '';
        $clean = trim($clean);

        if ($clean === '') return '';

        $delims = [
            'Qty', 'Quantity', 'Weight', 'COD', 'Amount', 'Order', 'Tracking', 'Waybill',
            'Receiver', 'Consignee', 'Sender', 'Address', 'Phone', 'Mobile',
            'Service', 'Date', 'Time', 'Payment',
        ];
        $delimPattern = implode('|', array_map(fn ($d) => preg_quote($d, '/'), $delims));

        $pattern = '/Goods\s*:\s*(.+?)(?=\s+(?:' . $delimPattern . ')\s*:|\s+(?:' . $delimPattern . ')\b|$)/i';

        if (preg_match($pattern, $clean, $m)) {
            $goods = trim($m[1]);
            $goods = preg_replace('/\s+/u', ' ', $goods) ?? $goods;
            $goods = trim($goods, " \t\n\r\0\x0B-–—:;");
            return $goods;
        }

        return '';
    }

    private function normalizeGoodsKey(string $goods): string
    {
        $g = str_replace("\xC2\xA0", ' ', $goods);
        $g = preg_replace('/\s+/u', ' ', $g) ?? $g;
        $g = trim($g);

        $g = preg_replace('/\b(\d+)\s*X\b/i', '$1 X', $g) ?? $g;

        $g = mb_strtoupper($g, 'UTF-8');
        return $g !== '' ? $g : 'UNKNOWN';
    }

    /**
     * Update progress in meta.json (throttled).
     *
     * @param array $fields
     * @param bool $force
     */
    private function updateProgress(array $fields, bool $force = false): void
    {
        $now = microtime(true);
        if (!$force && ($now - $this->lastProgressWrite) < 0.6) {
            return;
        }
        $this->lastProgressWrite = $now;

        $meta = $this->readMeta() ?? [];
        $meta['status'] = $meta['status'] ?? 'processing';
        $meta['progress'] = $meta['progress'] ?? [];

        foreach ($fields as $k => $v) {
            $meta['progress'][$k] = $v;
        }

        // percent compute
        $processed = (int)($meta['progress']['processed_pages_total'] ?? 0);
        $total = (int)($meta['progress']['total_pages_estimate'] ?? 0);
        if ($total > 0) {
            $pct = (int) floor(($processed / $total) * 100);
            if ($pct < 0) $pct = 0;
            if ($pct > 99 && ($meta['status'] ?? '') !== 'done') $pct = 99;
            $meta['progress']['percent'] = $pct;
        } else {
            $meta['progress']['percent'] = (int)($meta['progress']['percent'] ?? 0);
        }

        $this->writeMeta($meta);
    }

    /**
     * Run a process. If $captureOutput=true, returns stdout/stderr/exit.
     *
     * @param  array<int,string> $args
     * @param  int $timeoutSeconds
     * @param  bool $captureOutput
     * @return array{stdout:string, stderr:string, exit:int}|null
     */
    private function runProcess(array $args, int $timeoutSeconds, bool $captureOutput = false): ?array
    {
        $p = new Process($args);
        $p->setTimeout($timeoutSeconds);
        $p->run();

        $stdout = (string)$p->getOutput();
        $stderr = (string)$p->getErrorOutput();
        $exit = (int)$p->getExitCode();

        if (!$p->isSuccessful()) {
            // Process::getCommandLine may render with quotes; ok for debugging
            $cmd = $p->getCommandLine();
            $msg = "The command \"{$cmd}\" failed. Exit Code: {$exit}\n"
                . "Working directory: " . getcwd() . "\n"
                . "Output:\n{$stdout}\n"
                . "Error Output:\n{$stderr}";
            throw new \RuntimeException($msg);
        }

        if ($captureOutput) {
            return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => $exit];
        }

        return null;
    }

    private function pdfInfoBin(): string
    {
        $bin = (string) env('POPPLER_PDFINFO', 'pdfinfo');
        if ($this->looksLikePath($bin) && !file_exists($bin)) {
            throw new \RuntimeException("POPPLER_PDFINFO not found at: {$bin}");
        }
        return $bin;
    }

    private function pdfToTextBin(): string
    {
        $bin = (string) env('POPPLER_PDFTOTEXT', 'pdftotext');
        if ($this->looksLikePath($bin) && !file_exists($bin)) {
            throw new \RuntimeException("POPPLER_PDFTOTEXT not found at: {$bin}");
        }
        return $bin;
    }

    private function qpdfBin(): string
    {
        $bin = (string) env('QPDF_BIN', 'qpdf');
        if ($this->looksLikePath($bin) && !file_exists($bin)) {
            throw new \RuntimeException("QPDF_BIN not found at: {$bin}");
        }
        return $bin;
    }

    private function looksLikePath(string $value): bool
    {
        return str_contains($value, '/') || str_contains($value, '\\') || str_ends_with(strtolower($value), '.exe');
    }

    // ------------------------
    // Meta helpers (meta.json)
    // ------------------------

    private function metaPath(): string
    {
        return self::RUN_BASE_DIR . '/' . $this->runId . '/meta.json';
    }

    private function readMeta(): ?array
    {
        $path = $this->metaPath();
        if (!Storage::exists($path)) return null;

        $raw = Storage::get($path);
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    private function writeMeta(array $meta): void
    {
        $meta['updated_at'] = now()->toDateTimeString();
        Storage::put($this->metaPath(), json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
