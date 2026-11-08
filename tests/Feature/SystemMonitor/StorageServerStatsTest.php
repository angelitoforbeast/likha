<?php

namespace Tests\Feature\SystemMonitor;

use App\Services\ServerStorage;
use Illuminate\Support\Facades\Cache;

/**
 * Spec 243 amendment 243-1, S4: the Storage view also shows the System Monitor's "Server stats" (memory, swap, CPU load
 * with cores, uptime), read by the same code and cache as the CEO's /system-monitor. The numbers come from the fake.
 */
class StorageServerStatsTest extends StorageTestCase
{
    private const URL = '/system-monitor/storage';

    private function stats(array $override = []): array
    {
        return array_merge([
            'memory_total_mb'     => 7963,
            'memory_used_mb'      => 5210,
            'memory_free_mb'      => 412,
            'memory_available_mb' => 2753,
            'memory_used_percent' => 65.4,
            'swap_total_mb'       => 2047,
            'swap_used_mb'        => 128,
            'load_1m'             => 0.52,
            'load_5m'             => 0.4,
            'load_15m'            => 0.35,
            'cpu_count'           => 4,
            'uptime_seconds'      => 3 * 86400 + 5 * 3600 + 7 * 60 + 9,
        ], $override);
    }

    public function test_S4_the_oic_sees_the_server_stats_on_the_storage_view(): void
    {
        $this->diskAt(50);
        $this->storage->server = $this->stats();

        $this->actingAs($this->user('Marketing - OIC'))->get(self::URL)->assertOk()
            ->assertSeeInOrder(['Disk (/)', 'Server stats'])
            ->assertSeeInOrder(['Memory', '5,210 / 7,963 MB', '65.4%'])
            ->assertSeeInOrder(['Memory available', '2.69 GB'])
            ->assertSeeInOrder(['Swap', '128 / 2,047 MB'])
            ->assertSeeInOrder(['CPU load', '0.52 / 0.4 / 0.35', '1 / 5 / 15 min', '4 cores'])
            ->assertSeeInOrder(['Uptime', '3d 5h 7m']);
    }

    public function test_S4_memory_available_under_1_gb_is_shown_in_mb_and_short_uptimes_drop_the_days(): void
    {
        $this->storage->server = $this->stats(['memory_available_mb' => 812, 'uptime_seconds' => 2 * 3600 + 15 * 60]);

        $this->actingAs($this->user('CEO'))->get(self::URL)->assertOk()
            ->assertSeeInOrder(['Memory available', '812 MB'])
            ->assertSeeInOrder(['Uptime', '2h 15m']);
    }

    public function test_S4_the_section_follows_the_storage_link_like_the_rest_of_the_view(): void
    {
        $this->storage->server = $this->stats();

        $this->actingAs($this->user('Marketing'))->get(self::URL)->assertNotFound();
        $this->assertSame(0, $this->storage->serverCalls, 'nothing is read for a refused user');

        $this->setStorageAccess('Marketing', true);
        $this->actingAs($this->user('Marketing'))->get(self::URL)->assertOk()->assertSee('Server stats')->assertSee('4 cores');
    }

    public function test_S4_unreadable_stats_say_not_available_and_the_page_renders(): void
    {
        $this->diskAt(50);
        $this->storage->server = ServerStorage::emptyServerStats(); // no /proc, no sys_getloadavg

        $html = $this->actingAs($this->user('Marketing - OIC'))->get(self::URL)->assertOk()
            ->assertSeeInOrder(['Server stats', 'Memory', 'Not available', 'Memory available', 'Not available', 'Swap', 'Not available', 'CPU load', 'Not available', 'Uptime', 'Not available'])
            ->getContent();
        $this->assertStringNotContainsString(' cores', $html);
        $this->assertStringContainsString('100.0 GB', $html, 'the disk still shows');
    }

    public function test_S4_one_stat_missing_says_not_available_only_on_its_line(): void
    {
        $this->storage->server = $this->stats(['load_1m' => null, 'load_5m' => null, 'load_15m' => null, 'cpu_count' => 0]);

        $this->actingAs($this->user('Marketing - OIC'))->get(self::URL)->assertOk()
            ->assertSeeInOrder(['Memory', '5,210 / 7,963 MB'])
            ->assertSeeInOrder(['CPU load', 'Not available', 'Uptime', '3d 5h 7m'])
            ->assertDontSee(' cores');
    }

    public function test_S4_the_view_shares_the_ceo_monitors_10_second_cache(): void
    {
        $this->storage->server = $this->stats();
        $this->actingAs($this->user('Marketing - OIC'));

        $this->get(self::URL)->assertOk();
        $this->get(self::URL)->assertOk();
        $this->assertSame(1, $this->storage->serverCalls, 'one read per 10 seconds');
        $this->assertSame($this->stats(), Cache::get('sysmon_server'), 'the same cache entry as /system-monitor/data');

        $this->travel(11)->seconds();
        $this->get(self::URL)->assertOk();
        $this->assertSame(2, $this->storage->serverCalls);
    }

    public function test_S4_the_ceo_monitor_reads_the_same_shared_code(): void
    {
        $this->storage->server = $this->stats();
        $controller = app(\App\Http\Controllers\SystemMonitorController::class);

        $read = (new \ReflectionMethod($controller, 'getServerStats'))->invoke($controller);

        $this->assertSame($this->stats(), $read, 'the CEO monitor gets the same numbers, same keys');
        $this->assertSame(1, $this->storage->serverCalls);
        $this->assertSame(array_keys($this->stats()), array_keys(ServerStorage::emptyServerStats()), 'the shape /system-monitor/data sends');
    }

    public function test_S4_the_database_tables_and_processes_stay_on_the_ceo_monitor(): void
    {
        $this->storage->server = $this->stats();

        $this->actingAs($this->user('Marketing - OIC'))->get(self::URL)->assertOk()
            ->assertDontSee('Database tables')->assertDontSee('PHP processes')->assertDontSee('File storage');
        $this->get('/system-monitor')->assertNotFound();
    }
}
