<?php

namespace App\Services\Jnt;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * A shared limit on J&T label calls in flight across every worker (spec 269): `jnt.labels.max_concurrent` slots,
 * each an atomic cache lock. A caller holds one slot per call it sends and releases them when the answers are in.
 * A slot of a killed worker frees itself after SLOT_SECONDS.
 */
class JntLabelLimiter
{
    /** Above one batch of calls (HTTP timeout 30 s, sent together), so a held slot never expires mid-batch. */
    public const SLOT_SECONDS = 90;

    /**
     * Up to $want free slots (at least one), waiting up to $waitSeconds for one to free up; [] when none freed.
     *
     * @return array<int, Lock>
     */
    public function acquire(int $want, int $waitSeconds): array
    {
        $max = max(1, (int) config('jnt.labels.max_concurrent', 20));
        $want = max(1, min($want, $max));
        $deadline = microtime(true) + $waitSeconds;

        while (true) {
            $held = [];
            $start = random_int(0, $max - 1); // workers start at different slots
            for ($i = 0; $i < $max && count($held) < $want; $i++) {
                $lock = Cache::lock('jnt-label:slot:'.(($start + $i) % $max), self::SLOT_SECONDS);
                if ($lock->get()) {
                    $held[] = $lock;
                }
            }
            if ($held !== [] || microtime(true) >= $deadline) {
                return $held;
            }
            usleep(250_000);
        }
    }

    /** @param array<int, Lock> $slots */
    public function release(array $slots): void
    {
        foreach ($slots as $slot) {
            try {
                $slot->release();
            } catch (\Throwable $e) {
                // an expired slot is free already
            }
        }
    }
}
