<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use ZipArchive;
use DateTime;
use DateTimeZone;

// ✅ OpenSpout v4 imports
use OpenSpout\Reader\Common\Creator\ReaderFactory;

class JntOndelController extends Controller
{
    public function index()
    {
        return view('jnt.ondel-counter');
    }

    public function process(Request $request)
    {
        @ini_set('memory_limit', '512M');
        @set_time_limit(300);

        \Log::info('Upload started: ' . now('Asia/Manila'));

        $request->validate([
            'file'           => 'required|file',
            'delivered_date' => 'nullable|date',
        ]);

        $uploaded = $request->file('file');
        \Log::info('File received: ' . $uploaded->getClientOriginalName());

        $deliveredYmd = $request->input('delivered_date') ?: now('Asia/Manila')->toDateString();
        $realPath     = $uploaded->getRealPath();
        $ext          = strtolower($uploaded->getClientOriginalExtension() ?: '');

        // Unique sets
        $sets = [
            'delivering'        => [],
            'in_transit'        => [],
            'delivered_on_date' => [],
            'for_return'        => [],
        ];

        // Decide parser
        if ($ext === 'zip') {
            $this->parseZip($realPath, $deliveredYmd, $sets);
        } elseif (in_array($ext, ['csv', 'xlsx', 'xls'])) {
            $this->parseSingle($realPath, $ext, $deliveredYmd, $sets);
        } else {
            // sniff magic bytes (ZIP: "PK")
            $sig = '';
            if ($fh = @fopen($realPath, 'rb')) { $sig = fread($fh, 2) ?: ''; fclose($fh); }
            if ($sig === 'PK') $this->parseZip($realPath, $deliveredYmd, $sets);
            else $this->parseCsv($realPath, $deliveredYmd, $sets);
        }

        // Build response
        $dAll  = array_keys($sets['delivering']);
        $itAll = array_keys($sets['in_transit']);
        $dvAll = array_keys($sets['delivered_on_date']);
        $frAll = array_keys($sets['for_return']);

        return response()->json([
            'dAll'   => $dAll,
            'itAll'  => $itAll,
            'dvAll'  => $dvAll,
            'frAll'  => $frAll,
            'counts' => [
                'd'  => count($dAll),
                'it' => count($itAll),
                'dv' => count($dvAll),
                'fr' => count($frAll),
            ],
        ]);
    }

