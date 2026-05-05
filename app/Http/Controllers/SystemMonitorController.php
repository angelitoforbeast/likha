<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * System Monitor — CEO-only dashboard for live server + database health.
 *
 * Sections:
 *   1. Server stats (memory, swap, CPU load)
 *   2. Disk usage (overall + per-directory)
 *   3. Database stats (per-table size, top tables)
 *   4. File storage (uploads, logs)
 *   5. PHP processes (workers, FPM)
 *
 * All data heavily cached — 15-30s — kasi some queries (information_schema,
 * directory size scans) are slow on big systems.
 */
class SystemMonitorController extends Controller
{
    private function checkCEO(): void
    {
        $raw  = Auth::user()?->employeeProfile?->role ?? '';
        $norm = preg_replace('/\s+/u', ' ', trim((string) $raw));
        if (preg_match('/^ceo$/iu', $norm) !== 1) abort(404);
    }

    public function index()
    {
        $this->checkCEO();
        return view('system_monitor.index');
    }

    /**
     * JSON endpoint for live auto-refresh sa UI.
     * Cached aggressively kasi some queries are heavy.
     */
    public function data(Request $request)
    {
        $this->checkCEO();

        return response()->json([
            'server'    => $this->getServerStats(),
            'disk'      => $this->getDiskStats(),
            'database'  => $this->getDatabaseStats(),
            'storage'   => $this->getFileStorageStats(),
            'processes' => $this->getProcessStats(),
            'fetched_at' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Server-level stats — RAM, swap, CPU load.
     */
    private function getServerStats(): array
    {
        return Cache::remember('sysmon_server', 10, function () {
            $stats = [
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
        });
    }

    /**
     * Disk usage — overall + selected directories.
     */
    private function getDiskStats(): array
    {
        return Cache::remember('sysmon_disk', 30, function () {
            $root = '/';
            $total = @disk_total_space($root);
            $free  = @disk_free_space($root);
            $used  = ($total && $free) ? ($total - $free) : 0;

            return [
                'root_total_gb'   => $total ? round($total / 1024 / 1024 / 1024, 1) : 0,
                'root_used_gb'    => $used  ? round($used  / 1024 / 1024 / 1024, 1) : 0,
                'root_free_gb'    => $free  ? round($free  / 1024 / 1024 / 1024, 1) : 0,
                'root_percent'    => $total ? round($used / $total * 100, 1) : 0,
            ];
        });
    }

    /**
     * Database stats — per-table size, top tables.
     */
    private function getDatabaseStats(): array
    {
        return Cache::remember('sysmon_database', 60, function () {
            $dbName = config('database.connections.mysql.database');

            // Use explicit aliases para hindi mag-rely sa case ng information_schema
            // (some MySQL configs return TABLE_NAME uppercase)
            $tables = DB::select("
                SELECT
                    table_name AS tname,
                    table_rows AS trows,
                    ROUND(data_length / 1024 / 1024, 2) AS data_mb,
                    ROUND(index_length / 1024 / 1024, 2) AS index_mb,
                    ROUND((data_length + index_length) / 1024 / 1024, 2) AS total_mb
                FROM information_schema.tables
                WHERE table_schema = ?
                ORDER BY (data_length + index_length) DESC
                LIMIT 30
            ", [$dbName]);

            $totalRow = DB::selectOne("
                SELECT
                    ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS total_mb,
                    COUNT(*) AS table_count,
                    SUM(table_rows) AS total_rows
                FROM information_schema.tables
                WHERE table_schema = ?
            ", [$dbName]);

            return [
                'total_size_mb' => (float) ($totalRow->total_mb ?? 0),
                'table_count'   => (int) ($totalRow->table_count ?? 0),
                'total_rows'    => (int) ($totalRow->total_rows ?? 0),
                'tables'        => array_map(function ($t) {
                    return [
                        'name'      => $t->tname ?? '',
                        'rows'      => (int) ($t->trows ?? 0),
                        'data_mb'   => (float) ($t->data_mb ?? 0),
                        'index_mb'  => (float) ($t->index_mb ?? 0),
                        'total_mb'  => (float) ($t->total_mb ?? 0),
                    ];
                }, $tables),
            ];
        });
    }

    /**
     * File storage stats — selected directories within Laravel storage.
     */
    private function getFileStorageStats(): array
    {
        return Cache::remember('sysmon_storage', 120, function () {
            $base = storage_path();

            $dirs = [
                'jnt_v2_uploads' => $base . '/app/uploads/jnt_v2',
                'jnt_uploads'    => $base . '/app/uploads/jnt',
                'logs'           => $base . '/logs',
                'framework'      => $base . '/framework',
            ];

            $result = [];
            foreach ($dirs as $key => $path) {
                $size  = $this->dirSize($path);
                $files = $this->dirFileCount($path);
                $result[$key] = [
                    'path'       => $path,
                    'size_mb'    => round($size / 1024 / 1024, 2),
                    'file_count' => $files,
                    'exists'     => is_dir($path),
                ];
            }

            // Public/photos folder kung meron
            $publicPhotos = public_path('storage');
            if (is_dir($publicPhotos)) {
                $size = $this->dirSize($publicPhotos);
                $result['public_storage'] = [
                    'path'       => $publicPhotos,
                    'size_mb'    => round($size / 1024 / 1024, 2),
                    'file_count' => $this->dirFileCount($publicPhotos),
                    'exists'     => true,
                ];
            }

            return $result;
        });
    }

    /**
     * Recursive directory size — capped to prevent stalls on huge dirs.
     */
    private function dirSize(string $path, int $maxFiles = 50000): int
    {
        if (!is_dir($path)) return 0;

        $size = 0;
        $count = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                    $count++;
                    if ($count >= $maxFiles) break; // safety cap
                }
            }
        } catch (\Throwable $e) {
            // Permissions or other issue — return what we have
        }
        return $size;
    }

    /**
     * Recursive file count — capped same way.
     */
    private function dirFileCount(string $path, int $maxFiles = 50000): int
    {
        if (!is_dir($path)) return 0;

        $count = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $count++;
                    if ($count >= $maxFiles) break;
                }
            }
        } catch (\Throwable $e) {
        }
        return $count;
    }

