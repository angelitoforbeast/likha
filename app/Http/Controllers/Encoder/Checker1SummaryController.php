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
        $likeOp    = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';

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

        // ---------- Build date columns (YYYY-MM-DD) ----------
        $dates = [];
        $period = CarbonPeriod::create($startDate->copy()->startOfDay(), '1 day', $endDate->copy()->startOfDay());
        foreach ($period as $d) $dates[] = $d->format('Y-m-d');

        // For filtering rows: logs contain timestamps starting with "YYYY-MM-DD ..."
        // We will filter candidates by LIKE '%YYYY-MM-DD%' for each date in range.
        $dateLikePatterns = array_map(fn($d) => "%{$d}%", $dates);

        // ---------- Counters ----------
        $statusUserDateCounts = []; // [user][Y-m-d] = count
        $allUsersStatus = [];

        $histUserDateCounts = [];   // [user][Y-m-d] = count (distinct rows per user-date)
        $allUsersHist = [];

        // ---------- Query candidates + chunk for speed ----------
        $base = DB::table('macro_output')
            ->select(['id', 'status_logs'])
            ->selectRaw($histIdent . ' as historical_logs')
            ->where(function ($q) use ($histIdent, $likeOp, $dateLikePatterns) {
                // status_logs contains any date in range
                $q->where(function ($s) use ($likeOp, $dateLikePatterns) {
                    $s->whereNotNull('status_logs')
                      ->whereRaw("COALESCE(status_logs, '') <> ''")
                      ->where(function ($w) use ($likeOp, $dateLikePatterns) {
                          foreach ($dateLikePatterns as $pat) {
                              $w->orWhere('status_logs', $likeOp, $pat);
                          }
                      });
                })

                // OR historical_logs contains any date in range
                ->orWhere(function ($h) use ($histIdent, $likeOp, $dateLikePatterns) {
                    $h->whereRaw($histIdent . " IS NOT NULL")
                      ->whereRaw("COALESCE($histIdent, '') <> ''")
                      ->where(function ($w) use ($histIdent, $likeOp, $dateLikePatterns) {
                          foreach ($dateLikePatterns as $pat) {
                              $w->orWhereRaw("$histIdent $likeOp ?", [$pat]);
                          }
                      });
                });
            })
            ->orderBy('id');

        // chunkById to avoid loading all rows into memory
        $base->chunkById(2000, function ($rows) use (
            $tz, $startDate, $endDate,
            &$statusUserDateCounts, &$allUsersStatus,
            &$histUserDateCounts, &$allUsersHist
        ) {
            foreach ($rows as $r) {

                // A) STATUS last-editor (per row)
                if (!empty($r->status_logs)) {
                    $latest = self::extractLatestStatusByTimestampFlexible($r->status_logs);
                    if ($latest && !empty($latest['ts']) && !empty($latest['user'])) {
                        $ts = self::safeCarbon($latest['ts'], $tz);
                        if ($ts && $ts->betweenIncluded($startDate, $endDate)) {
                            $user = $latest['user'];
                            $dateKey = $ts->format('Y-m-d');
                            $allUsersStatus[$user] = true;
                            $statusUserDateCounts[$user][$dateKey] = ($statusUserDateCounts[$user][$dateKey] ?? 0) + 1;
                        }
                    }
                }

                // B) HISTORICAL (distinct rows per user-date)
                if (!empty($r->historical_logs)) {
                    $userDates = self::extractHistoricalUserDatesInRangeFlexible($r->historical_logs, $startDate, $endDate, $tz);
                    if (!empty($userDates)) {
                        foreach ($userDates as $user => $dateSet) {
                            $allUsersHist[$user] = true;
                            foreach (array_keys($dateSet) as $dateKey) {
                                $histUserDateCounts[$user][$dateKey] = ($histUserDateCounts[$user][$dateKey] ?? 0) + 1;
                            }
                        }
                    }
                }
            }
        }, 'id', 'id');

        // ---------- Build matrices ----------
        $usersStatus = array_keys($allUsersStatus);
        sort($usersStatus, SORT_NATURAL | SORT_FLAG_CASE);

        $statusMatrix = [];
        foreach ($usersStatus as $u) {
            $row = ['user' => $u, 'counts' => []];
            foreach ($dates as $d) $row['counts'][$d] = $statusUserDateCounts[$u][$d] ?? 0;
            $statusMatrix[] = $row;
        }

        $usersHist = array_keys($allUsersHist);
        sort($usersHist, SORT_NATURAL | SORT_FLAG_CASE);

        $historicalMatrix = [];
        foreach ($usersHist as $u) {
            $row = ['user' => $u, 'counts' => []];
            foreach ($dates as $d) $row['counts'][$d] = $histUserDateCounts[$u][$d] ?? 0;
            $historicalMatrix[] = $row;
        }

        // ---------- Pretty labels ----------
        $prettyDates = array_map(fn($d) => Carbon::parse($d, $tz)->format('M j'), $dates);

        return view('encoder.checker_1.summary', [
            'matrix'      => $statusMatrix,
            'histMatrix'  => $historicalMatrix,
            'dates'       => $dates,
            'prettyDates' => $prettyDates,
            'start'       => $startDate->format('Y-m-d'),
            'end'         => $endDate->format('Y-m-d'),
        ]);
    }

    private static function safeCarbon(string $ts, string $tz): ?Carbon
    {
        try {
            return Carbon::parse($ts, $tz);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * STATUS logs: pick line with the greatest timestamp.
     * Supports BOTH:
     * 1) New pipe format:  YYYY-MM-DD HH:MM:SS|user|VALUE
     * 2) Legacy bracket:   [YYYY-MM-DD HH:MM:SS] user changed STATUS: ...
     */
    private static function extractLatestStatusByTimestampFlexible(?string $logs): ?array
    {
        if (!$logs) return null;

        $logs = str_replace("\r\n", "\n", $logs);
        $lines = array_filter(array_map('trim', explode("\n", $logs)), fn($l) => $l !== '');
        if (empty($lines)) return null;

        $pipe = '/^(?<ts>\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\|(?<user>[^|]+)\|/u';
        $legacy = '/^\[(?<ts>\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\]\s+(?<user>.*?)\s+changed\s+STATUS\b/i';

        $latest = null;

        foreach ($lines as $line) {
            $ts = null; $user = null;

            if (preg_match($pipe, $line, $m)) {
                $ts = trim($m['ts'] ?? '');
                $user = trim($m['user'] ?? '');
            } elseif (preg_match($legacy, $line, $m)) {
                $ts = trim($m['ts'] ?? '');
                $user = trim($m['user'] ?? '');
            } else {
                continue;
            }

            if ($ts === '' || $user === '') continue;

            // string compare works for Y-m-d H:i:s
            if (!$latest || $ts > $latest['ts']) {
                $latest = ['ts' => $ts, 'user' => $user];
            }
        }

        return $latest;
    }

    /**
     * HISTORICAL logs: return [ user => [ 'YYYY-MM-DD' => true, ... ], ... ]
     * Supports BOTH:
     * 1) New pipe format:  ts|user|FIELD|OLD|NEW
     * 2) Legacy bracket:   [ts] user updated FIELD: "old" → "new"
     */
    private static function extractHistoricalUserDatesInRangeFlexible(?string $logs, Carbon $startDate, Carbon $endDate, string $tz): array
    {
        if (!$logs) return [];

        $logs = str_replace("\r\n", "\n", $logs);
        $lines = array_filter(array_map('trim', explode("\n", $logs)), fn($l) => $l !== '');
        if (empty($lines)) return [];

        $pipe = '/^(?<ts>\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\|(?<user>[^|]+)\|(?<field>[^|]+)\|/u';
        $legacy = '/^\[(?<ts>\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\]\s+(?<user>.*?)\s+(?:updated|changed)\s+/i';

        $result = [];

        foreach ($lines as $line) {
            $tsStr = null; $user = null;

            if (preg_match($pipe, $line, $m)) {
                $tsStr = trim($m['ts'] ?? '');
                $user  = trim($m['user'] ?? '');
            } elseif (preg_match($legacy, $line, $m)) {
                $tsStr = trim($m['ts'] ?? '');
                $user  = trim($m['user'] ?? '');
            } else {
                continue;
            }

            if ($tsStr === '' || $user === '') continue;

            $ts = self::safeCarbon($tsStr, $tz);
            if (!$ts) continue;

            if ($ts->betweenIncluded($startDate, $endDate)) {
                $dateKey = $ts->format('Y-m-d');
                $result[$user] ??= [];
                $result[$user][$dateKey] = true;
            }
        }

        return $result;
    }
}
