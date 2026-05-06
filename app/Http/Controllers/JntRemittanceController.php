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
                    'unique_sf_rates'   => [],
                    'by_date'           => [],
                ],
            ]);
        }

        $request->validate([
            'start_date' => 'required|date',
            'end_date'   => 'nullable|date',
        ]);

        $end = $end ?: $start;

        // Per-date rate map — handles mid-range rate changes (e.g., SF was ₱37 then ₱36 starting May 5)
        $ratesByDate = [];
        $cur = Carbon::parse($start);
        $endC = Carbon::parse($end);
        while ($cur->lte($endC)) {
            $d = $cur->toDateString();
            $ratesByDate[$d] = [
                'cod_fee_rate'           => FeeSetting::getRate('cod_fee_rate',           $host, $d),
                'cod_fee_vat_rate'       => FeeSetting::getRate('cod_fee_vat_rate',       $host, $d),
                'shipping_fee_per_order' => FeeSetting::getRate('shipping_fee_per_order', $host, $d),
            ];
            $cur->addDay();
        }

        // Validate: every date in range must have all 3 rates
        $missingByDate = [];
        foreach ($ratesByDate as $d => $r) {
            $miss = array_keys(array_filter($r, fn($v) => $v === null));
            if (!empty($miss)) $missingByDate[$d] = $miss;
        }
        if (!empty($missingByDate)) {
            $parts = [];
            foreach ($missingByDate as $d => $keys) $parts[] = "[{$d}: " . implode(', ', $keys) . ']';
            abort(422, "Missing fee_settings (host: {$host}): " . implode(' ', $parts) . ". Configure at /jnt/fee-settings.");
        }

        // Start-date snapshot — used for header display only (per-row uses ratesByDate)
        $codRate    = $ratesByDate[$start]['cod_fee_rate'];
        $codVatRate = $ratesByDate[$start]['cod_fee_vat_rate'];
        $expectedSF = $ratesByDate[$start]['shipping_fee_per_order'];

        // Unique SF rates in range (for alert display)
        $uniqueSfRates = array_values(array_unique(array_column($ratesByDate, 'shipping_fee_per_order')));
        sort($uniqueSfRates);

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

        // SF anomaly detection per date — each date uses its OWN expected SF (handles mid-range rate changes)
        $sfAnomalies = [];
        $anomalyQuery = DB::table('from_jnts')
            ->selectRaw("$dateSubExpr AS d, total_shipping_cost AS sf_value, COUNT(*) AS cnt")
            ->whereNotNull('submission_time')
            ->whereBetween(DB::raw($dateSubExpr), [$start, $end])
            ->groupBy('d', 'total_shipping_cost')
            ->orderBy('d')
            ->get();

        foreach ($anomalyQuery as $row) {
            $expectedForDate = (float) ($ratesByDate[$row->d]['shipping_fee_per_order'] ?? 0);
            if (abs((float)$row->sf_value - $expectedForDate) > 0.01) {
                $sfAnomalies[$row->d][] = [
                    'sf_value' => (float) $row->sf_value,
                    'count'    => (int) $row->cnt,
                    'expected' => $expectedForDate,
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

            // Per-date rates — handles mid-range rate changes
            $rateForDate = $ratesByDate[$d] ?? $ratesByDate[$start];
            $codFee     = round($codSum * $rateForDate['cod_fee_rate'], 2);
            $codFeeVat  = round($codFee * $rateForDate['cod_fee_vat_rate'], 2);
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
                'unique_sf_rates'   => $uniqueSfRates,
                'by_date'           => $ratesByDate,
            ],
        ]);
    }
}
