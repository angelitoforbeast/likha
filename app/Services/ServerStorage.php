<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Numbers of the server: the disk of `/`, the database size, the MySQL binary logs and the server stats (RAM, CPU load).
 *
 * One class so the Storage view and the banner read the same numbers, and so tests can bind a fake in the container
 * instead of depending on the test machine's disk or on MySQL.
 */
class ServerStorage
{
    /**
     * The disk of `/`, or null when the disk functions fail (they return false on an unreadable mount), so callers
     * show nothing instead of a wrong 0 %.
     *
     * @return array{total:float, free:float, used:float, percent:float}|null
     */
    public function disk(): ?array
    {
        $total = @disk_total_space('/');
        $free  = @disk_free_space('/');
        if (!is_numeric($total) || !is_numeric($free) || $total <= 0) {
            return null;
        }

        $used = max(0, $total - $free);

        return [
            'total'   => (float) $total,
            'free'    => (float) $free,
            'used'    => (float) $used,
            'percent' => $used / $total * 100,
        ];
    }

    /** The database size: the System Monitor's information_schema total. Null when it cannot be read. */
    public function databaseBytes(): ?float
    {
        try {
            $row = DB::selectOne('
                SELECT SUM(data_length + index_length) AS total_bytes
                FROM information_schema.tables
                WHERE table_schema = ?
            ', [config('database.connections.mysql.database')]);

            return $row === null ? null : (float) ($row->total_bytes ?? 0);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Total size and count of the MySQL binary logs. Null when the server refuses (no REPLICATION CLIENT privilege,
     * as on the live server today) or binary logging is off. Not logged: the refusal is the expected state until the
     * owner grants the privilege, and a log line on every view would only be noise.
     *
     * @return array{bytes:float, files:int}|null
     */
    public function binaryLogs(): ?array
    {
        try {
            $rows = $this->showBinaryLogs();
        } catch (\Throwable $e) {
            return null;
        }

        $bytes = 0.0;
        foreach ($rows as $row) {
            // Column names differ in case between MySQL versions (File_size, FILE_SIZE).
            $cols = array_change_key_case((array) $row, CASE_LOWER);
            $bytes += (float) ($cols['file_size'] ?? 0);
        }

        return ['bytes' => $bytes, 'files' => count($rows)];
    }

    /** The raw rows, separate so the summing can be tested without a MySQL server. */
    protected function showBinaryLogs(): array
    {
        return DB::select('SHOW BINARY LOGS');
    }

    /**
     * Server stats as the System Monitor sends them (`/system-monitor/data`), cached 10 s under the monitor's own key,
     * so the CEO's monitor and the Storage view (spec 243) share one reader and one cache entry.
     */
    public function cachedServerStats(): array
    {
        return Cache::remember('sysmon_server', 10, fn () => $this->serverStats());
    }

    /** What serverStats() returns when nothing can be read: the monitor's defaults (0, or null for the load). */
    public static function emptyServerStats(): array
    {
        return [
            'memory_total_mb'     => 0,
            'memory_used_mb'      => 0,
            'memory_free_mb'      => 0,
            'memory_available_mb' => 0,
            'memory_used_percent' => 0,
            'swap_total_mb'       => 0,
            'swap_used_mb'        => 0,
            'load_1m'             => null,
            'load_5m'             => null,
            'load_15m'            => null,
            'cpu_count'           => 0,
            'uptime_seconds'      => 0,
        ];
    }

    /**
     * RAM, swap, CPU load, cores and uptime from /proc and sys_getloadavg (Linux), moved unchanged from
     * SystemMonitorController. A value that cannot be read keeps its default from emptyServerStats().
     */
    public function serverStats(): array
    {
        $stats = self::emptyServerStats();

        // Memory from /proc/meminfo (Linux only)
        if (is_readable('/proc/meminfo')) {
            $meminfo = @file_get_contents('/proc/meminfo');
            if ($meminfo) {
                preg_match('/MemTotal:\s+(\d+)/', $meminfo, $mt);
                preg_match('/MemFree:\s+(\d+)/', $meminfo, $mf);
                preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $ma);
                preg_match('/SwapTotal:\s+(\d+)/', $meminfo, $st);
                preg_match('/SwapFree:\s+(\d+)/', $meminfo, $sf);

                $totalKb = (int) ($mt[1] ?? 0);
                $freeKb  = (int) ($mf[1] ?? 0);
                $availKb = (int) ($ma[1] ?? 0);
                $swapTotalKb = (int) ($st[1] ?? 0);
                $swapFreeKb  = (int) ($sf[1] ?? 0);

                $stats['memory_total_mb']     = (int) round($totalKb / 1024);
                $stats['memory_free_mb']      = (int) round($freeKb / 1024);
                $stats['memory_available_mb'] = (int) round($availKb / 1024);
                $stats['memory_used_mb']      = (int) round(($totalKb - $availKb) / 1024);
                $stats['memory_used_percent'] = $totalKb > 0
                    ? round(($totalKb - $availKb) / $totalKb * 100, 1)
                    : 0;
                $stats['swap_total_mb'] = (int) round($swapTotalKb / 1024);
                $stats['swap_used_mb']  = (int) round(($swapTotalKb - $swapFreeKb) / 1024);
            }
        }

        // CPU load
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if (is_array($load)) {
                $stats['load_1m']  = round($load[0] ?? 0, 2);
                $stats['load_5m']  = round($load[1] ?? 0, 2);
                $stats['load_15m'] = round($load[2] ?? 0, 2);
            }
        }

        // CPU count
        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = @file_get_contents('/proc/cpuinfo');
            $stats['cpu_count'] = substr_count($cpuinfo ?: '', 'processor') ?: 1;
        }

        // Uptime
        if (is_readable('/proc/uptime')) {
            $uptime = @file_get_contents('/proc/uptime');
            if ($uptime) {
                $stats['uptime_seconds'] = (int) floatval(explode(' ', $uptime)[0] ?? 0);
            }
        }

        return $stats;
    }
}
