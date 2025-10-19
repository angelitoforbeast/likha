<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

use App\Models\ZkRawUser;
use App\Models\ZkRawAttlog;
use App\Models\ZkAttendanceProcessed;

class AttendanceController extends Controller
{
    /**
     * RAW LOGS LIST
     * Route: GET /employee/attendance/index
     */
    public function index(Request $request)
{
    // Filters (from query string)
    $userId   = $request->query('user_id');        // e.g. "4"
    $dateRange = $request->query('date_range');    // e.g. "2025-09-01 to 2025-09-30"

    // Parse date range "YYYY-MM-DD to YYYY-MM-DD"
    $from = $to = null;
    if ($dateRange) {
        // Allow " to " or " - " separators (flatpickr uses " to " by default on range)
        $parts = preg_split('/\s+to\s+|\s+-\s+/i', trim($dateRange));
        if (isset($parts[0]) && $parts[0] !== '') {
            try { $from = \Carbon\Carbon::parse($parts[0])->format('Y-m-d'); } catch (\Throwable $e) {}
        }
        if (isset($parts[1]) && $parts[1] !== '') {
            try { $to = \Carbon\Carbon::parse($parts[1])->format('Y-m-d'); } catch (\Throwable $e) {}
        }
    }

    $query = \App\Models\ZkRawAttlog::query()
        ->when($userId, fn($q) => $q->where('zk_user_id', $userId))
        ->when($from && $to, fn($q) => $q->whereBetween('date', [$from, $to]))
        ->when($from && !$to, fn($q) => $q->whereDate('date', '>=', $from))
        ->when(!$from && $to, fn($q) => $q->whereDate('date', '<=', $to))
        ->orderBy('datetime_raw', 'desc');

    // Paginate (and keep filters in the query string)
    $logs = $query->paginate(100)->withQueryString();

    // Map for names + a simple list for the dropdown
    $users = \App\Models\ZkRawUser::query()
        ->select('zk_user_id','name_clean','name_raw')
        ->orderBy('zk_user_id')
        ->get();

    $userMap = $users->keyBy('zk_user_id');

    return view('employee.attendance.index', [
        'logs'      => $logs,
        'userMap'   => $userMap,
        'users'     => $users,
        'userId'    => $userId,
        'dateRange' => $dateRange,
    ]);
}


    /**
     * UPLOAD FORM
     * Route: GET /employee/attendance/upload
     */
    public function uploadForm()
    {
        return view('employee.attendance.upload');
    }

    /**
     * HANDLE UPLOADS (Option B: direct-read from temp upload)
     * Route: POST /employee/attendance/upload
     */
    public function uploadStore(Request $request)
    {
        $request->validate([
            'user_dat'   => ['nullable','file'],
            'attlog_dat' => ['nullable','file'],
        ]);

        if (!$request->hasFile('user_dat') && !$request->hasFile('attlog_dat')) {
            return back()->with('status', 'Please upload at least one file.');
        }

        $batch = 'upload_' . now()->format('Ymd_His') . '_' . Str::random(6);

        DB::beginTransaction();
        try {
            // user.dat
            if ($request->hasFile('user_dat')) {
                $file = $request->file('user_dat');
                $contents = file_get_contents($file->getRealPath()); // direct-read
                $this->ingestUserDat($contents, $batch);

                // optional archive copy (not required for parsing)
                $file->storeAs("zk_uploads/$batch", 'user.dat');
            }

            // attlog.dat
            if ($request->hasFile('attlog_dat')) {
                $file = $request->file('attlog_dat');
                $contents = file_get_contents($file->getRealPath()); // direct-read
                $this->ingestAttlogDat($contents, $batch);

                // optional archive copy
                $file->storeAs("zk_uploads/$batch", 'attlog.dat');
            }

            DB::commit();
            return redirect()->route('attendance.index')->with('status', "Uploaded & saved (batch: $batch).");

        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return back()->with('status', 'Upload failed: '.$e->getMessage());
        }
    }

