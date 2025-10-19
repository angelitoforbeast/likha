<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessPaymentActivityUpload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    /** Paginated records w/ dropdown filters and join to ad_accounts for name label. */
    public function records(Request $request)
{
    $perPage = 50;

    // Default date range: last 1 month including today
    $defaultEnd   = \Carbon\Carbon::today()->toDateString();
    $defaultStart = \Carbon\Carbon::today()->subMonth()->toDateString();

    $start = $request->input('start_date', $defaultStart);
    $end   = $request->input('end_date', $defaultEnd);

    $adAccountSel      = trim((string) $request->input('ad_account', ''));
    $paymentMethodSel  = trim((string) $request->input('payment_method', ''));

    // -------- Base (for rows & total) --------
    $base = DB::table('payment_activity_ads_manager as pa')
        ->leftJoin('ad_accounts as aa', 'aa.ad_account_id', '=', 'pa.ad_account');

    $applyFilters = function ($q) use ($start, $end, $adAccountSel, $paymentMethodSel) {
        $q->whereBetween('pa.date', [$start, $end]);

        if ($adAccountSel !== '') {
            $q->where('pa.ad_account', '=', $adAccountSel);
        }
        if ($paymentMethodSel !== '') {
            $q->where('pa.payment_method', '=', $paymentMethodSel);
        }
    };

    // -------- Rows (paginated) --------
    $rowsQuery = (clone $base)->selectRaw("
        pa.id,
        pa.date,
        pa.transaction_id,
        pa.amount,
        pa.ad_account,
        pa.payment_method,
        pa.source_filename,
        pa.import_batch_id,
        COALESCE(aa.name, pa.ad_account) as ad_account_name
    ");
    $applyFilters($rowsQuery);

    $rows = $rowsQuery
        ->orderBy('pa.date', 'desc') // recent first by default
        ->paginate($perPage)
        ->withQueryString();

    // -------- Total amount (same filters) --------
    $totalQuery = (clone $base)->selectRaw('COALESCE(SUM(pa.amount),0) as total_amount');
    $applyFilters($totalQuery);
    $totalAmount = (float) ($totalQuery->value('total_amount') ?? 0);

    // ========= Dropdown options (dependent) =========
    // Ad Account options:
    // - limited by date range
    // - limited by chosen payment method (if any)
    // - include IDs that exist in pa but have NO name in ad_accounts
    $adOptionsQuery = DB::table('payment_activity_ads_manager as pa')
        ->leftJoin('ad_accounts as aa', 'aa.ad_account_id', '=', 'pa.ad_account')
        ->whereBetween('pa.date', [$start, $end]);

    if ($paymentMethodSel !== '') {
        $adOptionsQuery->where('pa.payment_method', '=', $paymentMethodSel);
    }

    $adAccountOptions = $adOptionsQuery
        ->whereNotNull('pa.ad_account')
        ->where('pa.ad_account', '!=', '')
        ->selectRaw('pa.ad_account as id, aa.name as name')
        ->distinct()
        ->orderByRaw('COALESCE(aa.name, pa.ad_account) asc')
        ->get();

    // Payment Method options:
    // - limited by date range
    // - limited by chosen ad account (if any)
    $pmOptionsQuery = DB::table('payment_activity_ads_manager as pa')
        ->whereBetween('pa.date', [$start, $end]);

    if ($adAccountSel !== '') {
        $pmOptionsQuery->where('pa.ad_account', '=', $adAccountSel);
    }

    $paymentMethodOptions = $pmOptionsQuery
        ->whereNotNull('pa.payment_method')
        ->where('pa.payment_method', '!=', '')
        ->selectRaw('pa.payment_method as method')
        ->distinct()
        ->orderBy('pa.payment_method')
        ->get();

    return view('ads_manager.payment.records.index', [
        'rows'                 => $rows,
        'start'                => $start,
        'end'                  => $end,
        'totalAmount'          => $totalAmount,
        // dropdown data + current selections
        'adAccountOptions'     => $adAccountOptions,
        'paymentMethodOptions' => $paymentMethodOptions,
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
