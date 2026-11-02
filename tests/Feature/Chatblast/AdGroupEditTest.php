<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Models\PageSetting;
use App\Services\Chatbot\AdGroups;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\FlowGraph;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Spec 217: ang Edit ng ad group (ilang value sa isang Save, sa parehong endpoint ng value ng group, parehong access at
 * history) at ang mga group at ad na tumatakbo muna.
 */
class AdGroupEditTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_25_100000_create_chatbot_waits_table.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
            'database/migrations/2025_07_15_232022_create_ads_manager_reports_table.php',
            'database/migrations/2025_07_17_222429_add_headline_and_body_to_ads_manager_reports_table.php',
            'database/migrations/2026_05_22_140000_create_ad_catalog_table.php',
        ]]);
        Http::fake();
        $this->travelTo('2026-10-20 10:00:00');
        $this->page('Page P', '1001');
        foreach (['main_flow' => 'Page MF', 'price' => '199', 'seq_1' => 'Page Seq'] as $k => $v) app(PageSettings::class)->set('Page P', 'chatbot', $k, $v);
        app(AdGroups::class)->setMode('Page P', true);
    }

    private function group(string $name, array $ads, array $values = []): int
    {
        $r = app(AdGroups::class)->save('Page P', null, $name, $ads, $values);
        $this->assertTrue($r['ok'], $r['error'] ?? '');

        return $r['id'];
    }

    private function panel(): array
    {
        Cache::flush();
        PageSettings::forget('Page P');

        return $this->getJson('/chatbot/ad-groups?page=Page%20P')->assertOk()->json();
    }

    /** $n customers of the ad in the last days (each its own psid). */
    private function customers(string $ad, int $n, int $daysAgo = 1): void
    {
        for ($i = 0; $i < $n; $i++) {
            BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page P', 'psid' => $ad . $i, 'name' => 'C', 'message' => 'Hi',
                'payload' => ['ad_id' => $ad], 'received_at' => now()->subDays($daysAgo)]);
        }
    }

    /** The ad in the reports: On (spends on the last day, 2026-10-19), or Off since a few days; $since = its first day. */
    private function report(string $ad, bool $on, string $since = '2026-09-01'): void
    {
        DB::table('ads_manager_reports')->insert(['page_name' => 'Page P', 'day' => $since, 'campaign_name' => 'C', 'ad_set_name' => 'S', 'headline' => 'Ad ' . $ad,
            'ad_id' => $ad, 'amount_spent_php' => 50, 'campaign_delivery' => 'active', 'ad_set_delivery' => 'active']);
        DB::table('ads_manager_reports')->insert(['page_name' => 'Page P', 'day' => '2026-10-19', 'campaign_name' => 'C', 'ad_set_name' => 'S', 'headline' => 'Ad ' . $ad,
            'ad_id' => $ad, 'amount_spent_php' => $on ? 80 : 0, 'campaign_delivery' => $on ? 'active' : 'inactive', 'ad_set_delivery' => $on ? 'active' : 'inactive']);
    }

    // ---- S-217.2: the group Edit saves several values in one request ----

    public function test_S_217_2_2_and_2_5_a_group_edit_save_writes_its_values_in_one_request_with_history_and_restore(): void
    {
        $this->actingAs($this->user());
        $id = $this->group('Serum', ['111', '222'], ['main_flow' => 'Old MF', 'seq_1' => 'Group Seq']);

        // A paste with a blank and "Paste blanks as empty" on: the blank empties the group's own value (back to the page value).
        $this->postJson('/chatbot/ad-groups/value', ['page' => 'Page P', 'id' => $id, 'values' => ['main_flow' => 'New MF', 'price' => '499', 'seq_1' => '']])
            ->assertOk()->assertJson(['ok' => true, 'id' => $id]);
        $g = app(AdGroups::class)->groups('Page P')[$id];
        $this->assertSame(['main_flow' => 'New MF', 'price' => '499'], $g['values']);
        $this->assertSame(['111', '222'], $g['ads'], 'the ads are read inside the lock, not sent by the window');
        $this->assertSame('Serum', $g['name']);

        $history = $this->getJson('/chatbot/ad-groups/history?page=Page%20P&id=' . $id)->assertOk()->json('rows');
        $old = collect($history)->first(fn ($h) => $h['key'] === 'main_flow' && $h['value'] === 'Old MF');
        $this->assertNotNull($old, 'the old value is in the history');
        $this->assertFalse($old['active']);
        $this->postJson('/chatbot/ad-groups/restore', ['page' => 'Page P', 'setting' => $old['id']])->assertOk();
        $this->assertSame('Old MF', app(AdGroups::class)->groups('Page P')[$id]['values']['main_flow']);

        // "Paste blanks as empty" off: the window leaves the blank out, so the value stays (the same as the page).
        $this->postJson('/chatbot/ad-groups/value', ['page' => 'Page P', 'id' => $id, 'values' => ['price' => '599']])->assertOk();
        $this->assertSame(['main_flow' => 'Old MF', 'price' => '599'], app(AdGroups::class)->groups('Page P')[$id]['values']);
    }

    public function test_S_217_2_2_a_bad_value_in_the_group_edit_saves_nothing(): void
    {
        $this->actingAs($this->user());
        $id = $this->group('Serum', ['111'], ['main_flow' => 'Old MF']);
        $before = PageSetting::count();
        $this->postJson('/chatbot/ad-groups/value', ['page' => 'Page P', 'id' => $id, 'values' => ['main_flow' => 'New MF', 'picture' => 'not a link']])->assertStatus(422);
        $this->postJson('/chatbot/ad-groups/value', ['page' => 'Page P', 'id' => $id, 'values' => ['main_flow' => ['x']]])->assertStatus(422);
        $this->postJson('/chatbot/ad-groups/value', ['page' => 'Page P', 'id' => $id, 'values' => []])->assertStatus(422);
        $this->assertSame($before, PageSetting::count());
        $this->assertSame('Old MF', app(AdGroups::class)->groups('Page P')[$id]['values']['main_flow']);
    }

    public function test_S_217_2_3_identity_and_switch_keys_are_not_group_fields_and_are_refused(): void
    {
        $this->actingAs($this->user());
        $id = $this->group('Serum', ['111']);
        $keys = array_column($this->panel()['keys'], 'key');
        foreach (['token', 'provider', 'active', 'page_id', 'mode', 'gateway', 'flow'] as $k) $this->assertNotContains($k, $keys, $k . ' is not a group field');
        $this->assertContains('main_flow', $keys);

        $before = PageSetting::count();
        foreach (['token', 'provider', 'active', 'page_id', 'mode'] as $k) {
            $this->postJson('/chatbot/ad-groups/value', ['page' => 'Page P', 'id' => $id, 'values' => ['main_flow' => 'MF', $k => 'x']])
                ->assertStatus(422)->assertJsonFragment(['error' => $k . AdGroups::PAGE_ONLY]);
        }
        $this->assertSame($before, PageSetting::count(), 'nothing saved');
    }

    public function test_S_217_2_4_a_user_who_cannot_edit_page_values_gets_no_group_edit_and_403_on_its_save(): void
    {
        $id = $this->group('Serum', ['111'], ['main_flow' => 'Old MF']);
        $before = PageSetting::count();
        $this->actingAs($this->user('Data Encoder', 'enc@example.test'));
        $this->get('/chatbot/pages')->assertForbidden();
        $this->postJson('/chatbot/ad-groups/value', ['page' => 'Page P', 'id' => $id, 'values' => ['main_flow' => 'New MF']])->assertForbidden();
        $this->postJson('/chatbot/cells', ['changes' => [['page_name' => 'Page P', 'key' => 'main_flow', 'value' => 'X']]])->assertForbidden();
        $this->assertSame($before, PageSetting::count());
    }

    // ---- S-217.3: running ads first ----

    public function test_S_217_3_1_groups_with_an_on_ad_come_first_then_by_customers_then_name_and_ads_on_then_new_then_customers(): void
    {
        $this->actingAs($this->user());
        foreach (['301' => true, '302' => false, '303' => false, '304' => false, '305' => true, '306' => false] as $ad => $on) $this->report($ad, $on);
        $this->customers('301', 1);
        $this->customers('302', 5);
        $this->customers('303', 2);
        $this->customers('306', 2);
        $this->group('Zeta', ['301']);         // On, 1 customer
        $this->group('Alpha', ['302']);        // Off, 5 customers
        $this->group('Gamma', ['303']);        // Off, 2 customers
        $this->group('Mid', ['304', '305']);   // one On, no customers
        $this->group('Beta', ['306']);         // Off, 2 customers: before Gamma by name
        $this->assertSame(['Zeta', 'Mid', 'Alpha', 'Beta', 'Gamma'], array_column($this->panel()['groups'], 'name'));

        // Inside a group: On first, then New (first day in the last 3 days), then by customers.
        $this->report('401', false);
        $this->report('402', true);
        $this->report('403', false, '2026-10-18');
        $this->report('404', true);
        $this->customers('401', 9);
        $this->customers('402', 1);
        $this->customers('404', 3);
        $this->customers('403', 0);
        $this->group('Mixed', ['401', '402', '403', '404']);
        $mixed = collect($this->panel()['groups'])->firstWhere('name', 'Mixed');
        $this->assertSame(['404', '402', '403', '401'], array_column($mixed['ads'], 'ad_id'));
    }

    public function test_S_217_3_2_ads_not_in_a_group_yet_list_on_then_new_then_customers(): void
    {
        $this->actingAs($this->user());
        $this->report('501', false);                 // Off, many customers
        $this->report('502', true);                  // On, few customers
        $this->report('503', false, '2026-10-18');   // New, Off
        $this->report('504', true);                  // On, more customers
        $this->customers('501', 8);
        $this->customers('502', 1);
        $this->customers('503', 1);
        $this->customers('504', 4);
        $this->group('Other', ['999']);
        $this->assertSame(['503', '504', '502', '501'], array_column($this->panel()['unassigned'], 'ad_id'));
    }

    public function test_S_217_3_3_a_group_just_made_without_an_on_ad_stays_visible_after_the_running_groups(): void
    {
        $this->actingAs($this->user());
        $this->report('601', true);
        $this->customers('602', 7);                  // customers, but no report data: not On
        $this->group('Running', ['601']);
        $this->travelTo(now()->addMinutes(5));
        $new = $this->group('Just made', ['602', '603']);
        $groups = $this->panel()['groups'];
        $this->assertSame(['Running', 'Just made'], array_column($groups, 'name'));
        $this->assertFalse(collect($groups)->firstWhere('id', $new)['hidden'], 'a new group is never hidden');
    }
}
