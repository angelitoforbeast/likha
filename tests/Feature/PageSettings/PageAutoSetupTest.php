<?php

namespace Tests\Feature\PageSettings;

use App\Services\PageSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Botcake\BotcakeApiTestCase;

/**
 * Ang bagong page na kusang nase-setup: ang pag-save ng Botcake ID o token ay agad na sumusubok sa token, at kapag ok,
 * kinukuha ang mga flow, custom field at template; pinupunan ang malinaw at blangko; ang marami ay para sa dropdown.
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
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->user());

        $this->flowList = [
            ['id' => 501, 'name' => 'CHATBLAST - SHIPPING STATUS', 'is_removed' => false],
            ['id' => 502, 'name' => 'CHATBLAST - SHIPPING STATUS (post_order)', 'is_removed' => false],
            ['id' => 503, 'name' => 'Welcome', 'is_removed' => false],
            ['id' => 504, 'name' => 'CHATBLAST old', 'is_removed' => true],
        ];
        $this->fieldList = [['id' => 801, 'name' => 'GPT Input'], ['id' => 802, 'name' => 'Phone']];
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
            if ($path === 'message_templates') return Http::response(['data' => $this->templates, 'success' => true]);
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
        $this->assertSame(['custom_fields', 'flows', 'message_templates'], $this->calls, 'isang token check (na may custom fields), flows, templates');
        $this->assertSame('ok', substr((string) $this->get57('token_status'), 0, 2));
        $this->assertSame('502', $this->get57('flow.shipping'), 'ang (post_order) ang napili');
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

        // Hindi sumasagot ang Botcake: naka-save pa rin ang token, may refresh_error.
        $this->tokenAnswer = [200, null];
        Http::fake(fn () => Http::failedConnection());
        $this->cell('token', 'tok-third-9876')->assertOk()->assertJsonPath('status', 'replaced')->assertJsonPath('refresh_error', fn ($e) => is_string($e) && $e !== '');
        $this->assertSame('tok-third-9876', $this->get57('token'));
    }

    public static function flowCases(): array
    {
        return [
            'post_order ang pinipili'     => [[[1, 'CHATBLAST - SHIPPING STATUS'], [2, 'CHATBLAST - SHIPPING STATUS (post_order)']], '2'],
            'iisang kandidato'            => [[[3, 'chatblast shipping'], [4, 'Welcome']], '3'],
            'dalawa, walang post_order'   => [[[5, 'CHATBLAST A'], [6, 'CHATBLAST B']], null],
            'dalawang post_order'         => [[[7, 'CHATBLAST (post_order)'], [8, 'CHATBLAST copy (post_order)']], null],
            'walang CHATBLAST'            => [[[9, 'Welcome']], null],
        ];
    }

    /** @dataProvider flowCases */
    public function test_S_057_2_the_flow_is_filled_only_when_one_candidate_is_clear_and_the_list_is_kept_for_the_dropdown(array $flows, ?string $picked): void
    {
        $this->flowList = array_map(fn ($f) => ['id' => $f[0], 'name' => $f[1], 'is_removed' => false], $flows);
        $this->settings()->set('Page Dos', 'botcake', 'page_id', '2002');
        $this->cell('token', self::TOKEN)->assertOk();

        $this->assertSame($picked, $this->get57('flow.shipping'));
        $expected = array_values(array_map(fn ($f) => ['id' => (string) $f[0], 'name' => $f[1]], array_filter($flows, fn ($f) => stripos($f[1], 'chatblast') !== false)));
        $this->assertSame($expected, json_decode((string) $this->get57('flow.shipping.options'), true));
    }

    public static function fieldCases(): array
    {
        return [
            // [mga field, ang napili, ilang kandidato sa dropdown]
            'GPT Input'          => [[[11, 'GPT Input'], [12, 'Phone']], '11', 1],
            'message'            => [[[13, 'Message']], '13', 1],
            'dalawang baybay'    => [[[14, 'GPT Input'], [15, 'GPT-INPUT']], null, 2],
            'wala'               => [[[16, 'Phone']], null, 0],
        ];
    }

    /** @dataProvider fieldCases */
    public function test_S_057_2_the_field_is_filled_only_when_exactly_one_is_gpt_input_or_message(array $fields, ?string $picked, int $options): void
    {
        $this->fieldList = array_map(fn ($f) => ['id' => $f[0], 'name' => $f[1]], $fields);
        $this->settings()->set('Page Dos', 'botcake', 'page_id', '2002');
        $this->cell('token', self::TOKEN)->assertOk();

        $this->assertSame($picked, $this->get57('field.message'));
        $this->assertCount($options, (array) json_decode((string) $this->get57('field.message.options'), true));
    }

    public function test_S_057_3_a_value_a_person_saved_is_never_overwritten_and_stays_even_when_it_is_not_a_candidate(): void
    {
        $s = $this->settings();
        $s->set('Page Dos', 'botcake', 'page_id', '2002');
        $s->set('Page Dos', 'botcake', 'flow.shipping', '999', false, 1);   // hindi CHATBLAST flow
        $s->set('Page Dos', 'botcake', 'field.message', '777', false, 1);   // hindi GPT Input

        $this->cell('token', self::TOKEN)->assertOk();

        $this->assertSame(['999', '777'], [$this->get57('flow.shipping'), $this->get57('field.message')]);
        $this->assertSame(['501', '502'], array_column(json_decode((string) $this->get57('flow.shipping.options'), true), 'id'));
        // Ang babala ay sa page: ang value ay wala sa options.
        $html = $this->get('/chatblast/page-settings')->assertOk()->getContent();
        $this->assertStringContainsString('This flow is not a CHATBLAST flow in Botcake', $html);
        $this->assertStringContainsString("This field is not a 'GPT Input' field in Botcake", $html);
        // Ang options ay hindi naisusulat mula sa page.
        $this->cell('flow.shipping.options', '[]')->assertStatus(422);
    }
}
