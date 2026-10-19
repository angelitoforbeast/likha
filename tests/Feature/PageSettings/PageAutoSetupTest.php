<?php

namespace Tests\Feature\PageSettings;

use App\Services\PageSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Botcake\BotcakeApiTestCase;

/**
 * Ang bagong page na kusang nase-setup: ang pag-save ng Botcake ID o token ay agad na sumusubok sa token, at kapag ok,
 * ang setup check (spec 082): ang flow, ang custom field at ang template ayon sa eksaktong pangalan ng Setup box.
 * Pekeng Botcake at orasan dito; walang totoong tawag.
 */
class PageAutoSetupTest extends BotcakeApiTestCase
{
    private const TOKEN = 'tok-auto-setup-5k2p';

    private array $flowList = [];
    private array $fieldList = [];
    private array $templates = [];
    private array $tokenAnswer = [200, null]; // [http, body]; null body = success:true na may fieldList
    private mixed $flowAnswer = null;
    private int $templateHttp = 200;
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->user());

        $this->flowList = [
            ['id' => 501, 'name' => 'CHATBLAST - SHIPPING STATUS (post_order)', 'is_removed' => false],
            ['id' => 502, 'name' => 'UPDATE SHIPPING STATUS 2026', 'is_removed' => false],
            ['id' => 503, 'name' => 'Welcome', 'is_removed' => false],
            ['id' => 504, 'name' => 'UPDATE SHIPPING STATUS 2026', 'is_removed' => true],
        ];
        $this->fieldList = [['id' => 801, 'name' => 'message'], ['id' => 802, 'name' => 'GPT Input']];
        $this->templates = [['name' => 'post_order', 'status' => 'APPROVED', 'category' => 'UTILITY',
            'components' => [['type' => 'BODY', 'text' => "Update po\n{{1}}", 'example' => ['body_text' => [['Custom Field']]]]]]];

        Http::fake(function (Request $r) {
            $path = basename(parse_url($r->url(), PHP_URL_PATH));
            $this->calls[] = $path;
            if ($path === 'custom_fields') {
                return Http::response($this->tokenAnswer[1] ?? ['success' => true, 'data' => $this->fieldList], $this->tokenAnswer[0]);
            }
            if ($path === 'flows') {
                if ($this->flowAnswer !== null) return value($this->flowAnswer);
                return Http::response(['data' => ['flows' => $this->flowList, 'folders' => []], 'success' => true]);
            }
            if ($path === 'message_templates') return Http::response(['data' => $this->templates, 'success' => true], $this->templateHttp);
            return Http::response([], 404);
        });
    }

    private function settings(): PageSettings
    {
        return app(PageSettings::class);
    }

    private function cell(string $key, string $value)
    {
        return $this->postJson('/chatblast/page-settings/cell', ['page_name' => 'Page Dos', 'provider' => 'botcake', 'key' => $key, 'value' => $value]);
    }

    private function get57(string $key): ?string
    {
        return $this->settings()->get('Page Dos', 'botcake', $key);
    }

    public function test_S_057_1_saving_the_id_or_token_tests_the_token_at_once_and_reads_the_flows_fields_and_template(): void
    {
        $this->cell('page_id', '2002')->assertOk()->assertJsonMissingPath('cells'); // walang token pa: walang tawag
        $this->assertSame([], $this->calls);

        $res = $this->cell('token', self::TOKEN)->assertOk();
        $this->assertSame(['custom_fields', 'flows', 'message_templates', 'statistics'], $this->calls, 'isang token check (na may custom fields), flows, templates, saka ang baseline ng bagong flow');
        $this->assertSame('ok', substr((string) $this->get57('token_status'), 0, 2));
        $this->assertSame('502', $this->get57('flow.shipping'), 'ang eksaktong pangalan ng Setup box (hindi ang burang kapangalan)');
        $this->assertSame('801', $this->get57('field.message'));
        $this->assertSame('post_order', $this->get57('template.shipping'));
        foreach (['botcake.token_status', 'botcake.flow.shipping', 'botcake.field.message', 'botcake.flow.shipping.options', 'botcake.field.message.options'] as $key) {
            $this->assertArrayHasKey($key, $res->json('cells'), $key);
        }
        $this->assertSame('system', $res->json('cells')['botcake.flow.shipping']['by']);

        // Invalid na token: status lang, walang ibang binabasa.
        $this->calls = [];
        $this->tokenAnswer = [401, ['message' => 'unauthorized']];
        $this->cell('token', 'tok-other-1234')->assertOk();
        $this->assertSame(['custom_fields'], $this->calls);
        $this->assertSame('invalid', substr((string) $this->get57('token_status'), 0, 7));

        // Ibang Botcake page: ang listahan ng dating page ay inaalis (hindi na ito ang mga flow at field ng page).
        $this->assertNotNull($this->get57('flow.shipping.options'));
        $res = $this->cell('page_id', '2003')->assertOk();
        $this->assertSame([null, null], [$res->json('cells')['botcake.flow.shipping.options'], $res->json('cells')['botcake.field.message.options']]);
        $this->assertSame([null, null], [$this->get57('flow.shipping.options'), $this->get57('field.message.options')]);

        // Hindi sumasagot ang Botcake: naka-save pa rin ang token, may refresh_error.
        $this->tokenAnswer = [200, null];
        Http::fake(fn () => Http::failedConnection());
        $this->cell('token', 'tok-third-9876')->assertOk()->assertJsonPath('status', 'replaced')->assertJsonPath('refresh_error', fn ($e) => is_string($e) && $e !== '');
        $this->assertSame('tok-third-9876', $this->get57('token'));
    }

    public static function flowCases(): array
    {
        return [
            'isa, iba ang laki ng titik at espasyo' => [[[1, '  update   shipping status 2026 '], [2, 'Welcome']], '1'],
            'dalawang kapangalan'                  => [[[5, 'UPDATE SHIPPING STATUS 2026'], [6, 'Update Shipping Status 2026']], null],
            'walang tugma (hindi fuzzy)'           => [[[7, 'CHATBLAST (post_order)'], [8, 'UPDATE SHIPPING STATUS 2026 copy']], null],
        ];
    }

    /** @dataProvider flowCases */
    public function test_S_057_2_the_flow_is_set_only_when_exactly_one_has_the_setup_name_and_the_list_is_kept_for_the_dropdown(array $flows, ?string $picked): void
    {
        $this->flowList = array_map(fn ($f) => ['id' => $f[0], 'name' => $f[1], 'is_removed' => false], $flows);
        $this->settings()->set('Page Dos', 'botcake', 'page_id', '2002');
        $this->cell('token', self::TOKEN)->assertOk();

        $this->assertSame($picked, $this->get57('flow.shipping'));
        $expected = array_values(array_map(fn ($f) => (string) $f[0], array_filter($flows, fn ($f) => \App\Services\ChatblastSetupCheck::norm($f[1]) === 'update shipping status 2026')));
        $list = json_decode((string) $this->get57('flow.shipping.options'), true);
        $this->assertEqualsCanonicalizing($expected, array_column(array_filter($list, fn ($f) => $f['candidate']), 'id'));
        $this->assertCount(count($flows), $list, 'ang buong listahan ng flow (spec 062)');
    }

    public static function fieldCases(): array
    {
        return [
            // [mga field, ang napili, ilang kandidato sa dropdown]
            'message (iba ang laki)' => [[[13, ' Message '], [12, 'Phone']], '13', 1],
            'dalawang message'       => [[[14, 'message'], [15, 'MESSAGE']], null, 2],
            'GPT Input ay hindi ito' => [[[11, 'GPT Input']], null, 0],
        ];
    }

    /** @dataProvider fieldCases */
    public function test_S_057_2_the_field_is_set_only_when_exactly_one_has_the_setup_name(array $fields, ?string $picked, int $options): void
    {
        $this->fieldList = array_map(fn ($f) => ['id' => $f[0], 'name' => $f[1]], $fields);
        $this->settings()->set('Page Dos', 'botcake', 'page_id', '2002');
        $this->cell('token', self::TOKEN)->assertOk();

        $this->assertSame($picked, $this->get57('field.message'));
        $list = (array) json_decode((string) $this->get57('field.message.options'), true);
        $this->assertCount($options, array_filter($list, fn ($f) => $f['candidate']));
        $this->assertCount(count($fields), $list, 'ang buong listahan ng field (spec 062)');
    }

    public function test_S_057_3_a_value_a_person_saved_is_replaced_by_the_setup_name_and_kept_as_history(): void
    {
        $s = $this->settings();
        $s->set('Page Dos', 'botcake', 'page_id', '2002');
        $s->set('Page Dos', 'botcake', 'flow.shipping', '999', false, 1);   // ibang flow, inilagay ng tao
        $s->set('Page Dos', 'botcake', 'field.message', '777', false, 1);

        $this->cell('token', self::TOKEN)->assertOk();

        $this->assertSame(['502', '801'], [$this->get57('flow.shipping'), $this->get57('field.message')]);
        $this->assertSame(['999'], array_column($this->get('/chatblast/page-settings/history?page_name=Page%20Dos&provider=botcake&key=flow.shipping')->json('history'), 'value'));
        $this->assertSame(['502'], array_column(array_filter(json_decode((string) $this->get57('flow.shipping.options'), true), fn ($f) => $f['candidate']), 'id'));
        // Ang options at ang check ay hindi naisusulat mula sa page.
        $this->cell('flow.shipping.options', '[]')->assertStatus(422);
        $this->cell('setup.check', '{}')->assertStatus(422);
        $this->postJson('/chatblast/page-settings/cell', ['page_name' => 'Page Dos', 'provider' => 'botcake', 'key' => 'field.message.options', 'clear' => true])->assertStatus(422);
        $this->assertNotNull($this->get57('field.message.options'));
        foreach (['botcake.flow.shipping.options', 'botcake.field.message.options', 'botcake.setup.check', 'botcake.setup.delivery'] as $key) $this->assertContains($key, PageSettings::SYSTEM_KEYS);
    }

    public function test_S_057_4_the_page_has_the_dropdown_the_guides_and_the_setup_checklist(): void
    {
        $html = $this->get('/chatblast/page-settings')->assertOk()->getContent();

        foreach ([
            "'No flow named \"' + SETUP.flow + '\" in Botcake. Copy it in Botcake from another page to this page, then press Check.'",
            "'No custom field named \"' + SETUP.field + '\" in Botcake. Create it in Botcake, then press Check.'",
            'Template check failed: ',
            "function startPick(td, row, col, original)",
            "o.name + ' · ' + o.id",
            'How to set up a new page',
            'Send test to the Test PSID',
        ] as $needle) {
            $this->assertStringContainsString(htmlspecialchars_decode($needle, ENT_QUOTES), htmlspecialchars_decode($html, ENT_QUOTES), $needle);
        }

        // Ang pinili sa dropdown ay sine-save gaya ng tinype (parehong endpoint).
        $s = $this->settings();
        $s->set('Page Dos', 'botcake', 'page_id', '2002');
        $this->cell('flow.shipping', '501')->assertOk()->assertJsonPath('cell.value', '501');
        $this->assertSame('501', $this->get57('flow.shipping'));
    }

    public function test_S_057_8_an_untested_page_shows_needs_a_test_send_with_a_send_test_button_and_a_chip(): void
    {
        $html = $this->get('/chatblast/page-settings')->assertOk()->getContent();

        foreach (["'Needs a test send'", "['needs_test', 'Needs test'", "btn('send-test', 'Send test'", "'Set a Test PSID first'",
                  "const CONFIG_KEYS = ['botcake.page_id', 'botcake.token', 'botcake.flow.shipping', 'botcake.field.message'];",
                  'const complete = r => CONFIG_KEYS.every(k => r.cells[k]);'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertSame('Test send', collect($this->get('/chatblast/page-settings')->viewData('grid')['columns'])->firstWhere('key', 'botcake.test_ok')['label']);
        $this->cell('test_ok', '2026-10-18 10:00:00')->assertStatus(422); // system lang ang sumusulat
    }

    public function test_S_057_9_row_actions_use_fetch_and_sync_answers_json(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $html = $this->get('/chatblast/page-settings')->assertOk()->getContent();

        $this->assertStringNotContainsString('postForm(', $html, 'walang form post na nagre-reload ng page');
        foreach (['fetch(url.syncUrl', 'fetch(url.sendTestUrl', 'await testOne(r)'] as $needle) $this->assertStringContainsString($needle, $html, $needle);
        // Ang notice ay nasa nakapirming header: isang linya lang (buo sa tooltip), para hindi maitulak pababa ang grid.
        $this->assertStringContainsString("n.className = 'min-w-0 flex-1 basis-0 truncate text-xs ' + cls;", $html);
        $this->assertStringContainsString('n.title = text;', $html);

        $s = $this->settings();
        $s->set('Page Dos', 'botcake', 'page_id', '2002');
        $s->set('Page Dos', 'botcake', 'token', self::TOKEN);
        $this->postJson('/chatblast/page-settings/sync', ['page_name' => 'Page Dos'])->assertOk()->assertJsonPath('status', 'queued');
        $this->postJson('/chatblast/page-settings/sync', ['page_name' => 'Page Wala'])->assertStatus(422);
    }

    public static function templateCases(): array
    {
        return [
            'nasuri, walang approved' => [200, [], 'x', 'No template named post_order in Botcake'],
            'bigo ang templates call' => [500, null, 'error', 'Botcake: HTTP 500'],
        ];
    }

    /** @dataProvider templateCases */
    public function test_S_057_4_no_approved_template_shows_only_when_the_template_check_ran_and_found_none(int $http, ?array $templates, string $kind, string $reason): void
    {
        $this->templateHttp = $http;
        if ($templates !== null) $this->templates = $templates;
        $this->cell('page_id', '2002')->assertOk();
        $res = $this->cell('token', self::TOKEN)->assertOk();

        $this->assertNull($this->get57('template.shipping'));
        $check = json_decode($res->json('cells')['botcake.setup.check']['value'], true);
        // X kapag nasuri at walang tugma; "Check failed" (walang binago) kapag bigo ang tawag.
        $this->assertSame($reason, $kind === 'x' ? $check['x'][0] : $check['error']);
        // Ang page ay ayon sa naitala, hindi sa listahan ng flow: walang paghula na "walang template" kapag bigo ang call.
        $html = $this->get('/chatblast/page-settings')->assertOk()->getContent();
        $this->assertStringNotContainsString("optionsOf(row, SHIPPING_KEY) !== null) out.push", $html);
    }

    public function test_S_057_1_a_failed_save_check_names_the_reason_and_a_non_botcake_error_is_reported(): void
    {
        $html = $this->get('/chatblast/page-settings')->assertOk()->getContent();
        $this->assertStringContainsString("(data.refresh_error ? ' · ' + data.refresh_error : '')", $html, 'ang dahilan ng error ay nasa notice');

        \Illuminate\Support\Facades\Exceptions::fake();
        $this->mock(\App\Services\BotcakeClient::class, function ($m) {
            $m->shouldReceive('shared')->andThrow(new \RuntimeException('boom inside'));
        });
        $this->cell('page_id', '2002')->assertOk();
        $this->cell('token', self::TOKEN)->assertOk()->assertJsonPath('token_test', 'error')->assertJsonPath('refresh_error', 'Botcake: no answer');
        \Illuminate\Support\Facades\Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'boom inside');
    }
}
