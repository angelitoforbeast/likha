<?php

namespace App\Console\Commands;

use App\Services\BotcakeCustomerSync;
use App\Services\PageSettings;
use Illuminate\Console\Command;

/**
 * Botcake customer list → botcake_psids, saka matching ng orders ng page. Lahat ng page na may botcake.page_id
 * at botcake.token, o ang isang --page. Isang page sa isang pagkakataon.
 */
class SyncBotcakeCustomers extends Command
{
    protected $signature   = 'botcake:sync-customers {--page= : Isang page lang} {--full : Basahin hanggang dulo}';
    protected $description = 'Read each page\'s Botcake customers into botcake_psids and match recent orders without a PSID';

    public function handle(BotcakeCustomerSync $sync, PageSettings $settings): int
    {
        $pages = $sync->pages();

        if (($only = $this->option('page')) !== null) {
            $key = $settings->pageKey((string) $only);
            $pages = array_values(array_filter($pages, fn ($p) => $settings->pageKey($p) === $key));
            if (!$pages) {
                $this->error('Walang botcake.page_id at botcake.token ang page na ito.');
                return self::FAILURE;
            }
        }

        $runs = $sync->withLock(function () use ($pages, $sync) {
            $runs = [];
            foreach ($pages as $page) {
                $run = $sync->syncPage($page, (bool) $this->option('full'));
                $this->line(($run->status === 'done' ? 'OK ' : 'FAILED ') . $run->last_message . ($run->last_error ? ' | ' . $run->last_error : ''));
                $runs[] = $run;
            }
            return $runs;
        });

        if ($runs === null) {
            $this->line('Skipped: may tumatakbo pang Botcake customer sync.');
        }

        return self::SUCCESS;
    }
}