    /**
     * PROCESSED ATTENDANCE LIST
     * Route: GET /employee/attendance/processed
     */
    public function processedIndex(Request $request)
{
    // Filters from query string
    $userId    = $request->query('user_id');       // biometric ID
    $dateRange = $request->query('date_range');    // "YYYY-MM-DD to YYYY-MM-DD"

    // Parse date range (supports " to " or " - ")
    $from = $to = null;
    if ($dateRange) {
        $parts = preg_split('/\s+to\s+|\s+-\s+/i', trim($dateRange));
        if (!empty($parts[0])) {
            try { $from = \Carbon\Carbon::parse($parts[0])->format('Y-m-d'); } catch (\Throwable $e) {}
        }
        if (!empty($parts[1])) {
            try { $to = \Carbon\Carbon::parse($parts[1])->format('Y-m-d'); } catch (\Throwable $e) {}
        }
    }

    $query = \App\Models\ZkAttendanceProcessed::query()
        ->when($userId, fn($q) => $q->where('zk_user_id', $userId))
        ->when($from && $to, fn($q) => $q->whereBetween('date', [$from, $to]))
        ->when($from && !$to, fn($q) => $q->whereDate('date', '>=', $from))
        ->when(!$from && $to, fn($q) => $q->whereDate('date', '<=', $to))
        ->orderBy('date', 'desc')
        ->orderBy('zk_user_id');

    $rows = $query->paginate(50)->withQueryString();

    // For labels in table + dropdown options
    $users = \App\Models\ZkRawUser::query()
        ->select('zk_user_id','name_clean','name_raw')
        ->orderBy('zk_user_id')
        ->get();
    $userMap = $users->keyBy('zk_user_id');

    return view('employee.attendance.processed', [
        'rows'      => $rows,
        'userMap'   => $userMap,
        'users'     => $users,
        'userId'    => $userId,
        'dateRange' => $dateRange,
    ]);
}


    /**
     * BUILD DAILY Time In/Out + Lunch FROM RAW LOGS
     * Route: POST /employee/attendance/process
     * Optional inputs: from (Y-m-d), to (Y-m-d)
     */
    public function processAttendance(Request $request)
    {
        $from = $request->input('from'); // 'YYYY-MM-DD'
        $to   = $request->input('to');   // 'YYYY-MM-DD'

        $groupQuery = ZkRawAttlog::query()
            ->select('zk_user_id', 'date')
            ->when($from, fn($q) => $q->where('date', '>=', $from))
            ->when($to,   fn($q) => $q->where('date', '<=', $to))
            ->distinct()
            ->orderBy('date')
            ->orderBy('zk_user_id');

        $processedCount = 0;

        // Chunk distinct groups
        $groupQuery->chunk(500, function ($groups) use (&$processedCount) {
            foreach ($groups as $g) {
                // Normalize date to Y-m-d (handles "2025-09-01 00:00:00" cases)
                $dateStr = is_string($g->date)
                    ? substr($g->date, 0, 10)
                    : Carbon::parse($g->date)->format('Y-m-d');

                // Fetch times safely; whereDate is cross-DB (MySQL/PostgreSQL)
                $times = ZkRawAttlog::query()
                    ->where('zk_user_id', $g->zk_user_id)
                    ->whereDate('date', $dateStr)
                    ->orderBy('time')
                    ->pluck('time')
                    ->toArray();

                if (empty($times)) {
                    continue;
                }

                // Sanitize to strict HH:MM:SS
                $times = array_values(array_map(
                    fn($t) => substr(trim($t), 0, 8),
                    $times
                ));

                // Anti-double-tap: remove taps within 3 minutes of previous
                $times = $this->removeCloseTaps($dateStr, $times, 3);

                $timeIn  = $times[0] ?? null;
                $timeOut = $times[count($times)-1] ?? null;

                // Default lunch window; can be replaced per-employee schedule later
                [$lunchOut, $lunchIn] = $this->detectLunch($dateStr, $times, '14:00:00', '15:30:00');

                $workHours = $this->computeWorkHours($dateStr, $timeIn, $timeOut, $lunchOut, $lunchIn);

                ZkAttendanceProcessed::updateOrCreate(
                    ['zk_user_id' => $g->zk_user_id, 'date' => $dateStr],
                    [
                        'time_in'     => $timeIn,
                        'lunch_out'   => $lunchOut,
                        'lunch_in'    => $lunchIn,
                        'time_out'    => $timeOut,
                        'work_hours'  => $workHours,
                        'upload_batch'=> ZkRawAttlog::where('zk_user_id',$g->zk_user_id)
                                            ->whereDate('date',$dateStr)
                                            ->orderByDesc('id')
                                            ->value('upload_batch'),
                    ]
                );

                $processedCount++;
            }
        });

        return redirect()->route('attendance.processed.index')
            ->with('status', "Processed $processedCount day(s).");
    }

    /* =======================
       Helpers (parsers & math)
       ======================= */

    /**
     * Parse + upsert users from user.dat (ANSI/Windows-1252 w/ control chars).
     */
    private function ingestUserDat(string $raw, string $batch): void
    {
        // Normalize to UTF-8
        if (!mb_detect_encoding($raw, 'UTF-8', true)) {
            $raw = @iconv('Windows-1252', 'UTF-8//IGNORE', $raw);
        }

        // Clean control chars, collapse spaces
        $txt = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $raw);
        $txt = preg_replace('/\s+/u', ' ', $txt);
        $txt = trim($txt);

