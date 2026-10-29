<?php

namespace Tests\Feature\Chatblast;

use App\Models\ChatbotFlow;
use App\Models\PageSetting;
use App\Services\Chatbot\AdGroups;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ang ad group ay nakapagtatakda ng bawat value na naitatakda ng page (forms, prompts, shop details, after order, comment
 * replies, item, flow, Seq, ang AI ng page …); ang pagkakakilanlan at mga switch lang ng page ang hindi.
 */
class AdGroupAllValuesTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
        ]]);
        $this->travelTo('2026-10-20 10:00:00');
        $this->page('Precious Lopez', '1001');
        app(PageSettings::class)->set('Precious Lopez', 'chatbot', 'fill_up_form', 'Page form');
    }

    /** Isang tamang value para sa bawat uri ng key (larawan = link, flow = totoong flow, AI = mapipili). */
    private function sample(array $k): string
    {
        return match (true) {
            $k['key'] === 'flow_id' => (string) ChatbotFlow::create(['name' => 'Serum flow', 'graph' => ['nodes' => [], 'edges' => []]])->id,
            $k['key'] === 'ai_provider' => 'openai',
            $k['key'] === 'ai_fallback' => 'none',
            $k['key'] === 'ai_local_model' => 'qwen3:14b',
            $k['key'] === 'ai_local_timeout' => '9',
            $k['kind'] === 'image' => 'https://x.test/' . $k['key'] . '.jpg',
            default => 'Group ' . $k['key'],
        };
    }

    public function test_S_192_3_every_value_column_of_the_page_row_is_a_group_value_and_saves(): void
    {
        $this->actingAs($this->user());
        app(ChatbotFields::class)->add('Promo Text'); // isang column na idinagdag sa "+ Column"
        $groups = app(AdGroups::class);
        $id = $groups->save('Precious Lopez', null, 'Serum', ['111'], [])['id'];

        $keys = collect($this->getJson('/chatbot/ad-groups?page=Precious%20Lopez')->assertOk()->json('keys'))->keyBy('key');
        $columns = collect(app(ChatbotFields::class)->columns())->pluck('key')->reject(fn ($k) => in_array($k, ['flow', 'shop_details'], true));
        $this->assertSame([], $columns->diff($keys->keys())->values()->all(), 'bawat column ng page (maliban sa FLOW na text at SHOP DETAILS na binubuo) ay value ng group');
        foreach (['fill_up_form', 'loop_3_form', 'prompt_1', 'prompt_2', 'prompt_3', 'after_order_1', 'after_order_img_1', 'comment_reply_1', 'comment_pm', 'promo_text',
            'flow_id', 'ai_provider', 'ai_fallback', 'ai_local_model', 'ai_local_timeout'] as $key) {
            $this->assertTrue($keys->has($key), $key . ' ay value ng group');
        }
        $this->assertSame('FLOW', $keys['flow_id']['label']);
        $this->assertSame([['openai', 'OpenAI'], ['local', 'Local']], $keys['ai_provider']['options']);
        $this->assertSame([['openai', 'OpenAI'], ['local', 'Local'], ['none', 'None']], $keys['ai_fallback']['options']);

        $n = 0;
        foreach ($keys as $key => $k) {
            if (++$n % 25 === 0) $this->travel(61)->seconds(); // 30 sulat kada minuto (throttle ng page values)
            $value = $this->sample($k);
            $this->postJson('/chatbot/ad-groups/value', ['page' => 'Precious Lopez', 'id' => $id, 'key' => $key, 'value' => $value])->assertOk();
            $this->assertSame($value, $groups->groups('Precious Lopez')[$id]['values'][$key] ?? null, $key . ' ay naitala');
        }
        // Ang mali sa value ay tinatanggihan gaya ng sa page.
        $this->travel(61)->seconds();
        $bad = fn (string $key, string $value) => $this->postJson('/chatbot/ad-groups/value', ['page' => 'Precious Lopez', 'id' => $id, 'key' => $key, 'value' => $value]);
        $bad('after_order_img_1', 'not a link')->assertStatus(422);
        $bad('ai_provider', 'gemini')->assertStatus(422)->assertJson(['error' => 'AI provider: Pick OpenAI or Local.']);
        $bad('ai_local_timeout', '999')->assertStatus(422);
        $bad('ai_provider', 'default')->assertStatus(422)->assertJson(['error' => 'AI provider: leave it empty to use the page\'s.']);
        $bad('unknown_column', 'x')->assertStatus(422)->assertJson(['error' => 'unknown_column is not a value of the page.']);
    }

    public static function pageOnly(): array
    {
        return [
            'On/Off' => ['active'], 'provider (Botcake / Forbeast)' => ['provider'], 'Botcake ID' => ['page_id'], 'token' => ['token'],
            'the Ad-based switch' => ['mode'], 'auto-switch history' => ['auto_switch'], 'mock AI' => ['mock_ai'], 'FLOW text' => ['flow'],
            'SHOP DETAILS (built)' => ['shop_details'], 'site-only AI' => ['ai_breaker_seconds'], 'the group\'s own name' => ['name'], 'the group\'s ad list' => ['ads'],
            'not a key' => ['chat.key'],
        ];
    }

    #[DataProvider('pageOnly')]
    public function test_S_192_4_identity_and_switch_keys_are_refused_for_a_group(string $key): void
    {
        $this->actingAs($this->user());
        $id = app(AdGroups::class)->save('Precious Lopez', null, 'Serum', ['111'], [])['id'];
        $before = PageSetting::count();

        $this->postJson('/chatbot/ad-groups/value', ['page' => 'Precious Lopez', 'id' => $id, 'key' => $key, 'value' => '1'])
            ->assertStatus(422)->assertJson(['error' => $key . ' is the page\'s own setting (identity or a switch): it is set on the page only, not in a group.']);
        $this->assertFalse(app(AdGroups::class)->save('Precious Lopez', $id, 'Serum', ['111'], [$key => '1'])['ok']);
        $this->assertSame($before, PageSetting::count(), 'walang naisulat');
        $this->assertFalse(AdGroups::isValueKey($key));
        $this->assertNotContains($key, collect($this->getJson('/chatbot/ad-groups?page=Precious%20Lopez')->json('keys'))->pluck('key')->all());
    }
}
