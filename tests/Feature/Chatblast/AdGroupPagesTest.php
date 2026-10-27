<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\BotcakePsid;
use App\Models\PageSetting;
use App\Services\Chatbot\AdGroups;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * Ang Ad groups ng isang page sa /chatbot/pages (/chatbot/ad-groups): ang mga group, ang "Ads not in a group yet", at
 * ang mga sulat. Parehong access ng page values (NavAccess /chatblast).
 */
class AdGroupPagesTest extends ChatblastTestCase
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
        $this->page('Page P', '1001');
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

    private function chat(string $psid, string $ad, string $pageId = '1001', int $daysAgo = 0): void
    {
        BotcakeChat::create(['page_id' => $pageId, 'page_name' => $pageId === '1001' ? 'Page P' : 'Page Q', 'psid' => $psid, 'name' => 'C ' . $psid,
            'message' => 'Hi', 'payload' => ['ad_id' => $ad], 'received_at' => now()->subDays($daysAgo)]);
    }

    public function test_S_172_8_ads_not_in_a_group_show_names_customers_and_last_time_and_ticked_ones_join_a_group(): void
    {
        $this->actingAs($this->user());
        foreach (['7001', '7002', '7003'] as $p) $this->chat($p, '555', daysAgo: 2);
        $this->chat('7001', '555'); // parehong customer: isa pa rin
        $this->chat('7004', '666', daysAgo: 1);
        BotcakePsid::create(['page' => 'Page P', 'psid' => '7005', 'fb_name' => 'E', 'status' => 'matched', 'ad_id' => '666', 'last_interactive_at' => now()->subDays(3)]);
        $this->chat('7006', '777', daysAgo: 40);       // lampas 30 araw
        $this->chat('7007', '888', '2002');            // ibang page
        $this->chat('7008', '111');                    // nasa group na
        DB::table('ads_manager_reports')->insert(['page_name' => 'Page P', 'campaign_name' => 'Serum Sale', 'ad_set_name' => 'Women 25+', 'headline' => 'Glow', 'ad_id' => '555']);
        $a = app(AdGroups::class)->save('Page P', null, 'Item A', ['111'], [])['id'];

        $data = $this->getJson('/chatbot/ad-groups?page=Page%20P')->assertOk()->json();
        $this->assertSame([
            ['ad_id' => '555', 'name' => 'Serum Sale / Women 25+ / Glow', 'customers' => 3, 'last' => '2026-10-20 10:00'],
            ['ad_id' => '666', 'name' => null, 'customers' => 2, 'last' => '2026-10-19 10:00'],
        ], $data['unassigned']);
        $this->assertSame(['Item A'], array_column($data['groups'], 'name'));
        $this->assertSame(['666'], array_column($this->getJson('/chatbot/ad-groups?page=Page%20P&q=66')->json('unassigned'), 'ad_id'));
        $this->assertSame(['555'], array_column($this->getJson('/chatbot/ad-groups?page=Page%20P&q=serum')->json('unassigned'), 'ad_id'));

        $this->postJson('/chatbot/ad-groups/add', ['page' => 'Page P', 'id' => $a, 'ads' => ['555', '666']])->assertOk();
        $this->assertSame(['111', '555', '666'], app(AdGroups::class)->groups('Page P')[$a]['ads']);
        $this->postJson('/chatbot/ad-groups/add', ['page' => 'Page P', 'name' => 'Item B', 'ads' => ['555']])
            ->assertStatus(422)->assertJson(['error' => 'Ad 555 is already in the group "Item A".']);
    }

    public function test_S_172_8_typed_ads_are_parsed_and_non_digits_refused_on_save(): void
    {
        $this->actingAs($this->user());
        $this->postJson('/chatbot/ad-groups', ['page' => 'Page P', 'name' => 'Item A', 'ads' => "111, abc\n333"])
            ->assertStatus(422)->assertJson(['error' => 'Not an ad ID (digits only): abc.']);
        $id = $this->postJson('/chatbot/ad-groups', ['page' => 'Page P', 'name' => 'Item A', 'ads' => "111, 222\n333", 'values' => ['price' => '499', 'main_flow' => '']])
            ->assertOk()->json('id');
        $g = app(AdGroups::class)->groups('Page P')[$id];
        $this->assertSame([['111', '222', '333'], ['price' => '499']], [$g['ads'], $g['values']]);

        $this->postJson('/chatbot/ad-groups', ['page' => 'No Such Page', 'name' => 'X', 'ads' => '1'])->assertNotFound();
    }

    public function test_S_172_9_history_restore_and_delete_through_the_page(): void
    {
        $this->actingAs($this->user());
        $id = $this->postJson('/chatbot/ad-groups', ['page' => 'Page P', 'name' => 'Item A', 'ads' => '111', 'values' => ['price' => '499']])->json('id');
        $this->postJson('/chatbot/ad-groups', ['page' => 'Page P', 'id' => $id, 'name' => 'Item A', 'ads' => '111', 'values' => ['price' => '599']])->assertOk();
        $old = collect($this->getJson('/chatbot/ad-groups/history?page=Page%20P&id=' . $id)->assertOk()->json('rows'))->firstWhere('value', '499');
        $this->assertFalse($old['active']);
        $this->postJson('/chatbot/ad-groups/restore', ['page' => 'Page P', 'setting' => $old['id']])->assertOk();
        $this->assertSame('499', app(AdGroups::class)->groups('Page P')[$id]['values']['price']);
        $this->postJson('/chatbot/ad-groups/delete', ['page' => 'Page P', 'id' => $id])->assertOk();
        $this->assertSame([], app(AdGroups::class)->groups('Page P'));
    }

    public function test_S_172_9_restore_delete_and_history_stay_on_the_posted_page_and_keep_the_one_group_rule(): void
    {
        $this->actingAs($this->user());
        $groups = app(AdGroups::class);
        $q = $groups->save('Page Q', null, 'Q item', ['900'], ['price' => '1'])['id'];
        $groups->save('Page Q', $q, 'Q item', ['900'], ['price' => '2']);
        $qOld = PageSetting::where('page_name', 'Page Q')->where('key', 'g' . $q . '.price')->where('active', false)->value('id');
        $p = $groups->save('Page P', null, 'Item A', ['111'], [])['id'];
        $before = PageSetting::count();

        $this->postJson('/chatbot/ad-groups/restore', ['page' => 'Page P', 'setting' => $qOld])->assertStatus(422);
        $this->postJson('/chatbot/ad-groups/delete', ['page' => 'Page P', 'id' => $q === $p ? 99 : $q])->assertStatus(422);
        $this->assertSame([], $this->getJson('/chatbot/ad-groups/history?page=Page%20P&id=' . ($q === $p ? 99 : $q))->json('rows'));
        $this->assertSame([$before, '2'], [PageSetting::count(), $groups->groups('Page Q')[$q]['values']['price']], 'walang nagalaw sa ibang page');

        // Ang lumang ad list na may ad na nasa ibang group na: tinatanggihan, may pangalan ng group.
        $groups->save('Page P', $p, 'Item A', ['111', '555'], []);
        $groups->save('Page P', $p, 'Item A', ['111'], []);
        $groups->save('Page P', null, 'Item B', ['555'], []);
        $oldAds = PageSetting::where('page_name', 'Page P')->where('key', 'g' . $p . '.ads')->where('value', '111,555')->value('id');
        $this->postJson('/chatbot/ad-groups/restore', ['page' => 'Page P', 'setting' => $oldAds])
            ->assertStatus(422)->assertJson(['error' => 'Ad 555 is already in the group "Item B".']);

        // Ang version ng na-delete na group ay hindi na naibabalik.
        $this->postJson('/chatbot/ad-groups/delete', ['page' => 'Page P', 'id' => $p])->assertOk();
        $this->postJson('/chatbot/ad-groups/restore', ['page' => 'Page P', 'setting' => $oldAds])->assertStatus(422)->assertJson(['error' => 'This group was deleted.']);
        // Ang array sa halip na text: 422, hindi 500.
        $this->postJson('/chatbot/ad-groups', ['page' => 'Page P', 'name' => ['x'], 'ads' => ['1']])->assertStatus(422);
    }

    public function test_S_172_10_only_users_who_can_edit_page_values_see_or_write_ad_groups(): void
    {
        $id = app(AdGroups::class)->save('Page P', null, 'Item A', ['111'], [])['id'];
        $before = PageSetting::count();
        $calls = [
            ['get', '/chatbot/ad-groups?page=Page%20P', []],
            ['post', '/chatbot/ad-groups', ['page' => 'Page P', 'name' => 'Item B', 'ads' => '222']],
            ['post', '/chatbot/ad-groups/add', ['page' => 'Page P', 'id' => $id, 'ads' => ['333']]],
            ['post', '/chatbot/ad-groups/delete', ['page' => 'Page P', 'id' => $id]],
            ['get', '/chatbot/ad-groups/history?page=Page%20P&id=' . $id, []],
            ['post', '/chatbot/ad-groups/restore', ['page' => 'Page P', 'setting' => 1]],
        ];
        foreach ($calls as [$m, $url]) $this->json($m, $url)->assertUnauthorized();
        $this->actingAs($this->user('Data Encoder', 'enc@example.test'));
        foreach ($calls as [$m, $url, $body]) $this->json($m, $url, $body)->assertForbidden();
        $this->assertSame($before, PageSetting::count(), 'walang naisulat');

        // Ang role na may access sa /chatblast (gaya ng pag-edit ng page values) ay nakakasulat.
        $this->navLink('/chatblast', ['Marketing']);
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $this->getJson('/chatbot/ad-groups?page=Page%20P')->assertOk();
        $this->postJson('/chatbot/ad-groups', ['page' => 'Page P', 'name' => 'Item B', 'ads' => '222'])->assertOk();
        $this->get('/chatbot/pages')->assertOk()->assertSee('data-ag-url', false)->assertSee('js/chatbot-adgroups.js', false);
    }
}
