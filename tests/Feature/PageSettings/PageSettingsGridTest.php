<?php

namespace Tests\Feature\PageSettings;

use App\Models\BotcakePsid;
use App\Models\BotcakePsidImportRun;
use App\Models\PageSetting;
use App\Services\BotcakeCustomerSync;
use App\Services\PageSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Botcake\BotcakeApiTestCase;

/** /page-settings bilang grid na parang Google Sheet: isang row kada page, isang column kada provider + key. */
class PageSettingsGridTest extends BotcakeApiTestCase
{
    private const TOKEN = 'tok-grid-test-qq7z';

    private const KNOWN = [
        'botcake.page_id', 'botcake.token', 'botcake.flow.shipping', 'botcake.flow.on_delivery',
        'botcake.flow.in_transit', 'botcake.flow.broadcast', 'botcake.template.shipping', 'botcake.field.message', 'botcake.test_psid', 'botcake.test_ok',
        'botcake.token_status',
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
        return $this->get('/chatblast/page-settings')->assertOk()->viewData('grid');
    }

    private function cell(array $input)
    {
        return $this->postJson('/chatblast/page-settings/cell', $input + ['provider' => 'botcake']);
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

    public function test_S_054_1_every_column_carries_its_cell_group_and_other_keys_go_to_the_detail_row(): void
    {
        $this->settings()->set('Page Uno', 'sms', 'sender', 'UNO');

        $groups = array_column($this->grid()['columns'], 'group', 'key');

        $this->assertSame([
            'botcake.page_id' => 'botcake', 'botcake.token' => 'botcake', 'botcake.flow.shipping' => 'flows',
            'botcake.flow.on_delivery' => 'flows', 'botcake.flow.in_transit' => 'flows', 'botcake.flow.broadcast' => 'flows',
            'botcake.template.shipping' => 'template', 'botcake.field.message' => 'botcake', 'botcake.test_psid' => 'botcake', 'botcake.test_ok' => 'botcake',
            'botcake.token_status' => 'botcake', 'sms.sender' => 'other',
        ], $groups);
    }

    public function test_S_054_2_the_grid_has_five_cells_per_row_in_a_fixed_table_and_keeps_every_action(): void
    {
        $res = $this->get('/chatblast/page-settings')->assertOk();

        $res->assertSee('table-fixed', false)
            ->assertSee("[['botcake', 'Botcake'], ['flows', 'Chatblast flow']]", false)
            ->assertSee("text: 'Orders / Customers'", false)
            ->assertSee("text: 'Sync', title: 'Last sync / Next sync'", false);
        // Ang bawat action ng row at ng page: maliit na button o link, may title.
        foreach (["btn('test', 'Test'", "btn('sync', 'Sync', 'Sync now')", "title: 'Customers'", "title: 'Orders without a PSID'", "title: 'History'",
                  'id="test-all"', 'id="sync-all"', 'id="sync-all-pause"', 'id="sync-all-resume"', 'id="sync-all-stop"', 'id="add-page"', 'id="add-column"'] as $action) {
            $res->assertSee($action, false);
        }
    }

    public function test_S_054_3_the_sync_cell_shows_the_dates_and_time_then_the_next_stop_date(): void
    {
        $this->get('/chatblast/page-settings')
            ->assertSee("s.dates ? s.dates + ' · ' + (s.at || '').slice(11) : (s.at || s.status)", false)
            ->assertSee("'next: ' + (nx.from ? 'from ' + nx.from : nx.label)", false);
    }

    public function test_S_054_4_open_detail_rows_live_outside_the_render_and_hold_the_other_keys_and_history(): void
    {
        $this->get('/chatblast/page-settings')
            ->assertSee('const open = new Set();', false)
            ->assertSee('return open.has(r.key) ? [tr, detailTr(r)] : [tr];', false)
            ->assertSee("const other = inGroup('other');", false)
            ->assertSee("other.map(c => line(r, c, 'w-40'))", false)
            ->assertSee("class: 'hist-link", false);
    }

    public function test_S_054_6_lines_stored_in_tagalog_before_the_change_are_shown_in_english(): void
    {
        $s = $this->settings();
        $s->set('Page Uno', 'botcake', 'flow.shipping', '7001');
        $s->set('Page Uno', 'botcake', 'flow.shipping.name', '!hindi nasuri: Botcake: walang sagot', system: true);
        $s->set('Page Uno', 'botcake', 'flow.broadcast', '7004');
        $s->set('Page Uno', 'botcake', 'flow.broadcast.name', '!hindi nasuri: kulang sa oras', system: true);
        $s->set('Page Uno', 'botcake', 'flow.in_transit', '7003');
        $s->set('Page Uno', 'botcake', 'flow.in_transit.name', '(walang pangalan)', system: true);
        $s->set('Page Uno', 'botcake', 'template.shipping', 'ship_v1');
        $s->set('Page Uno', 'botcake', 'template.shipping.text', '(walang body)', system: true);
        foreach (['on_delivery' => 'Botcake: hindi listahan ang sagot', 'extra_a' => 'Botcake: tinanggihan (success:false)', 'extra_b' => 'walang botcake.page_id o token'] as $k => $reason) {
            $s->set('Page Uno', 'botcake', 'flow.' . $k, '7010');
            $s->set('Page Uno', 'botcake', 'flow.' . $k . '.name', '!hindi nasuri: ' . $reason, system: true);
        }

        $cells = collect($this->grid()['rows'])->firstWhere('name', 'Page Uno')['cells'];

        $this->assertSame([
            ['Check failed: Botcake: no answer', 'grey'], ['Check failed: out of time', 'grey'], ['(no name)', ''], ['(no body)', ''],
            ['Check failed: Botcake: not a list', 'grey'], ['Check failed: Botcake: refused (success:false)', 'grey'], ['Check failed: no botcake.page_id or token', 'grey'],
        ], array_map(fn ($k) => [$cells[$k]['line'], $cells[$k]['tone']],
            ['botcake.flow.shipping.name', 'botcake.flow.broadcast.name', 'botcake.flow.in_transit.name', 'botcake.template.shipping.text',
             'botcake.flow.on_delivery.name', 'botcake.flow.extra_a.name', 'botcake.flow.extra_b.name']));
    }

    public function test_S_054_7_the_page_text_is_english(): void
    {
        $this->settings()->set('Page Uno', 'botcake', 'page_id', '1001');
        $res = $this->get('/chatblast/page-settings')->assertOk();

        foreach (['Search pages', "'No pages yet. Use + Page.'", 'Test all tokens', 'Sync all'] as $text) {
            $res->assertSee($text, false);
        }
        // Mga salitang Tagalog na dating nasa page; ang mga comment sa code ay hindi kasama (// at /* */ ay tinatanggal muna).
        $html = preg_replace(['#/\*.*?\*/#s', '#{{--.*?--}}#s', '#^\s*//.*$#m', '#\s//\s.*$#m'], '', $res->getContent());
        foreach (['Hanapin', 'Pangalan ng page', 'lahat ng token', 'Sync lahat', 'Plano ng', 'tumatakbong', 'I-click', 'hindi pa nasusuri',
                  'piliin ang template', 'Wala pang page', 'susunod', 'Walang koneksyon', 'Hindi nai-save', 'Naidagdag', 'Burahin', 'Kinukuha',
                  'naka-pause', 'itinigil', 'tapos', 'na-match', 'Ibang key', 'Buksan ang detalye', 'Isara', 'araw'] as $tagalog) {
            $this->assertStringNotContainsString($tagalog, $html, $tagalog);
        }
    }

    public function test_S_054_8_the_summary_bar_counts_from_the_loaded_grid_and_each_chip_filters_with_the_name_filter(): void
    {
        $res = $this->get('/chatblast/page-settings')->assertOk()->assertSee('id="summary-bar"', false);

        foreach (["['all', 'Pages', () => true]", "['orders', 'With orders', r => r.info.orders > 0]", "['invalid', 'Token invalid', (r, st) => st.invalid]",
                  "['incomplete', 'Setup incomplete', (r, st) => st.incomplete]", "['flows', 'Flow problems', (r, st) => st.flows || st.template]",
                  "['failed', 'Last sync failed', (r, st) => st.failed]",
                  // Isang state() kada row sa bawat update ng bar; ang pangalan ng flow ay bilang lang kapag may ID ang flow.
                  'const st = new Map(all.map(r => [r, state(r)]));',
                  "flows: Object.entries(r.cells).some(([k, c]) => FLOW_NAME.test(k) && c && c.tone === 'red' && r.cells[k.slice(0, -'.name'.length)]),",
                  "template: !!(r.cells[SHIPPING_KEY] && r.cells[TEMPLATE_KEY] && r.cells[TEMPLATE_KEY + '.text'] && r.cells[TEMPLATE_KEY + '.text'].tone === 'red'),",
                  // Sa bawat poll, kapag may chip at walang ine-edit, sinasala ulit ang mga row.
                  'if (structure || (chip && !editing)) render();',
                  "const REQUIRED = ['botcake.page_id', 'botcake.token', 'botcake.flow.shipping', 'botcake.field.message', 'botcake.template.shipping'];",
                  '.filter(r => (!q || r.name.toLowerCase().includes(q)) && chipFilter(r))',
                  'chip = chip === key ? null : key;'] as $code) {
            $res->assertSee($code, false);
        }
    }

    public function test_S_060_6_a_ready_chip_right_after_pages_counts_the_pages_with_the_green_border(): void
    {
        // Ang Ready ay eksaktong ang mga page na may berdeng gilid; sumusunod sa chip at name filter gaya ng iba.
        $this->get('/chatblast/page-settings')->assertOk()
            ->assertSee("['all', 'Pages', () => true],\n        ['ready', 'Ready', r => borderOf(r) === 'border-l-green-500'],", false);
    }

    public function test_S_054_9_the_page_cell_border_shows_the_row_state_and_the_legend_explains_it(): void
    {
        $this->get('/chatblast/page-settings')->assertOk()
            ->assertSee("!r.info.orders ? 'border-l-transparent' : st.invalid || st.failed || st.template ? 'border-l-red-500' : st.incomplete || st.needsTest ? 'border-l-amber-400' : st.ok ? 'border-l-green-500' : 'border-l-transparent'", false)
            ->assertSee("['bg-green-500', 'Ready'], ['bg-amber-400', 'Setup incomplete or needs a test send'], ['bg-red-500', 'Token invalid, last sync failed or template not approved']", false);
    }

    public function test_S_054_10_each_flow_block_is_id_name_and_content_with_no_placeholder_and_the_lines_stay_outside_the_edited_value(): void
    {
        $res = $this->get('/chatblast/page-settings')->assertOk();
        $html = preg_replace(['#/\*.*?\*/#s', '#{{--.*?--}}#s', '#^\s*//.*$#m', '#\s//\s.*$#m'], '', $res->getContent());

        foreach (['Not checked yet', 'Pick a template', 'Template: ', 'hindi pa nasusuri', 'piliin ang template'] as $placeholder) {
            $this->assertStringNotContainsString($placeholder, $html, $placeholder);
        }
        // Ang pangalan at content ay katabi ng .cell (hindi sa loob), kaya hindi napapalitan ng input habang nag-e-edit.
        $res->assertSee("el('div', {class: 'flow-lines', 'data-pk': row.key, 'data-key': col.key}, flowLines(row, col))", false)
            // Ang pangalan ay kasama lang ng ID ng flow; ang hindi APPROVED na template ay maikling pulang linya sa ilalim ng content.
            ->assertSee("const out = [], name = row.cells[col.key] ? row.cells[col.key + '.name'] : null;", false)
            ->assertSee("if (body && body.tone === 'red') out.push(el('div', {class: 'text-xs text-red-600', text: body.line}));", false)
            // Ang "Clear the value" sa history: ang mga cell na binura ng server (hal. ang pangalan ng flow) ay nawawala agad.
            ->assertSee('delete row.cells[key];
            applyCells(row, data.cells);', false)
            ->assertSee("if (name && name.tone === 'red') out.push(el('div', {class: 'text-xs text-red-600', text: name.line}));", false)
            ->assertSee("if (body && body.tone === '' && !UNKNOWN.includes(body.line)) out.push(...withToken(body.line));", false)
            // "(no name)" / "(no body)" ay hindi alam na pangalan o content: walang linya; ang content ay kasama lang ng shipping ID.
            ->assertSee("const UNKNOWN = ['(no name)', '(no body)'];", false)
            ->assertSee("else if (name && name.tone === '' && !UNKNOWN.includes(name.line))", false)
            ->assertSee('if (col.key === SHIPPING_KEY && row.cells[SHIPPING_KEY]) {', false);
    }

    public function test_an_empty_table_still_shows_the_known_columns_including_the_four_flows(): void
    {
        $html = $this->get('/chatblast/page-settings')->assertOk()->getContent();

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
            ->assertStatus(422)->assertJsonPath('error', 'botcake.page_id takes digits only (no spaces, slashes or letters).');
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
        // Secret din sa bagong page: may active na secret ang column na ito sa ibang page.
        $this->cell(['page_name' => 'Page Dos', 'provider' => 'sms', 'key' => 'api_key', 'value' => 'sms-secret-dos-55kk', 'is_secret' => false])
            ->assertOk()->assertJsonPath('cell.value', '••••55kk');

        $since = now()->subMinute()->format('Y-m-d H:i:s');
        $bodies = [
            $res->getContent(),
            $this->get('/chatblast/page-settings')->getContent(),
            $this->getJson('/chatblast/page-settings/grid')->getContent(),
            $this->getJson('/chatblast/page-settings/grid?since=' . urlencode($since))->getContent(),
            $this->getJson('/chatblast/page-settings/history?page_name=Page%20Uno&provider=botcake&key=token')->assertOk()->getContent(),
            $this->getJson('/chatblast/page-settings/history?page_name=Page%20Uno&provider=sms&key=api_key')->assertOk()->getContent(),
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
        $this->assertSame('5501', $this->getJson('/chatblast/page-settings/history?page_name=page%20uno&provider=botcake&key=flow.on_delivery')
            ->assertOk()->json('history.0.value'));

        // Blangko kung wala namang laman: walang nangyayari.
        $this->cell(['page_name' => 'Page Uno', 'key' => 'flow.in_transit', 'value' => ''])->assertOk()->assertJsonPath('status', 'unchanged');
        $this->assertSame(1, PageSetting::count());

        // Ang secret ay nabubura lang sa "Burahin" (clear), dahil ang blangko roon ay "walang palit".
        $this->settings()->set('Page Uno', 'botcake', 'token', self::TOKEN);
        $this->cell(['page_name' => 'Page Uno', 'key' => 'token', 'clear' => true])->assertOk()->assertJsonPath('status', 'cleared');
        $this->assertNull($this->settings()->get('Page Uno', 'botcake', 'token'));

        // Dalawang active na row (sabay na unang save): ang clear ay nagbubura sa lahat, walang bumabalik na luma.
        PageSetting::create(['page_name' => 'Page Uno', 'provider' => 'botcake', 'key' => 'flow.broadcast', 'value' => '701', 'active' => true]);
        PageSetting::create(['page_name' => 'Page Uno', 'provider' => 'botcake', 'key' => 'flow.broadcast', 'value' => '702', 'active' => true]);
        $this->cell(['page_name' => 'Page Uno', 'key' => 'flow.broadcast', 'value' => ''])->assertOk()->assertJsonPath('status', 'cleared');
        $this->assertNull($this->settings()->get('Page Uno', 'botcake', 'flow.broadcast'));
    }

    public function test_a_new_page_and_a_new_column_exist_once_a_value_is_entered(): void
    {
        $this->order('Page Uno', 'Ana Cruz');

        $this->getJson('/chatblast/page-settings/page-check?page_name=' . urlencode('  page   uno '))
            ->assertOk()->assertJson(['name' => 'page uno', 'key' => 'page uno', 'in_orders' => true]);
        $this->getJson('/chatblast/page-settings/page-check?page_name=Page%20Wala')
            ->assertOk()->assertJson(['name' => 'Page Wala', 'in_orders' => false]);
        $this->getJson('/chatblast/page-settings/page-check?page_name=%20')->assertStatus(422);
        $this->assertSame(0, PageSetting::count(), 'walang naisusulat hangga\'t walang value');

        $this->cell(['page_name' => 'Page Wala', 'provider' => 'viber', 'key' => 'bot_uri', 'value' => 'likha-bot', 'is_secret' => false])
            ->assertOk()->assertJsonPath('status', 'created');

        $grid = $this->grid();
        $this->assertSame(['Page Uno', 'Page Wala'], array_column($grid['rows'], 'name'), 'ang Page Uno ay may order');
        $this->assertContains('viber.bot_uri', array_column($grid['columns'], 'key'));

        $this->cell(['page_name' => 'Page Wala', 'provider' => 'Bad Provider!', 'key' => 'x', 'value' => '1'])->assertStatus(422);
        $this->cell(['page_name' => str_repeat('P', 256), 'key' => 'page_id', 'value' => '1'])->assertStatus(422);
    }

    public function test_polling_returns_only_the_cells_changed_since_the_timestamp(): void
    {
        Carbon::setTestNow('2026-10-17 09:00:00');
        $s = $this->settings();
        $s->set('Page Uno', 'botcake', 'page_id', '1001');
        $s->set('Page Uno', 'botcake', 'flow.on_delivery', '5501');
        $s->set('Page Dos', 'botcake', 'page_id', '2002');

        Carbon::setTestNow('2026-10-17 09:05:00');
        $since = $this->getJson('/chatblast/page-settings/grid')->assertOk()->json('now');
        $this->assertSame('2026-10-17 09:04:58', $since, 'may 2 segundong overlap para walang makalusot na save');

        Carbon::setTestNow('2026-10-17 09:06:00');
        $s->set('Page Uno', 'botcake', 'page_id', '1111');
        $s->deactivate(PageSetting::where('key', 'flow.on_delivery')->sole());
        $s->set('Page Tres', 'botcake', 'token', self::TOKEN);

        Carbon::setTestNow('2026-10-17 09:07:00');
        $res = $this->getJson('/chatblast/page-settings/grid?since=' . urlencode($since))->assertOk();

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
        $this->assertSame('2026-10-17 09:06:58', $res->json('now'));
        $this->assertStringNotContainsString(self::TOKEN, $res->getContent());

        $this->getJson('/chatblast/page-settings/grid?since=' . urlencode('2026-10-17 09:07:00'))->assertOk()->assertJsonPath('cells', []);
        $this->getJson('/chatblast/page-settings/grid?since=hindi-oras')->assertStatus(422);
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

        $html = $this->get('/chatblast/page-settings')->getContent();
        $this->assertStringContainsString(route('page-settings.test-token'), $html);
        $this->assertStringContainsString(route('page-settings.sync'), $html);
        $this->assertStringContainsString('/chatblast/page-settings/psid-coverage/orders', $html);
    }

    public function test_the_grid_and_the_poll_show_since_when_a_sync_holds_the_lock(): void
    {
        $this->travelTo('2026-10-17 08:30:00');
        $sync = app(BotcakeCustomerSync::class);

        $this->assertNull($this->grid()['sync_lock']);
        $sync->withLock(function () {
            $this->travelTo('2026-10-17 08:45:00');
            $this->assertSame('2026-10-17 08:30', $this->grid()['sync_lock']);
            $this->assertSame('2026-10-17 08:30', $this->getJson('/chatblast/page-settings/grid?since=' . urlencode('2026-10-17 08:00:00'))->json('sync_lock'));
            $this->assertStringContainsString('A Botcake sync has been running since', $this->get('/chatblast/page-settings')->getContent());
        });
        $this->assertNull($this->getJson('/chatblast/page-settings/grid?since=' . urlencode('2026-10-17 08:00:00'))->json('sync_lock'));
    }

    public function test_the_query_count_stays_flat_from_5_to_60_pages(): void
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
                $this->order('Page ' . $i, 'Buyer ' . $i);
                $this->order('Page O' . $i, 'Buyer ' . $i); // may order, walang settings
            }

            $this->get('/chatblast/page-settings')->assertOk(); // unang request: auth at profile, hindi bahagi ng grid
            $n = [];
            foreach (['/chatblast/page-settings', '/chatblast/page-settings/grid', '/chatblast/page-settings/grid?since=' . urlencode('2000-01-01 00:00:00')] as $url) {
                Cache::forget('page-settings-grid:orders:30'); // kasama sa bilang ang query ng orders (walang cache)
                DB::flushQueryLog();
                DB::enableQueryLog();
                $this->get($url)->assertOk();
                $n[] = count(DB::getQueryLog());
                DB::disableQueryLog();
            }
            return $n;
        };

        $five = $count(5);
        $fifty = $count(60);
        $this->assertSame($five, $fifty);
        // Grid 8: settings, user names, customers, huling run, orders, plano (ROW_NUMBER), huling matagumpay na run, at ang
        // mga Sync lahat run (panel). Poll na may nagbagong run: 9 (kasama ang Botcake settings ng mga page na iyon). May
        // Sync lahat run: hanggang 2 pa (pangalan ng nagsimula, api run ng kasalukuyang page).
        $this->assertLessThanOrEqual(10, max($fifty), 'ilang query lang ang grid at ang poll (kasama ang huling totoong padala kada page)');
        $this->assertCount(120, $this->grid()['rows']);
    }

