<?php

namespace App\Console\Commands;

use App\Services\MetaAds\Connections;
use App\Services\MetaAds\Sync;
use Illuminate\Console\Command;

/**
 * Ang mga ad account na binabasa ng Meta Ads Sync at Compare (parehong pili ng CEO sa /ads_manager/report). Tanggap
 * lang ang ID na nasa isang connection. Walang token na ipinapakita.
 */
class MetaAdsAccounts extends Command
{
    protected $signature = 'meta-ads:accounts {--list : ilista ang bawat ad account at kung pinili} {--set= : ID,ID,… ang bagong pili}';

    protected $description = 'List or set the Meta ad accounts that Sync and Compare read';

    public function handle(Connections $connections, Sync $sync): int
    {
        $all = $connections->accounts();

        if ($this->option('set') !== null) {
            $unknown = $sync->chooseAccounts(explode(',', (string) $this->option('set')), null);
            if ($unknown) {
                $this->error('Not in any connection: ' . implode(', ', $unknown) . ' (nothing changed)');
                return self::FAILURE;
            }
            $this->info('Selected ' . count(Sync::accounts()) . ' of ' . count($all) . ' ad accounts: ' . implode(', ', Sync::accounts()));
        }

        if ($this->option('list') || $this->option('set') === null) {
            $selected = array_flip(Sync::accounts());
            foreach ($all as $a) {
                $this->line($a['id'] . '  ' . $a['name'] . '  ' . (isset($selected[$a['id']]) ? 'yes' : 'no') . '  (' . implode(', ', $a['who']) . ')');
            }
            $this->info(count($selected) . ' of ' . count($all) . ' ad accounts selected');
        }

        return self::SUCCESS;
    }
}
