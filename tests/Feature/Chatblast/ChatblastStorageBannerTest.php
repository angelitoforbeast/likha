<?php

namespace Tests\Feature\Chatblast;

use App\Services\ServerStorage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\SystemMonitor\FakeServerStorage;

/**
 * Spec 242 S2 on the Chatblast/Chatbot app shell (layouts/chatblast): the same storage banner as on <x-layout>, at the
 * top under the app bar and above the problems banner. The TV screen (layouts/chatbot-tv) stays without it.
 */
class ChatblastStorageBannerTest extends ChatblastTestCase
{
    private const GB = 1024 ** 3;

    private FakeServerStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2025_07_15_232022_create_ads_manager_reports_table.php',
            'database/migrations/2025_07_17_222429_add_headline_and_body_to_ads_manager_reports_table.php',
            'database/migrations/2025_07_17_224216_rename_body_to_body_ad_settings_in_ads_manager_reports_table.php',
        ]]);
        $this->storage = new FakeServerStorage();
        $this->app->instance(ServerStorage::class, $this->storage);
        $this->navLink('/chatblast', ['Marketing - OIC', 'Marketing']);
    }

    private function navLink(string $url, array $roles): void
    {
        if (!Schema::hasTable('nav_links')) {
            Artisan::call('migrate', ['--path' => 'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php', '--force' => true]);
        }
        $id = DB::table('nav_links')->insertGetId([
            'key' => 'chatblast', 'label' => 'Chatblast', 'route_url' => $url, 'icon' => 'fa-solid fa-link', 'active_pattern' => 'chatblast*',
            'is_active' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($roles as $role) {
            DB::table('nav_link_role_visibility')->insert(['nav_link_id' => $id, 'role' => $role, 'is_visible' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function diskAt(float $percent, float $totalGb = 200): void
    {
        $total = $totalGb * self::GB;
        $free  = $total * (100 - $percent) / 100;
        $this->storage->disk = ['total' => $total, 'free' => $free, 'used' => $total - $free, 'percent' => $percent];
    }

    public static function pages(): array
    {
        return ['chatblast' => ['/chatblast'], 'chatbot' => ['/chatbot/pages']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_S2_the_marketing_oic_sees_the_banner_on_chatblast_and_chatbot_at_80_percent(string $url): void
    {
        $this->diskAt(85); // 30 GB free

        $html = $this->actingAs($this->user('Marketing - OIC', 'oic@example.test'))->get($url)->assertOk()->getContent();

        $this->assertStringContainsString('Server storage is at 85% (30.0 GB free).', $html);
        $this->assertStringContainsString('data-storage-banner="amber"', $html);
        $this->assertStringContainsString('href="' . route('system.monitor.storage') . '"', $html);
        // At the top: under the app bar, before the page content.
        $this->assertLessThan(strpos($html, 'data-storage-banner'), strpos($html, '</header>'));
        $this->assertLessThan(strpos($html, '<main'), strpos($html, 'data-storage-banner'));
    }

    public function test_S2_red_at_90_percent_on_chatblast(): void
    {
        $this->diskAt(95);

        $this->actingAs($this->user('Marketing - OIC', 'oic@example.test'))->get('/chatblast')->assertOk()
            ->assertSee('Server storage is at 95% (10.0 GB free).')
            ->assertSee('data-storage-banner="red"', false);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_S2_another_role_never_sees_it_on_chatblast_and_chatbot(string $url): void
    {
        $this->diskAt(95);

        $html = $this->actingAs($this->user('Marketing', 'mkt@example.test'))->get($url)->assertOk()->getContent();

        $this->assertStringNotContainsString('data-storage-banner', $html);
        $this->assertStringNotContainsString('Server storage is at', $html);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function test_S2_under_80_percent_nothing_on_chatblast_and_chatbot(string $url): void
    {
        $this->diskAt(79.9);

        $html = $this->actingAs($this->user('Marketing - OIC', 'oic@example.test'))->get($url)->assertOk()->getContent();

        $this->assertStringNotContainsString('data-storage-banner', $html);
    }

    public function test_S2_without_a_banner_the_chatblast_page_is_byte_for_byte_as_before(): void
    {
        // The same page for the same user, with the disk unreadable and with the banner rendered then removed: equal.
        $user = $this->user('Marketing - OIC', 'oic@example.test');
        $this->actingAs($user);
        $without = $this->get('/chatblast')->assertOk()->getContent();

        cache()->flush();
        $this->diskAt(85);
        $with = $this->get('/chatblast')->assertOk()->getContent();

        $stripped = preg_replace('#    <div data-storage-banner=.*?</div>\n#s', '', $with, 1);
        $strip = fn ($h) => preg_replace('/(name="csrf-token" content=")[^"]*"|(_token" value=")[^"]*"/', '$1$2"', $h);
        $this->assertSame($strip($without), $strip($stripped));
    }
}
