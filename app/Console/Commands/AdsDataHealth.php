<?php

namespace App\Console\Commands;

use App\Services\MetaAds\DataHealth;
use Illuminate\Console\Command;

/**
 * Ang data health ng Ads Manager report: bawat ad account at kung may data kahapon. --record (ang 12:00 na schedule) ay
 * sinusuri ang kahapon at itinatabi; --json ay ipinapakita ang huling naitabi (o, kung wala pa, ang kasalukuyan) nang
 * walang isinusulat, para sa monitoring.
 */
class AdsDataHealth extends Command
{
    protected $signature = 'ads:data-health {--record : suriin ang kahapon at itabi (ang 12:00 na check)} {--json : ang huling check bilang JSON, walang isinusulat}';

    protected $description = 'Check that every ad account has Ads Manager data for yesterday';

    public function handle(): int
    {
        if ($this->option('json')) {
            $this->line(json_encode(DataHealth::last() ?? DataHealth::evaluate(null, false), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $result = $this->option('record') ? DataHealth::record() : DataHealth::evaluate(null, false);
        foreach ($result['accounts'] as $a) {
            $this->line(($a['id'] !== '' ? $a['id'] : '-') . '  ' . $a['name'] . '  ' . $a['status'] . '  (' . $a['source'] . ', ' . $a['rows'] . ' rows, '
                . number_format($a['spend'], 2) . ', latest ' . ($a['latest_day'] ?? '—') . ')');
        }
        $this->info($result['day'] . ': ' . $result['attention'] . ' of ' . count($result['accounts']) . ' ad accounts need attention'
            . ($this->option('record') ? ' (recorded)' : ''));

        return self::SUCCESS;
    }
}
