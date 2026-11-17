<?php

namespace App\Jobs;

use App\Http\Controllers\OwnerPrivateController;
use App\Services\DailyPrimaryItemService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recomputes the main item (daily_page_primary_item, last 90 days) for item names whose Item Type
 * Mapping changed, then bumps the /owner/private cache version (spec 289).
 *
 * The recompute ran inside the save request and went past nginx's 60 s, so staff got a 504 for a save
 * that had already gone through. Now the save only writes the mappings and queues this job.
 *
 * Several saves in a row share ONE pending job: forItemNames() merges the names into a pending list in
 * the cache and dispatches only when no job is pending yet. The job takes the whole list when it starts,
 * so a save that lands while it runs queues the next job (its names would otherwise be missed).
 */
class RecomputePrimaryItemsForAliases implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const QUEUE = 'default';

    public const PENDING_KEY = 'item_type_mapping:recompute_pending';

    private const LOCK_KEY = 'item_type_mapping:recompute_pending_lock';

    /** A pending list older than this is treated as a job that never ran (worker down, job lost), so a new one is queued. */
    public const STALE_SECONDS = 900;

    /** 90 days of dates, each a full slice recompute: minutes on the larger site, never near this. */
    public int $timeout = 1800;

    public int $tries = 1;

    /** @param array<int,string> $itemNames */
    public function __construct(public array $itemNames)
    {
        $this->onQueue(self::QUEUE);
    }

    /**
     * Adds the names to the pending list; queues a job only when none is pending.
     *
     * @param  array<int,string> $itemNames
     * @return bool true when a new job was queued, false when the names joined the pending one
     */
    public static function forItemNames(array $itemNames): bool
    {
        $names = self::clean($itemNames);
        if ($names === []) return false;

        $dispatch = self::withLock(function () use ($names) {
            $pending = Cache::get(self::PENDING_KEY);
            $fresh = is_array($pending)
                && isset($pending['at'], $pending['names'])
                && (now()->timestamp - (int) $pending['at']) < self::STALE_SECONDS;

            if ($fresh) {
                $pending['names'] = self::clean(array_merge($pending['names'], $names));
                Cache::forever(self::PENDING_KEY, $pending);
                return false;
            }

            Cache::forever(self::PENDING_KEY, ['names' => $names, 'at' => now()->timestamp]);
            return true;
        });

        if ($dispatch) self::dispatch($names);
        return $dispatch;
    }

    public function handle(DailyPrimaryItemService $service): void
    {
        @set_time_limit(0);

        $pending = self::withLock(function () {
            $p = Cache::get(self::PENDING_KEY);
            Cache::forget(self::PENDING_KEY);
            return is_array($p) && isset($p['names']) && is_array($p['names']) ? $p['names'] : [];
        });
        $names = self::clean(array_merge($this->itemNames, $pending));
        if ($names === []) return;

        $started = microtime(true);
        try {
            $summary = $service->recomputeForItemNames($names);
        } catch (Throwable $e) {
            // The mappings are already saved; only the main-item rows of these names lag until the next
            // recompute (nightly 14 days, or the next save of the same names).
            Log::error('RecomputePrimaryItemsForAliases failed: ' . get_class($e) . ': ' . $e->getMessage(), [
                'names' => $names,
            ]);
            // The mapping change still changes how /owner/private groups items.
            OwnerPrivateController::bumpCacheVersion();
            return;
        }

        OwnerPrivateController::bumpCacheVersion();

        Log::info('RecomputePrimaryItemsForAliases done', [
            'names' => count($names),
            'dates' => $summary['dates'] ?? 0,
            'seconds' => round(microtime(true) - $started, 1),
        ]);
    }

    public function failed(?Throwable $e): void
    {
        // Reached on a timeout or a worker kill, which the catch in handle() cannot see.
        Log::error('RecomputePrimaryItemsForAliases failed: ' . ($e ? get_class($e) . ': ' . $e->getMessage() : 'unknown'), [
            'names' => $this->itemNames,
        ]);
    }

    /** @return array<int,string> */
    private static function clean(array $names): array
    {
        $out = [];
        foreach ($names as $n) {
            $n = trim((string) $n);
            if ($n !== '') $out[$n] = true;
        }
        return array_keys($out);
    }

    private static function withLock(callable $fn): mixed
    {
        // The list is read and written by web requests and the worker at once; the lock keeps a merge
        // from being lost between the read and the write. If the lock store fails, run anyway: at worst a
        // second job is queued, never a lost save.
        try {
            return Cache::lock(self::LOCK_KEY, 10)->block(5, $fn);
        } catch (Throwable $e) {
            return $fn();
        }
    }
}
