<?php

namespace App\Console\Commands;

use App\Services\RtsProjection;
use Illuminate\Console\Command;

/** Gabi-gabi: kinukuwenta ang p(edad) ng RTS para sa Projected RTS% ng /owner/private at ng rts-check feed. */
class RtsLearn extends Command
{
    protected $signature = 'rts:learn';

    protected $description = 'Learn the RTS rate by order age (pooled and per page+item) from the last 8 weeks of resolved J&T orders';

    public function handle(RtsProjection $projection): int
    {
        $t = $projection->learn();
        $this->info(sprintf('Learned %d pooled age(s), %d item(s) with their own rate.', count($t['pooled']), count($t['items'])));

        return self::SUCCESS;
    }
}
