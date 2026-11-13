<?php

namespace App\Jobs;

use App\Services\Broadcast;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Queues a broadcast's rows (spec 263) in batches of Broadcast::BATCH, never in the web request: up to BUDGET seconds
 * per run, then it dispatches itself again from the saved cursor. On a stopped broadcast it cancels the waiting rows in
 * batches instead. One runner per broadcast (lock); after each run the feeder hands rows to the sender.
 */
class QueueBroadcast implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const BUDGET = 120;
    private const LOCK_SECONDS = 300;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(public int $broadcastId)
    {
        // The chatblast queue (its own workers), not default: the default is held by the Botcake sync for hours.
        $this->onConnection((string) config('queue.chatblast_connection'))->onQueue('chatblast');
    }

    public static function start(int $id): void
    {
        self::dispatch($id);
    }

    public static function lockKey(int $id): string
    {
        return 'chatblast-broadcast-queue:' . $id;
    }

    public function handle(Broadcast $broadcast): void
    {
        $lock = Cache::lock(self::lockKey($this->broadcastId), self::LOCK_SECONDS);
        if (!$lock->get()) return; // another run is queueing it; it goes on from the same cursor

        $more = false;
        try {
            $until = microtime(true) + self::BUDGET;
            do {
                $more = $broadcast->queueStep($this->broadcastId);
            } while ($more && microtime(true) < $until);
        } finally {
            $lock->release();
        }
        $broadcast->feed();
        if ($more) self::dispatch($this->broadcastId);
    }
}
