<?php

namespace App\Services\Chatbot;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Spec 273: the CPU steal of our server (the time the hosting provider's machine gave our virtual CPU to someone else).
 * A high steal slows every reply and send, and on 2026-11-13 it came with our own network stalling. server:cpu-steal
 * samples it every 5 minutes; the Problems panel shows a yellow line when the last hour's average is above
 * steal_alert_percent. Where /proc/stat cannot be read (not Linux), nothing is stored and no line shows.
 */
class CpuSteal
{
    public const CACHE = 'server:cpu-steal';

    /** The samples kept and averaged: the last hour. */
    public const WINDOW = 3600;

    /**
     * The "cpu" line of /proc/stat as [total, steal] in jiffies, or null when it cannot be read. The total is user, nice,
     * system, idle, iowait, irq, softirq and steal (guest time is already inside user).
     *
     * @return array{0: int, 1: int}|null
     */
    public function read(): ?array
    {
        if (! @is_readable('/proc/stat')) {
            return null;
        }
        $text = @file_get_contents('/proc/stat');
        if (! is_string($text) || ! preg_match('/^cpu\s+([\d\s]+)$/m', $text, $m)) {
            return null;
        }
        $v = array_map('intval', preg_split('/\s+/', trim($m[1])));
        if (count($v) < 8) {
            return null;
        }

        return [array_sum(array_slice($v, 0, 8)), $v[7]];
    }

    /** Two reads $seconds apart: the steal % in between, stored with its time (cache) and logged; null when unreadable. */
    public function sample(int $seconds = 5): ?float
    {
        $a = $this->read();
        if ($a === null) {
            return null;
        }
        Sleep::for($seconds)->seconds();
        $b = $this->read();
        if ($b === null || $b[0] <= $a[0]) {
            return null;
        }
        $pct = round(max(0, $b[1] - $a[1]) * 100 / ($b[0] - $a[0]), 1);
        $now = now()->getTimestamp();
        $kept = array_values(array_filter((array) Cache::get(self::CACHE), fn ($s) => is_array($s) && (int) ($s[0] ?? 0) > $now - self::WINDOW));
        $kept[] = [$now, $pct];
        Cache::put(self::CACHE, $kept, self::WINDOW * 2);
        Log::info('server cpu steal', ['percent' => $pct, 'hour_average' => self::hourAverage()]);

        return $pct;
    }

    /** The average steal % of the samples in the last hour, or null when there are none. */
    public static function hourAverage(): ?float
    {
        $now = now()->getTimestamp();
        $pcts = array_map(fn ($s) => (float) $s[1], array_filter((array) Cache::get(self::CACHE),
            fn ($s) => is_array($s) && isset($s[1]) && (int) ($s[0] ?? 0) > $now - self::WINDOW));

        return $pcts ? round(array_sum($pcts) / count($pcts), 1) : null;
    }
}
