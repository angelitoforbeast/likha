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
            'database/migrations/2025_07_17_224216_rename_body_to_body_ad_settings_in_ads_manager_reports_table.php',
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

    private function chat(string $psid, string $ad, int $daysAgo = 0, string $pageId = '1001'): void
    {
        BotcakeChat::create(['page_id' => $pageId, 'page_name' => $pageId === '1001' ? 'Precious Lopez' : 'Page Q', 'psid' => $psid, 'name' => 'C ' . $psid,
            'message' => 'Hi', 'payload' => ['ad_id' => $ad], 'received_at' => now()->subDays($daysAgo)]);
    }

    /** Isang araw ng ad sa ads_manager_reports: ang gastos at ang delivery ng campaign at ad set. */
    private function report(string $ad, string $day, float $spend, string $delivery = 'active', string $campaign = 'Serum Sale'): void
    {
        DB::table('ads_manager_reports')->insert(['page_name' => 'Precious Lopez', 'day' => $day, 'campaign_name' => $campaign, 'ad_set_name' => 'Women 25+',
            'headline' => 'Ad ' . $ad, 'ad_id' => $ad, 'campaign_id' => 'c' . $ad, 'ad_set_id' => 's' . $ad, 'amount_spent_php' => $spend,
            'campaign_delivery' => $delivery === 'active' ? 'active' : 'inactive', 'ad_set_delivery' => $delivery]);
    }

    /** @param array<int, string> $days Y-m-d */
    private function reports(string $ad, string $from, string $to, float $spend, string $delivery = 'active'): void
    {
        for ($d = \Carbon\Carbon::parse($from); $d->lte($to); $d->addDay()) $this->report($ad, $d->toDateString(), $spend, $delivery);
    }

    public function test_S_174_7_ad_status_comes_from_the_reports_and_a_group_with_every_ad_off_says_so(): void
    {
        $this->actingAs($this->user());
        $this->reports('501', '2026-10-10', '2026-10-19', 120);                     // may gastos sa huling araw
        $this->reports('502', '2026-10-10', '2026-10-14', 80);                      // huling gastos 5 araw bago ang huling araw
        $this->reports('502', '2026-10-15', '2026-10-19', 0, 'inactive');
        $this->report('503', '2026-10-19', 40);                                     // unang araw sa report: kahapon
        $this->reports('505', '2026-10-12', '2026-10-16', 0);                       // hindi kailanman gumastos
        $this->reports('505', '2026-10-17', '2026-10-19', 0, 'not_delivering');
        $this->report('506', '2026-10-01', 10);                                     // aktibo pa, walang gastos ngayon
        $this->report('506', '2026-10-19', 0);
        $this->reports('507', '2026-10-01', '2026-10-14', 60);                      // na-pause: wala nang row pagkatapos ng 10-14
        $this->chat('7001', '504', daysAgo: 10);                                    // wala sa reports
        $this->chat('7002', '504', daysAgo: 2);
        $groups = app(AdGroups::class);
        $groups->setMode('Precious Lopez', true);
        $a = $groups->save('Precious Lopez', null, 'Serum', ['501', '502', '503', '504', '506', '507'], [])['id'];
        $b = $groups->save('Precious Lopez', null, 'Old toner', ['502', '505'], [])['id'] ?? null;
        $this->assertNull($b, 'ang 502 ay nasa Serum na');
        $b = $groups->save('Precious Lopez', null, 'Old toner', ['505'], [])['id'];

        $data = $this->getJson('/chatbot/ad-groups?page=Precious%20Lopez')->assertOk()->json();
        $this->assertSame('2026-10-19', $data['as_of']);
        $ads = collect($data['groups'])->firstWhere('id', $a)['ads'];
        $pick = fn (array $r) => [$r['ad_id'], $r['status'], $r['off_since'], $r['off_days'], $r['new']];
        $this->assertSame([
            ['501', 'on', null, null, false],
            ['502', 'off', '2026-10-15', 5, false],
            ['503', 'on', null, null, true],
            ['504', 'none', null, null, false],
            ['506', 'on', null, null, false],
            ['507', 'off', '2026-10-15', 5, false],
        ], array_map($pick, $ads));
        // Ang ad na wala sa reports ay may customers pa rin; ang pangalan ay mula sa reports.
        $this->assertSame(['ad_id' => '504', 'name' => null, 'customers' => 2, 'last' => '2026-10-18 10:00'], array_intersect_key($ads[3], array_flip(['ad_id', 'name', 'customers', 'last'])));
        $this->assertSame('Serum Sale / Women 25+ / Ad 501', $ads[0]['name']);
        $toner = collect($data['groups'])->firstWhere('id', $b);
        $this->assertSame([['505', 'off', '2026-10-17', 3]], array_map(fn ($r) => array_slice($pick($r), 0, 4), $toner['ads']));
        $this->assertSame([false, true], [collect($data['groups'])->firstWhere('id', $a)['all_off'], $toner['all_off']]);
    }

    public function test_S_174_6_ads_not_in_a_group_show_status_sorted_new_then_on_then_customers_and_the_new_count_is_right(): void
    {
        $this->actingAs($this->user());
        foreach (['7001', '7002', '7003', '7004', '7005'] as $i => $p) $this->chat($p, '601', daysAgo: 20 - $i);
        $this->reports('601', '2026-09-01', '2026-10-19', 50);                      // matagal na, On
        $this->chat('7010', '602', daysAgo: 1);
        $this->report('602', '2026-10-19', 30);                                     // bago (kahapon lang sa report), On
        foreach (['7020', '7021', '7022'] as $p) $this->chat($p, '603', daysAgo: 6);
        $this->reports('603', '2026-09-01', '2026-10-10', 20);                      // Off
        $this->reports('603', '2026-10-11', '2026-10-19', 0, 'inactive');
        $this->chat('7030', '604', daysAgo: 1);                                     // wala sa reports, unang customer kahapon: bago
        foreach (['7040', '7041'] as $p) $this->chat($p, '605', daysAgo: 3);
        $this->reports('605', '2026-09-01', '2026-10-19', 10);
        $this->chat('7050', '606', daysAgo: 1, pageId: '2002');                     // ibang page
        $groups = app(AdGroups::class);
        $groups->setMode('Precious Lopez', true);
        $a = $groups->save('Precious Lopez', null, 'Serum', ['999'], [])['id'];

        $data = $this->getJson('/chatbot/ad-groups?page=Precious%20Lopez')->assertOk()->json();
        $this->assertSame([['602', 'on', true, 1], ['604', 'none', true, 1], ['601', 'on', false, 5], ['605', 'on', false, 2], ['603', 'off', false, 3]],
            array_map(fn ($r) => [$r['ad_id'], $r['status'], $r['new'], $r['customers']], $data['unassigned']));
        $this->assertSame(2, $data['new_unassigned']);
        $this->assertSame(2, collect($this->getJson('/chatbot/data')->json('rows'))->firstWhere('page', 'Precious Lopez')['ad_new']);
        $this->assertNull(collect($this->getJson('/chatbot/data')->json('rows'))->firstWhere('page', 'Page Q')['ad_new'], 'Page-based: walang badge');
        $this->assertSame(2, $this->getJson('/chatbot/ad-groups?page=Precious%20Lopez&q=605')->json('new_unassigned'), 'ang bilang ay hindi ayon sa hanap');

        $this->postJson('/chatbot/ad-groups/add', ['page' => 'Precious Lopez', 'id' => $a, 'ads' => ['601', '602']])->assertOk();
        $data = $this->getJson('/chatbot/ad-groups?page=Precious%20Lopez')->json();
        $this->assertSame(['601', '602', '999'], array_column(collect($data['groups'])->firstWhere('id', $a)['ads'], 'ad_id'));
        $this->assertSame(['604', '605', '603'], array_column($data['unassigned'], 'ad_id'));
        $this->assertSame(1, $data['new_unassigned']);
    }

    public function test_S_174_3_a_group_cell_saves_with_history_and_restore_and_page_only_keys_are_refused(): void
    {
        $this->actingAs($this->user());
        $groups = app(AdGroups::class);
        $id = $groups->save('Precious Lopez', null, 'Serum', ['111'], ['price' => '499'])['id'];
        $post = fn (array $b) => $this->postJson('/chatbot/ad-groups/value', ['page' => 'Precious Lopez', 'id' => $id] + $b);

        $post(['key' => 'price', 'value' => '599'])->assertOk();
        $post(['key' => 'main_flow', 'value' => 'Hello serum'])->assertOk();
        $this->assertSame(['price' => '599', 'main_flow' => 'Hello serum'], $groups->groups('Precious Lopez')[$id]['values']);
        $this->assertSame(['Serum', ['111']], [$groups->groups('Precious Lopez')[$id]['name'], $groups->groups('Precious Lopez')[$id]['ads']], 'ang pangalan at ads ay hindi nagalaw');

        $old = collect($this->getJson('/chatbot/ad-groups/history?page=Precious%20Lopez&id=' . $id)->json('rows'))->first(fn ($r) => $r['key'] === 'price' && $r['value'] === '499');
        $this->assertFalse($old['active']);
        $this->postJson('/chatbot/ad-groups/restore', ['page' => 'Precious Lopez', 'setting' => $old['id']])->assertOk();
        $this->assertSame('499', $groups->groups('Precious Lopez')[$id]['values']['price']);

        // Blangko = bumalik sa default (page value).
        $post(['key' => 'main_flow', 'value' => ''])->assertOk();
        $this->assertArrayNotHasKey('main_flow', $groups->groups('Precious Lopez')[$id]['values']);
        $post(['key' => 'active', 'value' => '0'])->assertStatus(422)->assertJson(['error' => 'active' . AdGroups::PAGE_ONLY]);
        $post(['key' => 'price', 'value' => ['x']])->assertStatus(422);
        $this->postJson('/chatbot/ad-groups/value', ['page' => 'Precious Lopez', 'id' => 99, 'key' => 'price', 'value' => '1'])->assertStatus(422)->assertJson(['error' => 'This group was deleted.']);

        $before = PageSetting::count();
        $this->actingAs($this->user('Data Encoder', 'enc@example.test'));
        $post(['key' => 'price', 'value' => '1'])->assertForbidden();
        $this->postJson('/chatbot/ad-groups/remove-ad', ['page' => 'Precious Lopez', 'id' => $id, 'ad' => '111'])->assertForbidden();
        $this->assertSame($before, PageSetting::count());
    }

    public function test_S_174_4_removing_an_ad_id_from_a_group_keeps_history(): void
    {
        $this->actingAs($this->user());
        $groups = app(AdGroups::class);
        $id = $groups->save('Precious Lopez', null, 'Serum', ['111', '222'], [])['id'];

        $this->postJson('/chatbot/ad-groups/remove-ad', ['page' => 'Precious Lopez', 'id' => $id, 'ad' => '222'])->assertOk();
        $this->assertSame(['111'], $groups->groups('Precious Lopez')[$id]['ads']);
        $this->assertSame([['111,222', false], ['111', true]], PageSetting::where('key', 'g' . $id . '.ads')->orderBy('id')->get()->map(fn ($s) => [$s->value, (bool) $s->active])->all());
        $this->postJson('/chatbot/ad-groups/remove-ad', ['page' => 'Precious Lopez', 'id' => $id, 'ad' => '222'])->assertStatus(422)->assertJson(['error' => 'Ad 222 is not in this group.']);
    }

    public function test_S_174_5_typed_ad_ids_are_added_to_a_group_non_digits_and_ads_of_another_group_are_refused(): void
    {
        $this->actingAs($this->user());
        $groups = app(AdGroups::class);
        $id = $groups->save('Precious Lopez', null, 'Serum', ['100'], [])['id'];
        $groups->save('Precious Lopez', null, 'Toner', ['444'], []);
        $add = fn (string $text) => $this->postJson('/chatbot/ad-groups/add', ['page' => 'Precious Lopez', 'id' => $id, 'ads' => $text]);

        $add("111, 222\n333")->assertOk();
        $this->assertSame(['100', '111', '222', '333'], $groups->groups('Precious Lopez')[$id]['ads']);
        $add('555, 12a')->assertStatus(422)->assertJson(['error' => 'Not an ID (digits only): 12a.']);
        $add('555 444')->assertStatus(422)->assertJson(['error' => 'Ad 444 is already in the group "Toner".']);
        $add(' , ')->assertStatus(422);
        $this->assertSame(['100', '111', '222', '333'], $groups->groups('Precious Lopez')[$id]['ads'], 'walang naidagdag sa mga tinanggihan');
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
