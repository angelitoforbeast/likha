<?php

namespace App\Http\Controllers\Encoder;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class Checker1SummaryController extends Controller
{
    public function index(Request $request)
    {
        $tz = 'Asia/Manila';

        // --- Default to LAST 7 DAYS (including today) ---
        // start = today - 6 days @ 00:00, end = today @ 23:59:59
        $start = $request->query('start');
        $end   = $request->query('end');

        if ($start && $end) {
            $startDate = Carbon::parse($start, $tz)->startOfDay();
            $endDate   = Carbon::parse($end,   $tz)->endOfDay();
        } else {
            $today = Carbon::now($tz);
            $startDate = $today->copy()->subDays(6)->startOfDay(); // 7 days window
            $endDate   = $today->copy()->endOfDay();
        }

        // Pull only what we need
        $rows = DB::table('macro_output')
            ->select(['id', 'status_logs'])
            ->whereNotNull('status_logs')
            ->where('status_logs', '!=', '')
            ->get();

        // Build date columns (YYYY-MM-DD) for the selected range
        $dates = [];
        $period = CarbonPeriod::create($startDate->copy()->startOfDay(), '1 day', $endDate->copy()->startOfDay());
        foreach ($period as $d) {
            $dates[] = $d->format('Y-m-d');
        }

        $userDateCounts = []; // [user][Y-m-d] = count
        $allUsers = [];

        foreach ($rows as $r) {
            $latest = self::extractLatestStatusByTimestamp($r->status_logs);
            if (!$latest) continue;

            $ts = Carbon::parse($latest['ts'], $tz);
            if (!$ts->betweenIncluded($startDate, $endDate)) continue;

            $user = $latest['user'];
            $dateKey = $ts->format('Y-m-d');
            $allUsers[$user] = true;

            if (!isset($userDateCounts[$user][$dateKey])) {
                $userDateCounts[$user][$dateKey] = 0;
            }
            $userDateCounts[$user][$dateKey] += 1;
        }

        // Sort users for display
        $users = array_keys($allUsers);
        sort($users, SORT_NATURAL | SORT_FLAG_CASE);

        // Build matrix rows for Blade
        $matrix = [];
        foreach ($users as $u) {
            $row = ['user' => $u, 'counts' => []];
            foreach ($dates as $d) {
                $row['counts'][$d] = $userDateCounts[$u][$d] ?? 0;
            }
            $matrix[] = $row;
        }

        // Pretty labels (e.g., "Sep 1")
        $prettyDates = array_map(function ($d) use ($tz) {
            return Carbon::parse($d, $tz)->format('M j');
        }, $dates);

        return view('encoder.checker_1.summary', [
            'matrix'      => $matrix,
            'dates'       => $dates,
            'prettyDates' => $prettyDates,
            'start'       => $startDate->format('Y-m-d'),
            'end'         => $endDate->format('Y-m-d'),
        ]);
    }

    /**
     * Scan all lines of status_logs; return the record with the greatest timestamp.
     * Matches lines like:
     * [YYYY-MM-DD HH:MM:SS] USER NAME changed STATUS: "OLD" → "NEW"
     *
     * @return array|null ['line'=>string,'ts'=>'YYYY-MM-DD HH:MM:SS','user'=>string]
     */
    private static function extractLatestStatusByTimestamp(?string $logs): ?array
    {
        if (!$logs) return null;

        $logs = str_replace("\r\n", "\n", $logs);
        $lines = array_filter(array_map('trim', explode("\n", $logs)), fn($l) => $l !== '');
        if (empty($lines)) return null;

        $pattern = '/^\[(?<ts>\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\]\s+(?<user>.*?)\s+changed\s+STATUS\b/i';

        $latest = null;
        foreach ($lines as $line) {
            if (preg_match($pattern, $line, $m)) {
                $recordTs = $m['ts'];
                if (!$latest || $recordTs > $latest['ts']) {
                    $latest = [
                        'line' => $line,
                        'ts'   => $recordTs,
                        'user' => trim($m['user']),
                    ];
                }
            }
        }
        return $latest;
    }
}
