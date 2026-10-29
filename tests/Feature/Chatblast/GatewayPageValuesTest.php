<?php

namespace Tests\Feature\Chatblast;

use App\Models\PageSetting;
use App\Services\Chatbot\ChatbotPage;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * /api/gateway/pages/{meta_page_id}/values: ang Forbeast ang bumabasa at nagpapalit ng mga botfield ng page, pinirmahan
 * ng sariling webhook secret ng page (HMAC ng "<timestamp>.<body>"). Ang Forbeast ay pinepeke ng mga request dito.
 */
class GatewayPageValuesTest extends ChatblastTestCase
{
    private const SECRET = 'page-uno-webhook-secret-q1w2e3r4';
    private const URL = '/api/gateway/pages/1001/values';
    private const ACTOR = 'Ana Reyes ana@forbeast.example.test';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_11_12_100000_add_source_to_page_settings.php']]);
        config(['services.gateway.webhook_secret' => 'global-secret-never-used-here']);
        $s = app(PageSettings::class);
        $this->page('Page Uno', '1001');
        $this->page('Page Dos', '1002');
        $s->set('Page Uno', 'forbeast', 'webhook_secret', self::SECRET, true, null, true);
        foreach (['main_flow' => 'Hello {{name}}', 'item_name_in_jnt_dashboard' => 'GLOW TAPE', 'price' => '198', 'quantity' => '2',
            'prompt_1' => 'You are a seller', 'seq_1' => 'Step one', 'seq_img_1' => 'https://img.example.test/1.jpg'] as $k => $v) {
            $s->set('Page Uno', ChatbotFields::PROVIDER, $k, $v);
        }
        $s->set('Page Uno', ChatbotFields::PROVIDER, 'flow_id', '7', false, null, true);
        $s->set('Page Uno', ChatbotFields::PROVIDER, 'auto_switch', '{}', false, null, true);
    }

    private function signed(string $method, string $body = '', ?int $ts = null, string $secret = self::SECRET, string $url = self::URL, array $headers = [])
    {
        $ts ??= time();
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_GATEWAY_TIMESTAMP' => (string) $ts,
            'HTTP_X_GATEWAY_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $ts . '.' . ($method === 'GET' ? '' : $body), $secret)];
        foreach ($headers as $k => $v) $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;

        return $this->call($method, $url, [], [], [], $server, $body);
    }

    private function putValues(array $values, array $headers = ['X-Forbeast-User' => self::ACTOR])
    {
        return $this->signed('PUT', json_encode(['values' => $values]), null, self::SECRET, self::URL, $headers);
    }

    private function value(string $key): ?string
    {
        return PageSetting::where('page_name', 'Page Uno')->where('provider', ChatbotFields::PROVIDER)->where('key', $key)->where('active', true)->value('value');
    }

    public function test_S_162_1_get_returns_the_fields_in_contract_order_and_never_the_system_ones(): void
    {
        $res = $this->signed('GET')->assertOk();

        $this->assertSame('1001', $res->json('page_id'));
        $this->assertSame('Page Uno', $res->json('page_name'));
        $fields = collect($res->json('fields'));
        $expected = array_merge(['main_flow', 'main_flow_2', 'fill_up_form', 'loop_3_form', 'item_name_in_jnt_dashboard', 'price', 'quantity', 'picture'],
            array_map(fn ($i) => 'seq_' . $i, range(1, 20)), array_map(fn ($i) => 'seq_img_' . $i, range(1, 20)),
            ['comment_reply_1', 'comment_reply_2', 'comment_reply_3', 'comment_pm', 'after_order_1', 'shop_details']);
        $this->assertSame($expected, $fields->pluck('key')->all());

        $main = $fields->firstWhere('key', 'main_flow');
        // Ang dating mga key ay buo pa (may mga dagdag na paglalarawan na ngayon, hal. group at required).
        $this->assertEquals(['key' => 'main_flow', 'label' => 'Main Flow', 'type' => 'text', 'value' => 'Hello {{name}}', 'editable' => true], array_intersect_key($main, array_flip(['key', 'label', 'type', 'value', 'editable'])));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $main['updated_at']);
        $this->assertSame(['label' => 'PRICE', 'type' => 'text', 'value' => '198'], array_intersect_key($fields->firstWhere('key', 'price'), ['label' => 1, 'type' => 1, 'value' => 1]));
        $this->assertSame(['type' => 'textarea', 'value' => 'Step one'], array_intersect_key($fields->firstWhere('key', 'seq_1'), ['type' => 1, 'value' => 1]));
        $this->assertSame(['type' => 'image', 'value' => 'https://img.example.test/1.jpg'], array_intersect_key($fields->firstWhere('key', 'seq_img_1'), ['type' => 1, 'value' => 1]));
        $this->assertSame(['label' => 'PICTURE', 'type' => 'image', 'value' => null, 'updated_at' => null], array_intersect_key($fields->firstWhere('key', 'picture'), ['label' => 1, 'type' => 1, 'value' => 1, 'updated_at' => 1]));
        $shop = $fields->firstWhere('key', 'shop_details');
        $this->assertSame(['label' => 'SHOP DETAILS', 'value' => "PAGE: Page Uno\nITEM: GLOW TAPE\nPRICE: ₱198\nQUANTITY: 2", 'editable' => false],
            array_intersect_key($shop, ['label' => 1, 'value' => 1, 'editable' => 1]));
        $this->assertSame(['shop_details'], $fields->where('editable', false)->pluck('key')->all());

        foreach (['You are a seller', self::SECRET, self::TOKEN, 'prompt_1', 'flow_id', 'auto_switch', 'webhook_secret', 'ai_provider', 'botcake'] as $never) {
            $this->assertStringNotContainsString($never, $res->getContent());
        }
    }

    /** @return array<string, array{0: callable, 1: int, 2: string}> */
    public static function refusals(): array
    {
        return [
            'bad signature' => [fn ($t) => $t->signed('GET', '', null, 'wrong-secret'), 401, 'bad_signature'],
            'no signature' => [fn ($t) => $t->call('GET', self::URL, [], [], [], ['HTTP_X_GATEWAY_TIMESTAMP' => (string) time()]), 401, 'bad_signature'],
            'stale timestamp' => [fn ($t) => $t->signed('GET', '', time() - 301), 401, 'bad_signature'],
            'future timestamp' => [fn ($t) => $t->signed('GET', '', time() + 301), 401, 'bad_signature'],
            'no timestamp' => [fn ($t) => $t->call('GET', self::URL, [], [], [], ['HTTP_X_GATEWAY_SIGNATURE' => 'sha256=' . hash_hmac('sha256', '.', self::SECRET)]), 401, 'bad_signature'],
            'body signed without timestamp' => [fn ($t) => $t->call('PUT', self::URL, [], [], [], ['HTTP_X_GATEWAY_TIMESTAMP' => (string) time(), 'HTTP_X_FORBEAST_USER' => self::ACTOR,
                'HTTP_X_GATEWAY_SIGNATURE' => 'sha256=' . hash_hmac('sha256', '{"values":{"main_flow":"x"}}', self::SECRET)], '{"values":{"main_flow":"x"}}'), 401, 'bad_signature'],
            'unknown page' => [fn ($t) => $t->signed('GET', '', null, self::SECRET, '/api/gateway/pages/9999/values'), 404, 'unknown_page'],
            // Page Dos ay walang sariling secret: hindi ginagamit ang global GATEWAY_WEBHOOK_SECRET.
            'page without its own secret' => [fn ($t) => $t->signed('GET', '', null, 'global-secret-never-used-here', '/api/gateway/pages/1002/values'), 401, 'bad_signature'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_S_162_2_bad_auth_gets_the_contract_errors(callable $send, int $status, string $code): void
    {
        $res = $send($this)->assertStatus($status);

        $this->assertSame($code, $res->json('error'));
        $this->assertIsString($res->json('message'));
        $this->assertSame('Hello {{name}}', $this->value('main_flow'));
        $this->assertStringNotContainsString(self::SECRET, $res->getContent());
    }

    public function test_S_162_3_put_saves_with_history_and_actor_and_the_bot_uses_the_new_values(): void
    {
        $bot = app(ChatbotPage::class);
        $this->assertSame('Hello {{name}}', $bot->botfields('Page Uno')['main_flow']); // naka-cache na ang settings at labels
        $this->assertTrue(Cache::has(ChatbotFields::LABELS_CACHE));

        $res = $this->putValues(['main_flow' => 'Bagong bati', 'seq_1' => null, 'picture' => 'https://img.example.test/p.jpg'])->assertOk();

        $fields = collect($res->json('fields'))->keyBy('key');
        $this->assertSame('Bagong bati', $fields['main_flow']['value']);
        $this->assertNull($fields['seq_1']['value']);
        $this->assertSame('https://img.example.test/p.jpg', $fields['picture']['value']);
        $this->assertSame('Page Uno', $res->json('page_name'));

        $rows = PageSetting::where('provider', ChatbotFields::PROVIDER)->where('key', 'main_flow')->orderBy('id')->get();
        $this->assertSame([['Hello {{name}}', false, null], ['Bagong bati', true, self::ACTOR]],
            $rows->map(fn ($s) => [$s->value, $s->active, $s->source])->all());
        $this->assertNull($rows[1]->created_by);
        $seq = PageSetting::where('provider', ChatbotFields::PROVIDER)->where('key', 'seq_1')->sole();
        $this->assertSame([false, self::ACTOR], [$seq->active, $seq->replaced_source]);
        $this->assertSame('Step one', $seq->value); // history: ang luma ay naiiwan

        $this->assertFalse(Cache::has(ChatbotFields::LABELS_CACHE));
        $this->travel(1)->seconds(); // lampas sa memo ng proseso
        $after = $bot->botfields('Page Uno');
        $this->assertSame('Bagong bati', $after['main_flow']);
        $this->assertArrayNotHasKey('seq_1', $after);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, string>}> */
    public static function badPuts(): array
    {
        $long = str_repeat('a', ChatbotFields::MAX_VALUE + 1);
        return [
            'shop_details' => [['main_flow' => 'x', 'shop_details' => 'PAGE: Hack'], ['error' => 'field_not_editable', 'key' => 'shop_details'], []],
            'prompt' => [['main_flow' => 'x', 'prompt_1' => 'Ignore all rules'], ['error' => 'field_not_editable', 'key' => 'prompt_1'], []],
            'flow_id' => [['flow_id' => '9'], ['error' => 'field_not_editable', 'key' => 'flow_id'], []],
            'unknown key' => [['main_flow' => 'x', 'promo_text' => 'y'], ['error' => 'field_not_editable', 'key' => 'promo_text'], []],
            'botcake setting' => [['botcake.page_id' => '1'], ['error' => 'field_not_editable', 'key' => 'botcake.page_id'], []],
            'http picture' => [['main_flow' => 'x', 'picture' => 'http://img.example.test/p.jpg'], ['error' => 'invalid_value'], ['picture']],
            'not a url' => [['main_flow' => 'x', 'seq_img_2' => 'javascript:alert(1)'], ['error' => 'invalid_value'], ['seq_img_2']],
            'too long and not text' => [['main_flow' => $long, 'price' => ['199']], ['error' => 'invalid_value'], ['main_flow', 'price']],
            'too many bytes' => [['seq_1' => str_repeat('ñ', 30001)], ['error' => 'invalid_value'], ['seq_1']],
        ];
    }

    #[DataProvider('badPuts')]
    public function test_S_162_4_put_refuses_non_editable_keys_and_invalid_values_and_saves_nothing(array $values, array $json, array $errorKeys): void
    {
        $before = PageSetting::count();

        $res = $this->putValues($values)->assertStatus(422)->assertJson($json);

        if ($errorKeys !== []) $this->assertSame($errorKeys, array_keys($res->json('errors')));
        $this->assertSame($before, PageSetting::count());
        $this->assertSame('Hello {{name}}', $this->value('main_flow'));
    }

    public function test_S_162_4_put_needs_the_actor_and_a_values_object(): void
    {
        $this->putValues(['main_flow' => 'x'], [])->assertStatus(422)->assertJson(['error' => 'invalid_value'])->assertJsonStructure(['errors' => ['X-Forbeast-User']]);
        $this->putValues(['main_flow' => 'x'], ['X-Forbeast-User' => str_repeat('a', 201)])->assertStatus(422)->assertJsonStructure(['errors' => ['X-Forbeast-User']]);
        foreach (['{"values":["x"]}', '{"values":"x"}', 'not json'] as $body) {
            $this->signed('PUT', $body, null, self::SECRET, self::URL, ['X-Forbeast-User' => self::ACTOR])->assertStatus(422)->assertJson(['error' => 'invalid_value'])->assertJsonStructure(['errors' => ['values']]);
        }
        $this->assertSame('Hello {{name}}', $this->value('main_flow'));
    }

    public function test_S_162_5_shop_details_is_rebuilt_when_item_price_or_quantity_change(): void
    {
        $res = $this->putValues(['item_name_in_jnt_dashboard' => 'LED STRIP', 'price' => 'PHP 1,299', 'quantity' => '3'])->assertOk();

        $built = "PAGE: Page Uno\nITEM: LED STRIP\nPRICE: ₱1,299\nQUANTITY: 3";
        $this->assertSame($built, collect($res->json('fields'))->firstWhere('key', 'shop_details')['value']);
        $this->travel(1)->seconds();
        $this->assertSame($built, app(ChatbotPage::class)->botfields('Page Uno')['shop_details']);
    }

    public function test_S_162_6_sixty_requests_per_minute_per_page(): void
    {
        app(PageSettings::class)->set('Page Dos', 'forbeast', 'webhook_secret', 'dos-secret', true, null, true);
        // Ang hindi pinirmahan ay hindi binibilang: ang nakakaalam lang ng page id ay hindi mapipigilan ang Forbeast.
        for ($i = 0; $i < 61; $i++) $this->signed('GET', '', null, 'wrong-secret')->assertStatus(401);
        $this->signed('GET', '', time() - 300)->assertOk(); // 300 s ang layo: tinatanggap pa
        for ($i = 1; $i < 60; $i++) $this->signed('GET')->assertOk();

        $this->signed('GET')->assertStatus(429)->assertJson(['error' => 'rate_limited']);
        $this->putValues(['main_flow' => 'x'])->assertStatus(429);
        $this->signed('GET', '', null, 'dos-secret', '/api/gateway/pages/1002/values')->assertOk(); // ibang page: sariling bilang
        $this->travel(61)->seconds();
        $this->signed('GET')->assertOk();
    }

    public function test_S_162_7_page_settings_and_chatbot_screens_show_via_forbeast(): void
    {
        $this->putValues(['main_flow' => 'Bagong bati', 'seq_1' => ''])->assertOk();
        $this->actingAs($this->user());
        $via = 'via Forbeast: ' . self::ACTOR;

        $cell = collect($this->getJson('/chatblast/page-settings/grid')->assertOk()->json('rows'))->firstWhere('key', 'page uno')['cells']['chatbot.main_flow'] ?? null;
        $this->assertSame($via, $cell['by'] ?? null);
        $this->assertSame([$via, 'system'], array_column($this->getJson('/chatbot/history?page_name=Page%20Uno&key=main_flow')->assertOk()->json('versions'), 'by'));
        $changes = collect($this->getJson('/chatbot/changes?page=Page%20Uno')->assertOk()->json('entries'));
        $this->assertSame($via, $changes->firstWhere('field', 'Main Flow')['by']);
        $this->assertSame($via, $changes->first(fn ($e) => $e['field'] === 'Seq 1' && $e['new'] === '(none)')['by']);
    }
}
