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
    private array $flowPages = [self::FLOWS];
    private mixed $flowAnswer = null;
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
            if ($path === 'custom_fields') {
                $this->clock->time += $this->tokenSeconds;
                return Http::response($this->tokenAnswer[1], $this->tokenAnswer[0]);
            }
            if ($path === 'flows') {
                if ($this->flowAnswer !== null) return value($this->flowAnswer);
                $page = (int) ($r->data()['page'] ?? 0);
                return Http::response(['data' => ['flows' => $this->flowPages[$page - 1] ?? []], 'success' => true]);
            }
            if ($path === 'message_templates') return Http::response(['data' => $this->templates, 'success' => true]);
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
        return $this->postJson('/page-settings/cell', ['page_name' => 'Page Uno', 'provider' => 'botcake', 'key' => $key, 'value' => $value]);
    }

    private function testAll()
    {
        return $this->postJson('/page-settings/test-token/one', ['page_name' => 'Page Uno']);
    }

    private function grid(): array
    {
        return $this->get('/page-settings')->assertOk()->viewData('grid');
    }

    private function get52(string $key): ?string
    {
        return $this->settings()->get('Page Uno', 'botcake', $key);
    }

    public static function flowAnswers(): array
    {
        return [
            'listed'        => ['7001', null, 'CHATBLAST - SHIPPING STATUS (post_order)', ''],
            'not listed'    => ['7999', null, 'Wala sa Botcake', 'red'],
            'removed'       => ['7003', null, 'Bura na sa Botcake', 'red'],
            '500'           => ['7001', fn () => Http::response(['message' => 'down'], 500), 'hindi nasuri: Botcake: HTTP 500', 'grey'],
            'success:false' => ['7001', fn () => Http::response(['success' => false]), 'hindi nasuri: Botcake: tinanggihan (success:false)', 'grey'],
            'no answer'     => ['7001', fn () => Http::failedConnection(), 'hindi nasuri: Botcake: walang sagot', 'grey'],
            'not a list'    => ['7001', fn () => Http::response(['data' => 'x', 'success' => true]), 'hindi nasuri: Botcake: hindi listahan ang sagot', 'grey'],
        ];
    }

    /** @dataProvider flowAnswers */
    public function test_S_052_1_saving_a_flow_id_fills_its_name_and_a_botcake_failure_never_blocks_the_save(string $id, ?\Closure $answer, string $line, string $tone): void
    {
        $this->flowAnswer = $answer;

        $res = $this->cell('flow.shipping', $id)->assertOk()->assertJsonPath('cell.value', $id);

        $this->assertSame($id, $this->get52('flow.shipping'));
        $this->assertSame($line, $res->json('cells')['botcake.flow.shipping.name']['line']);
        $this->assertSame($tone, $res->json('cells')['botcake.flow.shipping.name']['tone']);
        $this->assertSame(0, $this->calls['message_templates'], 'flows lang ang tinatawag kapag flow ID ang na-save');
        // Ang 0.5 s ay ang 2 request kada segundo sa pagitan ng mga page; walang backoff ng retry (2, 4, 8 s).
        $this->assertSame([], array_filter($this->clock->sleeps, fn ($s) => $s > 0.5), 'walang retry');
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

    public function test_S_052_2_every_page_of_the_flow_list_is_read_until_the_end_with_a_cap(): void
    {
        // Tatlong page saka blangko: apat na tawag, at nakita ang nasa ikatlong page.
        $this->flowPages = [[self::FLOWS[1]], [self::FLOWS[2]], [self::FLOWS[0]]];
        $this->cell('flow.shipping', '7001')->assertOk();
        $this->assertSame('CHATBLAST - SHIPPING STATUS (post_order)', $this->get52('flow.shipping.name'));
        $this->assertSame(4, $this->calls['flows']);

        // Hindi pinapansin ng API ang page: ang ikalawang page na walang bagong ID ang huli.
        $this->calls['flows'] = 0;
        $this->flowAnswer = fn () => Http::response(['data' => ['flows' => self::FLOWS], 'success' => true]);
        $this->cell('flow.shipping', '7999')->assertOk();
        $this->assertSame(2, $this->calls['flows']);
        $this->assertSame('!wala', $this->get52('flow.shipping.name'));

        // Laging may bago: hanggang 10 page lang, at ang hindi nakita ay hindi "Wala sa Botcake".
        $this->calls['flows'] = 0;
        $n = 0;
        $this->flowAnswer = function () use (&$n) {
            $n++;
            return Http::response(['data' => ['flows' => [['id' => 8000 + $n, 'name' => 'F' . $n, 'is_removed' => false]]], 'success' => true]);
        };
        $this->cell('flow.shipping', '7001')->assertOk();
        $this->assertSame(10, $this->calls['flows']);
        $this->assertSame('!hindi nasuri: hindi natapos basahin ang listahan ng flows', $this->get52('flow.shipping.name'));

        // 9 s kada page sa 20 s ng save: tatlong page (ang huli ay may 2 s pa), saka "hindi natapos".
        $this->clock->time += 100; // malayo sa huling request: walang 0.5 s na paghihintay
        $this->calls['flows'] = 0;
        $this->flowAnswer = function () use (&$n) {
            $n++;
            $this->clock->time += 9;
            return Http::response(['data' => ['flows' => [['id' => 8000 + $n, 'name' => 'F' . $n, 'is_removed' => false]]], 'success' => true]);
        };
        $this->cell('flow.shipping', '7002')->assertOk();
        $this->assertSame(3, $this->calls['flows']);
        $this->assertSame('!hindi nasuri: hindi natapos basahin ang listahan ng flows', $this->get52('flow.shipping.name'));

        // Lampas sa huling page ay data: null: dulo iyon, hindi error.
        $this->flowAnswer = function () use (&$n) {
            return ++$n === 1 ? Http::response(['data' => ['flows' => self::FLOWS], 'success' => true]) : Http::response(['data' => null, 'success' => true]);
        };
        $n = 0;
        $this->cell('flow.shipping', '7999')->assertOk();
        $this->assertSame('!wala', $this->get52('flow.shipping.name'));

        // Walang data sa ikalawang page: hindi dulo, kaya hindi masasabing "Wala sa Botcake".
        $this->flowAnswer = function () use (&$n) {
            return ++$n === 1 ? Http::response(['data' => ['flows' => self::FLOWS], 'success' => true]) : Http::response(['success' => true]);
        };
        $n = 0;
        $this->cell('flow.shipping', '7998')->assertOk();
        $this->assertSame('!hindi nasuri: Botcake: hindi listahan ang sagot', $this->get52('flow.shipping.name'));
    }

    public function test_S_052_3_test_lahat_refreshes_names_and_template_only_when_the_token_is_ok_and_returns_the_cells(): void
    {
        $this->settings()->set('Page Uno', 'botcake', 'flow.shipping', '7001');

        $res = $this->testAll()->assertOk()->assertJsonPath('status', 'ok');

        $this->assertSame(['flows' => 2, 'message_templates' => 1, 'custom_fields' => 1], $this->calls);
        $cells = $res->json('cells');
        $this->assertSame('CHATBLAST - SHIPPING STATUS (post_order)', $cells['botcake.flow.shipping.name']['line']);
        $this->assertSame('post_order', $cells['botcake.template.shipping']['value']);
        $this->assertSame('system', $cells['botcake.template.shipping']['by']);
        $this->assertSame("May update na po sa order nyo po❤️\n\n(mensahe ng sequence)", $cells['botcake.template.shipping.text']['line']);

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
        $this->tokenSeconds = 29; // mabagal ang token check: kulang na ang oras para sa flows at templates

        $this->testAll()->assertOk()->assertJsonPath('status', 'ok')
            ->assertJsonPath('refresh_error', 'kulang sa oras');

        $this->assertSame(0, $this->calls['flows'] + $this->calls['message_templates']);
        $this->assertNull($this->get52('flow.shipping.name'), 'walang isinulat: naiiwan ang dating linya');
    }

    public function test_S_052_3_the_row_test_token_button_refreshes_only_when_the_token_is_ok(): void
    {
        $this->settings()->set('Page Uno', 'botcake', 'flow.shipping', '7001');

        $this->tokenAnswer = [401, []];
        $this->post('/page-settings/test-token', ['page_name' => 'Page Uno'])->assertRedirect();
        $this->assertSame(0, $this->calls['flows'] + $this->calls['message_templates']);

        $this->tokenAnswer = [200, ['success' => true]];
        $this->post('/page-settings/test-token', ['page_name' => 'Page Uno'])->assertRedirect()->assertSessionHas('success');
        $this->assertSame('CHATBLAST - SHIPPING STATUS (post_order)', $this->get52('flow.shipping.name'));
        $this->assertSame('post_order', $this->get52('template.shipping'));
    }

    public static function picks(): array
    {
        $chatblast = self::template('chatblast', 'APPROVED', 'Follow up ko lang po yung sa order❤️ {{1}}', ['body_text' => [['Custom Field']]]);
        $pending   = self::template('chatblast', 'PENDING', 'Follow up po {{1}}', ['body_text' => [['Custom Field']]]);
        $plain     = self::template('post_order', 'APPROVED', 'May update po {{1}}'); // walang "Custom Field" na example

        return [
            '1: name in parentheses' => ['CHATBLAST - SHIPPING STATUS (post_order)', [$plain, $chatblast], 'post_order'],
            '1 pending, then 2'      => ['SHIPPING (chatblast)', [$pending, self::template('ship_x', 'APPROVED', 'Hi {{1}}', ['body_text' => [['custom field']]])], 'ship_x'],
            '2: single candidate'    => ['SHIPPING STATUS', [$chatblast, self::template('promo_x', 'APPROVED', 'Sale po!')], 'chatblast'],
            '3: two candidates'      => ['SHIPPING STATUS', [$chatblast, self::template('ship_x', 'APPROVED', 'Hi {{1}}', ['body_text' => [['Custom Field']]])], null],
            '3: pending only'        => ['SHIPPING STATUS', [$pending], null],
            'blank name is skipped'  => ['SHIPPING ( )', [self::template('  ', 'APPROVED', 'Hi {{1}}', ['body_text' => [['Custom Field']]])], null],
        ];
    }

    /** @dataProvider picks */
    public function test_S_052_4_an_empty_template_key_is_filled_by_the_flow_name_then_the_single_custom_field_template(string $flowName, array $templates, ?string $expected): void
    {
        $this->settings()->set('Page Uno', 'botcake', 'flow.shipping', '7001');
        $this->flowPages = [[['id' => 7001, 'name' => $flowName, 'is_removed' => false]]];
        $this->templates = $templates;

        $this->testAll()->assertOk();

        $this->assertSame($expected, $this->get52('template.shipping'));
        if ($expected === null) {
            $this->assertNull($this->get52('template.shipping.text'), 'walang template: "piliin ang template" sa grid');
        }
    }

    public function test_S_052_5_a_template_typed_by_the_owner_is_kept_and_an_unknown_or_pending_name_shows_red(): void
    {
        $this->settings()->set('Page Uno', 'botcake', 'flow.shipping', '7001');
        $this->templates[] = self::template('chatblast', 'APPROVED', 'Follow up ko lang po yung sa order❤️ {{1}}');
        $this->templates[] = self::template('luma_x', 'PENDING', 'Luma {{1}}');

        $res = $this->cell('template.shipping', 'chatblast')->assertOk();
        $this->assertSame(1, $this->calls['message_templates']);
        $this->assertSame(0, $this->calls['flows'], 'templates lang ang tinatawag kapag template ang na-save');
        $this->assertSame('Follow up ko lang po yung sa order❤️ (mensahe ng sequence)', $res->json('cells')['botcake.template.shipping.text']['line']);

        $this->testAll()->assertOk();
        $this->assertSame('chatblast', $this->get52('template.shipping'), 'hindi pinapalitan ng auto-pick ang itinype ng owner');

        foreach (['wala_ito', 'luma_x'] as $name) {
            $text = $this->cell('template.shipping', $name)->assertOk()->json('cells')['botcake.template.shipping.text'];
            $this->assertSame(['Hindi APPROVED na template ng page', 'red'], [$text['line'], $text['tone']], $name);
        }

        // Kapag binura ng owner: pipili ulit ang system.
        $this->cell('template.shipping', '')->assertOk()->assertJsonPath('cell.value', 'post_order');
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
        $bodies[] = $this->get('/page-settings')->getContent();
        $bodies[] = $this->getJson('/page-settings/grid?since=2000-01-01%2000:00:00')->getContent();

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
        $this->post('/page-settings/test-token', ['page_name' => 'Page Uno'])->assertNotFound();

        $this->assertSame(['flows' => 0, 'message_templates' => 0, 'custom_fields' => 0], $this->calls);
        $this->assertNull($this->get52('flow.shipping'));
    }

    public function test_S_052_6_the_name_and_text_keys_are_refused_from_the_page_and_the_template_key_is_editable(): void
    {
        foreach (['flow.shipping.name', 'flow.on_delivery.name', 'flow.custom_x.name', 'template.shipping.text'] as $key) {
            $this->cell($key, 'Kahit ano')->assertStatus(422);
            $this->post('/page-settings', ['page_name' => 'Page Uno', 'provider' => 'botcake', 'key' => $key, 'value' => 'Kahit ano'])
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
        $this->assertSame(['Wala sa Botcake', 'red'], [$uno['cells']['botcake.flow.custom_x.name']['line'], $uno['cells']['botcake.flow.custom_x.name']['tone']]);
        $this->assertSame('Follow up po (mensahe ng sequence)', $uno['cells']['botcake.template.shipping.text']['line']);
        $this->assertSame('Follow up po {{1}}', $uno['cells']['botcake.template.shipping.text']['value'], 'buo ang body para sa hover');
    }
}
