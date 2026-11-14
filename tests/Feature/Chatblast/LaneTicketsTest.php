<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\BotcakeSendPool;
use Tests\TestCase;

/**
 * A page holds services.botcake.lane_seconds of Botcake sends at its rate (tickets are held until Botcake answers). The
 * default stays 3 s: 10 s on Incepxion slowed every page's replies under load. Both pools count it the same way.
 */
class LaneTicketsTest extends TestCase
{
    public function test_by_default_a_page_holds_three_seconds_of_sends_at_its_rate(): void
    {
        config(['services.botcake.rate_per_page' => 10]);
        $this->assertSame(30, BotcakeSendPool::laneTickets());

        config(['services.botcake.rate_per_page' => 2]);
        $this->assertSame(6, BotcakeSendPool::laneTickets());
    }

    public function test_the_lane_seconds_setting_changes_it_and_never_goes_below_two(): void
    {
        config(['services.botcake.rate_per_page' => 10, 'services.botcake.lane_seconds' => 10]);
        $this->assertSame(100, BotcakeSendPool::laneTickets());

        config(['services.botcake.rate_per_page' => 0.1, 'services.botcake.lane_seconds' => 1]);
        $this->assertSame(2, BotcakeSendPool::laneTickets());
    }
}
