<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\ChatbotReply;
use App\Services\Chatbot\BotcakeOutbox;
use App\Services\Chatbot\ChatbotProvider;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowRun;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * Ang totoong address na dinaanan ng sagot: ang {{replies_via}} ay ang pangalan ng address ng page sa forbeast gateway
 * (hal. "Incepxion") kapag hindi ito ang default; kung wala, gaya ng dati.
 */
class ShowRealRouteTest extends ChatblastTestCase
{
    private const GW = 'https://gw.example.test/api';
    private const APP2 = 'https://incepxion.example.test/api';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_29_100000_create_botcake_send_requests_table.php',
            'database/migrations/2026_10_30_098000_add_raw_to_chatbot_replies.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
            'database/migrations/2026_11_09_100000_add_via_to_chatbot_replies.php',
            'database/migrations/2026_11_11_100000_add_real_send_to_chatbot_replies.php',
            'database/migrations/2026_11_13_100000_widen_via_on_chatbot_replies.php',
            'database/migrations/2026_11_17_100000_add_route_to_chatbot_replies.php',
        ]]);
        config([
            'services.chatbot.replies_enabled' => true,
            'services.gateway.url' => self::GW, 'services.gateway.key' => 'gw_global_key',
            'services.gateway.urls' => 'incepxion=' . self::APP2,
            'services.incepxionbot.url' => '',
        ]);
        $this->page('Page Uno', '1001');
        Http::fake([self::GW . '/*' => Http::response(['status' => 'queued'], 202), self::APP2 . '/*' => Http::response(['status' => 'queued'], 202),
            'https://botcake.io/*' => Http::response(['success' => true])]);
    }

    private function chat(array $payload): int
    {
        return BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => '7001', 'name' => 'Ana', 'message' => 'hi',
            'message_id' => 'm' . random_int(1, 999999), 'payload' => $payload, 'received_at' => now()])->id;
    }

    private function via(?int $chatId): string
    {
        return (new FlowRun(1, [], 'Page Uno', '1001', [], new CustomerFields(1001, 7001), $chatId, 'hi', 'Ana', '2026-11-04 09:00'))->text('{{replies_via}}');
    }

    private function gateway(?string $app): void
    {
        $s = app(PageSettings::class);
        $s->set('Page Uno', 'chatbot', 'provider', 'gateway');
        if ($app !== null) $s->set('Page Uno', 'chatbot', 'gateway_app', $app, false, null, true);
    }

    public function test_S_224_1_1_a_page_on_forbeast_without_an_address_is_forbeast(): void
    {
        $this->gateway(null);
        $this->assertSame('Forbeast', $this->via($this->chat(['source' => 'gateway'])));
        $this->assertSame('Forbeast', $this->via(null));
    }

    public function test_S_224_1_2_a_page_on_the_incepxion_address_is_incepxion(): void
    {
        $this->gateway('incepxion');
        $this->assertSame('Incepxion', $this->via($this->chat(['source' => 'gateway'])));
        $this->assertSame('Incepxion', $this->via(null), 'without a chat: the page provider and its address');
        $this->assertSame('Incepxion', ChatbotProvider::repliesVia('Page Uno', ChatbotProvider::FORBEAST));
    }

    public function test_S_224_1_3_botcake_stays_botcake_even_with_an_address(): void
    {
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'gateway_app', 'incepxion', false, null, true);
        $this->assertSame('Botcake', $this->via(null), 'page on Botcake');
        $this->gateway('incepxion');
        $this->assertSame('Botcake', $this->via($this->chat(['source' => 'default_reply'])), 'a chat Botcake delivered');
    }

    public function test_S_224_1_4_without_gateway_urls_the_label_is_todays(): void
    {
        config(['services.gateway.urls' => '']);
        $this->gateway('incepxion');
        $this->assertSame('Forbeast', $this->via($this->chat(['source' => 'gateway'])));
        $this->assertSame('Forbeast', $this->via(null));
        $this->assertSame('Incepxionbot', ChatbotProvider::repliesVia('Page Uno', 'incepxionbot'), 'the other gateway keeps its label');
        $this->assertSame('Botcake', ChatbotProvider::repliesVia('Page Uno', ChatbotProvider::BOTCAKE));
    }

    public function test_S_224_1_5_the_variable_help_names_the_address(): void
    {
        $info = (new \ReflectionClassConstant(\App\Http\Controllers\ChatbotFlowController::class, 'RUN_VAR_INFO'))->getValue();
        $this->assertStringContainsString('Incepxion', $info['replies_via']);
    }

    private function run1(): FlowRun
    {
        return new FlowRun(1, [], 'Page Uno', '1001', [], new CustomerFields(1001, 7001), null, 'hi', 'Ana', '2026-11-04 09:00');
    }

    /** @return array<string, array{0: ?string, 1: ?string}> node => [via, route] */
    private function rows(): array
    {
        return ChatbotReply::orderBy('id')->get()->mapWithKeys(fn ($r) => [(string) $r->node_id => [$r->via, $r->route]])->all();
    }

    public function test_S_224_2_1_a_flow_send_records_the_address_it_went_through(): void
    {
        $outbox = app(BotcakeOutbox::class);
        $outbox->send($this->run1(), 'n1', [['type' => 'text', 'text' => 'Botcake po']]);
        $this->gateway(null);
        $outbox->send($this->run1(), 'n2', [['type' => 'text', 'text' => 'Default po']]);
        $this->gateway('incepxion');
        $outbox->send($this->run1(), 'n3', [['type' => 'text', 'text' => 'Incepxion po']]);
        $outbox->appendRow($this->run1(), 'n4', 'sheet-1', 'Orders', ['a']);

        $this->assertSame(['n1' => ['botcake', null], 'n2' => ['forbeast', 'forbeast'], 'n3' => ['forbeast', 'incepxion'], 'n4' => [null, null]], $this->rows());
        $this->assertSame(['sent', 'sent', 'sent'], ChatbotReply::where('kind', 'send')->orderBy('id')->pluck('status')->all());
    }

    public function test_S_224_2_2_an_inbox_reply_records_the_address(): void
    {
        $this->gateway('incepxion');
        $this->chat(['source' => 'gateway']);
        $this->actingAs($this->user());
        $this->postJson('/chatbot/inbox/send', ['page_id' => '1001', 'psid' => '7001', 'text' => 'Manual po'])->assertOk();

        $this->assertSame(['' => ['forbeast', 'incepxion']], $this->rows());
    }

    public function test_S_224_2_3_only_a_forbeast_send_stores_an_address_and_old_rows_stay_null(): void
    {
        BotcakeOutbox::record(1001, 7001, null, 1, 'b', 'send', [], 'sent', via: 'botcake', route: 'incepxion');
        BotcakeOutbox::record(1001, 7001, null, 1, 'i', 'send', [], 'sent', via: 'incepxionbot', route: 'incepxionbot');
        BotcakeOutbox::record(1001, 7001, null, 1, 'old', 'send', [], 'sent', via: 'forbeast');
        $this->assertSame(['b' => ['botcake', null], 'i' => ['incepxionbot', null], 'old' => ['forbeast', null]], $this->rows());
        $this->assertSame(1, ChatbotReply::where('via', 'forbeast')->count(), 'via values and queries on them are unchanged');
    }

    /** Isang sagot kada daan: incepxion, ang default, Botcake, at ang lumang row na walang address. */
    private function threeReplies(): void
    {
        BotcakeOutbox::record(1001, 7001, null, 1, 'inc', 'send', [['type' => 'text', 'text' => 'A']], 'sent', via: 'forbeast', route: 'incepxion');
        BotcakeOutbox::record(1001, 7001, null, 1, 'def', 'send', [['type' => 'text', 'text' => 'B']], 'sent', via: 'forbeast', route: 'forbeast');
        BotcakeOutbox::record(1001, 7001, null, 1, 'bot', 'send', [['type' => 'text', 'text' => 'C']], 'sent', via: 'botcake');
        BotcakeOutbox::record(1001, 7001, null, 1, 'old', 'send', [['type' => 'text', 'text' => 'D']], 'sent', via: 'forbeast');
    }

    public function test_S_224_3_1_the_inbox_says_via_incepxion_only_for_a_reply_that_went_there(): void
    {
        $this->threeReplies();
        $this->actingAs($this->user());
        $out = collect($this->getJson('/chatbot/inbox/thread?page_id=1001&psid=7001')->assertOk()->json('items'))->where('side', 'out')->values();
        $this->assertSame([['forbeast', 'Incepxion'], ['forbeast', null], ['botcake', null], ['forbeast', null]], $out->map(fn ($x) => [$x['via'], $x['via_label'] ?? null])->all());
        $this->assertArrayNotHasKey('via_label', $out[1], 'the default keeps today\'s data');

        $html = $this->get('/chatbot/inbox')->assertOk()->getContent();
        $this->assertStringContainsString("const viaText = x => x.via_label ? 'via ' + x.via_label : (x.via && VIA[x.via] ? 'via ' + VIA[x.via] : '');", $html);
    }

    public function test_S_224_3_2_a_manual_inbox_reply_through_incepxion_comes_back_labelled(): void
    {
        $this->gateway('incepxion');
        $this->chat(['source' => 'gateway']);
        $this->actingAs($this->user());
        $item = $this->postJson('/chatbot/inbox/send', ['page_id' => '1001', 'psid' => '7001', 'text' => 'Manual po'])->assertOk()->json('item');
        $this->assertSame(['forbeast', 'Incepxion'], [$item['via'], $item['via_label'] ?? null]);
    }

    public function test_S_224_3_3_the_replies_screen_labels_the_address_too(): void
    {
        $this->threeReplies();
        $this->actingAs($this->user());
        $rows = $this->getJson('/chatbot/replies/data?date=' . now('Asia/Manila')->toDateString())->assertOk()->json('rows');
        $this->assertSame([['forbeast', null], ['botcake', null], ['forbeast', null], ['forbeast', 'Incepxion']], array_map(fn ($r) => [$r['via'], $r['via_label'] ?? null], $rows));
        $this->assertCount(3, $this->getJson('/chatbot/replies/data?date=' . now('Asia/Manila')->toDateString() . '&via=forbeast')->json('rows'), 'the via filter is unchanged');
        $id = ChatbotReply::where('node_id', 'inc')->value('id');
        $this->assertSame('Incepxion', $this->getJson('/chatbot/replies/' . $id)->assertOk()->json('via_label'));
        $html = $this->get('/chatbot/replies')->assertOk()->getContent();
        $this->assertStringContainsString("text: r.via_label || ({botcake: 'Botcake'", $html);
    }

    public function test_S_224_2_7_the_migration_adds_a_nullable_column_instantly_and_has_a_down(): void
    {
        $file = base_path('database/migrations/2026_11_17_100000_add_route_to_chatbot_replies.php');
        $this->assertStringContainsString('ADD COLUMN `route` VARCHAR(30) NULL, ALGORITHM=INSTANT', (string) file_get_contents($file));
        $m = require $file;
        $this->assertTrue(Schema::hasColumn('chatbot_replies', 'route'));
        $m->up(); // twice: nothing
        $m->down();
        $this->assertFalse(Schema::hasColumn('chatbot_replies', 'route'));
        $m->down();
        $m->up();
        $this->assertTrue(Schema::hasColumn('chatbot_replies', 'route'));
    }
}
