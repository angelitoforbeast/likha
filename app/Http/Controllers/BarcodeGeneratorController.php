<?php

namespace App\Http\Controllers;

use App\Models\BarcodePrintLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * /macro/barcodes — Bundle Barcode Generator.
 *
 * Nag-gge-generate ng isang BUNDLE BARCODE kada (ITEM_NAME + date) mula sa
 * macro_output (rows na may waybill). Bawat waybill sa grupo ay share ang
 * parehong bundle barcode. Output:
 *   - copy table (BARCODE, WAYBILL) — text, para i-paste sa ibang website
 *   - printable QR labels (QR + ITEM NAME + DATE + COUNT)
 *
 * Barcode value = UPPER(alphanumeric ng ITEM_NAME) + '-' + YYYYMMDD
 *   e.g. "1 x SEAT COVER" @ Aug 13 2026 → 1XSEATCOVER-20260813
 *
 * Access: CEO, Marketing, Marketing - OIC, Encoder - OIC.
 */
class BarcodeGeneratorController extends Controller
{
    private function getNormalizedRole(): string
    {
        $raw = Auth::user()?->employeeProfile?->role ?? '';
        return preg_replace('/\s+/u', ' ', trim((string) $raw));
    }

    private function checkAccess(): void
    {
        $n  = $this->getNormalizedRole();
        $ok = preg_match('/^ceo$/iu', $n)
            || preg_match('/^marketing$/iu', $n)
            || preg_match('/^marketing\s*[-–—]\s*oic$/iu', $n)
            || preg_match('/^encoder\s*[-–—]\s*oic$/iu', $n);
        if (!$ok) abort(404);
    }

    /** Bundle barcode value — deterministic mula item + date. */
    private function bundleBarcode(string $itemName, string $dateYmd): string
    {
        $slug = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $itemName));
        if ($slug === '') $slug = 'ITEM';
        return $slug . '-' . $dateYmd;
    }

    /** GET /macro/barcodes — UI (default date = kahapon PH). */
    public function index()
    {
        $this->checkAccess();
        return view('macro.barcodes.index', [
            'defaultDate' => Carbon::yesterday('Asia/Manila')->toDateString(),
        ]);
    }

    /** GET /macro/barcodes/data?date=YYYY-MM-DD — bundles + flat (barcode,waybill) rows. */
    public function data(Request $request)
    {
        $this->checkAccess();
        $request->validate(['date' => 'required|date']);
        $date = Carbon::parse($request->query('date'))->toDateString();

        // macro_output rows na may waybill, para sa piniling date (TIMESTAMP string parse).
        $rows = DB::table('macro_output')
            ->selectRaw("ITEM_NAME as item_name, waybill")
            ->whereRaw("DATE(STR_TO_DATE(`TIMESTAMP`, '%H:%i %d-%m-%Y')) = ?", [$date])
            ->whereNotNull('waybill')
            ->where('waybill', '<>', '')
            ->get();

        $dateYmd = Carbon::parse($date)->format('Ymd');

        // Group by exact ITEM_NAME; dedup waybills sa loob ng bundle.
        $byItem = [];
        foreach ($rows as $r) {
            $item = trim((string) $r->item_name);
            $wb   = trim((string) $r->waybill);
            if ($item === '' || $wb === '') continue;
            $byItem[$item][$wb] = true;
        }
        ksort($byItem, SORT_NATURAL | SORT_FLAG_CASE);

        $bundles = [];
        $flat    = [];
        foreach ($byItem as $item => $wbset) {
            $waybills = array_keys($wbset);
            $barcode  = $this->bundleBarcode($item, $dateYmd);
            $bundles[] = [
                'item_name' => $item,
                'count'     => count($waybills),
                'barcode'   => $barcode,
                'waybills'  => $waybills,
            ];
            foreach ($waybills as $wb) {
                $flat[] = ['barcode' => $barcode, 'waybill' => $wb];
            }
        }

        return response()->json([
            'date'     => $date,
            'date_ymd' => $dateYmd,
            'bundles'  => $bundles,
            'rows'     => $flat,
            'totals'   => ['bundles' => count($bundles), 'waybills' => count($flat)],
        ]);
    }

    /** POST /macro/barcodes/print — audit log kada print. */
    public function logPrint(Request $request)
    {
        $this->checkAccess();
        $data = $request->validate([
            'date'          => 'required|date',
            'bundle_count'  => 'nullable|integer|min:0',
            'waybill_count' => 'nullable|integer|min:0',
        ]);

        $user = Auth::user();
        BarcodePrintLog::create([
            'target_date'   => Carbon::parse($data['date'])->toDateString(),
            'bundle_count'  => (int) ($data['bundle_count'] ?? 0),
            'waybill_count' => (int) ($data['waybill_count'] ?? 0),
            'user_id'       => $user?->id,
            'user_name'     => $user?->name,
            'user_email'    => $user?->email,
            'user_role'     => $this->getNormalizedRole() ?: null,
            'ip'            => $request->ip(),
        ]);

        return response()->json(['ok' => true]);
    }

    /** GET /macro/barcodes/logs — print history. */
    public function logs()
    {
        $this->checkAccess();
        return view('macro.barcodes.logs', [
            'logs' => BarcodePrintLog::orderByDesc('id')->limit(500)->get(),
        ]);
    }
}
