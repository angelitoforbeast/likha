<?php

namespace Tests\Feature\PageSettings;

use App\Models\BotcakePsid;
use App\Models\BotcakePsidImportRun;
use App\Models\PageSetting;
use App\Services\PageSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Botcake\BotcakeApiTestCase;

/** /page-settings bilang grid na parang Google Sheet: isang row kada page, isang column kada provider + key. */
class PageSettingsGridTest extends BotcakeApiTestCase
{
    private const TOKEN = 'tok-grid-test-qq7z';

    private const KNOWN = [
        'botcake.page_id', 'botcake.token', 'botcake.flow.shipping', 'botcake.flow.on_delivery',
        'botcake.flow.in_transit', 'botcake.flow.broadcast', 'botcake.field.message', 'botcake.token_status',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->user());
    }

    private function settings(): PageSettings
    {
        return app(PageSettings::class);
    }

    private function grid(): array
    {
        return $this->get('/page-settings')->assertOk()->viewData('grid');
    }

    private function cell(array $input)
    {
        return $this->postJson('/page-settings/cell', $input + ['provider' => 'botcake']);
    }

    public function test_the_grid_has_one_row_per_page_and_the_known_columns_in_order_then_other_keys_by_name(): void
    {
        $s = $this->settings();
        $s->set('Page Uno', 'botcake', 'page_id', '1001');
        $s->set('page uno ', 'sms', 'sender', 'UNO');
        $s->set('Page Dos', 'botcake', 'flow.on_delivery', '5501');
        $s->set('Page Dos', 'alpha', 'zeta', 'z');
        $s->set('Page Tres', 'botcake', 'page_id', '3003');
        PageSetting::where('page_name', 'Page Tres')->update(['active' => false]); // walang active → walang row

        $grid = $this->grid();

        $this->assertSame(['Page Dos', 'Page Uno'], array_column($grid['rows'], 'name'));
        $this->assertSame(array_merge(self::KNOWN, ['alpha.zeta', 'sms.sender']), array_column($grid['columns'], 'key'));
        $labels = array_column($grid['columns'], 'label', 'key');
        $this->assertSame('Botcake ID', $labels['botcake.page_id']);
        $this->assertSame('Delivering flow ID', $labels['botcake.flow.on_delivery']);
        $this->assertTrue(collect($grid['columns'])->firstWhere('key', 'botcake.token_status')['readonly']);

        $uno = collect($grid['rows'])->firstWhere('name', 'Page Uno');
        $this->assertSame('1001', $uno['cells']['botcake.page_id']['value']);
        $this->assertSame('UNO', $uno['cells']['sms.sender']['value']);
        $this->assertArrayNotHasKey('botcake.flow.on_delivery', $uno['cells'], 'walang laman ang flow column ng page na ito');
        $this->assertSame('system', $uno['cells']['sms.sender']['by'], 'walang user = isinulat ng system o command');
    }

    public function test_an_empty_table_still_shows_the_known_columns_including_the_four_flows(): void
    {
        $html = $this->get('/page-settings')->assertOk()->getContent();

        $this->assertSame(self::KNOWN, array_column($this->grid()['columns'], 'key'));
        $this->assertStringContainsString('Shipping flow ID', $html);
        $this->assertStringContainsString('Broadcast flow ID', $html);
    }

    public function test_a_valid_cell_replaces_the_active_row_an_invalid_one_saves_nothing_and_the_same_value_adds_nothing(): void
    {
        $this->settings()->set('Page Uno', 'botcake', 'page_id', '1001');

        $this->cell(['page_name' => 'Page Uno', 'key' => 'page_id', 'value' => '2002'])
            ->assertOk()->assertJsonPath('status', 'replaced')->assertJsonPath('cell.value', '2002')->assertJsonPath('cell.by', 'CEO User');
        $this->assertSame([false, true], PageSetting::orderBy('id')->pluck('active')->map(fn ($v) => (bool) $v)->all());

        $this->cell(['page_name' => 'Page Uno', 'key' => 'page_id', 'value' => '442139078/'])
            ->assertStatus(422)->assertJsonPath('error', 'Ang botcake.page_id ay numero lang (walang espasyo, slash o letra).');
        $this->cell(['page_name' => 'Page Uno', 'key' => 'token_status', 'value' => 'ok'])->assertStatus(422);
        $this->assertSame(2, PageSetting::count());

        $this->cell(['page_name' => 'Page Uno', 'key' => 'page_id', 'value' => '2002'])->assertOk()->assertJsonPath('status', 'unchanged');
        $this->assertSame(2, PageSetting::count());
        $this->assertSame('2002', $this->settings()->get('Page Uno', 'botcake', 'page_id'));
    }

    public function test_the_secret_is_never_sent_and_a_replaced_token_shows_only_its_last_4(): void
    {
        $this->settings()->set('Page Uno', 'botcake', 'token', 'tok-old-value-a1b2');
        $this->settings()->set('Page Uno', 'sms', 'api_key', 'sms-secret-value-77xy', true);

        $res = $this->cell(['page_name' => 'Page Uno', 'key' => 'token', 'value' => self::TOKEN])
            ->assertOk()->assertJsonPath('status', 'replaced')->assertJsonPath('cell.value', '••••qq7z');
        $this->assertTrue($res->json('cell.secret'));

        // Blangko sa secret = walang binago (hindi binubura).
        $this->cell(['page_name' => 'Page Uno', 'key' => 'token', 'value' => ''])->assertOk()->assertJsonPath('status', 'unchanged');
        $this->assertSame(self::TOKEN, $this->settings()->get('Page Uno', 'botcake', 'token'));

        // Ang free secret key ay nananatiling secret kahit hindi ipinadala ang is_secret.
        $this->cell(['page_name' => 'Page Uno', 'provider' => 'sms', 'key' => 'api_key', 'value' => 'sms-secret-new-88zz'])->assertOk();
        $this->assertNotSame('sms-secret-new-88zz', PageSetting::where('key', 'api_key')->where('active', true)->value('value'));

        $since = now()->subMinute()->format('Y-m-d H:i:s');
        $bodies = [
            $res->getContent(),
            $this->get('/page-settings')->getContent(),
            $this->getJson('/page-settings/grid')->getContent(),
            $this->getJson('/page-settings/grid?since=' . urlencode($since))->getContent(),
            $this->getJson('/page-settings/history?page_name=Page%20Uno&provider=botcake&key=token')->assertOk()->getContent(),
            $this->getJson('/page-settings/history?page_name=Page%20Uno&provider=sms&key=api_key')->assertOk()->getContent(),
        ];
        foreach ($bodies as $body) {
            foreach ([self::TOKEN, 'tok-grid', 'tok-old-value', 'sms-secret'] as $secret) {
                $this->assertStringNotContainsString($secret, $body);
            }
        }
        $this->assertStringContainsString('qq7z', $bodies[1]);
        $this->assertStringContainsString('a1b2', $bodies[4], 'ang history ay huling 4 lang din');
    }

    public function test_clearing_a_cell_deactivates_the_setting_and_keeps_it_in_the_history(): void
    {
        $this->settings()->set('Page Uno', 'botcake', 'flow.on_delivery', '5501');

        $this->cell(['page_name' => 'Page Uno', 'key' => 'flow.on_delivery', 'value' => '  '])
            ->assertOk()->assertJsonPath('status', 'cleared')->assertJsonPath('cell', null);

        $this->assertNull($this->settings()->get('Page Uno', 'botcake', 'flow.on_delivery'));
        $this->assertNotNull(PageSetting::sole()->replaced_at);
        $this->assertSame('5501', $this->getJson('/page-settings/history?page_name=page%20uno&provider=botcake&key=flow.on_delivery')
            ->assertOk()->json('history.0.value'));

        // Blangko kung wala namang laman: walang nangyayari.
        $this->cell(['page_name' => 'Page Uno', 'key' => 'flow.in_transit', 'value' => ''])->assertOk()->assertJsonPath('status', 'unchanged');
        $this->assertSame(1, PageSetting::count());

        // Ang secret ay nabubura lang sa "Burahin" (clear), dahil ang blangko roon ay "walang palit".
        $this->settings()->set('Page Uno', 'botcake', 'token', self::TOKEN);
        $this->cell(['page_name' => 'Page Uno', 'key' => 'token', 'clear' => true])->assertOk()->assertJsonPath('status', 'cleared');
        $this->assertNull($this->settings()->get('Page Uno', 'botcake', 'token'));
    }

    public function test_a_new_page_and_a_new_column_exist_once_a_value_is_entered(): void
    {
        $this->order('Page Uno', 'Ana Cruz');

        $this->getJson('/page-settings/page-check?page_name=' . urlencode('  page   uno '))
            ->assertOk()->assertJson(['name' => 'page uno', 'in_orders' => true]);
        $this->getJson('/page-settings/page-check?page_name=Page%20Wala')
            ->assertOk()->assertJson(['name' => 'Page Wala', 'in_orders' => false]);
        $this->getJson('/page-settings/page-check?page_name=%20')->assertStatus(422);
        $this->assertSame(0, PageSetting::count(), 'walang naisusulat hangga\'t walang value');

        $this->cell(['page_name' => 'Page Wala', 'provider' => 'viber', 'key' => 'bot_uri', 'value' => 'likha-bot', 'is_secret' => false])
            ->assertOk()->assertJsonPath('status', 'created');

        $grid = $this->grid();
        $this->assertSame(['Page Wala'], array_column($grid['rows'], 'name'));
        $this->assertContains('viber.bot_uri', array_column($grid['columns'], 'key'));

        $this->cell(['page_name' => 'Page Wala', 'provider' => 'Bad Provider!', 'key' => 'x', 'value' => '1'])->assertStatus(422);
    }

    public function test_polling_returns_only_the_cells_changed_since_the_timestamp(): void
    {
        Carbon::setTestNow('2026-10-17 09:00:00');
        $s = $this->settings();
        $s->set('Page Uno', 'botcake', 'page_id', '1001');
        $s->set('Page Uno', 'botcake', 'flow.on_delivery', '5501');
        $s->set('Page Dos', 'botcake', 'page_id', '2002');

        Carbon::setTestNow('2026-10-17 09:05:00');
        $since = $this->getJson('/page-settings/grid')->assertOk()->json('now');
        $this->assertSame('2026-10-17 09:05:00', $since);

        Carbon::setTestNow('2026-10-17 09:06:00');
        $s->set('Page Uno', 'botcake', 'page_id', '1111');
        $s->deactivate(PageSetting::where('key', 'flow.on_delivery')->sole());
        $s->set('Page Tres', 'botcake', 'token', self::TOKEN);

        Carbon::setTestNow('2026-10-17 09:07:00');
        $res = $this->getJson('/page-settings/grid?since=' . urlencode($since))->assertOk();

        $cells = collect($res->json('cells'))->keyBy(fn ($c) => $c['page'] . '|' . $c['key']);
        $this->assertEqualsCanonicalizing(
            ['page uno|botcake.page_id', 'page uno|botcake.flow.on_delivery', 'page tres|botcake.token'],
            $cells->keys()->all(),
            'ang Page Dos ay hindi nagbago'
        );
        $this->assertSame('1111', $cells['page uno|botcake.page_id']['cell']['value']);
        $this->assertNull($cells['page uno|botcake.flow.on_delivery']['cell'], 'na-clear');
        $this->assertSame('••••qq7z', $cells['page tres|botcake.token']['cell']['value']);
        $this->assertSame('Page Tres', $cells['page tres|botcake.token']['name']);
        $this->assertSame('2026-10-17 09:07:00', $res->json('now'));
        $this->assertStringNotContainsString(self::TOKEN, $res->getContent());

        $this->getJson('/page-settings/grid?since=' . urlencode('2026-10-17 09:07:00'))->assertOk()->assertJsonPath('cells', []);
        $this->getJson('/page-settings/grid?since=hindi-oras')->assertStatus(422);
    }

    public function test_the_info_columns_show_available_customers_the_last_sync_and_the_actions(): void
    {
        $s = $this->settings();
        $s->set('Page Uno', 'botcake', 'page_id', '1001');
        $s->set('Page Uno', 'botcake', 'token', self::TOKEN);
        BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Ben Reyes', 'psid' => 'b1', 'status' => 'available', 'source' => 'api']);
        BotcakePsid::create(['page' => 'page uno', 'fb_name' => 'Cora Diaz', 'psid' => 'b2', 'status' => 'available', 'source' => 'api']);
        BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Dino Lim', 'psid' => 'b3', 'status' => 'inactive', 'source' => 'api']);
        BotcakePsidImportRun::create(['status' => 'done', 'source' => 'api', 'page_name' => 'Page Uno', 'stop_reason' => 'end']);
        BotcakePsidImportRun::create(['status' => 'done', 'source' => 'api', 'page_name' => 'Page Uno', 'stop_reason' => 'known_batch']);

        $uno = collect($this->grid()['rows'])->firstWhere('name', 'Page Uno');
        $this->assertSame(2, $uno['info']['customers']);
        $this->assertSame('known_batch', $uno['info']['last_sync']['stop_reason']);
        $this->assertTrue($uno['info']['botcake']);

        $html = $this->get('/page-settings')->getContent();
        $this->assertStringContainsString(route('page-settings.test-token'), $html);
        $this->assertStringContainsString(route('page-settings.sync'), $html);
        $this->assertStringContainsString('/page-settings/psid-coverage/orders', $html);
    }

    public function test_the_query_count_stays_flat_from_5_to_50_pages(): void
    {
        $count = function (int $pages): array {
            $s = $this->settings();
            for ($i = PageSetting::distinct()->count('page_name'); $i < $pages; $i++) {
                foreach (['page_id' => '100' . $i, 'token' => 'tok-load-' . $i . '-wxyz', 'flow.on_delivery' => '55' . $i] as $key => $value) {
                    $s->set('Page ' . $i, 'botcake', $key, $value, false, 1);
                }
                $s->set('Page ' . $i, 'sms', 'sender', 'S' . $i);
                BotcakePsid::create(['page' => 'Page ' . $i, 'fb_name' => 'N' . $i, 'psid' => 'p' . $i, 'status' => 'available', 'source' => 'api']);
                BotcakePsidImportRun::create(['status' => 'done', 'source' => 'api', 'page_name' => 'Page ' . $i, 'stop_reason' => 'end']);
            }

            $this->get('/page-settings')->assertOk(); // unang request: auth at profile, hindi bahagi ng grid
            $n = [];
            foreach (['/page-settings', '/page-settings/grid', '/page-settings/grid?since=' . urlencode('2000-01-01 00:00:00')] as $url) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                $this->get($url)->assertOk();
                $n[] = count(DB::getQueryLog());
                DB::disableQueryLog();
            }
            return $n;
        };

        $five = $count(5);
        $fifty = $count(50);
        $this->assertSame($five, $fifty);
        $this->assertLessThanOrEqual(6, max($fifty), 'ilang query lang ang grid at ang poll');
        $this->assertCount(50, $this->grid()['rows']);
    }

    public function test_only_the_ceo_reaches_the_grid_endpoints(): void
    {
        $this->settings()->set('Page Uno', 'botcake', 'page_id', '1001');
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));

        $this->get('/page-settings')->assertNotFound();
        $this->getJson('/page-settings/grid')->assertNotFound();
        $this->getJson('/page-settings/history?page_name=Page%20Uno&provider=botcake&key=page_id')->assertNotFound();
        $this->getJson('/page-settings/page-check?page_name=Page%20Uno')->assertNotFound();
        $this->cell(['page_name' => 'Page Uno', 'key' => 'page_id', 'value' => '2002'])->assertNotFound();
        $this->assertSame('1001', $this->settings()->get('Page Uno', 'botcake', 'page_id'));
    }
}