    // --- ZIP ---
    private function parseZip(string $zipPath, string $deliveredYmd, array &$sets): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            \Log::warning('Unable to open ZIP');
            return;
        }

        $tmpDir = storage_path('app/tmp_ondel_' . Str::random(8));
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if ($entry === false) continue;
            if (Str::endsWith($entry, '/')) continue; // skip folders

            $lower = strtolower($entry);
            if (!preg_match('/\.(csv|xlsx|xls)$/i', $lower)) continue;

            $target = $tmpDir . DIRECTORY_SEPARATOR . basename($entry);
            if (@copy("zip://{$zipPath}#{$entry}", $target) === false) {
                \Log::warning("Failed to extract {$entry}");
                continue;
            }

            $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
            $this->parseSingle($target, $ext, $deliveredYmd, $sets);

            @unlink($target);
        }

        $zip->close();
        @rmdir($tmpDir);
    }

    // --- Switch ---
    private function parseSingle(string $path, string $ext, string $deliveredYmd, array &$sets): void
    {
        if ($ext === 'csv')       $this->parseCsv($path, $deliveredYmd, $sets);
        if ($ext === 'xlsx' || $ext === 'xls') $this->parseXlsx($path, $deliveredYmd, $sets);
    }

    // --- CSV (stream) ---
    private function parseCsv(string $path, string $deliveredYmd, array &$sets): void
    {
        $f = new \SplFileObject($path, 'r');
        $f->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY);
        $f->setCsvControl(',');

        // header (detect ';' if needed)
        $header = [];
        while (!$f->eof()) {
            $row = $f->fgetcsv();
            if ($row === [null] || $row === false) continue;
            if (count($row) === 1 && strpos((string)$row[0], ';') !== false) {
                $f->setCsvControl(';');
                $f->rewind();
                $row = $f->fgetcsv();
            }
            $header = $this->normalizeHeader($row);
            break;
        }
        if (!$header) return;

        $idx = $this->findIndices($header);

        while (!$f->eof()) {
            $row = $f->fgetcsv();
            if ($row === [null] || $row === false) continue;
            $this->accumulateRow($row, $idx, $deliveredYmd, $sets);
        }
    }

    // --- XLSX/XLS (OpenSpout v4) ---
    private function parseXlsx(string $path, string $deliveredYmd, array &$sets): void
    {
        // ✅ v4: ReaderFactory::createFromFile()
        $reader = ReaderFactory::createFromFile($path);
        $reader->open($path);

        foreach ($reader->getSheetIterator() as $sheet) {
            $header = null;
            $idx    = null;
            $i = 0;

            foreach ($sheet->getRowIterator() as $row) {
                $i++;

                // ✅ v4: getCells() -> getValue()
                $cells = [];
                foreach ($row->getCells() as $cell) {
                    $cells[] = $cell->getValue();
                }

                if ($header === null) {
                    $header = $this->normalizeHeader($cells);
                    $idx    = $this->findIndices($header);
                    continue;
                }

                $this->accumulateRow($cells, $idx, $deliveredYmd, $sets);

                if ($i % 10000 === 0) {
                    \Log::info("OpenSpout processed {$i} rows…");
                }
            }
        }

        $reader->close();
    }

    // --- Helpers ---
    private function normalizeHeader(array $cells): array
    {
        $h = [];
        foreach ($cells as $c) $h[] = strtolower(trim((string)$c));
        return $h;
    }

    private function findIndices(array $header): array
    {
        $find = function(array $cands) use ($header) {
            foreach ($cands as $c) {
                $i = array_search(strtolower($c), $header, true);
                if ($i !== false) return (int)$i;
            }
            return -1;
        };

        return [
            'waybill' => $find(['Waybill Number','Waybill','Waybill No','WaybillNumber','WAYBILL NUMBER','WAYBILL']),
            'status'  => $find(['Order Status','Status','ORDER STATUS','STATUS']),
            'signing' => $find(['SigningTime','Signing Time','SIGNINGTIME','SIGNING TIME']),
        ];
    }

    private function accumulateRow(array $row, array $idx, string $deliveredYmd, array &$sets): void
    {
        $wb   = $this->safeCell($row, $idx['waybill']);
        $stat = strtolower(trim($this->safeCell($row, $idx['status'])));
        $sign = $this->safeCell($row, $idx['signing']);

        if ($wb === '') return;

        if ($stat === 'delivering') {
            $sets['delivering'][$wb] = true;
        } elseif ($stat === 'in transit') {
            $sets['in_transit'][$wb] = true;
        } elseif ($stat === 'delivered') {
            $signYmd = $this->phpYmdFromValue($sign);
            if ($signYmd && $signYmd === $deliveredYmd) {
                $sets['delivered_on_date'][$wb] = true;
            }
        } elseif ($stat === 'for return') {
            $sets['for_return'][$wb] = true; // client will intersect with today's delivering
        }
    }

    private function safeCell(array $row, int $idx): string
    {
        if ($idx < 0) return '';
        $v = $row[$idx] ?? '';
        return is_scalar($v) ? trim((string)$v) : '';
    }

    private function phpYmdFromValue($val): ?string
    {
        if ($val === null || $val === '') return null;

        // Numeric -> Excel serial date
        if (is_numeric($val)) {
            $seconds = ($val - 25569) * 86400; // days -> seconds
            $ts = (int) round($seconds);
            $dt = (new DateTime("@$ts"))->setTimezone(new DateTimeZone('Asia/Manila'));
            return $dt->format('Y-m-d');
        }

        // String -> strtotime
        $ts = strtotime((string)$val);
        if ($ts !== false) {
            return (new DateTime("@$ts"))->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d');
        }

        return null;
    }
}
