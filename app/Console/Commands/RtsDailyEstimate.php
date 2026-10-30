<?php

namespace App\Console\Commands;

use App\Services\AnalystRtsDaily;
use Carbon\Carbon;
use Illuminate\Console\Command;

/** Araw-araw 06:00: ang Mira RTS (tantya ng final RTS) ng bawat page+item at ang accuracy ng mga tantya 7 at 14 araw na ang nakalipas. */
class RtsDailyEstimate extends Command
{
    protected $signature = 'rts:daily-estimate {--as-of= : YYYY-MM-DD: ipakita lang ang tantya gamit ang alam noong araw na iyon (walang isinusulat)}';

    protected $description = 'Write the daily Mira RTS estimate per page+item and check the 7- and 14-day-old estimates against actual RTS';

    public function handle(): int
    {
        $now = Carbon::now('Asia/Manila');
        if (AnalystRtsDaily::duringNightStep($now)) {
            $this->error('03:00–03:45 Manila ang night Astra step: hindi tumatakbo ngayon.');

            return self::FAILURE;
        }

        $asOf = $this->option('as-of');
        if ($asOf !== null) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $asOf) || !strtotime((string) $asOf)) {
                $this->error('--as-of ay YYYY-MM-DD.');

                return self::FAILURE;
            }
            $at = Carbon::parse($asOf, 'Asia/Manila')->endOfDay();
            if ($at->gt($now)) $at = $now;
            $rows = (new AnalystRtsDaily())->estimates($at);
            $this->table(['Page', 'Item', 'Price', 'Matched', 'In transit %', 'Projected', 'Estimate'], array_map(
                fn ($r) => [$r['page'], $r['item'], $r['price'], $r['matched'], $r['in_transit_pct'], $r['projected_rts'],
                    $r['estimate'] ?? 'not enough yet'], array_values($rows)));

            return self::SUCCESS;
        }

        $t = microtime(true);
        $sum = (new AnalystRtsDaily())->run();
        $this->info(sprintf('Written %d, kept (analyst) %d, not enough at the latest price %d, skipped %d, failed %d in %.1fs.',
            $sum['written'], $sum['kept'], $sum['low'], $sum['skipped'], $sum['failed'], microtime(true) - $t));

        return self::SUCCESS;
    }
}
