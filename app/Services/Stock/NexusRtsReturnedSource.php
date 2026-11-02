<?php

namespace App\Services\Stock;

/**
 * Returned mula sa Nexus RTS (GET /api/rts-returns). Wala pa ang endpoint: stub lang, laging "not available".
 * Kapag pinili ito ng owner habang stub pa, ang returned sa /item ay "hindi available" (walang balik sa J&T).
 */
class NexusRtsReturnedSource implements ReturnedSource
{
    public function key(): string
    {
        return 'nexus';
    }

    public function returned(string $start, string $until): ReturnedResult
    {
        return ReturnedResult::unavailable('not available');
    }
}
