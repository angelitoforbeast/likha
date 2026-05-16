<?php

namespace App\Http\Controllers\Encoder;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

/**
 * Idle / activity timeline for encoders.
 *
 * Parses macro_output.status_logs for the date range, groups timestamps per
 * (user, calendar-date PH), and sends them to the view. The view classifies
 * inter-edit gaps into active/idle/long-break/away buckets using client-side
 * thresholds (so the user can adjust sliders without re-fetching).
 *
 * Why status_logs only (not historical_logs): user choice — STATUS changes are
 * the most reliable activity signal per encoder; historical_logs add noise from
 * field edits that may not represent independent "work units".
 */
class Checker1IdleSummaryController extends Controller
{
    public function index(Request $request)
    {
        $tz = 'Asia/Manila';

        // Default range: last 7 days through today (PH calendar).
        $start = $request->query('start');
        $end   = $request->query('end');
        if ($start && $end) {
            $startDate = Carbon::parse($start, $tz)->startOfDay();
            $endDate   = Carbon::parse($end,   $tz)->endOfDay();
        } else {
            $today = Carbon::now($tz);
            $startDate = $today->copy()->subDays(6)->startOfDay();
            $endDate   = $today->copy()->endOfDay();
        }
        if ($startDate->gt($endDate)) {
            [$startDate, $endDate] = [$endDate->copy()->startOfDay(), $startDate->copy()->endOfDay()];
        }

        // Date column spine (sorted ASC for matrix display).
        $dates = [];
        $period = CarbonPeriod::create($startDate->copy()->startOfDay(), '1 day', $endDate->copy()->startOfDay());
        foreach ($period as $d) $dates[] = $d->format('Y-m-d');

        // Pre-filter: only rows whose status_logs likely contain a date in range.
        // LIKE filter avoids parsing every row in the table; still parse to be safe.
        $dateLikePatterns = array_map(fn($d) => "%{$d}%", $dates);

        $base = DB::table('macro_output')
            ->select(['id', 'status_logs'])
            ->whereNotNull('status_logs')
            ->whereRaw("COALESCE(status_logs, '') <> ''")
            ->where(function ($q) use ($dateLikePatterns) {
                foreach ($dateLikePatterns as $pat) {
                    $q->orWhere('status_logs', 'LIKE', $pat);
                }
            })
            ->orderBy('id');

        // [user => [Y-m-d => [unix_ts, ...]]] — accumulated across chunks.
        $byUserDate = [];

        $base->chunkById(2000, function ($rows) use ($tz, $startDate, $endDate, &$byUserDate) {
            foreach ($rows as $r) {
                $entries = self::parseStatusLogs((string)$r->status_logs);
                foreach ($entries as $e) {
                    $ts = self::safeCarbon($e['ts'], $tz);
                    if (!$ts) continue;
                    if (!$ts->betweenIncluded($startDate, $endDate)) continue;
                    $user = $e['user'];
                    $dateKey = $ts->format('Y-m-d');
                    $byUserDate[$user][$dateKey][] = $ts->getTimestamp();
                }
            }
        }, 'id', 'id');

        // Sort timestamps per (user, date) ASC for deterministic gap calculation.
        // Also dedupe identical timestamps (rare — same second double-clicks).
        foreach ($byUserDate as $user => &$days) {
            foreach ($days as $d => &$tsList) {
                $tsList = array_values(array_unique($tsList));
                sort($tsList);
            }
            unset($tsList);
        }
        unset($days);

        // Sort users naturally for the matrix display.
        $users = array_keys($byUserDate);
        sort($users, SORT_NATURAL | SORT_FLAG_CASE);

        $prettyDates = array_map(fn($d) => Carbon::parse($d, $tz)->format('M j'), $dates);

        return view('encoder.checker_1.idle_summary', [
            'start'       => $startDate->format('Y-m-d'),
            'end'         => $endDate->format('Y-m-d'),
            'dates'       => $dates,
            'prettyDates' => $prettyDates,
            'users'       => $users,
            'byUserDate'  => $byUserDate, // Sent as JSON to Alpine for client-side classification
            // Thresholds loaded from app_settings (managed at /encoder/checker_1/idle-thresholds).
            // Falls back to controller defaults when no saved value exists yet.
            'thresholds'  => Checker1IdleThresholdsController::load(),
        ]);
    }

    /**
     * Parse status_logs into [['ts' => string, 'user' => string], ...].
     * Supports BOTH:
     *   - New pipe format:  YYYY-MM-DD HH:MM:SS|user|VALUE
     *   - Legacy bracket:   [YYYY-MM-DD HH:MM:SS] user changed STATUS: ...
     */
    private static function parseStatusLogs(string $logs): array
    {
        if ($logs === '') return [];
        $logs = str_replace("\r\n", "\n", $logs);
        $lines = array_filter(array_map('trim', explode("\n", $logs)), fn($l) => $l !== '');
        if (empty($lines)) return [];

        $pipe   = '/^(?<ts>\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\|(?<user>[^|]+)\|/u';
        $legacy = '/^\[(?<ts>\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\]\s+(?<user>.*?)\s+changed\s+STATUS\b/i';

        $out = [];
        foreach ($lines as $line) {
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
            $out[] = ['ts' => $ts, 'user' => $user];
        }
        return $out;
    }

    private static function safeCarbon(string $ts, string $tz): ?Carbon
    {
        try { return Carbon::parse($ts, $tz); }
        catch (\Throwable $e) { return null; }
    }
}
