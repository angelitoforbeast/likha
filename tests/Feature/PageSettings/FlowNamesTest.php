<?php

namespace Tests\Feature\PageSettings;

use App\Models\PageSetting;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Botcake\BotcakeApiTestCase;

/**
 * Ang pangalan ng bawat flow ID at ang template ng shipping flow sa /page-settings: mula sa GET flows at
 * GET message_templates ng Botcake (pekeng HTTP at orasan dito), itinatala bilang system keys sa page_settings.
 */
class FlowNamesTest extends BotcakeApiTestCase
{
    private const TOKEN = 'tok-flow-names-8w2j';
    private const API = 'botcake.io/api/public_api/v1/pages/1001/';

    private const FLOWS = [
        ['id' => 7001, 'name' => 'CHATBLAST - SHIPPING STATUS (post_order)', 'is_removed' => false, 'parent_id' => null],
        ['id' => 7002, 'name' => 'ON DELIVERY', 'is_removed' => false, 'parent_id' => 9],
        ['id' => 7003, 'name' => 'LUMANG FLOW', 'is_removed' => true, 'parent_id' => null],
    ];

    /** Mga sagot ng Botcake sa tawag na susunod; binibilang ang mga tawag kada path. */
    private array $flowList = self::FLOWS;
    private mixed $flowAnswer = null;
    private mixed $templateAnswer = null;
    private array $flowQueries = [];
    private array $requestTimes = [];
    private array $templates = [];
    private array $tokenAnswer = [200, ['success' => true, 'data' => []]];
    private float $tokenSeconds = 0;
    private array $calls = ['flows' => 0, 'message_templates' => 0, 'custom_fields' => 0];

    protected function setUp(): void
    {
        parent::setUp();

        $s = $this->settings();
        $s->set('Page Uno', 'botcake', 'page_id', '1001');
        $s->set('Page Uno', 'botcake', 'token', self::TOKEN);
        $this->actingAs($this->user());

        $this->templates = [
            self::template('post_order', 'APPROVED', "May update na po sa order nyo po❤️\n\n{{1}}", ['body_text' => [['Custom Field']]]),
            self::template('promo_x', 'APPROVED', 'Sale po ngayon!'),
        ];

        Http::fake(function (Request $r) {
            $path = basename(parse_url($r->url(), PHP_URL_PATH));
            $this->calls[$path] = ($this->calls[$path] ?? 0) + 1;
            $this->requestTimes[] = $this->clock->now();
            if ($path === 'custom_fields') {
                $this->clock->time += $this->tokenSeconds;
                return Http::response($this->tokenAnswer[1], $this->tokenAnswer[0]);
            }
            if ($path === 'flows') {
                // Gaya ng totoong API: hindi pinapansin ang page; ang data.flows ay ang buong listahan.
                $this->flowQueries[] = $r->data();
                if ($this->flowAnswer !== null) return value($this->flowAnswer);
                return Http::response(['data' => ['flows' => $this->flowList, 'folders' => []], 'success' => true]);
            }
            if ($path === 'message_templates') {
                if ($this->templateAnswer !== null) return value($this->templateAnswer);
                return Http::response(['data' => $this->templates, 'success' => true]);
            }
            return Http::response([], 404);
        });
    }

    private static function template(string $name, string $status, string $body, mixed $example = null): array
    {
        $bodyPart = ['type' => 'BODY', 'text' => $body] + ($example === null ? [] : ['example' => $example]);
        return ['name' => $name, 'status' => $status, 'category' => 'UTILITY', 'components' => [['type' => 'HEADER', 'text' => 'x'], $bodyPart]];
    }

    private function settings(): PageSettings
    {
        return app(PageSettings::class);
    }

    private function cell(string $key, string $value)
    {
        return $this->postJson('/chatblast/page-settings/cell', ['page_name' => 'Page Uno', 'provider' => 'botcake', 'key' => $key, 'value' => $value]);
    }

    private function testAll()
    {
        return $this->postJson('/chatblast/page-settings/test-token/one', ['page_name' => 'Page Uno']);
    }

    private function grid(): array
    {
        return $this->get('/chatblast/page-settings')->assertOk()->viewData('grid');
    }

    private function get52(string $key): ?string
    {
        return $this->settings()->get('Page Uno', 'botcake', $key);
    }

