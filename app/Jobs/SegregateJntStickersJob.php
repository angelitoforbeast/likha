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

    /**
     * @param  string  $runId
     * @param  array<int,string>  $inputStoragePaths  Storage relative paths (e.g. jnt-sticker-runs/{runId}/inputs/xxx.pdf)
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
        $meta['outputs'] = $meta['outputs'] ?? [];
        $this->writeMeta($meta);

        try {
            $groups = $this->buildGroupsFromInputs();

            $outputs = $this->generateOutputs($groups);

            $meta = $this->readMeta() ?? $meta;
            $meta['status'] = 'done';
            $meta['error'] = null;
            $meta['outputs'] = $outputs;
            $this->writeMeta($meta);
        } catch (Throwable $e) {
            $meta = $this->readMeta() ?? $meta;
            $meta['status'] = 'failed';
            $meta['error'] = $e->getMessage();
            $this->writeMeta($meta);
            throw $e;
        }
    }

    /**
     * Build grouping: goods_key => selections (file,page)
     *
     * @return array<string, array{label:string, selections: array<int, array{file:string, page:int}>}>
     */
    private function buildGroupsFromInputs(): array
    {
        $groups = [];

        foreach ($this->inputStoragePaths as $storagePath) {
            $absPdf = Storage::path($storagePath);
            if (!is_file($absPdf)) {
                continue;
            }

            $pageCount = $this->getPdfPageCount($absPdf);

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
            }
        }

        ksort($groups);
        return $groups;
    }

    /**
     * Generate output PDFs (one PDF per goods group).
     * FIX: use page ranges per file to avoid huge Windows command length.
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

        foreach ($groups as $goodsKey => $group) {
            $label = $group['label'];
            $selections = $group['selections'];

            if (empty($selections)) continue;

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

            // qpdf --empty --pages <file> <ranges...> <file2> <ranges...> -- <out.pdf>
            $args = [$qpdf, '--empty', '--pages'];

            foreach ($byFile as $filePath => $pages) {
                sort($pages);
                $pages = array_values(array_unique($pages));

                $args[] = $filePath;

                foreach ($this->pagesToRanges($pages) as $token) {
                    $args[] = $token; // e.g., "7-100" or "5"
                }
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
        return $outputs;
    }

    /**
     * Convert sorted unique pages to range tokens for qpdf.
     * Example: [1,2,3,5,7,8] => ["1-3", "5", "7-8"]
     *
     * @param  array<int,int> $pages
     * @return array<int,string>
     */
    private function pagesToRanges(array $pages): array
    {
        if (empty($pages)) return [];

        $ranges = [];
        $start = $pages[0];
        $prev  = $pages[0];

        for ($i = 1; $i < count($pages); $i++) {
            $p = $pages[$i];
            if ($p === $prev + 1) {
                $prev = $p;
                continue;
            }

            $ranges[] = ($start === $prev) ? (string)$start : ($start . '-' . $prev);
            $start = $p;
            $prev  = $p;
        }

        $ranges[] = ($start === $prev) ? (string)$start : ($start . '-' . $prev);
        return $ranges;
    }

    private function getPdfPageCount(string $absPdf): int
    {
        $pdfinfo = $this->pdfInfoBin();

        $proc = $this->runProcess([$pdfinfo, $absPdf], 60, true);
        $out = ($proc['stdout'] ?? '') . "\n" . ($proc['stderr'] ?? '');

        if (preg_match('/^\s*Pages:\s*(\d+)\s*$/mi', $out, $m)) {
            return max(1, (int)$m[1]);
        }

        return 1;
    }

    private function extractPageText(string $absPdf, int $page): string
    {
        $pdftotext = $this->pdfToTextBin();

        // Output to stdout ("-") so we don't need temp files
        $proc = $this->runProcess(
            [$pdftotext, '-enc', 'UTF-8', '-f', (string)$page, '-l', (string)$page, '-layout', $absPdf, '-'],
            60,
            true
        );

        return (string)($proc['stdout'] ?? '');
    }

    private function extractGoodsFromText(string $raw): string
    {
        // Normalize whitespace
        $raw = str_replace("\xC2\xA0", ' ', $raw); // NBSP
        $clean = preg_replace('/\s+/u', ' ', $raw) ?? '';
        $clean = trim($clean);

        if ($clean === '') return '';

        // Adjust delimiters if needed for your sticker template
        $delims = [
            'Qty', 'Quantity', 'Weight', 'COD', 'Amount', 'Order', 'Tracking', 'Waybill',
            'Receiver', 'Consignee', 'Sender', 'Address', 'Phone', 'Mobile',
            'Service', 'Date', 'Time', 'Payment',
        ];
        $delimPattern = implode('|', array_map(fn ($d) => preg_quote($d, '/'), $delims));

        // Capture after "Goods:" until next label or end
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

        // Standardize "1X" -> "1 X"
        $g = preg_replace('/\b(\d+)\s*X\b/i', '$1 X', $g) ?? $g;

        $g = mb_strtoupper($g, 'UTF-8');
        return $g !== '' ? $g : 'UNKNOWN';
    }

    /**
     * Run a process. If $captureOutput=true, returns stdout/stderr in array.
     *
     * @param  array<int,string> $args
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
            $cmd = $p->getCommandLine();
            $msg = "Command failed (exit {$exit}).\n{$cmd}\n\nSTDERR:\n{$stderr}\n\nSTDOUT:\n{$stdout}";
            throw new \RuntimeException($msg);
        }

        if ($captureOutput) {
            return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => (string)$exit];
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
        // If it's a path, validate exists
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
