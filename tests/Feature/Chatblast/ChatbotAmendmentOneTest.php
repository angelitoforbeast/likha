<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\BotcakePsid;
use App\Models\ChatbotFlow;
use App\Models\ChatbotReply;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\FlowGraph;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Amendment 1 ng 069: ang On/Off ng page ang switch ng pagsagot (On + flow = sumasagot), ang CHATBOT_REPLIES_ENABLED
 * ay emergency stop lang (true bilang default), ang Like at mga larawan mula sa Facebook CDN ay nililinis bago iimbak,
 * at ang botcake:chats-normalize para sa mga lumang row. Fake na HTTP.
 */
class ChatbotAmendmentOneTest extends ChatblastTestCase
{
    private const KEY = 'page-key-uno-test-a1s2d3f4g5h6j7k8l9';
    private const SEND = 'https://botcake.io/api/public_api/v1/pages/1001/flows/send_content';
    private const LIKE_BIG = 'https://scontent.xx.fbcdn.net/v/t39.1997-6/39178562_1505197616293642_5411344281094848512_n.png?_nc_cat=1&ccb=1-7&_nc_sid=fc17b8&_nc_oc=Q6cZ2&oh=00_AYA&oe=67189';
    private const LIKE_SMALL = 'https://scontent.fmnl4-1.fna.fbcdn.net/v/t39.1997-6/851587_369239346556147_162929011_n.png?stp=dst-png_s100x100&_nc_cat=1';
    private const LIKE_BY_ID = 'https://scontent.xx.fbcdn.net/v/t39.1997-6/12345_369239263222822_678_n.png';
    private const PHOTO = 'https://scontent.xx.fbcdn.net/v/t1.15752-9/464012345_1234567890_987654321_n.jpg?_nc_cat=105&oh=00_x&oe=671';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
        ]]);
        config(['services.botcake.chat_ips' => ['203.171.22.6'], 'services.botcake.chat_ip_mode' => 'log']);
        $this->page('Page Uno', '1001');
        app(PageSettings::class)->set('Page Uno', 'botcake', 'chat.key', self::KEY, true, null, true);
        BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Ana Cruz', 'psid' => '7001', 'status' => 'available', 'source' => 'api']);
    }

    private function send(string $message)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '203.171.22.6'])->postJson('/api/botcake/chat',
            ['page_id' => '1001', 'psid' => '7001', 'name' => 'Ana Cruz', 'message' => $message], ['X-Chat-Key' => self::KEY])->assertOk();
    }

    private function echoFlow(): ChatbotFlow
    {
        return ChatbotFlow::create(['name' => 'Echo', 'graph' => FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'You sent {{last_input}}']]]],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]])]);
    }

    public function test_S_069_A1_a_page_answers_when_it_is_on_and_linked_and_the_emergency_stop_is_on_by_default(): void
    {
        $this->assertTrue((bool) config('services.chatbot.replies_enabled'), 'CHATBOT_REPLIES_ENABLED ay true bilang default');
        Http::fake([self::SEND => Http::response(['success' => true])]);

        $this->send('no flow yet');                                   // On, walang flow: tinatanggap lang
        $this->assertSame(0, ChatbotReply::count());

        app(ChatbotPage::class)->link('Page Uno', $this->echoFlow());
        $this->send('hello');                                         // On + flow: sumasagot
        $this->assertSame([['sent', 'You sent hello']], ChatbotReply::get()->map(fn ($r) => [$r->status, $r->content[0]['text']])->all());

        app(PageSettings::class)->set('Page Uno', 'chatbot', 'active', '0', false, null, true);
        $this->send('while off');                                     // Off: hindi iniimbak, walang takbo
        $this->assertSame(1, ChatbotReply::count());
        $this->assertNull(BotcakeChat::where('message', 'while off')->first());

        app(PageSettings::class)->set('Page Uno', 'chatbot', 'active', '1', false, null, true);
        config(['services.chatbot.replies_enabled' => false]);        // emergency stop
        $this->send('emergency');
        $this->assertSame(['skipped', 'Replies are switched off'], [ChatbotReply::latest('id')->first()->status, ChatbotReply::latest('id')->first()->error]);
        Http::assertSentCount(1);
    }

    public function test_S_069_A2_the_page_list_and_flow_pages_say_how_the_switch_works(): void
    {
        Http::fake();
        $this->actingAs($this->user());

        $this->get('/chatbot/pages')->assertOk()->assertSee('On + a flow = the bot answers. On without a flow = chats are only received.');
        $this->get('/chatbot')->assertOk()->assertDontSee('Replies are switched off')->assertSee('On + a flow = the bot answers.');

        config(['services.chatbot.replies_enabled' => false]);
        $this->get('/chatbot')->assertOk()->assertSee('Emergency stop is on');
        $this->get('/chatbot/replies')->assertOk()->assertSee('Emergency stop is on');
    }

    public static function messages(): array
    {
        return [
            'like, big'            => [self::LIKE_BIG, '👍', null],
            'like, small'          => [self::LIKE_SMALL, '👍', null],
            'like, by sticker id'  => [self::LIKE_BY_ID, '👍', null],
            'photo'                => [self::PHOTO, '[Photo]', self::PHOTO],
            'another host'         => ['https://example.com/a.png', 'https://example.com/a.png', null],
            'lookalike host'       => ['https://fbcdn.net.evil.example/x.png', 'https://fbcdn.net.evil.example/x.png', null],
            'text with a link'     => ['see ' . self::PHOTO, 'see ' . self::PHOTO, null],
            'plain text'           => ['Magkano po?', 'Magkano po?', null],
        ];
    }

    /** @dataProvider messages */
    public function test_S_069_A3_likes_and_facebook_photos_are_cleaned_before_they_are_stored(string $in, string $stored, ?string $imageUrl): void
    {
        Http::fake();
        $this->send($in);

        $chat = BotcakeChat::sole();
        $this->assertSame($stored, $chat->message);
        $this->assertSame($imageUrl, $chat->payload['image_url'] ?? null);
        if ($stored === '👍') $this->assertStringNotContainsString('fbcdn', json_encode($chat->payload));
    }

    public function test_S_069_A4_the_flow_sees_the_cleaned_message_and_chats_show_photo_with_an_open_link(): void
    {
        Http::fake([self::SEND => Http::response(['success' => true])]);
        app(ChatbotPage::class)->link('Page Uno', $this->echoFlow());

        $this->send(self::LIKE_BIG);
        $this->send(self::PHOTO);

        $this->assertSame(['You sent 👍', 'You sent [Photo]'], ChatbotReply::orderBy('id')->get()->map(fn ($r) => $r->content[0]['text'])->all());
        $this->actingAs($this->user());
        foreach (['/chatbot/chats', '/chatbot/chats/customer?page_id=1001&psid=7001'] as $url) {
            $html = $this->get($url)->assertOk()->assertSee('[Photo]')->assertSee('👍')->getContent();
            $this->assertMatchesRegularExpression('#<a href="' . preg_quote(e(self::PHOTO), '#') . '"[^>]*rel="noopener noreferrer"[^>]*>open</a>#', $html);
        }
    }

    public function test_S_069_A5_the_normalize_command_counts_then_fixes_old_rows_once(): void
    {
        $at = now();
        foreach ([self::LIKE_BIG, self::LIKE_SMALL, self::LIKE_BY_ID, self::PHOTO, 'Hello', 'https://example.com/a.png'] as $i => $m) {
            DB::table('botcake_chats')->insert(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => (string) (7000 + $i), 'name' => 'C', 'message' => $m,
                'payload' => json_encode(['source' => 'default_reply']), 'received_at' => $at]);
        }

        $this->assertSame(0, Artisan::call('botcake:chats-normalize'));
        $this->assertStringContainsString('like: 3, photo: 1, untouched: 2', Artisan::output());
        $this->assertSame(4, BotcakeChat::where('message', 'like', '%fbcdn%')->count(), 'ang dry run ay walang binabago');

        $this->assertSame(1, Artisan::call('botcake:chats-normalize', ['--apply' => true]));          // walang --expect
        $this->assertSame(1, Artisan::call('botcake:chats-normalize', ['--apply' => true, '--expect' => 5]));
        $this->assertSame(4, BotcakeChat::where('message', 'like', '%fbcdn%')->count());

        $this->assertSame(0, Artisan::call('botcake:chats-normalize', ['--apply' => true, '--expect' => 4]));
        $this->assertStringContainsString('changed 4', Artisan::output());
        $this->assertSame(['👍', '👍', '👍', '[Photo]', 'Hello', 'https://example.com/a.png'], BotcakeChat::orderBy('id')->pluck('message')->all());
        $photo = BotcakeChat::where('message', '[Photo]')->sole();
        $this->assertSame(['source' => 'default_reply', 'image_url' => self::PHOTO], $photo->payload);

        $this->assertSame(0, Artisan::call('botcake:chats-normalize'));
        $this->assertStringContainsString('like: 0, photo: 0, untouched: 6', Artisan::output(), 'isang beses lang');
    }
}