    public static function flowAnswers(): array
    {
        return [
            'listed'        => ['7001', null, 'CHATBLAST - SHIPPING STATUS (post_order)', ''],
            'not listed'    => ['7999', null, 'Not in Botcake', 'red'],
            'removed'       => ['7003', null, 'Deleted in Botcake', 'red'],
            '500'           => ['7001', fn () => Http::response(['message' => 'down'], 500), 'Check failed: Botcake: HTTP 500', 'grey'],
            'success:false' => ['7001', fn () => Http::response(['success' => false]), 'Check failed: Botcake: refused (success:false)', 'grey'],
            'no answer'     => ['7001', fn () => Http::failedConnection(), 'Check failed: Botcake: no answer', 'grey'],
            'not a list'    => ['7001', fn () => Http::response(['data' => 'x', 'success' => true]), 'Check failed: Botcake: not a list', 'grey'],
        ];
    }

    /** @dataProvider flowAnswers */
    public function test_S_052_1_saving_a_flow_id_fills_its_name_and_a_botcake_failure_never_blocks_the_save(string $id, ?\Closure $answer, string $line, string $tone): void
    {
        $this->flowAnswer = $answer;
        $this->settings()->set('Page Uno', 'botcake', 'template.shipping', 'post_order'); // may template na: flows lang

        $res = $this->cell('flow.shipping', $id)->assertOk()->assertJsonPath('cell.value', $id);

        $this->assertSame($id, $this->get52('flow.shipping'));
        $this->assertSame($line, $res->json('cells')['botcake.flow.shipping.name']['line']);
        $this->assertSame($tone, $res->json('cells')['botcake.flow.shipping.name']['tone']);
        $this->assertSame(['flows' => 1, 'message_templates' => 0, 'custom_fields' => 0], $this->calls, 'isang flows call lang');
        $this->assertSame([], $this->clock->sleeps, 'walang retry');
        $uno = collect($this->grid()['rows'])->firstWhere('name', 'Page Uno');
        $this->assertSame($line, $uno['cells']['botcake.flow.shipping.name']['line'], 'nakikita rin pagkatapos ng reload');
    }

    public function test_S_052_1_saving_one_flow_names_every_flow_of_the_page_and_a_failure_marks_only_the_saved_one(): void
    {
        $this->settings()->set('Page Uno', 'botcake', 'flow.on_delivery', '7002');

        $this->cell('flow.broadcast', '7003')->assertOk();
        $this->assertSame('ON DELIVERY', $this->get52('flow.on_delivery.name'));
        $this->assertSame('!bura', $this->get52('flow.broadcast.name'));

        $this->flowAnswer = fn () => Http::response([], 500);
        $this->cell('flow.broadcast', '7001')->assertOk();
        $this->assertSame('ON DELIVERY', $this->get52('flow.on_delivery.name'), 'ang hindi binago ay naiiwan');
        $this->assertSame('!hindi nasuri: Botcake: HTTP 500', $this->get52('flow.broadcast.name'), 'hindi naiiwan ang pangalan ng lumang ID');

        // Binura ang flow ID: kasama ang pangalan nito, para hindi magamit sa pagpili ng template.
        $this->cell('flow.broadcast', '')->assertOk()->assertJsonPath('cells', ['botcake.flow.broadcast.name' => null]);
        $this->assertNull($this->get52('flow.broadcast.name'));
    }

    public function test_S_052_1_a_slow_read_does_not_name_a_flow_id_that_changed_meanwhile(): void
    {
        // Habang hinihintay ang Botcake, may ibang save na nagpalit ng ID (hal. Test lahat at ang grid nang sabay).
        $this->flowAnswer = function () {
            $this->settings()->set('Page Uno', 'botcake', 'flow.shipping', '7002');
            return Http::response(['data' => ['flows' => self::FLOWS], 'success' => true]);
        };

        $this->cell('flow.shipping', '7001')->assertOk();

        $this->assertNull($this->get52('flow.shipping.name'), 'ang pangalan ng 7001 ay hindi isinusulat para sa 7002');
    }

