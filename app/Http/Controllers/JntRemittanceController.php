<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class JntRemittanceController extends Controller
{
    public function index(Request $request)
    {
        $tz     = 'Asia/Manila';
        $driver = DB::getDriverName();

        // Default: yesterday (single day)
        $start = $request->input('start_date');
        $end   = $request->input('end_date');

        if (!$start && !$end) {
            $yesterday = Carbon::yesterday($tz)->toDateString();
            $start = $yesterday;
            $end   = $yesterday;
        } else {
            if ($start && !$end)  $end = $start;
            if (!$start && $end)  $start = $end;
        }

        // Driver-specific SQL bits
        $dateSignExpr = $driver === 'pgsql' ? "CAST(signingtime AS DATE)"     : "DATE(signingtime)";
        $dateSubExpr  = $driver === 'pgsql' ? "CAST(submission_time AS DATE)" : "DATE(submission_time)";

        $statusDelivered = $driver === 'pgsql'
            ? "status ILIKE 'delivered%'"
            : "LOWER(status) LIKE 'delivered%'";

        // Robust COD cast (strip commas, blanks -> 0)
        if ($driver === 'pgsql') {
            $codExpr = "COALESCE(NULLIF(REPLACE(cod, ',', ''), ''), '0')::numeric";
        } else { // mysql
            $codExpr = "CAST(REPLACE(COALESCE(NULLIF(cod,''), '0'), ',', '') AS DECIMAL(18,2))";
        }

        // Delivered by signingtime date
        $delivered = DB::table('from_jnts')
            ->selectRaw("$dateSignExpr AS d, COUNT(*) AS delivered_count, COALESCE(SUM($codExpr),0) AS cod_sum")
            ->whereRaw($statusDelivered)
            ->whereNotNull('signingtime')
            ->whereBetween(DB::raw($dateSignExpr), [$start, $end])
            ->groupBy('d')
            ->orderBy('d')
            ->get();

        // Pickups by submission_time date
        $picked = DB::table('from_jnts')
            ->selectRaw("$dateSubExpr AS d, COUNT(*) AS picked_count")
            ->whereNotNull('submission_time')
            ->whereBetween(DB::raw($dateSubExpr), [$start, $end])
            ->groupBy('d')
            ->orderBy('d')
            ->get();

        // Merge by date
        $byDate = [];
        foreach ($delivered as $r) {
            $d = $r->d;
            $byDate[$d] = $byDate[$d] ?? ['date' => $d, 'delivered' => 0, 'cod_sum' => 0.0, 'picked' => 0];
            $byDate[$d]['delivered'] = (int) $r->delivered_count;
            $byDate[$d]['cod_sum']   = (float) $r->cod_sum;
        }
        foreach ($picked as $r) {
            $d = $r->d;
            $byDate[$d] = $byDate[$d] ?? ['date' => $d, 'delivered' => 0, 'cod_sum' => 0.0, 'picked' => 0];
            $byDate[$d]['picked'] = (int) $r->picked_count;
        }

        // Compute rows + totals
        $rows = [];
        $totals = [
            'delivered'   => 0,
            'cod_sum'     => 0.0,
            'cod_fee'     => 0.0, // NEW: separate
            'cod_fee_vat' => 0.0, // NEW: separate
            'picked'      => 0,
            'ship_cost'   => 0.0,
            'remittance'  => 0.0,
        ];

        foreach ($byDate as $d => $vals) {
            $deliveredCnt = (int)   ($vals['delivered'] ?? 0);
            $codSum       = (float) ($vals['cod_sum']   ?? 0);
            $pickedCnt    = (int)   ($vals['picked']    ?? 0);

            // ✅ Correct formulas
            $codFee     = round($codSum * 0.015, 2);     // COD Fee = 1.5% × COD Sum
            $codFeeVat  = round($codFee * 0.12, 2);      // COD Fee VAT = 0.12 × COD Fee
            $shipCost   = round($pickedCnt * 37, 2);     // ₱37 per picked-up parcel
            $remit      = round($codSum - $codFee - $codFeeVat - $shipCost, 2);

            $rows[] = [
                'date'        => $d,
                'delivered'   => $deliveredCnt,
                'cod_sum'     => $codSum,
                'cod_fee'     => $codFee,
                'cod_fee_vat' => $codFeeVat,
                'picked'      => $pickedCnt,
                'ship_cost'   => $shipCost,
                'remittance'  => $remit,
            ];

            $totals['delivered']   += $deliveredCnt;
            $totals['cod_sum']     += $codSum;
            $totals['cod_fee']     += $codFee;
            $totals['cod_fee_vat'] += $codFeeVat;
            $totals['picked']      += $pickedCnt;
            $totals['ship_cost']   += $shipCost;
            $totals['remittance']  += $remit;
        }

        usort($rows, fn($a, $b) => strcmp($a['date'], $b['date']));

        return view('jnt.remittance', [
            'rows'   => $rows,
            'totals' => $totals,
            'start'  => $start,
            'end'    => $end,
        ]);
    }
}
