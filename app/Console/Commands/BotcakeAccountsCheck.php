<?php

namespace App\Console\Commands;

use App\Services\BotcakeAccounts;
use Illuminate\Console\Command;

/**
 * The daily check of the saved Botcake accounts (spec 272): list_access_token per account, recording OK with the page
 * count or failed with the reason, so a dead token shows red on /chatbot/botcake-accounts. It never writes a page token:
 * a page token change affects live sending, so that stays the "Update tokens in Likha" button.
 */
class BotcakeAccountsCheck extends Command
{
    protected $signature = 'botcake:accounts-check';

    protected $description = 'Check every saved Botcake account Access Token against Botcake (writes no page token)';

    public function handle(BotcakeAccounts $accounts): int
    {
        $counts = $accounts->checkAll();
        $this->info('Botcake accounts: '.$counts['ok'].' OK, '.$counts['failed'].' failed');

        return self::SUCCESS;
    }
}