    public function test_S_052_2_the_flow_list_is_read_with_one_call_and_an_odd_answer_marks_nothing_missing(): void
    {
        $this->settings()->set('Page Uno', 'botcake', 'template.shipping', 'post_order');

        // Isang tawag, walang ?page: ang wala sa buong listahan ay "Wala sa Botcake".
        $this->cell('flow.shipping', '7999')->assertOk();
        $this->assertSame(1, $this->calls['flows']);
        $this->assertSame([[]], $this->flowQueries, 'walang page na parameter');
        $this->assertSame('!wala', $this->get52('flow.shipping.name'));

        // Kakaibang sagot (walang data.flows, o data null): hindi listahan, kaya walang minamarkahang wala.
        foreach ([['data' => ['folders' => []], 'success' => true], ['data' => null, 'success' => true]] as $body) {
            $this->flowAnswer = fn () => Http::response($body);
            $this->cell('flow.shipping', '7001')->assertOk();
            $this->assertSame('!hindi nasuri: Botcake: not a list', $this->get52('flow.shipping.name'));
        }
        $this->assertSame(3, $this->calls['flows']);
    }

    public function test_S_052_3_test_lahat_refreshes_names_and_template_only_when_the_token_is_ok_and_returns_the_cells(): void
    {
        $this->settings()->set('Page Uno', 'botcake', 'flow.shipping', '7001');

        $res = $this->testAll()->assertOk()->assertJsonPath('status', 'ok');

        $this->assertSame(['flows' => 1, 'message_templates' => 1, 'custom_fields' => 1], $this->calls);
        // Iisang rate key ang token check, flows at templates: hindi lalampas sa 2 request kada segundo.
        $this->assertSame([1000.0, 1000.5, 1001.0], $this->requestTimes);
        $cells = $res->json('cells');
        $this->assertSame('CHATBLAST - SHIPPING STATUS (post_order)', $cells['botcake.flow.shipping.name']['line']);
        $this->assertSame('post_order', $cells['botcake.template.shipping']['value']);
        $this->assertSame('system', $cells['botcake.template.shipping']['by']);
        $this->assertSame("May update na po sa order nyo po❤️\n\n(sequence message)", $cells['botcake.template.shipping.text']['line']);

        foreach (['invalid' => [200, ['success' => false]], 'error' => [500, []]] as $status => $answer) {
            $this->calls = ['flows' => 0, 'message_templates' => 0, 'custom_fields' => 0];
            $this->tokenAnswer = $answer;
            $this->testAll()->assertOk()->assertJsonPath('status', $status)->assertJsonPath('cells', null);
            $this->assertSame(['flows' => 0, 'message_templates' => 0, 'custom_fields' => 1], $this->calls, $status);
        }
    }

    public function test_S_052_3_test_lahat_skips_the_refresh_when_its_time_budget_is_spent(): void
    {
        $this->settings()->set('Page Uno', 'botcake', 'flow.shipping', '7001');
        $this->tokenSeconds = 24; // mabagal ang token check: sa 25 s na budget, kulang na ang oras para sa flows at templates

        $this->testAll()->assertOk()->assertJsonPath('status', 'ok')
            ->assertJsonPath('refresh_error', 'out of time');

        $this->assertSame(0, $this->calls['flows'] + $this->calls['message_templates']);
        $this->assertNull($this->get52('flow.shipping.name'), 'walang isinulat: naiiwan ang dating linya');
    }

    public function test_S_052_3_the_row_test_token_button_refreshes_only_when_the_token_is_ok(): void
    {
        $this->settings()->set('Page Uno', 'botcake', 'flow.shipping', '7001');

        $this->tokenAnswer = [401, []];
        $this->post('/chatblast/page-settings/test-token', ['page_name' => 'Page Uno'])->assertRedirect();
        $this->assertSame(0, $this->calls['flows'] + $this->calls['message_templates']);

        $this->tokenAnswer = [200, ['success' => true, 'data' => []]];
        $this->post('/chatblast/page-settings/test-token', ['page_name' => 'Page Uno'])->assertRedirect()->assertSessionHas('success');
        $this->assertSame('CHATBLAST - SHIPPING STATUS (post_order)', $this->get52('flow.shipping.name'));
        $this->assertSame('post_order', $this->get52('template.shipping'));
    }

