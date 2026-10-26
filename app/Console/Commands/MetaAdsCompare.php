<?php

namespace App\Console\Commands;

use App\Services\MetaAds\Compare;
use App\Services\MetaAds\GraphException;
use App\Services\MetaAds\Sync;
use Illuminate\Console\Command;

/** Shadow: ang Ads Manager report mula sa Meta API laban sa mga upload (ads_manager_reports). Walang isinusulat. */
class MetaAdsCompare extends Command
{
    protected $signature = 'meta-ads:compare {--from= : Y-m-d (default: 6 araw bago ang --to)} {--to= : Y-m-d (default: ngayon, Manila)}';

    protected $description = 'Compare the Meta Ads API report with the uploaded Ads Manager report, per ad account (writes nothing)';

    public function handle(Compare $compare): int
    {
        [$from, $to] = Sync::range($this->option('from') ?: null, $this->option('to') ?: null);

        try {
            $report = $compare->run($from, $to);
        } catch (GraphException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info("Meta API vs uploads, {$from} to {$to}");
        foreach ($report['problems'] as $p) $this->warn($p);
        foreach ($report['accounts'] as $a) {
            $this->line(sprintf('act_%s %s (%s): API %d rows, upload %d rows, same %d; only in API %d, only in upload %d, differing %d; spend API %.2f vs upload %.2f',
                $a['account_id'], $a['name'], $a['who'], $a['api_rows'], $a['upload_rows'], $a['matched'],
                $a['only_api_count'], $a['only_upload_count'], $a['differ_count'], $a['api_spend'], $a['upload_spend']));
            foreach ($a['differ'] as $d) {
                $parts = [];
                foreach ($d['fields'] as $f => $v) $parts[] = "{$f} {$v['api']} vs {$v['upload']}" . ($v['pct'] === null ? '' : " ({$v['pct']}%)");
                $this->line("  {$d['day']} ad {$d['ad_id']}: " . implode('; ', $parts));
            }
        }

        return self::SUCCESS;
    }
}
