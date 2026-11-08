<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Storage numbers of the server: the disk of `/`, the database size and the MySQL binary logs.
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
}