    /**
     * PHP processes — workers + FPM.
     */
    private function getProcessStats(): array
    {
        return Cache::remember('sysmon_processes', 10, function () {
            $procs = [
                'queue_workers' => [],
                'fpm_count'     => 0,
                'fpm_total_mb'  => 0,
                'mysql_mb'      => 0,
            ];

            // Read from /proc/*/status for each PID with name 'php' or 'mysqld'
            // (more portable than parsing 'ps aux' output)
            if (!is_dir('/proc')) {
                return $procs;
            }

            $procDirs = @scandir('/proc') ?: [];
            foreach ($procDirs as $pid) {
                if (!ctype_digit($pid)) continue;

                $statusPath = "/proc/{$pid}/status";
                $cmdlinePath = "/proc/{$pid}/cmdline";

                if (!is_readable($statusPath) || !is_readable($cmdlinePath)) continue;

                $status = @file_get_contents($statusPath);
                if (!$status) continue;

                preg_match('/^Name:\s+(\S+)/m', $status, $nameMatch);
                preg_match('/VmRSS:\s+(\d+)/m', $status, $rssMatch);

                $name = $nameMatch[1] ?? '';
                $rssKb = (int) ($rssMatch[1] ?? 0);

                if ($name === 'mysqld') {
                    $procs['mysql_mb'] = max($procs['mysql_mb'], (int) round($rssKb / 1024));
                    continue;
                }

                if ($name !== 'php' && strpos($name, 'php') === false) {
                    continue;
                }

                $cmdline = @file_get_contents($cmdlinePath);
                $cmdline = str_replace("\0", ' ', $cmdline ?? '');

                if (strpos($cmdline, 'queue:work') !== false) {
                    $queue = 'unknown';
                    if (preg_match('/--queue=(\S+)/', $cmdline, $qm)) {
                        $queue = $qm[1];
                    }
                    $procs['queue_workers'][] = [
                        'pid'    => (int) $pid,
                        'queue'  => $queue,
                        'rss_mb' => (int) round($rssKb / 1024),
                    ];
                } elseif (strpos($cmdline, 'php-fpm') !== false || strpos($cmdline, 'fpm: pool') !== false) {
                    $procs['fpm_count']++;
                    $procs['fpm_total_mb'] += (int) round($rssKb / 1024);
                }
            }

            // Sort workers by queue + pid for stable display
            usort($procs['queue_workers'], function ($a, $b) {
                return [$a['queue'], $a['pid']] <=> [$b['queue'], $b['pid']];
            });

            return $procs;
        });
    }
}
