<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class JntStatusController extends Controller
{
    public function index(Request $request)
    {
        // ===== Status filter =====
        $status = $request->query('status', 'All');

        $allowed = [
            'All',
            'Delivered',
            'For Return',
            'Returned',
            'In Transit',
            'Delivering',
            'In Transit + Delivering',
        ];

        if (!in_array($status, $allowed, true)) {
            $status = 'All';
        }

        $driver = DB::getDriverName();

        // ===== Default date range: last month start -> this month end =====
        $defaultStart = Carbon::now('Asia/Manila')->startOfMonth()->subMonth()->startOfDay();
        $defaultEnd   = Carbon::now('Asia/Manila')->endOfMonth()->endOfDay();

        $dateRangeRaw = trim((string) $request->query('date_range', ''));
        [$startAt, $endAt] = $this->parseDateRange($dateRangeRaw, $defaultStart, $defaultEnd);

        // ===== Base query (from_jnts) =====
        $q = DB::table('from_jnts as j')
            ->select([
                'j.submission_time',
                'j.waybill_number',
                'j.receiver',
                'j.sender',
                'j.cod',
                'j.status',
                'j.signingtime',
                'j.item_name',
                'j.rts_reason', // ✅ added (for logic + optional display/copy)
                'mo.botcake_psid as botcake_psid',
                'psm.page as page',
            ]);

        // ✅ macro_output: safe 1 row per waybill
        $q->leftJoin(DB::raw("
            (SELECT waybill, MAX(botcake_psid) AS botcake_psid
             FROM macro_output
             GROUP BY waybill) mo
        "), 'mo.waybill', '=', 'j.waybill_number');

        // ✅ page_sender_mappings: safe 1 row per sender_name
        if ($driver === 'mysql') {
            $q->leftJoin(DB::raw("
                (SELECT sender_name, MAX(page) AS page
                 FROM page_sender_mappings
                 GROUP BY sender_name) psm
            "), function ($join) {
                $join->on(DB::raw('BINARY psm.sender_name'), '=', DB::raw('BINARY j.sender'));
            });
        } else {
            $q->leftJoin(DB::raw("
                (SELECT sender_name, MAX(page) AS page
                 FROM page_sender_mappings
                 GROUP BY sender_name) psm
            "), function ($join) {
                $join->on('psm.sender_name', '=', 'j.sender');
            });
        }

        // ===== Date filter =====
        $q->whereBetween('j.submission_time', [$startAt->toDateTimeString(), $endAt->toDateTimeString()]);

        // ===== Status filter + Delivering RTS exclusion =====
        if ($status !== 'All') {
            if ($status === 'In Transit + Delivering') {
                $q->where(function ($w) {
                    $w->where('j.status', 'In Transit')
                      ->orWhere(function ($d) {
                          $d->where('j.status', 'Delivering')
                            ->where(function ($rr) {
                                // exclude Delivering rows with rts_reason not empty
                                $rr->whereNull('j.rts_reason')
                                   ->orWhere(DB::raw("TRIM(COALESCE(j.rts_reason,''))"), '=', '');
                            });
                      });
                });
            } elseif ($status === 'Delivering') {
                $q->where('j.status', 'Delivering')
                  ->where(function ($rr) {
                      $rr->whereNull('j.rts_reason')
                         ->orWhere(DB::raw("TRIM(COALESCE(j.rts_reason,''))"), '=', '');
                  });
            } else {
                $q->where('j.status', '=', $status);
            }
        }

        // ===== Sort =====
        $q->orderByDesc('j.submission_time');

        // ===== COPY endpoint (returns TSV for ALL filtered rows) =====
        if ((string)$request->query('copy') === '1') {
            $all = $q->get();
            $tsv = $this->toTsv($all);

            return response($tsv, 200, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        // ===== Fetch for UI =====
        $isPaginated = ($status === 'All');
        if ($isPaginated) {
            $rows = $q->paginate(50)->appends([
                'status' => $status,
                'date_range' => $request->query('date_range'),
            ]);

            $totalCount  = $rows->total();
            $currentPage = $rows->currentPage();
            $lastPage    = $rows->lastPage();
            $prevUrl     = $rows->previousPageUrl();
            $nextUrl     = $rows->nextPageUrl();
        } else {
            // show ALL rows when not All
            $rows = $q->get();

            $totalCount  = $rows->count();
            $currentPage = 1;
            $lastPage    = 1;
            $prevUrl     = null;
            $nextUrl     = null;
        }

        return view('jnt.status', [
            'rows' => $rows,
            'status' => $status,
            'dateRangeRaw' => $dateRangeRaw ?: ($defaultStart->toDateString().' to '.$defaultEnd->toDateString()),
            'startAt' => $startAt,
            'endAt' => $endAt,
            'isPaginated' => $isPaginated,
            'totalCount' => $totalCount,
            'currentPage' => $currentPage,
            'lastPage' => $lastPage,
            'prevUrl' => $prevUrl,
            'nextUrl' => $nextUrl,
        ]);
    }

    private function parseDateRange(string $raw, Carbon $fallbackStart, Carbon $fallbackEnd): array
    {
        if ($raw === '') return [$fallbackStart, $fallbackEnd];

        $parts = preg_split('/\s+to\s+/i', $raw);
        if (!$parts || count($parts) !== 2) return [$fallbackStart, $fallbackEnd];

        try {
            $start = Carbon::parse(trim($parts[0]), 'Asia/Manila')->startOfDay();
            $end   = Carbon::parse(trim($parts[1]), 'Asia/Manila')->endOfDay();

            if ($end->lessThan($start)) {
                [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
            }

            return [$start, $end];
        } catch (\Throwable $e) {
            return [$fallbackStart, $fallbackEnd];
        }
    }

    private function toTsv($rows): string
    {
        $headers = [
            'submission_time',
            'waybill_number',
            'receiver',
            'sender',
            'page',
            'cod',
            'status',
            'signingtime',
            'item_name',
            'botcake_psid',
            'rts_reason', // ✅ included in copy (optional but useful)
        ];

        $lines = [];
        $lines[] = implode("\t", $headers);

        foreach ($rows as $r) {
            $vals = [
                $r->submission_time ?? '',
                $r->waybill_number ?? '',
                $r->receiver ?? '',
                $r->sender ?? '',
                $r->page ?? '',
                $r->cod ?? '',
                $r->status ?? '',
                $r->signingtime ?? '',
                $r->item_name ?? '',
                $r->botcake_psid ?? '',
                $r->rts_reason ?? '',
            ];

            $vals = array_map(function ($v) {
                $v = (string)$v;
                return str_replace(["\t", "\r", "\n"], [' ', ' ', ' '], $v);
            }, $vals);

            $lines[] = implode("\t", $vals);
        }

        return implode("\n", $lines);
    }
}
