<?php

namespace App\Console\Commands;

use App\Jobs\ImportBotcakePsidFromGoogleSheet;
use App\Models\BotcakePsidImportRun;
use Illuminate\Console\Command;

/**
 * Kusang Botcake PSID import (scheduler): lahat ng settings, walang cutoff, gaya ng button.
 * Naka-queue ang trabaho para hindi maantala ang ibang scheduled task; ang lock sa job ang pumipigil sa dalawang sabay.
 */
class ImportBotcakePsid extends Command
{
    protected $signature   = 'botcake:psid-import {--clean-sheet : Burahin muna sa sheet ang mga row na naitala na}';
    protected $description = 'Queue the Botcake PSID import (optionally delete already recorded sheet rows first)';

    public function handle(): int
    {
        $clean = (bool) $this->option('clean-sheet');

        $run = BotcakePsidImportRun::create([
            'status'       => 'running',
            'last_message' => $clean ? 'Queued by scheduler (clean sheet, then import)...' : 'Queued by scheduler...',
        ]);

        dispatch(
            (new ImportBotcakePsidFromGoogleSheet(null, $run->id, $clean))
                ->onConnection('database')
                ->onQueue('default')
        );

        $this->info("PSID import queued (run #{$run->id}).");

        return self::SUCCESS;
    }
}
