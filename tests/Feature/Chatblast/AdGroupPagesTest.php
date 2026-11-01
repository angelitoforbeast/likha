<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\BotcakePsid;
use App\Models\PageSetting;
use App\Services\Chatbot\AdGroups;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
            'database/migrations/2025_07_17_224216_rename_body_to_body_ad_settings_in_ads_manager_reports_table.php',
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
        ], array_map(fn ($r) => array_intersect_key($r, array_flip(['ad_id', 'name', 'customers', 'last'])), $data['unassigned']));
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
            ->assertStatus(422)->assertJson(['error' => 'Not an ID (digits only): abc.']);
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

    // ---- Spec 212: ang uri ng bawat ID, ang roll-up, ang "Ads not in a group yet" na walang sakop na ad, at ang tahimik ----

    private function catalogRow(string $ad, ?string $adset, ?string $campaign, ?string $adsetName = null, ?string $campaignName = null): void
    {
        DB::table('ad_catalog')->insert(['ad_id' => $ad, 'ad_set_id' => $adset, 'campaign_id' => $campaign, 'ad_set_name' => $adsetName,
            'campaign_name' => $campaignName, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Isang araw ng ad sa reports; $spend > 0 = On sa araw na iyon. */
    private function report(string $ad, string $day, float $spend, string $campaignName = 'Serum Sale', string $adsetName = 'Women 25+'): void
    {
        DB::table('ads_manager_reports')->insert(['page_name' => 'Page P', 'day' => $day, 'campaign_name' => $campaignName, 'ad_set_name' => $adsetName,
            'headline' => 'Ad ' . $ad, 'ad_id' => $ad, 'amount_spent_php' => $spend, 'campaign_delivery' => $spend > 0 ? 'active' : 'inactive', 'ad_set_delivery' => $spend > 0 ? 'active' : 'inactive']);
    }

    /** Ang ad na huling gumastos $lastSpendDaysBefore araw bago ang 2026-10-19 (ang as_of): Off nang ganoong bilang ng araw. */
    private function offFor(string $ad, int $days): void
    {
        $this->report($ad, \Carbon\Carbon::parse('2026-10-19')->subDays($days)->toDateString(), 50);
        $this->report($ad, '2026-10-19', 0);
    }

    /** Ang group na ginawa $daysAgo araw na ang nakaraan. */
    private function groupAt(int $daysAgo, string $name, array $ids, array $values = []): int
    {
        $now = now()->copy();
        $this->travelTo($now->copy()->subDays($daysAgo));
        $r = app(AdGroups::class)->save('Page P', null, $name, $ids, $values);
        $this->travelTo($now);
        $this->assertTrue($r['ok'], $r['error'] ?? '');

        return $r['id'];
    }

    private function panel(string $q = ''): array
    {
        \Illuminate\Support\Facades\Cache::flush();
        \App\Services\PageSettings::forget('Page P');

        return $this->getJson('/chatbot/ad-groups?page=Page%20P' . ($q !== '' ? '&q=' . $q : ''))->assertOk()->json();
    }

    private function migrateCatalog(): void
    {
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_05_22_140000_create_ad_catalog_table.php']]);
    }

    public function test_S_212_4_1_4_2_and_4_3_each_id_shows_its_kind_its_own_name_and_rolled_up_status_and_customers(): void
    {
        $this->migrateCatalog();
        $this->actingAs($this->user());
        $this->catalogRow('40001', '50001', '60001', 'Kababaihan 25+', 'Serum Sale 🌸 <b>');
        $this->catalogRow('40002', '50002', '60001', 'Kalalakihan ñ', 'Serum Sale 🌸 <b>');
        $this->report('40001', '2026-10-19', 80);                     // On
        $this->report('40002', '2026-10-10', 30);                     // Off mula 10-11
        $this->report('40002', '2026-10-19', 0);
        $this->chat('7001', '40001');
        $this->chat('7001', '40002', daysAgo: 1);                     // parehong customer sa dalawang ad
        $this->chat('7002', '40002', daysAgo: 2);
        $id = app(AdGroups::class)->save('Page P', null, 'Serum', ['40001', '50002', '60001', '999999'], [])['id'];

        $rows = collect($this->panel()['groups'])->firstWhere('id', $id)['ads'];
        $pick = fn ($r) => [$r['ad_id'], $r['kind'], $r['name'], $r['status'], $r['off_since'], $r['customers'], $r['last']];
        $this->assertSame([
            ['40001', 'ad', 'Serum Sale / Women 25+ / Ad 40001', 'on', null, 1, '2026-10-20 10:00'],
            ['50002', 'adset', 'Kalalakihan ñ', 'off', '2026-10-11', 2, '2026-10-19 10:00'],
            ['60001', 'campaign', 'Serum Sale 🌸 <b>', 'on', null, 2, '2026-10-20 10:00'],
            ['999999', 'unknown', null, 'none', null, 0, null],
        ], array_map($pick, $rows));
        // Ang hindi kilalang ID ay naka-save at naaalis.
        $this->postJson('/chatbot/ad-groups/remove-ad', ['page' => 'Page P', 'id' => $id, 'ad' => '999999'])->assertOk();
        $this->assertSame(['40001', '50002', '60001'], app(AdGroups::class)->groups('Page P')[$id]['ads']);
    }

    public function test_S_212_4_7_and_2_10_opening_the_panel_never_calls_meta_and_shows_no_token(): void
    {
        $this->migrateCatalog();
        Http::fake(['graph.facebook.com/*' => fn () => throw new \RuntimeException('Meta must not be called')]);
        $token = 'EAApaneltesttokenSECRETxyz0123456789';
        app(\App\Services\MetaAds\Connections::class)->save(1, $token, ['fb_user_name' => 'Owner', 'ad_accounts' => []]);
        $this->actingAs($this->user());
        app(AdGroups::class)->setMode('Page P', true);
        app(AdGroups::class)->save('Page P', null, 'By campaign', ['60001'], []);
        $this->chat('7001', '48888');                                 // bagong ad: walang alam na campaign
        $body = $this->getJson('/chatbot/ad-groups?page=Page%20P')->assertOk()->getContent();
        Http::assertNothingSent();
        $this->assertStringNotContainsString($token, $body);
        $this->assertSame(['48888'], array_column(json_decode($body, true)['unassigned'], 'ad_id'), 'hindi kilala ang campaign: nakalista pa');
    }

    public function test_S_212_5_1_to_5_7_ads_covered_by_a_grouped_ad_set_or_campaign_are_not_listed(): void
    {
        $this->migrateCatalog();
        $this->actingAs($this->user());
        app(AdGroups::class)->setMode('Page P', true);
        $this->catalogRow('40001', '50001', '60001');
        $this->catalogRow('40002', '50002', '60001');
        $this->chat('7001', '40001');                                 // sakop ng ad set 50001
        $this->chat('7002', '40002');                                 // kapatid na ad set: hindi sakop
        $this->chat('7003', '40099');                                 // hindi kilala ang ad set at campaign
        $id = app(AdGroups::class)->save('Page P', null, 'By ad set', ['50001'], [])['id'];

        $data = $this->panel();
        $this->assertSame(['40002', '40099'], array_column($data['unassigned'], 'ad_id'));
        $this->assertSame([2, 2], [$data['unassigned_total'], $data['new_unassigned']], 'ang bilang ay sa listahang walang sakop');
        $this->assertSame(2, collect($this->getJson('/chatbot/data')->json('rows'))->firstWhere('page', 'Page P')['ad_new']);
        $this->assertSame([], $this->panel('40001')['unassigned'], 'ang hanap ay sa nakalista lang');
        $this->assertSame(['40002'], array_column($this->panel('40002')['unassigned'], 'ad_id'));
        $this->assertSame(2, $this->panel('400')['unassigned_total'], 'q=400: ang dalawang nakalista lang, hindi ang sakop');

        // Ang sakop na ad ay puwedeng idagdag nang mano-mano sa ibang group.
        $this->postJson('/chatbot/ad-groups', ['page' => 'Page P', 'name' => 'Exact', 'ads' => '40001'])->assertOk();
        $this->postJson('/chatbot/ad-groups/delete', ['page' => 'Page P', 'id' => app(AdGroups::class)->groupForAd('Page P', '40001')])->assertOk();
        // Alisin ang ad set sa group: nakalista ulit ang ad.
        $this->postJson('/chatbot/ad-groups/remove-ad', ['page' => 'Page P', 'id' => $id, 'ad' => '50001'])->assertOk();
        $this->assertSame(['40001', '40002', '40099'], array_column($this->panel()['unassigned'], 'ad_id'));
    }

    public function test_S_212_6_1_to_6_4_6_8_and_6_9_quiet_groups_hide_and_quiet_ids_fold(): void
    {
        $this->migrateCatalog();
        $this->actingAs($this->user());
        $this->report('41000', '2026-10-19', 90);                     // ang as_of ay 2026-10-19
        $this->offFor('41001', 10);
        $this->chat('7001', '41001', daysAgo: 10);
        $quiet = $this->groupAt(30, 'Quiet', ['41001']);              // 6.1
        $this->offFor('41002', 10);
        $this->chat('7002', '41002', daysAgo: 3);
        $talked = $this->groupAt(30, 'Talked', ['41002']);            // 6.2
        $this->offFor('41003', 6);
        $six = $this->groupAt(30, 'Off 6 days', ['41003']);           // 6.3
        $this->offFor('41004', 7);
        $seven = $this->groupAt(30, 'Off 7 days', ['41004']);
        $this->offFor('41005', 9);
        $mixed = $this->groupAt(30, 'One on', ['41000', '41005']);    // 6.4
        $empty = $this->groupAt(30, 'Empty', []);                     // 6.8
        // 6.9: isang campaign na Off ang lahat at walang mensahe, at isang ad set na may On na ad.
        $this->catalogRow('41006', '51006', '61006');
        $this->offFor('41006', 12);
        $this->catalogRow('41007', '51007', '61007');
        $this->report('41007', '2026-10-19', 20);
        $levels = $this->groupAt(30, 'Levels', ['61006', '51007']);

        $data = $this->panel();
        $g = collect($data['groups'])->keyBy('id');
        $this->assertSame([true, false, false, true, false, false, false],
            [$g[$quiet]['hidden'], $g[$talked]['hidden'], $g[$six]['hidden'], $g[$seven]['hidden'], $g[$mixed]['hidden'], $g[$empty]['hidden'], $g[$levels]['hidden']]);
        $this->assertSame([10, 6, 7], [$g[$quiet]['ads'][0]['off_days'], $g[$six]['ads'][0]['off_days'], $g[$seven]['ads'][0]['off_days']]);
        $this->assertSame([false, true], array_column($g[$mixed]['ads'], 'quiet'), 'ang On ay nakikita, ang tahimik ay nakatiklop');
        $this->assertSame([['51007', 'adset', false], ['61006', 'campaign', true]], array_map(fn ($r) => [$r['ad_id'], $r['kind'], $r['quiet']], $g[$levels]['ads']));
        $this->assertSame(['groups' => 2, 'ids' => 2, 'unassigned' => 0], $data['hidden']);
    }

    public function test_S_212_6_5_6_6_6_7_and_6_10_hiding_follows_the_data_writes_nothing_and_keeps_routing(): void
    {
        $this->migrateCatalog();
        $this->actingAs($this->user());
        app(AdGroups::class)->setMode('Page P', true);
        $this->report('41000', '2026-10-19', 90);
        $this->offFor('41001', 10);
        $quiet = $this->groupAt(30, 'Quiet', ['41001']);
        $noData = $this->groupAt(30, 'No report data', ['42001']);    // 6.6: walang report, walang mensahe
        $before = PageSetting::count();
        $g = fn () => collect($this->panel()['groups'])->keyBy('id');
        $this->assertSame([true, true], [$g()[$quiet]['hidden'], $g()[$noData]['hidden']]);
        $this->assertSame($before, PageSetting::count(), 'ang pagtatago ay walang isinusulat');
        $this->assertSame($quiet, app(AdGroups::class)->groupForAd('Page P', '41001'), 'ang nakatagong group ay sumasagot pa rin');

        // 6.5: may mensahe ulit, o bumalik sa On: nakikita sa susunod na load.
        $this->chat('7009', '42001', daysAgo: 2);
        $this->report('41001', '2026-10-19', 40);
        $this->assertSame([false, false], [$g()[$quiet]['hidden'], $g()[$noData]['hidden']]);

        // 6.10: tumigil ang sync (ang huling report ay 20 araw na): ang On noon ay On pa rin, hindi bagong tago.
        DB::table('ads_manager_reports')->delete();
        $this->report('43001', '2026-09-30', 50);
        $stale = $this->groupAt(30, 'Stale sync', ['43001']);
        $this->assertSame(['on', false], [$g()[$stale]['ads'][0]['status'], $g()[$stale]['hidden']]);
    }

    public function test_S_212_7_1_to_7_3_7_6_and_7_7_new_and_hand_added_stay_visible_then_fold_like_any_other(): void
    {
        $this->migrateCatalog();
        $this->actingAs($this->user());
        $this->report('41000', '2026-10-19', 90);
        $this->offFor('41001', 10);
        $fresh = app(AdGroups::class)->save('Page P', null, 'Just made', ['44001'], [])['id'];
        $old = $this->groupAt(30, 'Old', ['41001']);
        $g = fn () => collect($this->panel()['groups'])->keyBy('id');
        $this->assertSame([false, true], [$g()[$fresh]['hidden'], $g()[$old]['hidden']], '7.1: ang bago ay nakikita');

        // 7.7: ang ID na nasa nakatagong group ay tinatanggihan, may pangalan at numero ng group.
        $this->postJson('/chatbot/ad-groups/add', ['page' => 'Page P', 'id' => $fresh, 'ads' => '41001'])
            ->assertStatus(422)->assertJson(['error' => 'Ad 41001 is already in the group "Old".', 'group' => $old]);
        // 7.2: idinagdag nang mano-mano sa nakatagong group: nakikita ulit; ang lumang tahimik na ID ay nakatiklop pa rin.
        $this->postJson('/chatbot/ad-groups/add', ['page' => 'Page P', 'id' => $old, 'ads' => '44002'])->assertOk();
        $this->assertFalse($g()[$old]['hidden']);
        $this->assertSame([['41001', true], ['44002', false]], array_map(fn ($r) => [$r['ad_id'], $r['quiet']], $g()[$old]['ads']));
        // 7.6: Rename, History, Remove at Delete ay gaya ng dati sa nakatagong group.
        $this->travel(8)->days();
        $this->assertSame([true, true], [$g()[$fresh]['hidden'], $g()[$old]['hidden']], '7.3: lampas 7 araw, walang traffic: nakatiklop');
        $this->postJson('/chatbot/ad-groups', ['page' => 'Page P', 'id' => $old, 'name' => 'Old renamed', 'ads' => '41001,44002'])->assertOk();
        $this->assertNotEmpty($this->getJson('/chatbot/ad-groups/history?page=Page%20P&id=' . $old)->json('rows'));
        $this->postJson('/chatbot/ad-groups/remove-ad', ['page' => 'Page P', 'id' => $old, 'ad' => '44002'])->assertOk();
        $this->postJson('/chatbot/ad-groups/delete', ['page' => 'Page P', 'id' => $old])->assertOk();
        $this->assertArrayNotHasKey($old, app(AdGroups::class)->groups('Page P'));
    }

    public function test_S_212_6_11_and_7_8_two_hundred_groups_count_right_and_the_badge_ignores_hiding(): void
    {
        $this->migrateCatalog();
        $this->actingAs($this->user());
        app(AdGroups::class)->setMode('Page P', true);
        $this->report('41000', '2026-10-19', 90);
        $this->travelTo('2026-09-20 10:00:00');
        // G1: isang On na ad; G2–G10: may mensahe kahapon; G11–G200: walang report at walang mensahe (tahimik).
        foreach (range(1, 200) as $i) app(AdGroups::class)->save('Page P', null, 'G' . $i, [$i === 1 ? '41000' : (string) (($i <= 10 ? 450000 : 460000) + $i)], []);
        $this->travelTo('2026-10-20 10:00:00');
        $this->chat('7001', '45555', daysAgo: 1);                     // bagong ad na wala sa group (New)
        foreach (range(2, 10) as $i) $this->chat('80' . $i, (string) (450000 + $i), daysAgo: 1);
        $data = $this->panel();
        $this->assertCount(200, $data['groups']);
        $this->assertSame(['groups' => 190, 'ids' => 0, 'unassigned' => 0], $data['hidden']);
        $this->assertSame(10, count(array_filter($data['groups'], fn ($g) => !$g['hidden'])), 'maikli ang pangunahing tingin');
        $this->assertSame([1, 1], [$data['new_unassigned'], collect($this->getJson('/chatbot/data')->json('rows'))->firstWhere('page', 'Page P')['ad_new']]);
    }

    public function test_S_212_1_12_a_user_without_chatblast_access_gets_403_on_every_ad_group_route_and_nothing_is_written(): void
    {
        $id = app(AdGroups::class)->save('Page P', null, 'Item A', ['111'], [])['id'];
        $before = PageSetting::count();
        $this->actingAs($this->user('Data Encoder', 'enc@example.test'));
        foreach ([
            ['get', '/chatbot/ad-groups?page=Page%20P', []],
            ['post', '/chatbot/ad-groups', ['page' => 'Page P', 'name' => 'Item B', 'ads' => '60001']],
            ['post', '/chatbot/ad-groups/add', ['page' => 'Page P', 'id' => $id, 'ads' => '50001']],
            ['post', '/chatbot/ad-groups/delete', ['page' => 'Page P', 'id' => $id]],
            ['get', '/chatbot/ad-groups/history?page=Page%20P&id=' . $id, []],
            ['post', '/chatbot/ad-groups/restore', ['page' => 'Page P', 'setting' => 1]],
            ['post', '/chatbot/ad-groups/value', ['page' => 'Page P', 'id' => $id, 'key' => 'price', 'value' => '1']],
            ['post', '/chatbot/ad-groups/remove-ad', ['page' => 'Page P', 'id' => $id, 'ad' => '111']],
            ['get', '/chatbot/ad-groups/ad?page=Page%20P&ad=111', []],
            ['post', '/chatbot/ad-groups/mode', ['page' => 'Page P', 'mode' => 'ad']],
        ] as [$m, $url, $body]) $this->json($m, $url, $body)->assertForbidden();
        $this->assertSame($before, PageSetting::count(), 'walang naisulat');
    }
}
