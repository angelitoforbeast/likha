<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessPaymentActivityUpload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PaymentActivityController extends Controller
{
    /** Upload form (multi-file). */
    public function create()
    {
        return view('ads_manager.payment.upload', [
            'heading' => 'Upload Payment Activity',
        ]);
    }

    /** Store uploads (multi-file), queue parsing job per file. */
    public function store(Request $request)
    {
        $request->validate([
            'files'   => ['required', 'array', 'min:1'],
            'files.*' => ['file', 'mimes:csv,txt,xlsx', 'max:51200'], // 50MB
        ]);

        $userName = auth()->user()?->name ?? 'system';
        $batchId  = (string) Str::uuid();

        foreach ($request->file('files') as $file) {
            $original = $file->getClientOriginalName();
            $stamp    = now()->format('Ymd_His');
            $stored   = $file->storeAs(
                'uploads/payment_activity',
                "{$stamp}__{$original}",
                'local'
            );

            ProcessPaymentActivityUpload::dispatch(
                storedPath:   $stored,
                originalName: $original,
                batchId:      $batchId,
                uploadedBy:   $userName
            )->onQueue('default');
        }

        return redirect()
            ->route('ads_payment.records.index')
            ->with('status', 'Files uploaded. Processing in background.');
    }

    /** Paginated records with dropdown filters + ad_account name join. */
    public function records(Request $request)
    {
        $perPage = 50;

        // Default date range: last 1 month including today
        $defaultEnd   = Carbon::today()->toDateString();
        $defaultStart = Carbon::today()->subMonth()->toDateString();

        $start = $request->input('start_date', $defaultStart);
        $end   = $request->input('end_date', $defaultEnd);

        $adAccountSel     = trim((string) $request->input('ad_account', ''));
        $paymentMethodSel = trim((string) $request->input('payment_method', ''));

        // Base query
        $base = DB::table('payment_activity_ads_manager as pa')
            ->leftJoin('ad_accounts as aa', 'aa.ad_account_id', '=', 'pa.ad_account');

        // Filters (date always applied)
        $applyFilters = function ($q) use ($start, $end, $adAccountSel, $paymentMethodSel) {
            $q->whereBetween('pa.date', [$start, $end]);
            if ($adAccountSel !== '') {
                $q->where('pa.ad_account', '=', $adAccountSel);
            }
            if ($paymentMethodSel !== '') {
                $q->where('pa.payment_method', '=', $paymentMethodSel);
            }
        };

        // Rows
        $rowsQuery = (clone $base)->select([
            'pa.id',
            'pa.date',
            'pa.transaction_id',
            'pa.amount',
            'pa.ad_account',
            'pa.payment_method',
            'pa.source_filename',
            'pa.import_batch_id',
            DB::raw('COALESCE(aa.name, pa.ad_account) as ad_account_name'),
        ]);
        $applyFilters($rowsQuery);

        $rows = $rowsQuery
            ->orderBy('pa.date', 'desc') // recent first
            ->paginate($perPage)
            ->withQueryString();

        // Total for same filters
        $totalQuery = (clone $base)->selectRaw('COALESCE(SUM(pa.amount),0) as total_amount');
        $applyFilters($totalQuery);
        $totalAmount = (float) ($totalQuery->value('total_amount') ?? 0);

        /**
         * Dropdown options (Postgres-safe):
         *  - SELECT DISTINCT <alias>, <order by alias>
         *  - Order by aliases that appear in the select list.
         */

        // Ad Account dropdown (limited by date + chosen payment method)
        $adOptionsQuery = DB::table('payment_activity_ads_manager as pa')
            ->leftJoin('ad_accounts as aa', 'aa.ad_account_id', '=', 'pa.ad_account')
            ->whereBetween('pa.date', [$start, $end]);

        if ($paymentMethodSel !== '') {
            $adOptionsQuery->where('pa.payment_method', '=', $paymentMethodSel);
        }

        $adAccountOptions = $adOptionsQuery
            ->whereNotNull('pa.ad_account')
            ->where('pa.ad_account', '!=', '')
            ->selectRaw('DISTINCT pa.ad_account AS id, COALESCE(aa.name, pa.ad_account) AS name')
            ->orderBy('name', 'asc')
            ->get();

        // Payment Method dropdown (limited by date + chosen ad account)
        $pmOptionsQuery = DB::table('payment_activity_ads_manager as pa')
            ->whereBetween('pa.date', [$start, $end]);

        if ($adAccountSel !== '') {
            $pmOptionsQuery->where('pa.ad_account', '=', $adAccountSel);
        }

        $paymentMethodOptions = $pmOptionsQuery
            ->whereNotNull('pa.payment_method')
            ->where('pa.payment_method', '!=', '')
            ->selectRaw('DISTINCT pa.payment_method AS method')
            ->orderBy('method', 'asc')
            ->get();

        return view('ads_manager.payment.records.index', [
            'rows'                 => $rows,
            'start'                => $start,
            'end'                  => $end,
            'totalAmount'          => $totalAmount,
            // dropdowns + current selections (names match your Blade)
            'adAccountOptions'     => $adAccountOptions,     // [{id, name}]
            'paymentMethodOptions' => $paymentMethodOptions, // [{method}]
            'adAccountSel'         => $adAccountSel,
            'paymentMethodSel'     => $paymentMethodSel,
        ]);
    }

    /** Optional: Delete All (add route if you want to use it). */
    public function truncate()
    {
        DB::table('payment_activity_ads_manager')->truncate();

        return redirect()
            ->route('ads_payment.records.index')
            ->with('status', 'All payment activity records deleted.');
    }
}
