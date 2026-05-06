<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\FeeSetting;

class JntRemittanceController extends Controller
{
    public function index(Request $request)
    {
        $tz     = 'Asia/Manila';
        $driver = DB::getDriverName();

        // Host-based scope
        $host = strtolower((string) $request->getHost());

        $start = $request->input('start_date');
        $end   = $request->input('end_date');

        // Empty state — no dates picked yet, walang i-co-compute
        if (!$start) {
            return view('jnt.remittance', [
                'rows'   => [],
                'totals' => [
                    'delivered' => 0, 'cod_sum' => 0.0, 'cod_fee' => 0.0, 'cod_fee_vat' => 0.0,
                    'picked' => 0, 'ship_cost' => 0.0, 'remittance' => 0.0, 'sf_anomaly_count' => 0,
                ],
                'start'  => null,
                'end'    => null,
                'rates'  => [
                    'host'              => $host,
                    'cod_fee_rate'      => null,
                    'cod_vat_rate'      => null,
                    'expected_ship_fee' => null,
                ],
            ]);
        }

        $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'nullable|date',
        ]);

        $end = $end ?: $start;

        // Get rates from Fee Settings — no fallback, must be configured at /jnt/fee-settings
        $codRate    = FeeSetting::getRate('cod_fee_rate', $host, $start);
        $codVatRate = FeeSetting::getRate('cod_fee_vat_rate', $host, $start);
        $expectedSF = FeeSetting::getRate('shipping_fee_per_order', $host, $start);

        if ($codRate === null || $codVatRate === null || $expectedSF === null) {
            $missing = array_filter([
                $codRate    === null ? 'cod_fee_rate'           : null,
                $codVatRate === null ? 'cod_fee_vat_rate'       : null,
                $expectedSF === null ? 'shipping_fee_per_order' : null,
            ]);
            abort(422, "Missing fee_settings for {$start} (host: {$host}): " . implode(', ', $missing) . ". Configure at /jnt/fee-settings.");
        }

        // Driver-specific SQL bits
        $dateSignExpr = $driver === 'pgsql' ? "CAST(signingtime AS DATE)"      : "DATE(signingtime)";
        $dateSubExpr  = $driver === 'pgsql' ? "CAST(submission_time AS DATE)" : "DATE(submission_time)";

        $statusDelivered = $driver === 'pgsql'
            ? "status ILIKE 'delivered%'"
            : "LOWER(status) LIKE 'delivered%'";

        // Robust COD cast (strip commas, blanks -> 0)
        if ($driver === 'pgsql') {
            $codExpr = "COALESCE(NULLIF(REPLACE(cod, ',', ''), ''), '0')::numeric";
            $sfExpr  = "COALESCE(total_shipping_cost, 0)::numeric";
        } else { // mysql
            $codExpr = "CAST(REPLACE(COALESCE(NULLIF(cod,''), '0'), ',', '') AS DECIMAL(18,2))";
            $sfExpr  = "CAST(COALESCE(total_shipping_cost, 0) AS DECIMAL(18,2))";
        }

        // Delivered by signingtime date — now includes actual shipping cost sum
        $delivered = DB::table('from_jnts')
            ->selectRaw("$dateSignExpr AS d, COUNT(*) AS delivered_count, COALESCE(SUM($codExpr),0) AS cod_sum, COALESCE(SUM($sfExpr),0) AS actual_ship_cost")
            ->whereRaw($statusDelivered)
            ->whereNotNull('signingtime')
            ->whereBetween(DB::raw($dateSignExpr), [$start, $end])
            ->groupBy('d')
            ->orderBy('d')
            ->get();

        // Pickups by submission_time date — now includes actual shipping cost sum
        $picked = DB::table('from_jnts')
            ->selectRaw("$dateSubExpr AS d, COUNT(*) AS picked_count, COALESCE(SUM($sfExpr),0) AS picked_ship_cost")
            ->whereNotNull('submission_time')
            ->whereBetween(DB::raw($dateSubExpr), [$start, $end])
            ->groupBy('d')
            ->orderBy('d')
            ->get();

        // SF anomaly detection per date: count records with unexpected shipping fee
        $sfAnomalies = [];
        if ($expectedSF !== null) {
            $anomalyQuery = DB::table('from_jnts')
                ->selectRaw("$dateSubExpr AS d, total_shipping_cost AS sf_value, COUNT(*) AS cnt")
                ->whereNotNull('submission_time')
                ->whereBetween(DB::raw($dateSubExpr), [$start, $end])
                ->where('total_shipping_cost', '!=', $expectedSF)
                ->groupBy('d', 'total_shipping_cost')
                ->orderBy('d')
                ->get();

            foreach ($anomalyQuery as $row) {
                $sfAnomalies[$row->d][] = [
                    'sf_value' => (float) $row->sf_value,
                    'count'    => (int) $row->cnt,
                ];
            }
        }

        // Merge by date
        $byDate = [];
        foreach ($delivered as $r) {
            $d = $r->d;
            $byDate[$d] = $byDate[$d] ?? ['date' => $d, 'delivered' => 0, 'cod_sum' => 0.0, 'picked' => 0, 'actual_ship_cost' => 0.0, 'picked_ship_cost' => 0.0];
            $byDate[$d]['delivered']         = (int) $r->delivered_count;
            $byDate[$d]['cod_sum']           = (float) $r->cod_sum;
            $byDate[$d]['actual_ship_cost']  = (float) $r->actual_ship_cost;
        }
        foreach ($picked as $r) {
            $d = $r->d;
            $byDate[$d] = $byDate[$d] ?? ['date' => $d, 'delivered' => 0, 'cod_sum' => 0.0, 'picked' => 0, 'actual_ship_cost' => 0.0, 'picked_ship_cost' => 0.0];
            $byDate[$d]['picked']            = (int) $r->picked_count;
            $byDate[$d]['picked_ship_cost']  = (float) $r->picked_ship_cost;
        }

        // Compute rows + totals
        $rows = [];
        $totals = [
            'delivered'        => 0,
            'cod_sum'          => 0.0,
            'cod_fee'          => 0.0,
            'cod_fee_vat'      => 0.0,
            'picked'           => 0,
            'ship_cost'        => 0.0,
            'remittance'       => 0.0,
            'sf_anomaly_count' => 0,
        ];

        foreach ($byDate as $d => $vals) {
            $deliveredCnt = (int)   ($vals['delivered'] ?? 0);
            $codSum       = (float) ($vals['cod_sum']   ?? 0);
            $pickedCnt    = (int)   ($vals['picked']    ?? 0);

            // Use ACTUAL shipping cost from database
            $shipCost = (float) ($vals['picked_ship_cost'] ?? 0.0);

            // COD fee calculations using Fee Settings rates
            $codFee     = round($codSum * $codRate, 2);
            $codFeeVat  = round($codFee * $codVatRate, 2);
            $remit      = round($codSum - $codFee - $codFeeVat - $shipCost, 2);

            // SF anomaly info for this date
            $dateAnomalies = $sfAnomalies[$d] ?? [];
            $anomalyCount  = array_sum(array_column($dateAnomalies, 'count'));

            $rows[] = [
                'date'             => $d,
                'delivered'        => $deliveredCnt,
                'cod_sum'          => $codSum,
                'cod_fee'          => $codFee,
                'cod_fee_vat'      => $codFeeVat,
                'picked'           => $pickedCnt,
                'ship_cost'        => $shipCost,
                'remittance'       => $remit,
                'sf_anomalies'     => $dateAnomalies,
                'sf_anomaly_count' => $anomalyCount,
            ];

            $totals['delivered']        += $deliveredCnt;
            $totals['cod_sum']          += $codSum;
            $totals['cod_fee']          += $codFee;
            $totals['cod_fee_vat']      += $codFeeVat;
            $totals['picked']           += $pickedCnt;
            $totals['ship_cost']        += $shipCost;
            $totals['remittance']       += $remit;
            $totals['sf_anomaly_count'] += $anomalyCount;
        }

        usort($rows, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return view('jnt.remittance', [
            'rows'   => $rows,
            'totals' => $totals,
            'start'  => $start,
            'end'    => $end,

            'rates'  => [
                'host'              => $host,
                'cod_fee_rate'      => $codRate,
                'cod_vat_rate'      => $codVatRate,
                'expected_ship_fee' => $expectedSF,
            ],
        ]);
    }
}
