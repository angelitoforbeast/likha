<?php

namespace Tests\Feature\Chatblast;

use App\Models\PageSetting;
use App\Services\Chatbot\AdGroups;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\PageValues;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use App\Services\PageSettingsGrid;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

/**
 * Ang mga ad group ng isang page (provider "adgroup" sa page_settings): ang pangalan, ang mga ad ID at ang mga value na
 * pumapalit sa page value. Ang page value ang default.
 */
class AdGroupsTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_20_100000_create_botcake_chats_table.php', 'database/migrations/2026_10_23_100000_create_chatbot_tables.php']]);
        $this->page('Page P', '1001');
        $this->page('Page Q', '2002');
    }

    private function ag(): AdGroups
    {
        return app(AdGroups::class);
    }

    public function test_S_172_7_an_ad_is_in_one_group_per_page_but_may_be_in_a_group_of_another_page(): void
    {
        $a = $this->ag()->save('Page P', null, 'Item A', ['111', '222'], ['price' => '499']);
        $this->assertTrue($a['ok']);

        $b = $this->ag()->save('Page P', null, 'Item B', ['111', '333'], []);
        $this->assertFalse($b['ok']);
        $this->assertSame('Ad 111 is already in the group "Item A".', $b['error']);
        $this->assertCount(1, $this->ag()->groups('Page P'), 'walang naisulat na pangalawang group');

        $this->assertTrue($this->ag()->save('Page Q', null, 'Item B', ['111'], [])['ok']);
        // Ang pag-save ulit ng parehong group (kasama ang sarili nitong ad) ay hindi duplicate.
        $this->assertTrue($this->ag()->save('Page P', $a['id'], 'Item A', ['111', '222', '444'], ['price' => '499'])['ok']);
        $this->assertSame(['111', '222', '444'], $this->ag()->groups('Page P')[$a['id']]['ads']);
    }

    public function test_S_172_8_typed_ad_ids_are_split_on_commas_spaces_and_lines_and_non_digits_are_refused(): void
    {
        $this->assertSame(['ids' => ['111', '222', '333'], 'bad' => []], AdGroups::parseAds("111, 222\n333"));
        $this->assertSame(['ids' => ['111'], 'bad' => ['12a', '-5']], AdGroups::parseAds('111 12a, -5 111'));

        $r = $this->ag()->save('Page P', null, 'Item A', ['111', '12a'], []);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('12a', $r['error']);
        $this->assertSame([], $this->ag()->groups('Page P'));
    }

    public function test_S_172_8_ticked_ads_are_added_to_an_existing_or_a_new_group(): void
    {
        $a = $this->ag()->save('Page P', null, 'Item A', ['111'], []);
        $this->assertTrue($this->ag()->addAds('Page P', $a['id'], null, ['555', '666'])['ok']);
        $this->assertSame(['111', '555', '666'], $this->ag()->groups('Page P')[$a['id']]['ads']);

        $new = $this->ag()->addAds('Page P', null, 'Item C', ['777']);
        $this->assertTrue($new['ok']);
        $this->assertSame('Item C', $this->ag()->groups('Page P')[$new['id']]['name']);
        $this->assertSame('Ad 555 is already in the group "Item A".', $this->ag()->addAds('Page P', $new['id'], null, ['555'])['error']);
    }

    public function test_S_172_9_edits_keep_history_restore_brings_back_an_old_value_and_delete_falls_back_to_the_page(): void
    {
        $a = $this->ag()->save('Page P', null, 'Item A', ['111'], ['price' => '499', 'main_flow' => 'X']);
        $this->ag()->save('Page P', $a['id'], 'Item A', ['111'], ['price' => '599', 'main_flow' => '']);

        $g = $this->ag()->groups('Page P')[$a['id']];
        $this->assertSame(['price' => '599'], $g['values'], 'ang blangko ay bumabalik sa page value');
        $history = $this->ag()->history('Page P', $a['id']);
        $old = collect($history)->first(fn ($h) => $h['key'] === 'price' && $h['value'] === '499' && !$h['active']);
        $this->assertNotNull($old, 'ang lumang presyo ay nasa history');
        $this->assertNotNull(collect($history)->first(fn ($h) => $h['key'] === 'main_flow' && !$h['active']), 'ang na-clear ay nasa history');

        $this->assertTrue($this->ag()->restore('Page P', $old['id'])['ok']);
        $this->assertSame('499', $this->ag()->groups('Page P')[$a['id']]['values']['price']);

        $this->ag()->delete('Page P', $a['id']);
        $this->assertSame([], $this->ag()->groups('Page P'));
        $this->assertNull($this->ag()->groupForAd('Page P', '111'));
        $this->assertSame(0, PageSetting::where('provider', AdGroups::PROVIDER)->where('active', true)->count());
        $this->assertGreaterThan(0, PageSetting::where('provider', AdGroups::PROVIDER)->where('active', false)->count(), 'ang na-delete ay may history');

        // Ang restore ng row ng na-delete na group ay hindi bumubuhay ng group na walang pangalan.
        $this->assertFalse($this->ag()->restore('Page P', $old['id'])['ok']);
        // Ang bagong group ay hindi gumagamit ulit ng numero ng na-delete.
        $this->assertNotSame($a['id'], $this->ag()->save('Page P', null, 'Item D', ['111'], [])['id']);
    }

    /** Ang sulat ng ibang worker: diretso sa table, kaya hindi nabubura ang memo ng prosesong ito. */
    private function otherWorkerSetsAds(string $page, int $id, string $ads): void
    {
        $row = PageSetting::where('provider', AdGroups::PROVIDER)->where('key', 'g' . $id . '.ads')->where('active', true)->first();
        \Illuminate\Support\Facades\DB::table('page_settings')->where('id', $row->id)->update(['active' => false, 'replaced_at' => now()]);
        \Illuminate\Support\Facades\DB::table('page_settings')->insert(['page_name' => $page, 'provider' => AdGroups::PROVIDER, 'key' => 'g' . $id . '.ads',
            'value' => $ads, 'is_secret' => false, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_S_172_7_adding_ticked_ads_reads_the_groups_fresh_under_the_lock(): void
    {
        $a = $this->ag()->save('Page P', null, 'Item A', ['111'], [])['id'];
        $b = $this->ag()->save('Page P', null, 'Item B', ['222'], [])['id'];
        $this->ag()->groups('Page P'); // ang memo ng prosesong ito ay may laman na

        // Sabay: idinagdag ng iba ang 555 sa Item B, at ang 444 sa Item A.
        $this->otherWorkerSetsAds('Page P', $b, '222,555');
        $this->otherWorkerSetsAds('Page P', $a, '111,444');
        $this->assertSame('Ad 555 is already in the group "Item B".', $this->ag()->addAds('Page P', $a, null, ['555'])['error'] ?? null);
        $this->assertTrue($this->ag()->addAds('Page P', $a, null, ['333'])['ok']);
        $this->assertSame(['111', '333', '444'], $this->ag()->groups('Page P')[$a]['ads'], 'walang nawalang ad ng iba');
    }

    public function test_S_172_2_only_the_listed_value_keys_are_accepted(): void
    {
        $r = $this->ag()->save('Page P', null, 'Item A', ['111'], ['active' => '0']);
        $this->assertFalse($r['ok']);
        $this->assertFalse($this->ag()->save('Page P', null, 'Item A', ['111'], ['picture' => 'not a link'])['ok']);
        $this->assertFalse($this->ag()->save('Page P', null, 'Item A', ['111'], ['flow_id' => 'abc'])['ok']);
        $this->assertSame('Pick a flow from the list.', $this->ag()->save('Page P', null, 'Item A', ['111'], ['flow_id' => '987654'])['error'] ?? null, 'walang ganoong flow');
        $this->assertFalse($this->ag()->save('Page P', null, '', ['111'], [])['ok'], 'kailangan ang pangalan');
        $this->assertSame([], $this->ag()->groups('Page P'));

        $ok = $this->ag()->save('Page P', null, 'Item A', ['111'], ['item_name_in_jnt_dashboard' => 'Serum', 'price' => '499', 'quantity' => '1',
            'picture' => 'https://x.test/a.jpg', 'main_flow' => 'X', 'main_flow_2' => 'Y', 'flow_id' => (string) \App\Models\ChatbotFlow::create(['name' => 'Serum flow', 'graph' => ['nodes' => [], 'edges' => []]])->id, 'seq_3' => 'S3', 'seq_img_3' => 'https://x.test/3.jpg']);
        $this->assertTrue($ok['ok'], $ok['error'] ?? '');
    }

    public function test_S_172_11_group_rows_stay_out_of_the_page_values_readers_and_the_generic_grid(): void
    {
        $s = app(PageSettings::class);
        $s->set('Page P', 'chatbot', 'price', '199');
        $this->ag()->save('Page P', null, 'Item A', ['111'], ['price' => '499', 'main_flow' => 'X']);

        $values = collect(app(PageValues::class)->read('Page P'))->pluck('value', 'key');
        $this->assertSame('199', $values['price']);
        $this->assertNull($values['main_flow']);
        $this->assertSame('199', app(ChatbotPage::class)->botfields('Page P')['price']);
        $this->assertNotContains('g1.price', array_column(app(ChatbotFields::class)->columns(), 'key'));

        $grid = app(PageSettingsGrid::class)->build();
        $this->assertStringNotContainsString(AdGroups::PROVIDER . '.', json_encode($grid['columns']));
        $this->assertNotNull($s->validate('Page P', AdGroups::PROVIDER, 'g1.price', '1'), 'ang generic grid ay hindi sumusulat ng group');
        $row = PageSetting::where('provider', AdGroups::PROVIDER)->where('key', 'g1.ads')->first();
        $this->actingAs($this->user())->post('/chatblast/page-settings/' . $row->id . '/deactivate')->assertRedirect();
        $this->assertTrue($row->fresh()->active, 'hindi rin nabubura mula sa generic grid');
    }

    // ---- Spec 212: ang ID ay ad, ad set o campaign; iisang group pa rin ang bawat ID ----

    private function catalog(): void
    {
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_05_22_140000_create_ad_catalog_table.php']]);
        foreach ([['40001', '50001', '60001'], ['40002', '50002', '60001']] as [$ad, $set, $camp]) {
            \Illuminate\Support\Facades\DB::table('ad_catalog')->insert(['ad_id' => $ad, 'ad_set_id' => $set, 'campaign_id' => $camp, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_S_212_1_6_an_id_at_any_level_is_in_one_group_only_and_an_ad_plus_its_ad_set_in_two_groups_is_allowed(): void
    {
        $this->catalog();
        $x = $this->ag()->save('Page P', null, 'X', ['60001', '50002', '40001'], [])['id'];
        foreach (['60001' => 'Campaign', '50002' => 'Ad set', '40001' => 'Ad'] as $id => $label) {
            $r = $this->ag()->save('Page P', null, 'Y', [$id], []);
            $this->assertSame([false, $label . ' ' . $id . ' is already in the group "X".', $x], [$r['ok'], $r['error'], $r['group']]);
        }
        $this->assertCount(1, $this->ag()->groups('Page P'));
        // Ang ad sa isang group at ang ad set nito sa iba: puwede (ang ad ang nananaig sa takbo).
        $this->assertTrue($this->ag()->save('Page P', null, 'Its ad set', ['50001'], [])['ok']);
        $this->assertSame($x, $this->ag()->groupForAd('Page P', '40001'));
    }

    public function test_S_212_1_7_a_double_submitted_add_stores_the_id_once_and_says_so(): void
    {
        $a = $this->ag()->save('Page P', null, 'A', ['111'], [])['id'];
        $this->assertSame(['ok' => true, 'id' => $a], $this->ag()->addAds('Page P', $a, null, ['50001']));
        $rows = PageSetting::where('key', 'g' . $a . '.ads')->count();
        $this->assertSame(['ok' => true, 'id' => $a, 'note' => 'Already in this group: 50001.'], $this->ag()->addAds('Page P', $a, null, ['50001']));
        $this->assertSame(['111', '50001'], $this->ag()->groups('Page P')[$a]['ads']);
        $this->assertSame($rows, PageSetting::where('key', 'g' . $a . '.ads')->count(), 'walang bagong row');
    }

    public function test_S_212_8_4_two_saves_of_the_same_ad_set_one_is_refused_and_nothing_half_saved_is_seen(): void
    {
        $a = $this->ag()->save('Page P', null, 'A', ['111'], [])['id'];
        $b = $this->ag()->save('Page P', null, 'B', ['222'], [])['id'];
        $this->ag()->groups('Page P'); // ang memo ng prosesong ito ay luma na pagkatapos ng sulat ng iba
        // Ang ibang worker ay nauna: ang ad set 50001 ay nasa B na.
        $this->otherWorkerSetsAds('Page P', $b, '222,50001');
        $r = $this->ag()->save('Page P', $a, 'A', ['111', '50001'], ['price' => '499']);
        $this->assertSame([false, 'Ad 50001 is already in the group "B".'], [$r['ok'], $r['error']], 'binasa sa loob ng lock, hindi ang lumang memo');
        $this->assertSame([['111'], []], [$this->ag()->groups('Page P')[$a]['ads'], $this->ag()->groups('Page P')[$a]['values']], 'walang kalahating naisulat');
        $this->assertSame($b, $this->ag()->groupForAd('Page P', '50001'));
    }

    public function test_S_212_8_5_a_500_id_group_of_mixed_levels_saves_matches_and_lists(): void
    {
        $this->catalog();
        $ids = array_merge(['50002', '60001'], array_map(fn ($i) => (string) (700000 + $i), range(1, 498)));
        $r = $this->ag()->save('Page P', null, 'Big', $ids, []);
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertCount(500, $this->ag()->groups('Page P')[$r['id']]['ads']);
        $this->assertSame($r['id'], $this->ag()->groupForAd('Page P', '40001'), 'sa campaign');
        $this->assertSame($r['id'], $this->ag()->groupForAd('Page P', '700250'), 'ang ad mismo');
        $this->assertSame('At most 500 ads in a group.', $this->ag()->addAds('Page P', $r['id'], null, ['999999'])['error']);
        $rows = $this->ag()->overview('Page P')['groups'][$r['id']]['rows'];
        $this->assertCount(500, $rows);
        $this->assertSame(['adset', 'campaign'], array_column(array_slice(array_values(array_filter($rows, fn ($x) => in_array($x['ad_id'], ['50002', '60001'], true))), 0, 2), 'kind'));
    }
}
