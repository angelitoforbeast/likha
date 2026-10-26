<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ShopDetails;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ang SHOP DETAILS ay binubuo mula sa pangalan ng page, ITEM NAME IN JNT DASHBOARD, PRICE at QUANTITY; ang naka-save na
 * shop_details ay hindi na binabasa at hindi na mababago sa /chatbot/pages.
 */
class ShopDetailsAutoTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_20_100000_create_botcake_chats_table.php']]);
        $this->page('Alexa Angeles', '1001');
    }

    private function settings(): PageSettings
    {
        return app(PageSettings::class);
    }

    private function fields(string $page, string $item, string $price, string $quantity, ?string $stored = null): void
    {
        foreach (['item_name_in_jnt_dashboard' => $item, 'price' => $price, 'quantity' => $quantity, 'shop_details' => $stored] as $key => $value) {
            if ($value !== null && $value !== '') $this->settings()->set($page, 'chatbot', $key, $value);
        }
    }

    /** @return array<string, array{0: string, 1: ?string, 2?: string}> */
    public static function prices(): array
    {
        return [
            'peso sign' => ['₱198', '₱198'],
            'plain' => ['198', '₱198'],
            'commas' => ['1,299', '₱1,299'],
            'PHP and a space' => ['PHP 1,299', '₱1,299'],
            'P and a decimal' => ['P 1,299.50', '₱1,299.50'],
            'peso sign and a space' => ['₱ 198', '₱198'],
            'no number: no PRICE line' => ['FREE', null],
            'a word starting with P' => ['Promo 199', null],
            'multi-line item: first line only' => ['198', '₱198', "GLOW TAPE
PRICE: ₱1"],
        ];
    }

    #[DataProvider('prices')]
    public function test_S_158_1_the_rule_builds_page_item_price_and_quantity(string $price, ?string $shown, string $item = ' GLOW TAPE '): void
    {
        $this->fields('Alexa Angeles', $item, $price, ' 2 ');

        $this->assertSame("PAGE: Alexa Angeles\nITEM: GLOW TAPE\n" . ($shown === null ? '' : "PRICE: {$shown}\n") . 'QUANTITY: 2', app(ShopDetails::class)->for('Alexa Angeles'));
    }

    public function test_S_158_2_flow_variables_use_the_computed_text_and_ignore_a_stale_stored_row(): void
    {
        $this->fields('Alexa Angeles', 'GLOW TAPE', '₱198', '2', "PAGE: Other Page\nITEM: REF CLEANER\nPRICE: ₱999\nQUANTITY: 1");
        $expected = "PAGE: Alexa Angeles\nITEM: GLOW TAPE\nPRICE: ₱198\nQUANTITY: 2";

        $bot = app(ChatbotPage::class)->botfields('Alexa Angeles');
        $this->assertSame([$expected, $expected], [$bot['shop_details'], $bot['SHOP DETAILS']]);
    }

    public function test_S_158_3_the_grid_shows_it_read_only_and_every_write_is_refused(): void
    {
        $this->actingAs($this->user());
        $this->fields('Alexa Angeles', 'GLOW TAPE', '198', '2', 'old hand-typed text');

        $data = $this->getJson('/chatbot/data')->assertOk()->json();
        $col = collect($data['columns'])->firstWhere('key', 'shop_details');
        $this->assertSame(['SHOP DETAILS', 'auto'], [$col['label'], $col['kind']]);
        $this->assertSame("PAGE: Alexa Angeles\nITEM: GLOW TAPE\nPRICE: ₱198\nQUANTITY: 2", $data['rows'][0]['cells']['shop_details']);
        $this->get('/chatbot/pages')->assertOk()->assertSee(ShopDetails::NOTE);

        $this->postJson('/chatbot/cell', ['page_name' => 'Alexa Angeles', 'key' => 'shop_details', 'value' => 'typed'])
            ->assertStatus(422)->assertJsonPath('error', ShopDetails::READ_ONLY);
        $res = $this->postJson('/chatbot/cells', ['changes' => [
            ['page_name' => 'Alexa Angeles', 'key' => 'shop_details', 'value' => 'pasted'],
            ['page_name' => 'Alexa Angeles', 'key' => 'quantity', 'value' => '3'],
        ]])->assertOk()->json();
        $this->assertSame([1, 1], [$res['saved'], $res['skipped']]);
        $old = $this->settings()->pageQuery('Alexa Angeles')->where('provider', 'chatbot')->where('key', 'shop_details')->sole();
        $this->postJson('/chatbot/restore', ['id' => $old->id])->assertStatus(422);
        $this->assertSame('old hand-typed text', $this->settings()->get('Alexa Angeles', ChatbotFields::PROVIDER, 'shop_details'));
        $this->assertSame(1, $this->settings()->pageQuery('Alexa Angeles')->where('key', 'shop_details')->count());
    }

    public function test_S_158_3_the_paste_mapping_does_not_offer_shop_details(): void
    {
        $this->actingAs($this->user());
        config(['services.openai.key' => 'test-key']);
        $seen = null;
        $this->mock(\App\Services\Chatbot\PasteFieldAi::class, function ($m) use (&$seen) {
            $m->shouldReceive('map')->once()->andReturnUsing(function ($cells, $header, $fields) use (&$seen) {
                $seen = array_column($fields, 'key');
                return null;
            });
        });

        $this->postJson('/chatbot/paste-map', ['page_name' => 'Alexa Angeles', 'cells' => ['GLOW TAPE', '198']])->assertOk();
        $this->assertContains('price', $seen);
        $this->assertNotContains('shop_details', $seen);
    }

    public function test_S_158_5_the_diff_command_lists_only_pages_whose_old_text_differs(): void
    {
        $this->page('Tech Flask Ph', '2002');
        $this->fields('Alexa Angeles', 'GLOW TAPE', '198', '2', "PAGE: Alexa Angeles\r\nITEM: GLOW TAPE\r\nPRICE: ₱198\r\nQUANTITY: 2");
        $this->fields('Tech Flask Ph', 'TECH FLASK', '349', '1', "PAGE: Vanessa Reyes\nITEM: CHRISTMAS LIGHTS\nPRICE: ₱299\nQUANTITY: 1");
        $before = \App\Models\PageSetting::count();

        Artisan::call('chatbot:shop-details-diff');
        $out = Artisan::output();

        $this->assertStringContainsString('Tech Flask Ph', $out);
        $this->assertStringContainsString('CHRISTMAS LIGHTS', $out);
        $this->assertStringContainsString('ITEM: TECH FLASK', $out);
        $this->assertStringNotContainsString('Alexa Angeles', $out);
        $this->assertStringContainsString('1 of 2 pages differ', $out);
        $this->assertSame($before, \App\Models\PageSetting::count(), 'read-only');
    }

    public function test_S_158_6_an_incepxion_style_page_on_forbeast_uses_the_same_rule(): void
    {
        $this->page('Incepxion PH — Smart Home', '3003');
        $this->settings()->set('Incepxion PH — Smart Home', 'chatbot', 'provider', 'gateway');
        $this->fields('Incepxion PH — Smart Home', 'WIFI PLUG', 'PHP 1,299', '1');

        $bot = app(ChatbotPage::class)->botfields('Incepxion PH — Smart Home');
        $this->assertSame("PAGE: Incepxion PH — Smart Home\nITEM: WIFI PLUG\nPRICE: ₱1,299\nQUANTITY: 1", $bot['SHOP DETAILS']);
    }

    public function test_S_158_3_the_shop_details_column_keeps_its_place_so_sheet_pastes_stay_aligned(): void
    {
        $keys = fn () => array_column(app(ChatbotFields::class)->columns(), 'key');
        $this->assertSame('shop_details', last($keys()), 'no typed row: at the end');

        // Ang page na may lumang tina-type na row: kasama ng ibang ginagamit na key, pagkatapos ng mga idinagdag (gaya ngayon).
        app(ChatbotFields::class)->add('Promo Text');
        $this->settings()->set('Alexa Angeles', 'chatbot', 'shop_details', 'old');
        $this->settings()->set('Alexa Angeles', 'chatbot', 'zz_note', 'x');
        $this->assertSame(['promo_text', 'shop_details', 'zz_note'], array_slice($keys(), -3));
    }
}
