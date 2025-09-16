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

        // Default = yesterday (single day). If only one date provided, use it for both ends.
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

        // Driver-specific date extraction + case-insensitive Delivered
        $dateSignExpr = $driver === 'pgsql' ? "CAST(signingtime AS DATE)"     : "DATE(signingtime)";
        $dateSubExpr  = $driver === 'pgsql' ? "CAST(submission_time AS DATE)" : "DATE(submission_time)";
        $statusDelivered = $driver === 'pgsql'
            ? "status ILIKE 'delivered%'"
            : "LOWER(status) LIKE 'delivered%'";

        // Sum of COD (robust casting if strings exist)
        $codExpr = $driver === 'pgsql'
            ? "COALESCE(NULLIF(cod,''), '0')::numeric"
            : "CAST(COALESCE(NULLIF(cod,''), '0') AS DECIMAL(18,2))";

        // --- Delivered per day (based on signingtime date) ---
        $delivered = DB::table('from_jnts')
            ->selectRaw("$dateSignExpr AS d, COUNT(*) AS delivered_count, COALESCE(SUM($codExpr),0) AS cod_sum")
            ->whereRaw($statusDelivered)
            ->whereNotNull('signingtime')
            ->whereBetween(DB::raw($dateSignExpr), [$start, $end])
            ->groupBy('d')
            ->orderBy('d')
            ->get();

        // --- Picked up per day (based on submission_time date) ---
        $picked = DB::table('from_jnts')
            ->selectRaw("$dateSubExpr AS d, COUNT(*) AS picked_count")
            ->whereNotNull('submission_time')
            ->whereBetween(DB::raw($dateSubExpr), [$start, $end])
            ->groupBy('d')
            ->orderBy('d')
            ->get();

        // Combine by date (outer merge in PHP)
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

        // Compute derived fields
        $rows = [];
        $totals = [
            'delivered'  => 0,
            'cod_sum'    => 0.0,
            'cod_fee'    => 0.0,
            'picked'     => 0,
            'ship_cost'  => 0.0,
            'remittance' => 0.0,
        ];

        foreach ($byDate as $d => $vals) {
            $deliveredCnt = (int)   ($vals['delivered'] ?? 0);
            $codSum       = (float) ($vals['cod_sum']   ?? 0);
            $pickedCnt    = (int)   ($vals['picked']    ?? 0);

            $codFee   = // OLD:
// $codFee   = round($codSum * 0.015, 2);      // 1.5%

// NEW:
$codFee   = round($codSum * 0.815 * 0.0112, 2);  // 81.5% × 1.12%

            $shipCost = round($pickedCnt * 37, 2);      // ₱37 per picked-up parcel
            $remit    = round($codSum - $codFee - $shipCost, 2);

            $rows[] = [
                'date'        => $d,
                'delivered'   => $deliveredCnt,
                'cod_sum'     => $codSum,
                'cod_fee'     => $codFee,
                'picked'      => $pickedCnt,
                'ship_cost'   => $shipCost,
                'remittance'  => $remit,
            ];

            $totals['delivered']  += $deliveredCnt;
            $totals['cod_sum']    += $codSum;
            $totals['cod_fee']    += $codFee;
            $totals['picked']     += $pickedCnt;
            $totals['ship_cost']  += $shipCost;
            $totals['remittance'] += $remit;
        }

        // Sort ascending by date
        usort($rows, fn($a, $b) => strcmp($a['date'], $b['date']));

        return view('jnt.remittance', [
            'rows'  => $rows,
            'totals'=> $totals,
            'start' => $start,
            'end'   => $end,
        ]);
    }
}
