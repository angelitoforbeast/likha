<?php

namespace App\Jobs;

use App\Services\BotcakeCustomerSync;
use App\Services\BotcakeSyncAll;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Isang page ng "Sync lahat". Pagkatapos ng page (tagumpay man o hindi) ito ang nagpapadala ng susunod, kaya isa-isa
 * ang mga page. Kapag may ibang sync na may hawak ng lock, hindi nilalaktawan ang page: babalik pagkalipas ng
 * WAIT_SECONDS. Timeout 1800 s, mas maikli sa retry_after (1900 s) ng database queue para hindi ito maulit habang tumatakbo.
 */
class SyncAllBotcakeCustomersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(public string $runId, public int $index)
    {
    }

    public function handle(BotcakeCustomerSync $sync, BotcakeSyncAll $all): void
    {
        $state = $all->state($this->runId);
        if (!$state) {
            // Walang state (hal. cache:clear sa deploy, o naubos ang TTL sa mahabang backlog): titigil ang run, kaya
            // nag-iiwan ng bakas. Ang job ng ibang run o ng tapos na run ay tahimik na humihinto.
            if (!Cache::has(BotcakeSyncAll::STATE)) {
                Log::warning('Sync lahat: wala na ang state ng run sa cache; tumigil ang run', ['run_id' => $this->runId, 'index' => $this->index]);
            }
            return;
        }

        $page = $state['pages'][$this->index] ?? null;
        if ($page === null) {
            $all->save(['done' => count($state['pages'])] + $state);
            return;
        }

        $state['current'] = $page;
        $state['waiting'] = false;
        $all->save($state);

        try {
            $run = $sync->withLock(fn () => $sync->syncPage($page));
        } catch (Throwable $e) {
            report($e);
            $run = false;
        }

        if ($run === null) {
            $state['waiting'] = true;
            $all->save($state);
            self::dispatch($this->runId, $this->index)->delay(now()->addSeconds(BotcakeSyncAll::WAIT_SECONDS));
            return;
        }

        $this->next($all, $state, $run !== false && $run->status === 'done');
    }

    /** Pinatay ang job (hal. timeout): bilang na failed ang page, at tuloy sa susunod. */
    public function failed(?Throwable $e): void
    {
        $all = app(BotcakeSyncAll::class);
        $state = $all->state($this->runId);
        if ($state) $this->next($all, $state, false);
    }

    private function next(BotcakeSyncAll $all, array $state, bool $ok): void
    {
        if ($state['done'] !== $this->index) return; // naitala na ang page na ito (hal. failed() pagkatapos ng handle())
        $state['done']++;
        $state[$ok ? 'ok' : 'failed']++;
        $all->save($state);

        if ($state['done'] < count($state['pages'])) {
            self::dispatch($this->runId, $this->index + 1);
        }
    }
}
