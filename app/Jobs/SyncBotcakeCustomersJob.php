<?php

namespace App\Jobs;

use App\Services\BotcakeCustomerSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Ang "Sync Botcake customers now" button ng /page-settings: isang page, sa queue (maaaring ilang minuto). */
class SyncBotcakeCustomersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 1;

    public function __construct(public string $page, public bool $full = false)
    {
    }

    public function handle(BotcakeCustomerSync $sync): void
    {
        // Kapag may tumatakbong sync (schedule o ibang button), laktaw: ang susunod na oras ang kukuha.
        $sync->withLock(fn () => $sync->syncPage($this->page, $this->full));
    }

    /** Pinatay o pumalya ang job: ang run na naiwang "running" ay failed (killed); itinutuloy ito ng susunod na sync. */
    public function failed(?\Throwable $e): void
    {
        app(BotcakeCustomerSync::class)->markKilled($this->page);
    }
}