    public function test_only_the_ceo_reaches_the_grid_endpoints(): void
    {
        $this->settings()->set('Page Uno', 'botcake', 'page_id', '1001');
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));

        $this->get('/chatblast/page-settings')->assertNotFound();
        $this->getJson('/chatblast/page-settings/grid')->assertNotFound();
        $this->getJson('/chatblast/page-settings/history?page_name=Page%20Uno&provider=botcake&key=page_id')->assertNotFound();
        $this->getJson('/chatblast/page-settings/page-check?page_name=Page%20Uno')->assertNotFound();
        $this->cell(['page_name' => 'Page Uno', 'key' => 'page_id', 'value' => '2002'])->assertNotFound();
        $this->assertSame('1001', $this->settings()->get('Page Uno', 'botcake', 'page_id'));
    }

    /**
     * S-056.37: ang Flows cell ay iisang "Chatblast flow" (ang shipping); ang ibang flow slot na may laman ay nasa ▸ at
     * nae-edit pa rin; ang {{1}} ng content ay malinaw na token na may paalala; ang walang lamang nae-edit ay "+ set".
     */
    public function test_S_056_37_one_chatblast_flow_a_clear_customer_token_and_empty_values_that_look_clickable(): void
    {
        $html = $this->get('/chatblast/page-settings')->assertOk()->getContent();

        foreach ([
            "[['botcake', 'Botcake'], ['flows', 'Chatblast flow']]",
            "group === 'flows' ? inGroup(group).filter(c => c.key === SHIPPING_KEY).map(c => flowBlock(r, c))",
            "'Other flow IDs (not used by chatblast)'",
            "columns.filter(c => FLOW_KEY.test(c.key) && c.key !== SHIPPING_KEY && r.cells[c.key])",
            "const TOKEN = '[message for the customer]', TOKEN_HINT = 'Filled per customer, see To send / History';",
            'const SLOT = ' . json_encode(\App\Services\BotcakeFlowNames::SLOT) . ';',
            "text: '+ set'",
            '#grid-table .cell.empty:hover { outline: 1px dashed #94a3b8; }',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
    }
}
