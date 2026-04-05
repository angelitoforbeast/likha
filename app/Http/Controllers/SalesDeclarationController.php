<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SalesDeclarationController extends Controller
{
    /**
     * Show the sales declaration form + results.
     */
    public function index(Request $request)
    {
        $month = $request->input('month', now()->format('Y-m'));

        // Parse month boundaries
        $startDate = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $endDate   = $startDate->copy()->endOfMonth();

        // Get available senders for this month
        $senders = DB::table('from_jnts')
            ->whereBetween('submission_time', [$startDate, $endDate])
            ->whereNotNull('sender')
            ->where('sender', '!=', '')
            ->distinct()
            ->orderBy('sender')
            ->pluck('sender');

        // Get available items for this month
        $items = DB::table('from_jnts')
            ->whereBetween('submission_time', [$startDate, $endDate])
            ->whereNotNull('item_name')
            ->where('item_name', '!=', '')
            ->distinct()
            ->orderBy('item_name')
            ->pluck('item_name');

        // Get available statuses
        $statuses = DB::table('from_jnts')
            ->whereBetween('submission_time', [$startDate, $endDate])
            ->whereNotNull('status')
            ->distinct()
            ->orderBy('status')
            ->pluck('status');

        // Summary for the month (all delivered)
        $monthTotal = DB::table('from_jnts')
            ->whereBetween('submission_time', [$startDate, $endDate])
            ->where('status', 'Delivered')
            ->sum('cod');

        $monthCount = DB::table('from_jnts')
            ->whereBetween('submission_time', [$startDate, $endDate])
            ->where('status', 'Delivered')
            ->count();

        return view('jnt.sales-declaration', compact(
            'month', 'senders', 'items', 'statuses',
            'monthTotal', 'monthCount'
        ));
    }

    /**
     * Generate the sales declaration: randomly pick orders until target is reached.
     * Returns JSON for AJAX call.
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
        $status          = $request->input('status', 'Delivered');
        $perDay          = $request->boolean('per_day', true);

        $startDate = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $endDate   = $startDate->copy()->endOfMonth();

        // Build query
        $query = DB::table('from_jnts')
            ->whereBetween('submission_time', [$startDate, $endDate])
            ->where('status', $status);

        if (!empty($selectedItems)) {
            $query->whereIn('item_name', $selectedItems);
        }

        if (!empty($selectedSenders)) {
            $query->whereIn('sender', $selectedSenders);
        }

        // Get all qualifying orders
        $allOrders = $query->select([
                'id', 'submission_time', 'waybill_number', 'receiver',
                'receiver_cellphone', 'sender', 'item_name', 'cod',
                'province', 'city', 'barangay', 'total_shipping_cost', 'status'
            ])
            ->get()
            ->toArray();

        // Shuffle randomly
        shuffle($allOrders);

        // Pick orders until target is reached
        $selected = [];
        $runningTotal = 0.0;

        foreach ($allOrders as $order) {
            $cod = (float) $order->cod;
            if ($cod <= 0) continue;

            $selected[] = $order;
            $runningTotal += $cod;

            if ($runningTotal >= $targetAmount) {
                break;
            }
        }

        // Build per-day breakdown if needed
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
            'available'     => count($allOrders),
            'per_day'       => $perDayData,
            'orders'        => $selected,
        ]);
    }

    /**
     * Export the generated sales declaration as CSV/Excel.
     */
    public function export(Request $request)
    {
        $month        = $request->input('month', now()->format('Y-m'));
        $targetAmount = (float) $request->input('target_amount', 0);
        $selectedItems   = $request->input('items', []);
        $selectedSenders = $request->input('senders', []);
        $status          = $request->input('status', 'Delivered');
        $seed            = $request->input('seed', null);

        // If items/senders come as comma-separated strings (from hidden form), parse them
        if (is_string($selectedItems) && $selectedItems !== '') {
            $selectedItems = explode(',', $selectedItems);
        }
        if (is_string($selectedSenders) && $selectedSenders !== '') {
            $selectedSenders = explode(',', $selectedSenders);
        }

        $startDate = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $endDate   = $startDate->copy()->endOfMonth();

        $query = DB::table('from_jnts')
            ->whereBetween('submission_time', [$startDate, $endDate])
            ->where('status', $status);

        if (!empty($selectedItems) && $selectedItems !== ['']) {
            $query->whereIn('item_name', $selectedItems);
        }

        if (!empty($selectedSenders) && $selectedSenders !== ['']) {
            $query->whereIn('sender', $selectedSenders);
        }

        $allOrders = $query->select([
                'id', 'submission_time', 'waybill_number', 'receiver',
                'receiver_cellphone', 'sender', 'item_name', 'cod',
                'province', 'city', 'barangay', 'total_shipping_cost', 'status'
            ])
            ->get()
            ->toArray();

        // Use seed for reproducible random if provided, otherwise random
        if ($seed) {
            mt_srand((int) $seed);
            usort($allOrders, function () { return mt_rand(-1, 1); });
        } else {
            shuffle($allOrders);
        }

        // Pick orders until target
        $selected = [];
        $runningTotal = 0.0;

        foreach ($allOrders as $order) {
            $cod = (float) $order->cod;
            if ($cod <= 0) continue;

            $selected[] = $order;
            $runningTotal += $cod;

            if ($runningTotal >= $targetAmount) {
                break;
            }
        }

        // Sort by date for export
        usort($selected, function ($a, $b) {
            return strcmp($a->submission_time, $b->submission_time);
        });

        if (empty($selected)) {
            return back()->with('error', 'No orders found for the selected filters.');
        }

        // Build CSV
        $handle = fopen('php://temp', 'w+');

        // Header info rows
        fputcsv($handle, ['SALES DECLARATION']);
        fputcsv($handle, ['Month:', $month]);
        fputcsv($handle, ['Target Amount:', number_format($targetAmount, 2)]);
        fputcsv($handle, ['Actual Total:', number_format($runningTotal, 2)]);
        fputcsv($handle, ['Total Orders:', count($selected)]);
        fputcsv($handle, ['Status:', $status]);
        fputcsv($handle, ['Generated:', now()->format('Y-m-d H:i:s')]);
        fputcsv($handle, []); // blank row

        // Column headers
        fputcsv($handle, [
            'DATE', 'WAYBILL', 'RECEIVER', 'PHONE', 'SENDER',
            'ITEM', 'COD', 'PROVINCE', 'CITY', 'BARANGAY', 'SHIPPING COST'
        ]);

        foreach ($selected as $order) {
            fputcsv($handle, [
                Carbon::parse($order->submission_time)->format('Y-m-d'),
                $order->waybill_number,
                $order->receiver,
                $order->receiver_cellphone,
                $order->sender,
                $order->item_name,
                $order->cod,
                $order->province,
                $order->city,
                $order->barangay,
                $order->total_shipping_cost,
            ]);
        }

        // Summary footer
        fputcsv($handle, []);
        fputcsv($handle, ['', '', '', '', '', 'TOTAL:', $runningTotal]);

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