    public function test_S_052_5_a_template_typed_by_the_owner_is_kept_and_an_unknown_or_pending_name_shows_red(): void
    {
        $this->settings()->set('Page Uno', 'botcake', 'flow.shipping', '7001');
        $this->templates[] = self::template('chatblast', 'APPROVED', 'Follow up ko lang po yung sa order❤️ {{1}}');
        $this->templates[] = self::template('luma_x', 'PENDING', 'Luma {{1}}');

        $res = $this->cell('template.shipping', 'chatblast')->assertOk();
        $this->assertSame(1, $this->calls['message_templates']);
        $this->assertSame(0, $this->calls['flows'], 'templates lang ang tinatawag kapag template ang na-save');
        $this->assertSame('Follow up ko lang po yung sa order❤️ (sequence message)', $res->json('cells')['botcake.template.shipping.text']['line']);

        $this->testAll()->assertOk();
        $this->assertSame('post_order', $this->get52('template.shipping'), 'ang setup check ang nagsusulat ng pangalan ng Setup box');

        foreach (['wala_ito', 'luma_x'] as $name) {
            $text = $this->cell('template.shipping', $name)->assertOk()->json('cells')['botcake.template.shipping.text'];
            $this->assertSame(['Template not approved in Botcake', 'red'], [$text['line'], $text['tone']], $name);
        }

        // Kapag binura ng owner: walang auto-pick (ang susunod na setup check ang maglalagay ulit).
        $this->cell('template.shipping', '')->assertOk()->assertJsonPath('cell', null);
    }

    public function test_S_052_4_saving_the_shipping_flow_with_an_empty_template_shows_no_template_without_a_pick(): void
    {
        $res = $this->cell('flow.shipping', '7001')->assertOk();

        $this->assertSame(['flows' => 1, 'message_templates' => 1, 'custom_fields' => 0], $this->calls);
        $this->assertArrayNotHasKey('botcake.template.shipping', $res->json('cells'), 'no auto-pick from the flow name');
        $this->assertSame(\App\Services\BotcakeFlowNames::NO_TEMPLATE, $this->get52('template.shipping.text'));

        // Ibang flow: flows lang; ang shipping flow na wala pa ring template: templates ulit.
        $this->cell('flow.on_delivery', '7002')->assertOk();
        $this->cell('flow.shipping', '7003')->assertOk();
        $this->assertSame(['flows' => 3, 'message_templates' => 2, 'custom_fields' => 0], $this->calls);
    }

    public function test_S_052_5_a_failed_template_save_does_not_overwrite_a_newer_template(): void
    {
        // Habang hinihintay ang Botcake, may mas bagong save na naglagay ng ibang template at ng body nito.
        $this->templateAnswer = function () {
            $this->settings()->set('Page Uno', 'botcake', 'template.shipping', 'chatblast');
            $this->settings()->set('Page Uno', 'botcake', 'template.shipping.text', 'Follow up po {{1}}', system: true);
            return Http::response([], 500);
        };

        $this->cell('template.shipping', 'post_order')->assertOk()->assertJsonPath('refresh_error', 'Botcake: HTTP 500');

        $this->assertSame('Follow up po {{1}}', $this->get52('template.shipping.text'), 'hindi pinalitan ng "hindi nasuri"');
    }

    public function test_S_052_7_a_refresh_with_the_same_answers_writes_no_new_row(): void
    {
        $this->settings()->set('Page Uno', 'botcake', 'flow.shipping', '7001');
        $this->testAll()->assertOk();
        $count = PageSetting::count();

        $res = $this->testAll()->assertOk();

        $this->assertSame($count, PageSetting::count());
        $this->assertSame(1, PageSetting::where('key', 'flow.shipping.name')->count(), 'walang history ng parehong pangalan');
        $this->assertArrayHasKey('botcake.flow.shipping.name', $res->json('cells'), 'ibinabalik pa rin ang cell');
    }

