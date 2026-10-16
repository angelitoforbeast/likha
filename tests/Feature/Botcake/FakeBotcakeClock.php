<?php

namespace Tests\Feature\Botcake;

use App\Support\BotcakeClock;

/** Orasan na hindi talaga naghihintay: tinatala ang bawat sleep at sinusulong ang oras. */
class FakeBotcakeClock extends BotcakeClock
{
    public float $time = 1000.0;

    /** @var array<int, float> */
    public array $sleeps = [];

    /** @var array<int, float> oras ng bawat request (tinatawag ng client) */
    public array $requestTimes = [];

    public function now(): float
    {
        return $this->time;
    }

    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->time += $seconds;
    }
}
