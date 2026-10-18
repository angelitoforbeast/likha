<?php

namespace App\Console\Commands;

use App\Services\BotcakeCustomerSync;
use App\Services\PageSettings;
use Illuminate\Console\Command;

/**
 * Botcake customer list → botcake_psids, saka matching ng orders ng page. Lahat ng page na may botcake.page_id
 * at botcake.token, o ang isang --page. Isang page sa isang pagkakataon.
 * --nightly: para sa simpleng cron line (walang schedule:run, hal. Incepxion): --full kapag Linggo sa Manila, by date sa
 * ibang araw; agad na lalabas kapag naka-off ang services.botcake.sync_enabled.
 */
class SyncBotcakeCustomers extends Command
{
    protected $signature   = 'botcake:sync-customers {--page= : Isang page lang} {--full : Basahin hanggang dulo} {--nightly : Linggo sa Manila = --full, ibang araw = by date; wala kapag naka-off ang sync}';
    protected $description = 'Read each page\'s Botcake customers into botcake_psids and match recent orders without a PSID';

    public function handle(BotcakeCustomerSync $sync, PageSettings $settings): int
    {
        $full = (bool) $this->option('full');
        if ($this->option('nightly')) {
            if (!config('services.botcake.sync_enabled')) {
                $this->line('Skipped: naka-off ang Botcake sync (BOTCAKE_SYNC_ENABLED).');
                return self::SUCCESS;
            }
            $full = $full || now('Asia/Manila')->isSunday();
        }

        $pages = $sync->pages();

        if (($only = $this->option('page')) !== null) {
            $key = $settings->pageKey((string) $only);
            $pages = array_values(array_filter($pages, fn ($p) => $settings->pageKey($p) === $key));
            if (!$pages) {
                $this->error('Walang botcake.page_id at botcake.token ang page na ito.');
                return self::FAILURE;
            }
        }

        // Kapag pinatay ang run (SIGTERM / SIGINT) habang hawak nito ang lock, bitawan ito para hindi ma-"Skipped"
        // ang susunod na run. Hindi ginagalaw ang lock ng ibang proseso. Walang pcntl (hal. Windows): ang TTL ang sasalo.
        $holding = false;
        if (extension_loaded('pcntl')) {
            $this->trap([SIGTERM, SIGINT], function () use ($sync, &$holding) {
                if ($holding) $sync->forceRelease();
                exit(1);
            });
        }

        // Isang lock kada page (TTL 2 oras bawat isa), para hindi maubos ang TTL sa mahabang run ng maraming page.
        foreach ($pages as $page) {
            $run = $sync->withLock(function () use ($page, $sync, $full, &$holding) {
                $holding = true;
                try {
                    return $sync->syncPage($page, $full);
                } finally {
                    $holding = false;
                }
            });

            // Abala ang lock (hal. Sync lahat o ang row Sync): ang page lang na ito ang nilalaktawan, tuloy sa susunod.
            if ($run === null) {
                $since = $sync->lockedSince();
                $this->line('Skipped: may tumatakbo pang Botcake customer sync' . ($since ? ' (mula ' . $since->copy()->timezone('Asia/Manila')->format('Y-m-d H:i') . ')' : '') . '. Laktaw ang ' . $page . '; tuloy sa susunod na page.');
                continue;
            }
            $this->line(($run->status === 'done' ? 'OK ' : 'FAILED ') . $run->last_message . ($run->last_error ? ' | ' . $run->last_error : ''));
        }

        return self::SUCCESS;
    }
}
