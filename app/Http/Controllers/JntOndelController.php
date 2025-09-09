<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use ZipArchive;
use DateTime;
use DateTimeZone;

// OpenSpout v4
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

        $request->validate([
            'file'           => 'required|file',
            'delivered_date' => 'nullable|date',
        ]);

        $uploaded     = $request->file('file');
        $deliveredYmd = $request->input('delivered_date') ?: now('Asia/Manila')->toDateString();
        $realPath     = $uploaded->getRealPath();                       // temp path (may .tmp)
        $ext          = strtolower($uploaded->getClientOriginalExtension() ?: '');

        // Unique sets (associative arrays para O(1) membership)
        $sets = [
            'delivering'        => [],
            'in_transit'        => [],
            'delivered_on_date' => [],
            'for_return'        => [],
        ];

        if ($ext === 'zip') {
            $this->parseZip($realPath, $deliveredYmd, $sets);
        } elseif (in_array($ext, ['csv', 'xlsx', 'xls'])) {
            $this->parseSingle($realPath, $ext, $deliveredYmd, $sets);
        } else {
            // sniff magic (ZIP: PK)
            $sig = '';
            if ($fh = @fopen($realPath, 'rb')) { $sig = fread($fh, 2) ?: ''; fclose($fh); }
            if ($sig === 'PK') $this->parseZip($realPath, $deliveredYmd, $sets);
            else $this->parseCsv($realPath, $deliveredYmd, $sets);
        }

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

    // ---------- ZIP (only CSV/XLSX inside) ----------
    private function parseZip(string $zipPath, string $deliveredYmd, array &$sets): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) return;

        $tmpDir = storage_path('app/tmp_ondel_' . Str::random(8));
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if ($entry === false || str_ends_with($entry, '/')) continue;

            $lower = strtolower($entry);
            if (!preg_match('/\.(csv|xlsx|xls)$/i', $lower)) continue;

            $target = $tmpDir . DIRECTORY_SEPARATOR . basename($entry);
            if (@copy("zip://{$zipPath}#{$entry}", $target) === false) continue;

            $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
            $this->parseSingle($target, $ext, $deliveredYmd, $sets);

            @unlink($target);
        }

        $zip->close();
        @rmdir($tmpDir);
    }

    // ---------- Single switch ----------
    private function parseSingle(string $path, string $ext, string $deliveredYmd, array &$sets): void
    {
        if ($ext === 'csv') {
            $this->parseCsv($path, $deliveredYmd, $sets);
        } elseif ($ext === 'xlsx') {
            $this->parseXlsx($path, 'xlsx', $deliveredYmd, $sets);
        } elseif ($ext === 'xls') {
            // Optional: support via PhpSpreadsheet; otherwise suggest CSV/XLSX
            if (class_exists(\PhpOffice\PhpSpreadsheet\Reader\Xls::class)) {
                $this->parseXlsViaPhpSpreadsheet($path, $deliveredYmd, $sets);
            } else {
                // skip silently or throw; here we skip to keep response OK
            }
        }
    }

    // ---------- CSV (stream) – uses only 3 columns ----------
    private function parseCsv(string $path, string $deliveredYmd, array &$sets): void
    {
        $f = new \SplFileObject($path, 'r');
        $f->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY);
        $f->setCsvControl(',');

        // header (detect ;)
        $header = [];
        while (!$f->eof()) {
            $row = $f->fgetcsv();
            if ($row === [null] || $row === false) continue;
            if (count($row) === 1 && strpos((string)$row[0], ';') !== false) {
                $f->setCsvControl(';'); $f->rewind(); $row = $f->fgetcsv();
            }
            $header = $this->normalizeHeader($row);
            break;
        }
        if (!$header) return;

        $idx = $this->findIndices($header);
        // Kung wala ang Waybill o Status, walang silbi
        if ($idx['waybill'] < 0 || $idx['status'] < 0) return;

        while (!$f->eof()) {
            $row = $f->fgetcsv();
            if ($row === [null] || $row === false) continue;

            // KUNIN LANG ANG KAILANGAN
            $wb   = $this->safeCell($row, $idx['waybill']);
            $stat = strtolower(trim($this->safeCell($row, $idx['status'])));
            $sign = $idx['signing'] >= 0 ? $this->safeCell($row, $idx['signing']) : '';

            if ($wb === '' || !$this->isInterestingStatus($stat)) continue;

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
                $sets['for_return'][$wb] = true;
            }
        }
    }

    // ---------- XLSX (OpenSpout v4) – only read needed cells ----------
    private function parseXlsx(string $path, string $type, string $deliveredYmd, array &$sets): void
    {
        // IMPORTANT: use createFromType('xlsx') – temp path may have .tmp
        $reader = ReaderFactory::createFromType($type);
        $reader->open($path);

        foreach ($reader->getSheetIterator() as $sheet) {
            $header = null;
            $idx    = null;

            foreach ($sheet->getRowIterator() as $row) {
                // First row = header
                if ($header === null) {
                    // Read raw header cell strings only
                    $cells = $row->getCells();
                    $h = [];
                    foreach ($cells as $cell) { $h[] = strtolower(trim((string)$cell->getValue())); }
                    $header = $h;
                    $idx    = $this->findIndices($header);
                    if ($idx['waybill'] < 0 || $idx['status'] < 0) break; // skip sheet
                    continue;
                }

                // DO NOT build values array; pick by index only
                $cells = $row->getCells();

                $wb   = ($idx['waybill'] >= 0 && isset($cells[$idx['waybill']])) ? trim((string)$cells[$idx['waybill']]->getValue()) : '';
                $stat = ($idx['status']  >= 0 && isset($cells[$idx['status'] ])) ? strtolower(trim((string)$cells[$idx['status']]->getValue())) : '';
                $sign = ($idx['signing'] >= 0 && isset($cells[$idx['signing']])) ? (string)$cells[$idx['signing']]->getValue() : '';

                if ($wb === '' || !$this->isInterestingStatus($stat)) continue;

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
                    $sets['for_return'][$wb] = true;
                }
            }
        }

        $reader->close();
    }

    // ---------- Optional XLS (PhpSpreadsheet) ----------
    private function parseXlsViaPhpSpreadsheet(string $path, string $deliveredYmd, array &$sets): void
    {
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xls();
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);

        foreach ($spreadsheet->getWorksheetIterator() as $ws) {
            $rows = $ws->toArray(null, false, false, false);
            if (!$rows) continue;

            $header = $this->normalizeHeader($rows[0] ?? []);
            $idx    = $this->findIndices($header);
            if ($idx['waybill'] < 0 || $idx['status'] < 0) continue;

            $n = count($rows);
            for ($i = 1; $i < $n; $i++) {
                $row  = $rows[$i];
                $wb   = $this->safeCell($row, $idx['waybill']);
                $stat = strtolower(trim($this->safeCell($row, $idx['status'])));
                $sign = $idx['signing'] >= 0 ? $this->safeCell($row, $idx['signing']) : '';

                if ($wb === '' || !$this->isInterestingStatus($stat)) continue;

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
                    $sets['for_return'][$wb] = true;
                }
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }

    // ---------- Helpers ----------
    private function normalizeHeader(array $cells): array
    {
        $h = [];
        foreach ($cells as $c) { $h[] = strtolower(trim((string)$c)); }
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

    private function isInterestingStatus(string $stat): bool
    {
        // Para mabilis mag-skip ng ibang status
        return $stat === 'delivering' || $stat === 'in transit' || $stat === 'delivered' || $stat === 'for return';
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

        // Excel serial number
        if (is_numeric($val)) {
            $seconds = ($val - 25569) * 86400;
            $ts = (int) round($seconds);
            $dt = (new DateTime("@$ts"))->setTimezone(new DateTimeZone('Asia/Manila'));
            return $dt->format('Y-m-d');
        }

        // String dates
        $ts = strtotime((string)$val);
        if ($ts !== false) {
            return (new DateTime("@$ts"))->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d');
        }

        return null;
    }
}
