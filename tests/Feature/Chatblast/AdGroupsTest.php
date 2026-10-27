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
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_20_100000_create_botcake_chats_table.php']]);
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

    public function test_S_172_2_only_the_listed_value_keys_are_accepted(): void
    {
        $r = $this->ag()->save('Page P', null, 'Item A', ['111'], ['fill_up_form' => 'x']);
        $this->assertFalse($r['ok']);
        $this->assertFalse($this->ag()->save('Page P', null, 'Item A', ['111'], ['picture' => 'not a link'])['ok']);
        $this->assertFalse($this->ag()->save('Page P', null, 'Item A', ['111'], ['flow_id' => 'abc'])['ok']);
        $this->assertFalse($this->ag()->save('Page P', null, '', ['111'], [])['ok'], 'kailangan ang pangalan');
        $this->assertSame([], $this->ag()->groups('Page P'));

        $ok = $this->ag()->save('Page P', null, 'Item A', ['111'], ['item_name_in_jnt_dashboard' => 'Serum', 'price' => '499', 'quantity' => '1',
            'picture' => 'https://x.test/a.jpg', 'main_flow' => 'X', 'main_flow_2' => 'Y', 'flow_id' => '7', 'seq_3' => 'S3', 'seq_img_3' => 'https://x.test/3.jpg']);
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
}
