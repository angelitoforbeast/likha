<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\HandleGatewayEvent;
use App\Jobs\RunChatbotFlow;
use App\Models\AppSetting;
use App\Models\BotcakeChat;
use App\Models\BotcakePsid;
use App\Models\ChatbotFlow;
use App\Services\Chatbot\ChatAttachments;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\FlowGraph;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ang mensaheng attachment lang (larawan, audio, video, reel, file, fallback; walang text) ay hindi sinasagot ng bot sa
 * lahat ng page: walang takbo, walang AI, walang padala; itinatala bilang "ignored" (Inbox, Health) at ang uri at link ay
 * naiiwan sa chat. Ang sticker at Like ay gaya ng dati. chatbot_attachments = reply: gaya ng dati. Fake na HTTP.
 */
class ChatbotAttachmentsTest extends ChatblastTestCase
{
    private const KEY_UNO = 'page-key-uno-test-a1s2d3f4g5h6j7k8l9';
    private const PSID = '6012345678901234';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([RunChatbotFlow::class]);
        Http::fake();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_inbox_indexes.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_28_100000_create_chatbot_ai_requests_table.php',
        ]]);
        config(['services.chatbot.replies_enabled' => true, 'services.botcake.chat_ips' => ['203.171.22.6'], 'services.botcake.chat_ip_mode' => 'log',
            'services.gateway.url' => 'https://gw.example.test', 'services.gateway.webhook_secret' => 'gw-secret']);
        $this->page('Page Uno', '1001');
        app(PageSettings::class)->set('Page Uno', 'botcake', 'chat.key', self::KEY_UNO, true, null, true);
        BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Ana Cruz', 'psid' => self::PSID, 'status' => 'available', 'source' => 'api']);
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Hi']]]],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]])]);
        app(ChatbotPage::class)->link('Page Uno', $flow);
    }

    private function botcakePush(string $message)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '203.171.22.6'])->postJson('/api/botcake/chat',
            ['page_id' => '1001', 'psid' => self::PSID, 'name' => 'Ana Cruz', 'message' => $message], ['X-Chat-Key' => self::KEY_UNO]);
    }

    /** Ang mensahe mula sa Forbeast (page na nasa gateway). */
    private function forbeast(array $attachments, ?string $text = null): void
    {
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'provider', 'gateway');
        (new HandleGatewayEvent(HandleGatewayEvent::fields(['kind' => 'event', 'event_id' => random_int(1, 99999), 'page_id' => '1001', 'psid' => self::PSID,
            'type' => 'message', 'mid' => 'm_' . uniqid(), 'text' => $text, 'attachments' => $attachments])))->handle(app(PageSettings::class), app(\App\Services\BotcakeChatGuard::class));
    }

    private function ran(): int
    {
        return Queue::pushed(RunChatbotFlow::class)->count();
    }

    private function ignoredEvents(): array
    {
        return DB::table('chatbot_events')->where('type', 'ignored')->orderBy('id')->pluck('detail')->map(fn ($d) => json_decode($d, true)['attachment'])->all();
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> mensahe ng Botcake, uri, ang naitabing link */
    public static function botcakeAttachments(): array
    {
        return [
            'photo' => ['https://scontent.xx.fbcdn.net/v/t1.15752-9/abc_n.jpg?stp=dst', 'photo', 'https://scontent.xx.fbcdn.net/v/t1.15752-9/abc_n.jpg?stp=dst'],
            'audio' => ['[audio] https://cdn.fbsbx.com/v/t59.3654-21/voice.mp4', 'audio', 'https://cdn.fbsbx.com/v/t59.3654-21/voice.mp4'],
            'video' => ['[video] https://video.xx.fbcdn.net/v/clip.mp4', 'video', 'https://video.xx.fbcdn.net/v/clip.mp4'],
            'reel' => ['[reel] https://www.facebook.com/reel/123', 'reel', 'https://www.facebook.com/reel/123'],
            'file' => ['[file] https://cdn.fbsbx.com/v/order.pdf', 'file', 'https://cdn.fbsbx.com/v/order.pdf'],
            'fallback' => ['[fallback] https://www.facebook.com/share/xyz', 'fallback', 'https://www.facebook.com/share/xyz'],
        ];
    }

    #[DataProvider('botcakeAttachments')]
    public function test_S_149_28_an_attachment_only_message_is_ignored_logged_and_kept_for_later(string $message, string $type, string $url): void
    {
        $this->botcakePush($message)->assertOk();

        $this->assertSame(0, $this->ran(), 'no run, so no AI and no send');
        $this->assertSame([$type], $this->ignoredEvents());
        $chat = BotcakeChat::sole();
        // Ang uri ay gaya ng dating nito (ang larawan ay "image"), ang link ay buo.
        $this->assertSame([$type === 'photo' ? 'image' : $type, $url, $type], [$chat->payload['attachment']['type'] ?? null, $chat->payload['attachment']['url'] ?? null, $chat->payload['_ignored'] ?? null]);
        $this->assertSame(0, DB::table('chatbot_replies')->count());
    }

    public function test_S_149_28_a_forbeast_audio_or_photo_is_ignored_and_its_link_kept(): void
    {
        $this->forbeast([['type' => 'audio', 'url' => 'https://cdn.fbsbx.com/voice.mp4']]);
        $this->forbeast([['type' => 'image', 'url' => 'https://scontent.xx.fbcdn.net/v/t1.15752-9/p_n.jpg']]);

        $this->assertSame(0, $this->ran());
        $this->assertSame(['audio', 'photo'], $this->ignoredEvents());
        $this->assertSame(['https://cdn.fbsbx.com/voice.mp4', 'https://scontent.xx.fbcdn.net/v/t1.15752-9/p_n.jpg'],
            BotcakeChat::orderBy('id')->get()->map(fn ($c) => $c->payload['attachment']['url'] ?? null)->all());
    }

    public function test_S_149_29_stickers_likes_and_a_photo_with_text_are_answered_as_today(): void
    {
        $this->botcakePush('https://scontent.xx.fbcdn.net/v/t39.1997-6/39178562_1505197616293642_5411344281094848512_n.png')->assertOk(); // Like
        $this->botcakePush('[sticker] https://scontent.xx.fbcdn.net/v/t39.1997-6/cat_n.png')->assertOk();
        $this->forbeast([['type' => 'image', 'url' => 'https://scontent.xx.fbcdn.net/v/t39.1997-6/sticker_n.png']]); // ang sticker ay larawan sa Forbeast
        $this->forbeast([['type' => 'image', 'url' => 'https://scontent.xx.fbcdn.net/v/t1.15752-9/p_n.jpg']], 'Magkano po ito?');

        $this->assertSame(4, $this->ran());
        $this->assertSame([], $this->ignoredEvents());
        $this->assertSame('Magkano po ito?', BotcakeChat::orderByDesc('id')->value('message'));
    }

    public function test_S_149_30_the_reply_setting_restores_todays_behaviour_and_only_the_ceo_changes_it(): void
    {
        $this->actingAs($this->user('Marketing', 'mk@example.test'));
        $this->post('/chatbot/health/attachments', ['mode' => 'reply'])->assertForbidden();
        $this->actingAs($this->user());
        $this->assertStringContainsString('name="mode"', $this->get('/chatbot/health')->assertOk()->getContent());
        $this->post('/chatbot/health/attachments', ['mode' => 'nope'])->assertSessionHasErrors('mode');
        $this->post('/chatbot/health/attachments', ['mode' => 'reply'])->assertRedirect();
        $this->assertSame('reply', AppSetting::get(ChatAttachments::SETTING));

        $this->botcakePush('[audio] https://cdn.fbsbx.com/voice.mp4')->assertOk();

        $this->assertSame([1, []], [$this->ran(), $this->ignoredEvents()]);
        $this->assertSame('[audio] https://cdn.fbsbx.com/voice.mp4', BotcakeChat::sole()->message, 'stored as today');
    }

    public function test_S_149_31_ignored_messages_show_in_the_inbox_and_are_counted_on_health(): void
    {
        $this->botcakePush('[audio] https://cdn.fbsbx.com/voice.mp4')->assertOk();
        $this->actingAs($this->user());

        $item = collect($this->getJson('/chatbot/inbox/thread?page_id=1001&psid=' . self::PSID)->assertOk()->json('items'))->firstWhere('side', 'in');
        $this->assertSame('audio', $item['ignored']);
        $this->assertStringContainsString("'Not answered by the bot (' + x.ignored + ' only)'", $this->get('/chatbot/inbox')->getContent());
        $this->assertSame(1, $this->get('/chatbot/health')->viewData('tiles')['today']['ignored']);
    }
}
