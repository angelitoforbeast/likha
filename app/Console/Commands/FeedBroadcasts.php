<?php

namespace App\Console\Commands;

use App\Models\ChatblastBroadcast;
use App\Services\Broadcast;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Hands the waiting rows of the sending broadcasts (spec 263) to the chatblast sender, under each page's hourly cap and
 * the in-flight limit. Started every minute (routes/console.php) and feeds several times inside that minute (every
 * --every seconds, --loops times), so a page gets new rows as soon as its in-flight rows finish instead of once a
 * minute. The Botcake pace per page still decides how fast rows really go out. Does nothing while no broadcast is sending.
 */
class FeedBroadcasts extends Command
{
    protected $signature   = 'chatblast:broadcast-feed {--loops=6 : Feeds inside one run} {--every=10 : Seconds between feeds}';
    protected $description = 'Hand waiting broadcast rows to the chatblast sender (per-page hourly cap)';

    /** A run never feeds past this many seconds, so it ends before the next minute's run starts. */
    private const WINDOW = 55;

    public function handle(Broadcast $broadcast): int
    {
        if (!Schema::hasColumn('chatblast_campaigns', 'broadcast')) return self::SUCCESS; // not migrated yet
        $loops = max(1, min(12, (int) $this->option('loops')));
        $every = max(0, min(30, (int) $this->option('every')));
        $start = microtime(true);
        $total = 0;

        for ($i = 0; $i < $loops; $i++) {
            if ($i > 0) {
                $next = $start + $i * $every;
                if ($next - $start > self::WINDOW) break;
                if (($wait = $next - microtime(true)) > 0) usleep((int) ($wait * 1_000_000));
            }
            if (!ChatblastBroadcast::exists()) break;
            $total += $broadcast->feed();
        }
        $this->line($total . ' handed out');

        return self::SUCCESS;
    }
}
