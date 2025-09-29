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

        // Driver-aware identifier quoting for column with space: HISTORICAL LOGS
        $driver = DB::connection()->getDriverName(); // 'mysql' | 'pgsql' | ...
        $histIdent = $driver === 'pgsql' ? '"HISTORICAL LOGS"' : '`HISTORICAL LOGS`';

        // --- Default to LAST 7 DAYS (including today) ---
        $start = $request->query('start');
        $end   = $request->query('end');

        if ($start && $end) {
            $startDate = Carbon::parse($start, $tz)->startOfDay();
            $endDate   = Carbon::parse($end,   $tz)->endOfDay();
        } else {
            $today = Carbon::now($tz);
            $startDate = $today->copy()->subDays(6)->startOfDay(); // 7-day window
            $endDate   = $today->copy()->endOfDay();
        }

        // Pull needed columns (alias HISTORICAL LOGS -> historical_logs)
        $rows = DB::table('macro_output')
            ->select([
                'id',
                'status_logs',
            ])
            ->selectRaw($histIdent.' as historical_logs')
            ->where(function($q){
                // safely check non-empty status_logs
                $q->whereNotNull('status_logs')
                  ->whereRaw("COALESCE(status_logs, '') <> ''");
            })
            ->orWhere(function($q) use ($histIdent){
                // safely check non-empty HISTORICAL LOGS (with space)
                $q->whereRaw($histIdent.' is not null')
                  ->whereRaw('COALESCE('.$histIdent.", '') <> ''");
            })
            ->get();

        // ---------- Build date columns (YYYY-MM-DD) ----------
        $dates = [];
        $period = CarbonPeriod::create($startDate->copy()->startOfDay(), '1 day', $endDate->copy()->startOfDay());
        foreach ($period as $d) {
            $dates[] = $d->format('Y-m-d');
        }

        // ---------- A) STATUS last-editor matrix ----------
        $statusUserDateCounts = []; // [user][Y-m-d] = count (rows)
        $allUsersStatus = [];

        foreach ($rows as $r) {
            if (empty($r->status_logs)) continue;

            $latest = self::extractLatestStatusByTimestamp($r->status_logs);
            if (!$latest) continue;

            $ts = Carbon::parse($latest['ts'], $tz);
            if (!$ts->betweenIncluded($startDate, $endDate)) continue;

            $user = $latest['user'];
            $dateKey = $ts->format('Y-m-d');
            $allUsersStatus[$user] = true;

            if (!isset($statusUserDateCounts[$user][$dateKey])) {
                $statusUserDateCounts[$user][$dateKey] = 0;
            }
            $statusUserDateCounts[$user][$dateKey] += 1;
        }

        $usersStatus = array_keys($allUsersStatus);
        sort($usersStatus, SORT_NATURAL | SORT_FLAG_CASE);

        $statusMatrix = [];
        foreach ($usersStatus as $u) {
            $row = ['user' => $u, 'counts' => []];
            foreach ($dates as $d) {
                $row['counts'][$d] = $statusUserDateCounts[$u][$d] ?? 0;
            }
            $statusMatrix[] = $row;
        }

        // ---------- B) HISTORICAL LOGS: matrix (distinct rows edited per user × date) ----------
        $histUserDateCounts = []; // [user][Y-m-d] = count (distinct rows)
        $allUsersHist = [];

        foreach ($rows as $r) {
            if (empty($r->historical_logs)) continue;

            // Per row, get set of user=>dates they edited (within range)
            $userDates = self::extractHistoricalUserDatesInRange($r->historical_logs, $startDate, $endDate, $tz);
            if (empty($userDates)) continue;

            foreach ($userDates as $user => $dateSet) {
                $allUsersHist[$user] = true;
                foreach (array_keys($dateSet) as $dateKey) {
                    if (!isset($histUserDateCounts[$user][$dateKey])) {
                        $histUserDateCounts[$user][$dateKey] = 0;
                    }
                    $histUserDateCounts[$user][$dateKey] += 1; // 1 per row per (user,date)
                }
            }
        }

        $usersHist = array_keys($allUsersHist);
        sort($usersHist, SORT_NATURAL | SORT_FLAG_CASE);

        $historicalMatrix = [];
        foreach ($usersHist as $u) {
            $row = ['user' => $u, 'counts' => []];
            foreach ($dates as $d) {
                $row['counts'][$d] = $histUserDateCounts[$u][$d] ?? 0;
            }
            $historicalMatrix[] = $row;
        }

        // ---------- Pretty labels ----------
        $prettyDates = array_map(function ($d) use ($tz) {
            return Carbon::parse($d, $tz)->format('M j');
        }, $dates);

        return view('encoder.checker_1.summary', [
            // A) STATUS
            'matrix'      => $statusMatrix,
            // B) HISTORICAL
            'histMatrix'  => $historicalMatrix,

            'dates'       => $dates,
            'prettyDates' => $prettyDates,
            'start'       => $startDate->format('Y-m-d'),
            'end'         => $endDate->format('Y-m-d'),
        ]);
    }

    /**
     * STATUS logs: pick line with the greatest timestamp.
     * Matches: [YYYY-MM-DD HH:MM:SS] USER NAME changed STATUS: "OLD" → "NEW"
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

    /**
     * HISTORICAL logs: for a single row, return:
     * [ user => [ 'YYYY-MM-DD' => true, ... ], ... ]
     * Accepts verbs "updated" or "changed" (case-insensitive).
     */
    private static function extractHistoricalUserDatesInRange(?string $logs, Carbon $startDate, Carbon $endDate, string $tz = 'Asia/Manila'): array
    {
        if (!$logs) return [];

        $logs = str_replace("\r\n", "\n", $logs);
        $lines = array_filter(array_map('trim', explode("\n", $logs)), fn($l) => $l !== '');
        if (empty($lines)) return [];

        // Allow A-Z field names incl. spaces/underscores/numbers
        $pattern = '/^\[(?<ts>\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\]\s+(?<user>.*?)\s+(?:updated|changed)\s+[A-Z0-9_ ]+/i';

        $result = []; // [user][date]=true
        foreach ($lines as $line) {
            if (preg_match($pattern, $line, $m)) {
                $ts = Carbon::parse($m['ts'], $tz);
                if ($ts->betweenIncluded($startDate, $endDate)) {
                    $user = trim($m['user']);
                    $dateKey = $ts->format('Y-m-d');
                    if (!isset($result[$user])) $result[$user] = [];
                    $result[$user][$dateKey] = true;
                }
            }
        }
        return $result;
    }
}
