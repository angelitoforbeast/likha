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

    /** Tinatawag sa simula ng bawat sleep (hal. ibang proseso na dumarating habang naghihintay ang isa); true = ang hook na ang nagsulong ng oras. */
    public ?\Closure $onSleep = null;

    public function now(): float
    {
        return $this->time;
    }

    public function sleep(float $seconds): void
    {
        if ($this->onSleep && ($this->onSleep)($seconds) === true) {
            $this->sleeps[] = $seconds;
            return;
        }
        $this->sleeps[] = $seconds;
        $this->time += $seconds;
    }
}
