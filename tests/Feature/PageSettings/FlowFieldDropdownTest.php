<?php

namespace Tests\Feature\PageSettings;

use App\Services\ChatblastReady;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Botcake\BotcakeApiTestCase;

/**
 * Ang Chatblast flow at ang message field ay pinipili sa dropdown: ang buong listahan ng flow at custom field ng page
 * ay itinatabi (…options) sa Test at sa pag-save ng Botcake ID o token, kaya ang page ay hindi tumatawag sa Botcake.
 * Pekeng Botcake dito; walang totoong tawag.
 */
class FlowFieldDropdownTest extends BotcakeApiTestCase
{
    private const TOKEN = 'tok-dropdown-7h3q';

    private array $flowList = [];
    private array $fieldList = [];
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->user());

        $this->flowList = [
            ['id' => 503, 'name' => 'Welcome', 'is_removed' => false],
            ['id' => 501, 'name' => 'CHATBLAST - SHIPPING STATUS', 'is_removed' => false],
            ['id' => 505, 'name' => 'alpha promo', 'is_removed' => false],
            ['id' => 502, 'name' => 'CHATBLAST - SHIPPING STATUS (post_order)', 'is_removed' => false],
            ['id' => 504, 'name' => 'CHATBLAST old', 'is_removed' => true],
        ];
        $this->fieldList = [['id' => 802, 'name' => 'Phone'], ['id' => 804, 'name' => 'message'], ['id' => 803, 'name' => 'address'], ['id' => 801, 'name' => 'GPT Input']];

        Http::fake(function (Request $r) {
            $path = basename(parse_url($r->url(), PHP_URL_PATH));
            $this->calls[] = $path;
            if ($path === 'custom_fields') return Http::response(['success' => true, 'data' => $this->fieldList]);
            if ($path === 'flows') return Http::response(['data' => ['flows' => $this->flowList, 'folders' => []], 'success' => true]);
            if ($path === 'message_templates') return Http::response(['data' => [], 'success' => true]);
            return Http::response([], 404);
        });
    }

    private function cell(string $key, string $value)
    {
        return $this->postJson('/chatblast/page-settings/cell', ['page_name' => 'Page Tres', 'provider' => 'botcake', 'key' => $key, 'value' => $value]);
    }

    private function list(string $key): array
    {
        return json_decode((string) app(PageSettings::class)->get('Page Tres', 'botcake', $key . '.options'), true);
    }

    public function test_S_062_1_the_stored_lists_hold_every_flow_and_field_in_order_with_the_recommended_marks(): void
    {
        $this->cell('page_id', '3003')->assertOk();
        $this->cell('token', self::TOKEN)->assertOk();

        // Ang may pangalang tugma sa Setup box muna (recommended), saka ang iba ayon sa pangalan; ang binura ay wala. Walang
        // flow na "UPDATE SHIPPING STATUS 2026" dito, kaya walang recommended na flow.
        $this->assertSame([
            ['id' => '505', 'name' => 'alpha promo', 'candidate' => false, 'recommended' => false],
            ['id' => '501', 'name' => 'CHATBLAST - SHIPPING STATUS', 'candidate' => false, 'recommended' => false],
            ['id' => '502', 'name' => 'CHATBLAST - SHIPPING STATUS (post_order)', 'candidate' => false, 'recommended' => false],
            ['id' => '503', 'name' => 'Welcome', 'candidate' => false, 'recommended' => false],
        ], $this->list('flow.shipping'));
        $this->assertSame([
            ['id' => '804', 'name' => 'message', 'candidate' => true, 'recommended' => true],
            ['id' => '803', 'name' => 'address', 'candidate' => false, 'recommended' => false],
            ['id' => '801', 'name' => 'GPT Input', 'candidate' => false, 'recommended' => false],
            ['id' => '802', 'name' => 'Phone', 'candidate' => false, 'recommended' => false],
        ], $this->list('field.message'));
        // Ayon sa eksaktong pangalan lang: ang field "message" ay naisulat; walang flow na tugma, kaya walang flow.
        $this->assertSame([null, '804'], [app(PageSettings::class)->get('Page Tres', 'botcake', 'flow.shipping'), app(PageSettings::class)->get('Page Tres', 'botcake', 'field.message')]);
    }

    public function test_S_062_2_picking_an_item_saves_its_id_through_the_cell_save_with_history_and_a_ready_reset(): void
    {
        $s = app(PageSettings::class);
        foreach (['page_id' => '3003', 'token' => self::TOKEN, 'flow.shipping' => '502', 'field.message' => '801'] as $k => $v) $s->set('Page Tres', 'botcake', $k, $v);
        $s->set('Page Tres', 'botcake', 'test_ok', now('Asia/Manila')->format('Y-m-d H:i:s'), false, null, true);
        $this->assertTrue(app(ChatblastReady::class)->tested('Page Tres'));
        $this->travel(1)->seconds();

        // Ang pinili sa dropdown ay ang ID nito, sa parehong endpoint ng tinype; ang ID na wala sa listahan ay puwede rin.
        $this->cell('field.message', '804')->assertOk()->assertJsonPath('status', 'replaced')->assertJsonPath('cell.value', '804');
        $this->assertSame('804', $s->get('Page Tres', 'botcake', 'field.message'));
        $this->assertSame(['801'], array_column($this->get('/chatblast/page-settings/history?page_name=Page%20Tres&provider=botcake&key=field.message')->json('history'), 'value'));
        $this->assertFalse(app(ChatblastReady::class)->tested('Page Tres'), 'bagong field: kailangan ulit ng test');
        $this->cell('flow.shipping', '9999')->assertOk()->assertJsonPath('cell.value', '9999'); // "Type an ID…"
        $this->cell('flow.shipping', 'abc')->assertStatus(422);
    }

    public function test_S_062_3_the_page_edits_the_two_ids_with_a_dropdown_that_falls_back_to_typing(): void
    {
        $html = $this->get('/chatblast/page-settings')->assertOk()->getContent();

        foreach ([
            "const TYPE_ID = '__type__';",
            "el('option', {value: TYPE_ID, text: 'Type an ID…'})",
            "'No list yet: press Test'",
            "' (not in Botcake list)'",
            "(o.recommended ? ' (recommended)' : '')",
            "o.name + ' · ' + o.id",
            'if (PICK_KEYS.includes(col.key) && !secret && !typing) return startPick(td, row, col, original);',
            '|| td, row, col, true);', // "Type an ID…": ang text input
        ] as $needle) {
            $this->assertStringContainsString($needle, htmlspecialchars_decode($html, ENT_QUOTES), $needle);
        }
        // Isang dropdown lang: ang dating laging-nakikitang dropdown sa ilalim ng ID ay wala na.
        $this->assertStringNotContainsString("el('select', {class: 'cb-pick", $html);
    }

    public function test_S_062_4_rendering_the_page_and_the_grid_never_calls_botcake(): void
    {
        $s = app(PageSettings::class);
        foreach (['page_id' => '3003', 'token' => self::TOKEN, 'flow.shipping' => '502'] as $k => $v) $s->set('Page Tres', 'botcake', $k, $v);
        $s->set('Page Tres', 'botcake', 'flow.shipping.options', json_encode([['id' => '502', 'name' => 'CHATBLAST (post_order)', 'candidate' => true, 'recommended' => true]]), false, null, true);

        $this->get('/chatblast/page-settings')->assertOk();
        $row = collect($this->getJson('/chatblast/page-settings/grid')->assertOk()->json('rows'))->firstWhere('name', 'Page Tres');
        $this->assertStringContainsString('"recommended":true', $row['cells']['botcake.flow.shipping.options']['value']);
        $this->getJson('/chatblast/page-settings/grid?since=2000-01-01 00:00:00')->assertOk();
        $this->assertSame([], $this->calls);
    }
}
