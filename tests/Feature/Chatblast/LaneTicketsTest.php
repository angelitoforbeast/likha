<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\BotcakeSendPool;
use Tests\TestCase;

/**
 * A page holds 10 seconds of Botcake sends at its rate (tickets are held until Botcake answers), so a 5-7 s Botcake at
 * peak does not cap one busy page below its rate. Both the send pool and the sequence pool count it the same way.
 */
class LaneTicketsTest extends TestCase
{
    public function test_by_default_a_page_holds_ten_seconds_of_sends_at_its_rate(): void
    {
        config(['services.botcake.rate_per_page' => 10]);
        $this->assertSame(100, BotcakeSendPool::laneTickets());

        config(['services.botcake.rate_per_page' => 2]);
        $this->assertSame(20, BotcakeSendPool::laneTickets());
    }

    public function test_the_lane_seconds_setting_changes_it_and_never_goes_below_two(): void
    {
        config(['services.botcake.rate_per_page' => 10, 'services.botcake.lane_seconds' => 3]);
        $this->assertSame(30, BotcakeSendPool::laneTickets(), 'the old 3 s');

        config(['services.botcake.rate_per_page' => 0.1, 'services.botcake.lane_seconds' => 1]);
        $this->assertSame(2, BotcakeSendPool::laneTickets());
    }
}
