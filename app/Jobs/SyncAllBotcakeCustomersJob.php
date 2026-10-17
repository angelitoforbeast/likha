<?php

namespace App\Jobs;

use App\Services\BotcakeCustomerSync;
use App\Services\BotcakeSyncAll;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Isang page ng "Sync lahat". Ang run ay nasa database (BotcakeSyncAll); ang job ay tumatakbo lang kapag ang run ay
 * running at ang page_index nito ay ang page ng job. Pagkatapos ng page (tagumpay man o hindi), ang BotcakeSyncAll ang
 * kumukuha ng susunod na page at nagpapadala ng job, kung running pa: kaya laging nananalo ang Pause at Stop, at isa-isa
 * ang mga page. Kapag may ibang sync na may hawak ng lock, hindi nilalaktawan ang page: babalik pagkalipas ng
 * WAIT_SECONDS. Timeout 1800 s, mas maikli sa retry_after (1900 s) ng database queue para hindi ito maulit habang
 * tumatakbo. Ang $runId ay ang run_key (pareho ang pangalan ng property sa lumang job, para mabasa ang naka-queue pa).
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
        // Bago ang migrate (wala pa ang mga column): babalik mamaya ang parehong page, para hindi mawala ang kadena.
        if (!$all->ready()) {
            self::dispatch($this->runId, $this->index)->delay(now()->addSeconds(60));
            return;
        }

        $run = $all->find($this->runId);
        if (!$run) {
            // Walang ganitong run (hal. nabura ang lumang state sa cache bago ito naampon): nag-iiwan ng bakas.
            Log::warning('Sync lahat: walang run para sa job na ito; tumigil', ['run_id' => $this->runId, 'index' => $this->index]);
            return;
        }
        if ((int) $run->page_index !== $this->index) return; // luma o naulit na job: tapos na ang page na ito

        // Naka-pause o stopped: binibitawan ang page (ang Resume ang magpapadala ulit), maliban kung na-Resume na habang
        // binibitawan: kung gayon, ang job na ito ang tumutuloy.
        if ($run->status !== 'running') {
            if (!$all->release($run, $this->index)) return;
            $run = $run->fresh();
        }

        $page = $run->sync_state['pages'][$this->index] ?? null;
        if ($page === null) {
            $all->advance($run, $this->index, null, false);
            return;
        }

        try {
            $apiRun = $sync->withLock(fn () => $sync->syncPage($page));
        } catch (Throwable $e) {
            report($e);
            $apiRun = false;
        }

        if ($apiRun === null) {
            $all->setWaiting($run, true);
            self::dispatch($this->runId, $this->index)->delay(now()->addSeconds(BotcakeSyncAll::WAIT_SECONDS));
            return;
        }

        $all->advance($run, $this->index, $apiRun ?: null, $apiRun !== false && $apiRun->status === 'done');
    }

    /** Pinatay ang job (hal. timeout): ang api run ay killed, bilang na failed ang page, at tuloy sa susunod kung running. */
    public function failed(?Throwable $e): void
    {
        $all = app(BotcakeSyncAll::class);
        if (!$all->ready()) return;
        $run = $all->find($this->runId);
        if (!$run || (int) $run->page_index !== $this->index) return;

        if ($page = $run->sync_state['pages'][$this->index] ?? null) app(BotcakeCustomerSync::class)->markKilled($page);
        $all->advance($run, $this->index, null, false);
    }
}
