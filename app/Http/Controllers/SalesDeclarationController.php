<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SalesDeclarationController extends Controller
{
    /**
     * Show the sales declaration form.
     */
    public function index(Request $request)
    {
        $month = $request->input('month', now()->format('Y-m'));

        $startDate = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $endDate   = $startDate->copy()->endOfMonth();

        // Month summary (Delivered only)
        $monthTotal = DB::table('from_jnts')
            ->whereBetween('submission_time', [$startDate, $endDate])
            ->where('status', 'Delivered')
            ->sum('cod');

        $monthCount = DB::table('from_jnts')
            ->whereBetween('submission_time', [$startDate, $endDate])
            ->where('status', 'Delivered')
            ->count();

        // Available statuses
        $statuses = DB::table('from_jnts')
            ->whereBetween('submission_time', [$startDate, $endDate])
            ->whereNotNull('status')
            ->distinct()
            ->orderBy('status')
            ->pluck('status');

        return view('jnt.sales-declaration', compact(
            'month', 'statuses', 'monthTotal', 'monthCount'
        ));
    }

    /**
     * AJAX: Return cascading filter options (senders, items with prices, COD values).
     */
    public function filterOptions(Request $request)
    {
        $month = $request->input('month', now()->format('Y-m'));
        $status = $request->input('status', 'Delivered');
        $selectedSenders = $request->input('senders', []);
        $selectedItems   = $request->input('items', []);

        $startDate = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $endDate   = $startDate->copy()->endOfMonth();

        // --- Build base query conditions ---
        $baseWhere = function ($q) use ($startDate, $endDate, $status) {
            $q->whereBetween('submission_time', [$startDate, $endDate])
              ->where('status', $status);
        };

        // --- Senders: filtered by selected items ---
        $senderQuery = DB::table('from_jnts')->where($baseWhere);
        if (!empty($selectedItems)) {
            // Extract raw item names (strip the price parenthesis)
            $rawItems = array_map(function ($item) {
                return preg_replace('/\s*\(₱.*\)$/', '', $item);
            }, $selectedItems);
            $senderQuery->whereIn('item_name', $rawItems);
        }
        $senders = $senderQuery
            ->whereNotNull('sender')
            ->where('sender', '!=', '')
            ->distinct()
            ->orderBy('sender')
            ->pluck('sender')
            ->values();

        // --- Items with prices: filtered by selected senders ---
        $itemQuery = DB::table('from_jnts')->where($baseWhere);
        if (!empty($selectedSenders)) {
            $itemQuery->whereIn('sender', $selectedSenders);
        }

        $itemPrices = $itemQuery
            ->whereNotNull('item_name')
            ->where('item_name', '!=', '')
            ->select('item_name', DB::raw('CAST(cod AS DECIMAL(10,0)) as cod_val'))
            ->distinct()
            ->orderBy('item_name')
            ->get();

        // Group prices per item
        $itemMap = [];
        foreach ($itemPrices as $row) {
            $name = $row->item_name;
            $cod  = (int) $row->cod_val;
            if (!isset($itemMap[$name])) {
                $itemMap[$name] = [];
            }
            if ($cod > 0 && !in_array($cod, $itemMap[$name])) {
                $itemMap[$name][] = $cod;
            }
        }

        $items = [];
        foreach ($itemMap as $name => $prices) {
            sort($prices);
            $priceStr = implode(', ', array_map(fn ($p) => '₱' . number_format($p), $prices));
            $items[] = [
                'value' => $name,
                'label' => $name . ($priceStr ? " ({$priceStr})" : ''),
                'prices' => $prices,
            ];
        }

        usort($items, fn ($a, $b) => strcmp($a['value'], $b['value']));

        // --- COD values: filtered by selected senders + items ---
        $codQuery = DB::table('from_jnts')->where($baseWhere);
        if (!empty($selectedSenders)) {
            $codQuery->whereIn('sender', $selectedSenders);
        }
        if (!empty($selectedItems)) {
            $rawItems = array_map(function ($item) {
                return preg_replace('/\s*\(₱.*\)$/', '', $item);
            }, $selectedItems);
            $codQuery->whereIn('item_name', $rawItems);
        }

        $codValues = $codQuery
            ->where('cod', '>', 0)
            ->select(DB::raw('DISTINCT CAST(cod AS DECIMAL(10,0)) as cod_val'))
            ->orderBy('cod_val')
            ->pluck('cod_val')
            ->map(fn ($v) => (int) $v)
            ->values();

        return response()->json([
            'senders'    => $senders,
            'items'      => $items,
            'cod_values' => $codValues,
        ]);
    }

    /**
     * Generate the sales declaration: randomly pick orders until target is reached,
     * with minimum orders per day distribution.
     */
    public function generate(Request $request)
    {
        $request->validate([
            'month'         => 'required|date_format:Y-m',
            'target_amount' => 'required|numeric|min:1',
        ]);

        $month        = $request->input('month');
        $targetAmount = (float) $request->input('target_amount');
        $selectedItems   = $request->input('items', []);
        $selectedSenders = $request->input('senders', []);
        $selectedCods    = $request->input('cod_values', []);
        $status          = $request->input('status', 'Delivered');
        $perDay          = $request->boolean('per_day', true);
        $minPerDay       = max(1, (int) $request->input('min_per_day', 5));
        $maxPerDayInput  = $request->input('max_per_day', 10);
        $maxPerDay       = ($maxPerDayInput !== null && $maxPerDayInput !== '') ? max(1, (int) $maxPerDayInput) : null;

        $startDate = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $endDate   = $startDate->copy()->endOfMonth();

        // Build query
        $query = DB::table('from_jnts')
            ->whereBetween('submission_time', [$startDate, $endDate])
            ->where('status', $status)
            ->where('cod', '>', 0);

        if (!empty($selectedItems)) {
            // Strip price parenthesis from item names
            $rawItems = array_map(function ($item) {
                return preg_replace('/\s*\(₱.*\)$/', '', $item);
            }, $selectedItems);
            $query->whereIn('item_name', $rawItems);
        }

        if (!empty($selectedSenders)) {
            $query->whereIn('sender', $selectedSenders);
        }

        if (!empty($selectedCods)) {
            $codInts = array_map('intval', $selectedCods);
            $query->whereIn(DB::raw('CAST(cod AS DECIMAL(10,0))'), $codInts);
        }

        // Get all qualifying orders
        $allOrders = $query->select([
                'id', 'submission_time', 'signingtime', 'waybill_number', 'receiver',
                'receiver_cellphone', 'sender', 'item_name', 'cod',
                'province', 'city', 'barangay', 'total_shipping_cost', 'remarks', 'status'
            ])
            ->get();

        // Full ADDRESS galing sa macro_output (wala sa from_jnts) — attach per order.
        $waybills = $allOrders->pluck('waybill_number')->filter()->unique()->values()->all();
        $addrByWaybill = [];
        if (!empty($waybills)) {
            $addrByWaybill = DB::table('macro_output')
                ->whereIn('waybill', $waybills)
                ->whereNotNull('ADDRESS')
                ->pluck('ADDRESS', 'waybill')
                ->all();
        }
        foreach ($allOrders as $o) {
            $o->address = $addrByWaybill[$o->waybill_number] ?? '';
        }

        $totalAvailable = $allOrders->count();

        if ($totalAvailable === 0) {
            return response()->json([
                'success'       => true,
                'target_amount' => $targetAmount,
                'actual_total'  => 0,
                'total_orders'  => 0,
                'available'     => 0,
                'per_day'       => [],
                'orders'        => [],
            ]);
        }

        // Group orders by date
        $ordersByDate = [];
        foreach ($allOrders as $order) {
            $date = Carbon::parse($order->submission_time)->format('Y-m-d');
            $ordersByDate[$date][] = $order;
        }

        // Shuffle within each date
        foreach ($ordersByDate as $date => &$orders) {
            shuffle($orders);
        }
        unset($orders);

        ksort($ordersByDate);

        // Phase 1: Pick minimum orders per day from each date (respecting max)
        $selected = [];
        $runningTotal = 0.0;
        $pickedPerDay = []; // track count per day

        foreach ($ordersByDate as $date => &$orders) {
            $picked = 0;
            $remaining = [];
            $limit = $maxPerDay ? min($minPerDay, $maxPerDay) : $minPerDay;
            foreach ($orders as $order) {
                if ($picked < $limit) {
                    $selected[] = $order;
                    $runningTotal += (float) $order->cod;
                    $picked++;
                } else {
                    $remaining[] = $order;
                }
            }
            $pickedPerDay[$date] = $picked;
            $orders = $remaining; // keep unpicked for phase 2
        }
        unset($orders);

        // Phase 2: If still under target, pick more randomly from remaining (respecting max per day)
        if ($runningTotal < $targetAmount) {
            $pool = [];
            foreach ($ordersByDate as $date => $orders) {
                foreach ($orders as $order) {
                    $order->_date = $date; // tag with date for max check
                    $pool[] = $order;
                }
            }
            shuffle($pool);

            foreach ($pool as $order) {
                $date = $order->_date;
                // Check max per day limit
                if ($maxPerDay && ($pickedPerDay[$date] ?? 0) >= $maxPerDay) {
                    continue; // skip, this day is full
                }
                $selected[] = $order;
                $runningTotal += (float) $order->cod;
                $pickedPerDay[$date] = ($pickedPerDay[$date] ?? 0) + 1;
                if ($runningTotal >= $targetAmount) {
                    break;
                }
            }
        }

        // If phase 1 already exceeded target, trim from the end
        if ($runningTotal > $targetAmount && count($selected) > 1) {
            // We keep all — "more or less" the target is fine
        }

        // Build per-day breakdown
        $perDayData = [];
        if ($perDay && !empty($selected)) {
            foreach ($selected as $order) {
                $date = Carbon::parse($order->submission_time)->format('Y-m-d');
                if (!isset($perDayData[$date])) {
                    $perDayData[$date] = [
                        'date'   => $date,
                        'orders' => 0,
                        'total'  => 0.0,
                    ];
                }
                $perDayData[$date]['orders']++;
                $perDayData[$date]['total'] += (float) $order->cod;
            }
            ksort($perDayData);
            $perDayData = array_values($perDayData);
        }

        // Sort selected orders by submission_time for display
        usort($selected, function ($a, $b) {
            return strcmp($a->submission_time, $b->submission_time);
        });

        return response()->json([
            'success'       => true,
            'target_amount' => $targetAmount,
            'actual_total'  => $runningTotal,
            'total_orders'  => count($selected),
            'available'     => $totalAvailable,
            'per_day'       => $perDayData,
            'orders'        => $selected,
        ]);
    }

    /**
     * Export the generated sales declaration as CSV.
     */
    public function export(Request $request)
    {
        $month        = $request->input('month', now()->format('Y-m'));
        $targetAmount = (float) $request->input('target_amount', 0);
        $selectedItems   = $request->input('items', []);
        $selectedSenders = $request->input('senders', []);
        $selectedCods    = $request->input('cod_values', []);
        $status          = $request->input('status', 'Delivered');
        $minPerDay       = max(1, (int) $request->input('min_per_day', 5));
        $maxPerDayInput  = $request->input('max_per_day', 10);
        $maxPerDay       = ($maxPerDayInput !== null && $maxPerDayInput !== '') ? max(1, (int) $maxPerDayInput) : null;
        $seed            = $request->input('seed', null);

        // Parse comma-separated strings
        if (is_string($selectedItems) && $selectedItems !== '') {
            $selectedItems = explode('||', $selectedItems);
        }
        if (is_string($selectedSenders) && $selectedSenders !== '') {
            $selectedSenders = explode('||', $selectedSenders);
        }
        if (is_string($selectedCods) && $selectedCods !== '') {
            $selectedCods = explode(',', $selectedCods);
        }

        $startDate = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $endDate   = $startDate->copy()->endOfMonth();

        $query = DB::table('from_jnts')
            ->whereBetween('submission_time', [$startDate, $endDate])
            ->where('status', $status)
            ->where('cod', '>', 0);

        if (!empty($selectedItems) && $selectedItems !== ['']) {
            $rawItems = array_map(function ($item) {
                return preg_replace('/\s*\(₱.*\)$/', '', $item);
            }, $selectedItems);
            $query->whereIn('item_name', $rawItems);
        }

        if (!empty($selectedSenders) && $selectedSenders !== ['']) {
            $query->whereIn('sender', $selectedSenders);
        }

        if (!empty($selectedCods) && $selectedCods !== ['']) {
            $codInts = array_map('intval', $selectedCods);
            $query->whereIn(DB::raw('CAST(cod AS DECIMAL(10,0))'), $codInts);
        }

        $allOrders = $query->select([
                'id', 'submission_time', 'signingtime', 'waybill_number', 'receiver',
                'receiver_cellphone', 'sender', 'item_name', 'cod',
                'province', 'city', 'barangay', 'total_shipping_cost', 'remarks', 'status'
            ])
            ->get();

        // Full ADDRESS galing sa macro_output (wala ito sa from_jnts) — lookup by waybill.
        $addrByWaybill = [];
        $waybills = $allOrders->pluck('waybill_number')->filter()->unique()->values()->all();
        if (!empty($waybills)) {
            $addrByWaybill = DB::table('macro_output')
                ->whereIn('waybill', $waybills)
                ->whereNotNull('ADDRESS')
                ->pluck('ADDRESS', 'waybill')
                ->all();
        }

        // Use seed for reproducible random
        if ($seed) {
            mt_srand((int) $seed);
        }

        // Group by date
        $ordersByDate = [];
        foreach ($allOrders as $order) {
            $date = Carbon::parse($order->submission_time)->format('Y-m-d');
            $ordersByDate[$date][] = $order;
        }

        // Shuffle within each date (seeded)
        foreach ($ordersByDate as $date => &$orders) {
            usort($orders, function () { return mt_rand(-1, 1); });
        }
        unset($orders);
        ksort($ordersByDate);

        // Phase 1: min per day (respecting max)
        $selected = [];
        $runningTotal = 0.0;
        $pickedPerDay = [];

        foreach ($ordersByDate as $date => &$orders) {
            $picked = 0;
            $remaining = [];
            $limit = $maxPerDay ? min($minPerDay, $maxPerDay) : $minPerDay;
            foreach ($orders as $order) {
                if ($picked < $limit) {
                    $selected[] = $order;
                    $runningTotal += (float) $order->cod;
                    $picked++;
                } else {
                    $remaining[] = $order;
                }
            }
            $pickedPerDay[$date] = $picked;
            $orders = $remaining;
        }
        unset($orders);

        // Phase 2: fill to target (respecting max per day)
        if ($runningTotal < $targetAmount) {
            $pool = [];
            foreach ($ordersByDate as $date => $orders) {
                foreach ($orders as $order) {
                    $order->_date = $date;
                    $pool[] = $order;
                }
            }
            usort($pool, function () { return mt_rand(-1, 1); });

            foreach ($pool as $order) {
                $date = $order->_date;
                if ($maxPerDay && ($pickedPerDay[$date] ?? 0) >= $maxPerDay) {
                    continue;
                }
                $selected[] = $order;
                $runningTotal += (float) $order->cod;
                $pickedPerDay[$date] = ($pickedPerDay[$date] ?? 0) + 1;
                if ($runningTotal >= $targetAmount) {
                    break;
                }
            }
        }

        // Sort by date
        usort($selected, function ($a, $b) {
            return strcmp($a->submission_time, $b->submission_time);
        });

        if (empty($selected)) {
            return back()->with('error', 'No orders found for the selected filters.');
        }

        // Build CSV
        $handle = fopen('php://temp', 'w+');

        fputcsv($handle, ['SALES DECLARATION']);
        fputcsv($handle, ['Month:', $month]);
        fputcsv($handle, ['Target Amount:', number_format($targetAmount, 2)]);
        fputcsv($handle, ['Actual Total:', number_format($runningTotal, 2)]);
        fputcsv($handle, ['Total Orders:', count($selected)]);
        fputcsv($handle, ['Status:', $status]);
        fputcsv($handle, ['Min Orders/Day:', $minPerDay]);
        fputcsv($handle, ['Max Orders/Day:', $maxPerDay ?? 'No limit']);
        fputcsv($handle, ['Generated:', now()->format('Y-m-d H:i:s')]);
        fputcsv($handle, []);

        fputcsv($handle, [
            'Submission Time', 'Signing Time', 'WAYBILL', 'RECEIVER', 'PHONE', 'SENDER',
            'ITEM', 'COD', 'PROVINCE', 'CITY', 'BARANGAY', 'ADDRESS', 'SHIPPING COST', 'REMARKS'
        ]);

        foreach ($selected as $order) {
            fputcsv($handle, [
                Carbon::parse($order->submission_time)->format('Y-m-d'),
                !empty($order->signingtime) ? Carbon::parse($order->signingtime)->format('Y-m-d') : '',
                $order->waybill_number,
                $order->receiver,
                $order->receiver_cellphone,
                $order->sender,
                $order->item_name,
                $order->cod,
                $order->province,
                $order->city,
                $order->barangay,
                $addrByWaybill[$order->waybill_number] ?? '',
                $order->total_shipping_cost,
                $order->remarks,
            ]);
        }

        fputcsv($handle, []);
        fputcsv($handle, ['', '', '', '', '', '', 'TOTAL:', $runningTotal]);

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        $filename = "SalesDeclaration_{$month}_{$targetAmount}_" . now()->format('His') . ".csv";

        return response($content, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename={$filename}",
        ]);
    }
}
