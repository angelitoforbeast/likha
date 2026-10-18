<?php

namespace App\Console\Commands;

use App\Models\BotcakeChat;
use Illuminate\Console\Command;

/**
 * Binubura ang botcake_chats na mas luma sa 90 araw (araw-araw, 04:25 Manila, via scheduler; sa server na walang
 * schedule:run, isang beses kada araw mula sa chatblast:auto).
 */
class PruneBotcakeChats extends Command
{
    protected $signature   = 'botcake:prune-chats';
    protected $description = 'Delete botcake_chats older than 90 days';

    public function handle(): int
    {
        $deleted = BotcakeChat::prune();
        $this->info("botcake_chats: {$deleted} row(s) na mas luma sa 90 araw ang binura.");

        return self::SUCCESS;
    }
}
