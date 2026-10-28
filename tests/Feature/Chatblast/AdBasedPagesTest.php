<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\PageSetting;
use App\Services\Chatbot\AdGroups;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * Page-based o Ad-based na reply values sa /chatbot/pages (spec 174): ang switch kada page, ang mga group sa loob ng
 * row, ang mga ad ID ng group na may status, at ang "Ads not in a group yet". Parehong access ng page values.
 */
class AdBasedPagesTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2025_07_15_232022_create_ads_manager_reports_table.php',
            'database/migrations/2025_07_17_222429_add_headline_and_body_to_ads_manager_reports_table.php',
        ]]);
        $this->travelTo('2026-10-20 10:00:00');
        $this->page('Precious Lopez', '1001');
        $this->page('Page Q', '2002');
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

    private function modeRows(): array
    {
        return PageSetting::where('page_name', 'Precious Lopez')->where('provider', AdGroups::PROVIDER)->where('key', 'mode')
            ->orderBy('id')->get()->map(fn ($s) => [$s->value, (bool) $s->active])->all();
    }

    public function test_S_174_1_every_page_starts_page_based_an_editor_switches_it_with_history_and_others_get_403(): void
    {
        $this->actingAs($this->user());
        $this->assertSame(['Page Q' => 'page', 'Precious Lopez' => 'page'], array_column($this->getJson('/chatbot/data')->assertOk()->json('rows'), 'reply', 'page'));
        $this->assertSame('page', $this->getJson('/chatbot/ad-groups?page=Precious%20Lopez')->assertOk()->json('mode'));

        $this->postJson('/chatbot/ad-groups/mode', ['page' => 'Precious Lopez', 'mode' => 'ad'])->assertOk()->assertJson(['mode' => 'ad']);
        $this->assertSame([['ad', true]], $this->modeRows(), 'isang setting row');
        $this->assertTrue(app(AdGroups::class)->adBased('Precious Lopez'));
        $this->assertSame(['Page Q' => 'page', 'Precious Lopez' => 'ad'], array_column($this->getJson('/chatbot/data')->json('rows'), 'reply', 'page'));
        $this->postJson('/chatbot/ad-groups/mode', ['page' => 'Precious Lopez', 'mode' => 'ad'])->assertOk();
        $this->assertSame([['ad', true]], $this->modeRows(), 'parehong mode: walang bagong row');

        $this->postJson('/chatbot/ad-groups/mode', ['page' => 'Precious Lopez', 'mode' => 'page'])->assertOk()->assertJson(['mode' => 'page']);
        $this->assertSame([['ad', false]], $this->modeRows(), 'history: ang row ay naiiwang inactive');
        $this->assertNotNull(PageSetting::where('provider', AdGroups::PROVIDER)->where('key', 'mode')->value('replaced_at'));
        $this->postJson('/chatbot/ad-groups/mode', ['page' => 'Precious Lopez', 'mode' => 'other'])->assertStatus(422);

        // Ang hindi makapag-edit ng page values: 403, walang naisulat.
        $before = PageSetting::count();
        $this->actingAs($this->user('Data Encoder', 'enc@example.test'));
        $this->postJson('/chatbot/ad-groups/mode', ['page' => 'Precious Lopez', 'mode' => 'ad'])->assertForbidden();
        $this->assertSame($before, PageSetting::count());
        $this->navLink('/chatblast', ['Marketing']);
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $this->postJson('/chatbot/ad-groups/mode', ['page' => 'Precious Lopez', 'mode' => 'ad'])->assertOk();
    }
}
