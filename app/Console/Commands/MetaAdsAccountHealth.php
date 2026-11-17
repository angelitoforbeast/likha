<?php

namespace App\Console\Commands;

use App\Services\MetaAds\AccountHealth;
use Illuminate\Console\Command;

/**
 * Spec 284: keeps the stored ad-account read (balance, spend cap, status) recent, so the warning on
 * /ads_manager/report is not older than an hour even when nobody opens the Ad Accounts page. Read-only toward Meta.
 */
class MetaAdsAccountHealth extends Command
{
    protected $signature = 'meta-ads:account-health {--force : read even when the stored read is under 10 minutes old}';

    protected $description = 'Read the balance, spend cap and status of every Meta ad account (read-only)';

    public function handle(AccountHealth $health): int
    {
        $data = $this->option('force') ? $health->refresh()['data'] : $health->refreshIfStale();
        if ($data === null) {
            $this->info('Nothing read: no Meta connection, or the stored read is under ' . AccountHealth::FRESH_MINUTES . ' minutes old.');
            return self::SUCCESS;
        }

        $this->info(count($data['rows'] ?? []) . ' ad account(s); ' . count(AccountHealth::warnings($data)) . ' warning(s)');
        foreach ($data['errors'] ?? [] as $e) $this->warn($e);

        return self::SUCCESS;
    }
}