        // Pattern: Name (letters/digits/spaces) + ID (1–5 digits)
        $re = '/([A-Za-z][A-Za-z0-9 ]{1,30}?)\s+(\d{1,5})(?=\s|$)/u';
        preg_match_all($re, $txt, $matches, PREG_SET_ORDER);

        foreach ($matches as $m) {
            $name = trim($m[1]);
            $id   = trim($m[2]);

            if ($name === '' || preg_match('/^\d+$/', $name)) continue;

            // Optional: split CamelCase → "Angelito Forbes"
            $nameClean = preg_replace('/([a-z])([A-Z])/', '$1 $2', $name);
            $nameClean = preg_replace('/\s+/', ' ', $nameClean);

            ZkRawUser::updateOrCreate(
                ['zk_user_id' => $id],
                [
                    'name_raw'     => $name,
                    'name_clean'   => $nameClean,
                    'upload_batch' => $batch,
                ]
            );
        }
    }

    /**
     * Parse + insert logs from attlog.dat
     * Expected line formats:
     *  - "ID YYYY-MM-DD HH:MM:SS"
     *  - "ID\tYYYY-MM-DD\tHH:MM:SS"
     *  - or combined "ID 2025-09-27T08:56:31"
     */
    private function ingestAttlogDat(string $raw, string $batch): void
    {
        if (!mb_detect_encoding($raw, 'UTF-8', true)) {
            $raw = @iconv('Windows-1252', 'UTF-8//IGNORE', $raw);
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $rows = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 2) continue;

            $id = trim($parts[0]);
            $date = null; $time = null;

            if (isset($parts[2])) {
                $date = $parts[1];
                $time = $parts[2];
            } else {
                // combined datetime?
                if (strpos($parts[1], 'T') !== false) {
                    [$date, $time] = explode('T', $parts[1]);
                } elseif (strlen($parts[1]) >= 19) {
                    $date = substr($parts[1], 0, 10);
                    $time = substr($parts[1], 11, 8);
                }
            }

            if (!$date || !$time) continue;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
            if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) continue;

            $rows[] = [
                'zk_user_id'   => $id,
                'datetime_raw' => "$date $time",
                'date'         => $date,
                'time'         => $time,
                'upload_batch' => $batch,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            ZkRawAttlog::insert($chunk);
        }
    }

    /** Remove near-duplicate taps within N minutes (anti-double-tap). */
    private function removeCloseTaps(string $date, array $times, int $minGapMinutes = 3): array
    {
        $clean = [];
        $prev = null;

        foreach ($times as $t) {
            $t = substr(trim($t), 0, 8); // HH:MM:SS
            if (!$prev) {
                $clean[] = $t;
                $prev = $t;
                continue;
            }

            // Use parse to avoid strict format errors
            $d1 = Carbon::parse("$date $prev");
            $d2 = Carbon::parse("$date $t");
            $diff = $d1->diffInMinutes($d2);

            if ($diff >= $minGapMinutes) {
                $clean[] = $t;
                $prev = $t;
            }
        }

        return $clean;
    }

    /**
     * Detect lunch as first tap within a window, then next tap after it.
     * Defaults: 14:00:00–15:30:00
     */
    private function detectLunch(string $date, array $times, string $winStart = '14:00:00', string $winEnd = '15:30:00'): array
    {
        $lunchOut = null;
               $lunchIn  = null;

        foreach ($times as $idx => $t) {
            if ($t >= $winStart && $t <= $winEnd) {
                $lunchOut = $t;
                // next tap after lunchOut
                for ($j = $idx + 1; $j < count($times); $j++) {
                    if ($times[$j] > $lunchOut) {
                        $lunchIn = $times[$j];
                        break;
                    }
                }
                break;
            }
        }

        return [$lunchOut, $lunchIn];
    }

    /** Compute effective hours = (out - in) minus lunch (if both present), capped lunch 2h. */
    private function computeWorkHours(string $date, ?string $timeIn, ?string $timeOut, ?string $lunchOut, ?string $lunchIn): float
    {
        if (!$timeIn || !$timeOut) {
            return 0.0;
        }

        $start = Carbon::parse("$date " . substr(trim($timeIn), 0, 8));
        $end   = Carbon::parse("$date " . substr(trim($timeOut), 0, 8));

        $totalMinutes = max(0, $start->diffInMinutes($end));

        $lunchMinutes = 0;
        if ($lunchOut && $lunchIn) {
            $lo = Carbon::parse("$date " . substr(trim($lunchOut), 0, 8));
            $li = Carbon::parse("$date " . substr(trim($lunchIn), 0, 8));
            $lunchMinutes = max(0, $lo->diffInMinutes($li));
            $lunchMinutes = min($lunchMinutes, 120); // cap to 2h
        }

        $effectiveMinutes = max(0, $totalMinutes - $lunchMinutes);
        return round($effectiveMinutes / 60, 2);
    }
}
