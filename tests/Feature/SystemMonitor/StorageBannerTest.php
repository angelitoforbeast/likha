<?php

namespace Tests\Feature\SystemMonitor;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/** Spec 242 S2: the storage banner on top of every <x-layout> page at 80 % or more. */
class StorageBannerTest extends StorageTestCase
{
    private function page(?string $role): string
    {
        $this->actingAs($this->user($role));

        return (string) $this->blade('<x-layout>body</x-layout>');
    }

    public static function seeingRoles(): array
    {
        return [
            'ceo'        => ['CEO'],
            'oic'        => ['Marketing - OIC'],
            'oic spaced' => [' marketing  - oic'],
        ];
    }

    #[DataProvider('seeingRoles')]
    public function test_S2_1_amber_banner_from_80_to_under_90_percent(string $role): void
    {
        $this->diskAt(85); // 200 GB disk: 30 GB free

        $html = $this->page($role);

        $this->assertStringContainsString('Server storage is at 85% (30.0 GB free).', $html);
        $this->assertStringContainsString('data-storage-banner="amber"', $html);
        $this->assertStringContainsString('href="' . route('system.monitor.storage') . '"', $html);
    }

    public function test_S2_1_exactly_80_percent_is_amber_and_89_9_is_still_amber(): void
    {
        $this->diskAt(80);
        $this->assertStringContainsString('data-storage-banner="amber"', $this->page('CEO'));

        cache()->flush();
        $this->diskAt(89.9);
        $html = $this->page('CEO');
        $this->assertStringContainsString('data-storage-banner="amber"', $html);
        $this->assertStringContainsString('Server storage is at 89%', $html);
    }

    #[DataProvider('seeingRoles')]
    public function test_S2_2_red_banner_at_90_percent_or_more(string $role): void
    {
        $this->diskAt(95); // 10 GB free

        $html = $this->page($role);

        $this->assertStringContainsString('Server storage is at 95% (10.0 GB free).', $html);
        $this->assertStringContainsString('data-storage-banner="red"', $html);
        $this->assertStringNotContainsString('data-storage-banner="amber"', $html);

        cache()->flush();
        $this->diskAt(90);
        $this->assertStringContainsString('data-storage-banner="red"', $this->page($role));
    }

    public static function otherRoles(): array
    {
        return [
            'marketing' => ['Marketing'],
            'encoder'   => ['Encoder'],
            'no role'   => [null],
        ];
    }

    #[DataProvider('otherRoles')]
    public function test_S2_3_other_roles_never_see_it(?string $role): void
    {
        $this->diskAt(95);

        $html = $this->page($role);

        $this->assertStringNotContainsString('data-storage-banner', $html);
        $this->assertStringNotContainsString('Server storage is at', $html);
        $this->assertSame(0, $this->storage->diskCalls, 'the disk is not even read for them');
    }

    public function test_S2_4_under_80_percent_nothing_is_rendered(): void
    {
        $this->diskAt(79.9);

        $html = $this->page('CEO');

        $this->assertStringNotContainsString('data-storage-banner', $html);
        $this->assertStringNotContainsString('Server storage is at', $html);
        $this->assertStringContainsString('body', $html);
    }

    public function test_S2_5_a_failed_measurement_shows_no_banner_and_the_page_renders(): void
    {
        $this->storage->disk = null; // the disk functions returned false
        $html = $this->page('CEO');
        $this->assertStringNotContainsString('data-storage-banner', $html);
        $this->assertStringContainsString('body', $html);

        cache()->flush();
        $this->storage->diskThrows = true;
        $html = $this->page('Marketing - OIC');
        $this->assertStringNotContainsString('data-storage-banner', $html);
        $this->assertStringContainsString('body', $html);
    }

    public function test_S2_5_the_banner_is_on_a_real_page_request(): void
    {
        $this->diskAt(92);

        $this->actingAs($this->user('CEO'))
            ->get('/system-monitor')
            ->assertOk()
            ->assertSee('Server storage is at 92% (16.0 GB free).')
            ->assertSee('System Monitor (CEO)');
    }

    public function test_S2_6_the_check_is_cached_and_never_scans_or_queries_information_schema(): void
    {
        $this->diskAt(85);
        $queries = [];
        DB::listen(function ($q) use (&$queries) { $queries[] = $q->sql; });

        $this->page('CEO');
        $this->page('Marketing - OIC');
        $this->page('CEO');

        $this->assertSame(1, $this->storage->diskCalls, 'one disk read per 60 seconds, not per page');
        $this->travel(61)->seconds();
        $this->page('CEO');
        $this->assertSame(2, $this->storage->diskCalls, 'read again once the 60 seconds are over');
        $this->assertSame(0, $this->storage->databaseCalls);
        $this->assertSame(0, $this->storage->binaryLogCalls);
        $this->assertSame([], array_values(array_filter($queries, fn ($sql) => stripos($sql, 'information_schema') !== false || stripos($sql, 'binary logs') !== false)));
    }
}
