<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Isang order ng Astra night run (spec 007 §6.4), sa sariling queue na `astra` para manatiling libre
 * ang `default` worker sa imports at J&T.
 */
class RunNightAstraRow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const QUEUE = 'astra';

    public int $timeout = 540;
    public int $tries = 3;

    public function __construct(public int $rowId)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(): void
    {
    }
}
