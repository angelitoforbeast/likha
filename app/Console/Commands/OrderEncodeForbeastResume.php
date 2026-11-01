<?php

namespace App\Console\Commands;

use App\Services\OrderEncode\ForbeastOrders;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 211: ang bantay ng mga padalang naka-pause (tinanggihan ng gateway ang site key, 401/403). Kapag lipas na ang pause
 * ng isang gateway, ang pinakalumang naka-pause ay ipinapadala ulit bilang probe; kapag tinanggap, ang lahat ng iba ay
 * ipinapadala. Walang ginagawa kapag nakapatay ang padala (walang go-live) o walang naka-pause.
 */
class OrderEncodeForbeastResume extends Command
{
    protected $signature = 'order-encode:forbeast-resume';
    protected $description = 'Probe the gateways whose order sends to Forbeast are paused, and resume them when the site key works again (spec 211)';

    public function handle(ForbeastOrders $orders): int
    {
        if (!Schema::hasTable('order_encodes') || !Schema::hasColumn('order_encodes', 'forbeast_state')) return self::SUCCESS;

        $n = $orders->probe();
        if ($n > 0) $this->info($n . ' paused gateway(s) probed.');

        return self::SUCCESS;
    }
}
