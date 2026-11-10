<?php

namespace App\Console\Commands;

use App\Models\ChatblastBroadcast;
use App\Services\Broadcast;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Hands the waiting rows of the sending broadcasts (spec 263) to the chatblast sender, under each page's hourly cap and
 * the in-flight limit. Every minute (routes/console.php); does nothing while no broadcast is sending.
 */
class FeedBroadcasts extends Command
{
    protected $signature   = 'chatblast:broadcast-feed';
    protected $description = 'Hand waiting broadcast rows to the chatblast sender (per-page hourly cap)';

    public function handle(Broadcast $broadcast): int
    {
        if (!Schema::hasColumn('chatblast_campaigns', 'broadcast')) return self::SUCCESS; // not migrated yet
        if (!ChatblastBroadcast::exists()) return self::SUCCESS;
        $this->line($broadcast->feed() . ' handed out');

        return self::SUCCESS;
    }
}
