<?php

namespace Tests\Feature\OrderEncode;

use App\Services\OrderEncode\LocationStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/** order-encode:sync-locations: ang LocationData ng dalawang sheet bilang file; araw-araw 04:10 Manila. */
class SyncLocationsTest extends OrderEncodeTestCase
{
    private function sheets(array $macro, array $after): void
    {
        $this->google->put(self::MACRO, 'LocationData!A2:D', $macro);
        $this->google->put(self::AFTER, 'LocationData!B2:D', $after);
    }

    public function test_S_130_29_it_stores_each_list_in_sheet_order_and_says_when_the_two_are_identical(): void
    {
        $this->configurePage('shadow', lists: false);
        $this->sheets(Fixtures::macroRows(), array_map(fn ($r) => array_slice($r, 1), Fixtures::macroRows()));

        $this->assertSame(0, Artisan::call('order-encode:sync-locations'));

        $this->assertSame(Fixtures::macroRows(), $this->store->read(LocationStore::MACRO, self::MACRO));
        $this->assertSame(array_map(fn ($r) => array_slice($r, 1), Fixtures::macroRows()), $this->store->read(LocationStore::AFTER, self::AFTER));
        $this->assertStringContainsString('identical', Artisan::output());
    }

    public function test_S_130_30_it_says_when_the_lists_differ(): void
    {
        $this->configurePage('shadow', lists: false);
        $this->sheets(Fixtures::macroRows(), Fixtures::checkerRows());

        Artisan::call('order-encode:sync-locations');

        $this->assertStringContainsString('NOT identical', Artisan::output());
    }

    public function test_S_130_31_pages_that_are_off_or_a_failed_or_empty_read_leave_the_files_alone(): void
    {
        $this->configurePage('off', lists: false);
        $this->store->write(LocationStore::AFTER, self::AFTER, [['KEEP', 'ME', 'PLEASE']]);

        $this->assertSame(0, Artisan::call('order-encode:sync-locations'));
        $this->assertSame([], $this->google->calls);

        $this->configurePage('shadow', lists: false);
        $this->sheets(Fixtures::macroRows(), []); // walang laman: hindi ino-overwrite
        $this->assertSame(1, Artisan::call('order-encode:sync-locations'));
        $this->assertSame([['KEEP', 'ME', 'PLEASE']], $this->store->read(LocationStore::AFTER, self::AFTER));

        $this->google->fail = true;
        $this->assertSame(1, Artisan::call('order-encode:sync-locations'));
    }

    public function test_S_130_32_the_commands_are_scheduled_at_04_10_daily_and_every_ten_minutes(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
        $sync = $events->first(fn ($e) => str_contains($e->command, 'order-encode:sync-locations'));
        $compare = $events->first(fn ($e) => str_contains($e->command, 'order-encode:compare'));

        $this->assertSame(['10 4 * * *', 'Asia/Manila'], [$sync->expression, (string) $sync->timezone]);
        $this->assertSame('*/10 * * * *', $compare->expression);
    }
}
