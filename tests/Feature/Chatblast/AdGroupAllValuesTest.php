<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Models\PageSetting;
use App\Services\Chatbot\AdGroups;
use App\Services\Chatbot\ChatbotAiChain;
use App\Services\Chatbot\ChatbotAiQueue;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotRunner;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowGraph;
use App\Services\Chatbot\FlowRun;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ang ad group ay nakapagtatakda ng bawat value na naitatakda ng page (forms, prompts, shop details, after order, comment
 * replies, item, flow, Seq, ang AI ng page …); ang pagkakakilanlan at mga switch lang ng page ang hindi.
 */
class AdGroupAllValuesTest extends ChatblastTestCase
{
    private const SEND = 'https://botcake.io/api/public_api/v1/pages/1001/flows/send_content';
    private const OPENAI = 'https://api.openai.com/v1/chat/completions';
    private const LOCAL = 'http://127.0.0.1:11435/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_25_100000_create_chatbot_waits_table.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
            'database/migrations/2026_10_28_100000_create_chatbot_ai_requests_table.php',
        ]]);
        config(['services.chatbot.replies_enabled' => true, 'services.botcake.async_sends' => false, 'services.chatbot.ai_async' => false,
            'services.openai.key' => 'sk-test-not-real', 'services.openai.timeout' => 60,
            'services.chatbot_ai.providers.local.url' => 'http://127.0.0.1:11435/v1', 'services.chatbot_ai.providers.local.key' => '']);
        Http::fake([
            self::SEND => Http::response(['success' => true]),
            self::OPENAI => Http::response(['choices' => [['message' => ['content' => 'openai answer']]]]),
            self::LOCAL => Http::response(['choices' => [['message' => ['content' => 'local answer']]]]),
            '*' => Http::response('not expected', 599),
        ]);
        ChatbotAiChain::forget();
        $this->travelTo('2026-10-20 10:00:00');
        $this->page('Precious Lopez', '1001');
        $s = app(PageSettings::class);
        foreach (['fill_up_form' => 'Page form', 'loop_3_form' => 'Page loop', 'prompt_1' => 'Page prompt', 'price' => '199'] as $k => $v) $s->set('Precious Lopez', 'chatbot', $k, $v);
    }

    /** Ang flow: ang dalawang form, saka ang AI na ang system ay ang Prompt 1 (ipinapadala ang sagot). */
    private function formAndAiFlow(): void
    {
        $flow = ChatbotFlow::create(['name' => 'Form flow', 'graph' => FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'],
            ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => '{{Fill-Up Form}}|{{Loop 3 Form}}|{{PRICE}}']]]],
            ['id' => 'n3', 'type' => 'ai', 'config' => ['system' => '{{Prompt 1}}', 'user' => '{{last_input}}', 'save_to' => 'answer', 'history' => 0]],
            ['id' => 'n4', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'AI: {{answer}}']]]],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2'], ['from' => 'n2', 'port' => 'continue', 'to' => 'n3'], ['from' => 'n3', 'port' => 'success', 'to' => 'n4']]])]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);
        app(ChatbotPage::class)->link('Precious Lopez', $flow->fresh());
    }

    private function says(string $psid, ?string $ad): void
    {
        $chat = BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Precious Lopez', 'psid' => $psid, 'name' => 'Ana Cruz', 'message' => 'Hi po',
            'payload' => $ad === null ? [] : ['ad_id' => $ad], 'received_at' => now()]);
        app(ChatbotRunner::class)->handle($chat->id);
    }

    /** @return array<int, string> ang mga text na naipadala sa Botcake para sa PSID */
    private function sent(string $psid): array
    {
        return Http::recorded(fn (HttpRequest $r) => $r->url() === self::SEND && ($r->data()['psid'] ?? null) === $psid)
            ->map(fn ($p) => $p[0]->data()['data']['content']['messages'][0]['text'] ?? '')->values()->all();
    }

    /** @return array<int, array{0: string, 1: string}> [aling AI, ang system prompt] ng bawat tawag sa AI */
    private function aiCalls(): array
    {
        return Http::recorded(fn (HttpRequest $r) => in_array($r->url(), [self::OPENAI, self::LOCAL], true))
            ->map(fn ($p) => [$p[0]->url() === self::LOCAL ? 'local' : 'openai', (string) ($p[0]->data()['messages'][0]['content'] ?? '')])->values()->all();
    }

    private function serum(array $values): int
    {
        $groups = app(AdGroups::class);
        $groups->setMode('Precious Lopez', true);

        return $groups->save('Precious Lopez', null, 'Serum', ['111'], $values)['id'];
    }

    public function test_S_192_1_a_group_sets_the_forms_the_prompt_and_the_ai_and_a_customer_without_a_group_gets_the_pages(): void
    {
        $this->formAndAiFlow();
        $this->serum(['fill_up_form' => 'Serum form', 'loop_3_form' => 'Serum loop', 'prompt_1' => 'Serum prompt', 'ai_provider' => 'local', 'ai_local_model' => 'qwen3:14b']);

        $this->says('7001', '111');
        $this->assertSame(['Serum form|Serum loop|199', 'AI: local answer'], $this->sent('7001'));
        $this->assertSame([['local', 'Serum prompt']], $this->aiCalls());
        $this->assertSame('qwen3:14b', Http::recorded(fn (HttpRequest $r) => $r->url() === self::LOCAL)->first()[0]->data()['model']);

        $this->says('7002', '999'); // ad sa walang group
        $this->says('7003', null);  // walang ad
        $this->assertSame(['Page form|Page loop|199', 'AI: openai answer'], $this->sent('7002'));
        $this->assertSame(['Page form|Page loop|199', 'AI: openai answer'], $this->sent('7003'));
        $this->assertSame([['local', 'Serum prompt'], ['openai', 'Page prompt'], ['openai', 'Page prompt']], $this->aiCalls());
    }

    public function test_S_192_2_an_empty_group_value_falls_back_to_the_page_value(): void
    {
        $this->formAndAiFlow();
        $id = $this->serum(['fill_up_form' => 'Serum form', 'prompt_1' => 'Serum prompt']);
        app(AdGroups::class)->setValue('Precious Lopez', $id, 'prompt_1', ''); // binura: ang page value na

        $this->says('7001', '111');
        $this->assertSame(['Serum form|Page loop|199', 'AI: openai answer'], $this->sent('7001'));
        $this->assertSame([['openai', 'Page prompt']], $this->aiCalls());
    }

    public function test_S_192_5_a_page_based_page_ignores_its_groups_values(): void
    {
        $this->formAndAiFlow();
        $this->serum(['fill_up_form' => 'Serum form', 'loop_3_form' => 'Serum loop', 'prompt_1' => 'Serum prompt', 'ai_provider' => 'local', 'ai_local_model' => 'qwen3:14b']);
        app(AdGroups::class)->setMode('Precious Lopez', false);

        $this->says('7001', '111');
        $this->assertSame(['Page form|Page loop|199', 'AI: openai answer'], $this->sent('7001'));
        $this->assertSame([['openai', 'Page prompt']], $this->aiCalls());
    }

    public function test_S_192_1_the_inbox_panel_shows_the_customers_group_values(): void
    {
        $this->formAndAiFlow();
        $this->serum(['fill_up_form' => 'Serum form']);
        foreach (['7001' => '111', '7002' => '999'] as $psid => $ad) {
            BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Precious Lopez', 'psid' => $psid, 'name' => 'Ana', 'message' => 'Hi', 'payload' => ['ad_id' => $ad], 'received_at' => now()]);
        }
        $this->actingAs($this->user());
        $form = fn (string $psid) => collect($this->getJson('/chatbot/inbox/panel?page_id=1001&psid=' . $psid)->assertOk()->json('botfields'))->firstWhere('label', 'Fill-Up Form')['value'] ?? null;

        $this->assertSame('Serum form', $form('7001'));
        $this->assertSame('Page form', $form('7002'));
    }

    public function test_S_192_1_the_run_resumed_after_the_pool_keeps_the_group_it_asked_with(): void
    {
        $flow = ChatbotFlow::create(['name' => 'After AI', 'graph' => FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'],
            ['id' => 'n3', 'type' => 'ai', 'config' => ['user' => '{{last_input}}', 'save_to' => 'answer', 'history' => 0]],
            ['id' => 'n4', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => '{{answer}}|{{Fill-Up Form}}']]]],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n3'], ['from' => 'n3', 'port' => 'success', 'to' => 'n4']]])]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);
        app(ChatbotPage::class)->link('Precious Lopez', $flow->fresh());
        $a = $this->serum(['fill_up_form' => 'Serum form']);
        app(AdGroups::class)->save('Precious Lopez', null, 'Toner', ['222'], ['fill_up_form' => 'Toner form']);
        // Ang tanong sa AI ay sa Serum (hal. sequence na naka-pin), pero ang pinakabagong ad ng customer ay nasa Toner na.
        BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Precious Lopez', 'psid' => '7001', 'name' => 'Ana', 'message' => 'Hi', 'payload' => ['ad_id' => '222'], 'received_at' => now()]);
        $ticket = ['request_id' => 'req-192-1', 'page_id' => 1001, 'psid' => 7001, 'chat_id' => null, 'flow_id' => $flow->id, 'node_id' => 'n3'];
        DB::table(ChatbotAiQueue::TABLE)->insert(['request_id' => 'req-192-1', 'page_id' => 1001, 'psid' => 7001, 'chat_id' => null, 'flow_id' => $flow->id, 'node_id' => 'n3',
            'page_name' => 'Precious Lopez', 'model' => 'gpt-4o-mini', 'messages' => '[]', 'max_tokens' => 100, 'status' => 'answered', 'answer' => 'Opo',
            'context' => json_encode(['input' => 'Hi', 'full_name' => 'Ana', 'received_at' => '2026-10-20 18:00', 'ai_calls' => 1, 'steps' => 1, 'ad_group' => $a]),
            'deadline_at' => now()->addMinute(), 'created_at' => now(), 'updated_at' => now()]);
        (new CustomerFields(1001, 7001))->set(CustomerFields::AI, 'req-192-1');

        $this->assertSame('done', app(ChatbotRunner::class)->resumeAi($ticket));
        $this->assertSame(['Opo|Serum form'], $this->sent('7001'));
    }

    public function test_S_192_3_a_group_cannot_pick_local_when_no_local_model_is_set_anywhere(): void
    {
        config(['services.chatbot_ai.providers.local.model' => '']);
        $id = $this->serum([]);
        $groups = app(AdGroups::class);

        $this->assertSame('AI provider: ' . \App\Services\Chatbot\ChatbotAiSettings::NO_MODEL, $groups->setValue('Precious Lopez', $id, 'ai_provider', 'local')['error'] ?? null);
        $this->assertTrue($groups->setValue('Precious Lopez', $id, 'ai_local_model', 'qwen3:14b')['ok']);
        $this->assertTrue($groups->setValue('Precious Lopez', $id, 'ai_provider', 'local')['ok'], 'may model na ang group');
    }

    public function test_S_192_1_the_ai_answered_by_the_pool_uses_the_groups_ai_too(): void
    {
        $id = $this->serum(['ai_provider' => 'local', 'ai_local_model' => 'qwen3:14b']);
        $run = new FlowRun(1, [], 'Precious Lopez', '1001', [], new CustomerFields(1001, 7001), null, 'Hi', 'Ana Cruz', '2026-10-20 18:00');
        $run->adGroup = $id;
        ChatbotAiQueue::submit($run, 'n3', 'gpt-4o-mini', [['role' => 'user', 'content' => 'Hi']], 100);
        $row = DB::table(ChatbotAiQueue::TABLE)->first();

        $this->assertSame(['local', 'openai'], array_column(ChatbotAiChain::forRow($row), 'provider'), 'ang group ng takbo ay kasama ng ticket (Local, saka ang default na fallback)');
        $this->assertSame('qwen3:14b', ChatbotAiChain::forRow($row)[0]['model']);
        DB::table(ChatbotAiQueue::TABLE)->update(['context' => json_encode(['input' => 'Hi'])]); // ticket na mula pa bago ito (walang ad_group): ang AI ng page
        $this->assertSame(['openai'], array_column(ChatbotAiChain::forRow(DB::table(ChatbotAiQueue::TABLE)->first()), 'provider'));
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
        Queue::fake();
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
        // Spec 210: Claude at DeepSeek ay mapipili rin (ang key ay sa pool ng kumpanya).
        $this->assertSame([['openai', 'OpenAI'], ['anthropic', 'Claude'], ['deepseek', 'DeepSeek'], ['local', 'Local']], $keys['ai_provider']['options']);
        $this->assertSame([['openai', 'OpenAI'], ['anthropic', 'Claude'], ['deepseek', 'DeepSeek'], ['local', 'Local'], ['none', 'None']], $keys['ai_fallback']['options']);

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
        Queue::fake();
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
