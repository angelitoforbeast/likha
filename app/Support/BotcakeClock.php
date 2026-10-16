<?php

namespace App\Support;

/** Orasan ng Botcake client (rate limit at backoff). Hiwalay para mapalitan sa tests: walang totoong paghihintay doon. */
class BotcakeClock
{
    public function now(): float
    {
        return microtime(true);
    }

    public function sleep(float $seconds): void
    {
        if ($seconds > 0) usleep((int) round($seconds * 1_000_000));
    }
}