    public function test_S_052_8_the_token_is_never_returned_logged_or_stored_and_only_names_and_text_are_kept(): void
    {
        $logs = [];
        Event::listen(MessageLogged::class, function ($e) use (&$logs) { $logs[] = $e->message . json_encode($e->context); });
        $this->settings()->set('Page Uno', 'botcake', 'flow.shipping', '7001');
        $this->flowAnswer = fn () => Http::response(['message' => 'bad ' . self::TOKEN], 500);

        $bodies = [$this->cell('flow.shipping', '7001')->getContent()];
        $this->flowAnswer = null;
        $bodies[] = $this->testAll()->getContent();
        $bodies[] = $this->get('/chatblast/page-settings')->getContent();
        $bodies[] = $this->getJson('/chatblast/page-settings/grid?since=2000-01-01%2000:00:00')->getContent();

        // Ang huling 4 ng token ay sadyang nakikita sa grid (••••8w2j); ang buong token ay hindi kailanman.
        foreach ($bodies as $text) $this->assertStringNotContainsString(self::TOKEN, $text);
        foreach ($logs as $text) $this->assertStringNotContainsString('8w2j', $text);
        $stored = PageSetting::where('is_secret', false)->pluck('value')->implode("\n");
        $this->assertStringNotContainsString('8w2j', $stored);
        $this->assertStringNotContainsString('components', $stored, 'walang buong sagot ng Botcake');
        $this->assertSame("May update na po sa order nyo po❤️\n\n{{1}}", $this->get52('template.shipping.text'));
    }

    public function test_S_052_9_other_roles_still_get_404_and_no_botcake_call(): void
    {
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));

        $this->cell('flow.shipping', '7001')->assertNotFound();
        $this->testAll()->assertNotFound();
        $this->post('/chatblast/page-settings/test-token', ['page_name' => 'Page Uno'])->assertNotFound();

        $this->assertSame(['flows' => 0, 'message_templates' => 0, 'custom_fields' => 0], $this->calls);
        $this->assertNull($this->get52('flow.shipping'));
    }

    public function test_S_052_6_the_name_and_text_keys_are_refused_from_the_page_and_the_template_key_is_editable(): void
    {
        foreach (['flow.shipping.name', 'flow.on_delivery.name', 'flow.custom_x.name', 'template.shipping.text'] as $key) {
            $this->cell($key, 'Kahit ano')->assertStatus(422);
            $this->post('/chatblast/page-settings', ['page_name' => 'Page Uno', 'provider' => 'botcake', 'key' => $key, 'value' => 'Kahit ano'])
                ->assertSessionHasErrors('value');
        }
        $this->assertSame(0, PageSetting::where('key', 'like', '%.name')->orWhere('key', 'like', '%.text')->count());

        $this->cell('template.shipping', 'post_order')->assertOk()->assertJsonPath('cell.value', 'post_order');
        $this->assertSame('post_order', $this->get52('template.shipping'));
    }

    public function test_S_052_7_name_and_text_rows_sit_in_their_cells_and_are_not_columns(): void
    {
        $s = $this->settings();
        $s->set('Page Uno', 'botcake', 'flow.shipping', '7001');
        $s->set('Page Uno', 'botcake', 'flow.shipping.name', 'SHIPPING STATUS', system: true);
        $s->set('Page Uno', 'botcake', 'flow.custom_x.name', '!wala', system: true);
        $s->set('Page Uno', 'botcake', 'template.shipping.text', 'Follow up po {{1}}', system: true);

        $grid = $this->grid();
        $keys = array_column($grid['columns'], 'key');

        $this->assertNotContains('botcake.flow.shipping.name', $keys);
        $this->assertNotContains('botcake.flow.custom_x.name', $keys);
        $this->assertNotContains('botcake.template.shipping.text', $keys);
        $this->assertSame(array_search('botcake.flow.broadcast', $keys) + 1, array_search('botcake.template.shipping', $keys));
        $this->assertFalse(collect($grid['columns'])->firstWhere('key', 'botcake.template.shipping')['readonly']);

        $uno = collect($grid['rows'])->firstWhere('name', 'Page Uno');
        $this->assertSame('SHIPPING STATUS', $uno['cells']['botcake.flow.shipping.name']['line']);
        $this->assertSame(['Not in Botcake', 'red'], [$uno['cells']['botcake.flow.custom_x.name']['line'], $uno['cells']['botcake.flow.custom_x.name']['tone']]);
        $this->assertSame('Follow up po (sequence message)', $uno['cells']['botcake.template.shipping.text']['line']);
        $this->assertSame('Follow up po {{1}}', $uno['cells']['botcake.template.shipping.text']['value'], 'buo ang body para sa hover');
    }
}
