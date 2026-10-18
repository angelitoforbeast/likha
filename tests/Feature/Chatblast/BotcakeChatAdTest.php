<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\BotcakePsid;
use App\Models\PageSetting;
use App\Services\BotcakeChatBlock;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Ang ad_id at page_name ng "Chat to site" block: ang body na isinusulat, ang ad_id sa payload at sa botcake_psids, ang
 * "+ Page" na nangangailangan ng pangalan, Botcake ID at token at nag-a-On ng page.
 * Fake na HTTP; walang tawag sa totoong Botcake.
 */
class BotcakeChatAdTest extends ChatblastTestCase
{
    private const KEY_UNO = 'page-key-uno-test-a1s2d3f4g5h6j7k8l9';
    private const BOTCAKE_IP = '203.171.22.6';
    private const NEW_TOKEN = 'tok-new-page-test-5t6y7u';
    private const INFO = 'botcake.io/api/public_api/v1/pages/*/customer/*/info';
    private const BODY = '{"page_id":"{{page_id}}","psid":"{{psid}}","name":"{{user_full_name}}","message":"{{last_text_input}}","source":"default_reply","ad_id":"{{ad_id}}","page_name":"{{page_name}}"}';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
        ]]);
        config([
            'services.botcake.chat_shared_key_ok' => false,
            'services.botcake.chat_ips' => [self::BOTCAKE_IP], 'services.botcake.chat_ip_mode' => 'log',
            'app.url' => 'https://app.example.test',
        ]);
        $this->page('Page Uno', '1001');
        $this->settings()->set('Page Uno', 'botcake', 'chat.key', self::KEY_UNO, true, null, true);
        BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Ana Cruz', 'psid' => 'psid-known', 'status' => 'available', 'source' => 'api']);
    }

    private function settings(): PageSettings
    {
        return app(PageSettings::class);
    }

    private function send(array $body = [], string $key = self::KEY_UNO)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => self::BOTCAKE_IP])->postJson('/api/botcake/chat',
            $body + ['page_id' => '1001', 'psid' => 'psid-known', 'name' => 'Ana Cruz', 'message' => 'Hello po', 'source' => 'default_reply'],
            ['X-Chat-Key' => $key]);
    }

    public function test_S_072_1_on_and_the_command_write_the_body_with_ad_id_and_page_name(): void
    {
        Http::fake(['botcake.io/*' => Http::response(['success' => true])]);
        $this->page('Page Dos', '2002');
        $this->actingAs($this->user());

        $this->postJson('/chatbot/active', ['page_name' => 'Page Dos', 'on' => true])->assertOk()->assertJson(['error' => null]);
        $this->assertSame(0, Artisan::call('botcake:chat-setup', ['page' => 'Page Uno']));

        $writes = collect(Http::recorded())->filter(fn ($s) => str_ends_with($s[0]->url(), '/default_reply/content'))->values();
        $this->assertCount(2, $writes);
        foreach ($writes as [$req]) {
            $card = $req->data()['blocks'][0]['cards'][0];
            $this->assertSame(self::BODY, $card['body']);
            $this->assertSame(json_decode(self::BODY, true), $card['body_preview']);
        }
    }

    public function test_S_072_2_the_command_on_a_set_up_page_rewrites_the_block_with_the_same_key(): void
    {
        Http::fake(['botcake.io/*' => Http::response(['success' => true])]);

        $this->assertSame(0, Artisan::call('botcake:chat-setup', ['page' => 'Page Uno']));

        $this->assertSame(self::KEY_UNO, $this->settings()->get('Page Uno', 'botcake', 'chat.key'));
        [$req] = Http::recorded()[0];
        $card = $req->data()['blocks'][0]['cards'][0];
        $this->assertSame(['key' => 'X-Chat-Key', 'value' => self::KEY_UNO], $card['headers'][0]);
        $this->assertSame(self::BODY, $card['body']);
        $this->assertStringNotContainsString(self::KEY_UNO, Artisan::output());
    }

    public function test_S_072_3_the_ad_id_fills_a_new_chat_verify_row_and_an_empty_one_but_never_overwrites(): void
    {
        Http::fake([self::INFO => Http::response(['success' => true, 'data' => ['psid' => 'psid-new']])]);
        BotcakePsid::create(['page' => 'page uno ', 'fb_name' => 'Ben Reyes', 'psid' => 'psid-has-ad', 'status' => 'available', 'source' => 'api', 'ad_id' => '120200000000000001']);

        $this->send(['psid' => 'psid-new', 'ad_id' => '120200000000000777'])->assertOk();
        $this->send(['psid' => 'psid-known', 'ad_id' => '120200000000000888'])->assertOk();
        $this->send(['psid' => 'psid-has-ad', 'ad_id' => '120200000000000999'])->assertOk();

        $new = BotcakePsid::where('psid', 'psid-new')->sole();
        $this->assertSame(['chat_verify', '120200000000000777'], [$new->source, $new->ad_id]);
        $this->assertSame('120200000000000888', BotcakePsid::where('psid', 'psid-known')->sole()->ad_id);
        $this->assertSame('120200000000000001', BotcakePsid::where('psid', 'psid-has-ad')->sole()->ad_id);
        $this->assertSame(['120200000000000777', '120200000000000888', '120200000000000999'],
            BotcakeChat::orderBy('id')->get()->map(fn ($c) => $c->payload['ad_id'] ?? null)->all());
    }

    public static function unfilled(): array
    {
        return ['placeholder' => ['{{ad_id}}', '{{page_name}}'], 'empty' => ['', ''], 'spaces' => ['  ', ' ']];
    }

    /** @dataProvider unfilled */
    public function test_S_072_4_an_unfilled_or_empty_ad_id_and_page_name_store_nothing(string $adId, string $pageName): void
    {
        Http::fake([self::INFO => Http::response(['success' => true, 'data' => ['psid' => 'psid-new']])]);

        $this->send(['psid' => 'psid-new', 'ad_id' => $adId, 'page_name' => $pageName])->assertOk();
        $this->send(['psid' => 'psid-known', 'ad_id' => $adId, 'page_name' => $pageName, 'message' => 'two'])->assertOk();

        $this->assertSame([null, null], BotcakePsid::whereIn('psid', ['psid-new', 'psid-known'])->orderBy('psid')->pluck('ad_id')->all());
        foreach (BotcakeChat::all() as $chat) {
            $this->assertArrayNotHasKey('ad_id', $chat->payload);
            $this->assertArrayNotHasKey('page_name', $chat->payload);
            $this->assertSame('default_reply', $chat->payload['source']);
        }
        $this->assertSame(2, BotcakeChat::count());
        $this->assertSame('Page Uno', $this->settings()->pagesWith('botcake', ['page_id', 'token'])[0]);
    }

    public function test_S_072_5_plus_page_needs_the_name_id_and_token_and_switches_the_page_on(): void
    {
        Http::fake(['botcake.io/*' => Http::response(['success' => true, 'data' => []])]);
        $this->actingAs($this->user());

        foreach ([['page_id' => '4004', 'token' => self::NEW_TOKEN], ['page_name' => ' ', 'page_id' => '4004', 'token' => self::NEW_TOKEN]] as $noName) {
            $this->postJson('/chatbot/pages', $noName)->assertStatus(422)->assertJson(['error' => 'The page name is required.']);
        }
        Http::assertNothingSent();
        $this->assertNull(PageSetting::where('value', '4004')->first());

        $this->postJson('/chatbot/pages', ['page_name' => 'Page Cuatro', 'page_id' => '4004', 'token' => self::NEW_TOKEN])
            ->assertOk()->assertJson(['page' => 'Page Cuatro', 'token_test' => 'ok', 'active' => true]);

        $key = (string) $this->settings()->get('Page Cuatro', 'botcake', 'chat.key');
        $this->assertGreaterThanOrEqual(32, strlen($key));
        $sent = collect(Http::recorded())->map(fn ($s) => $s[0])->filter(fn ($r) => str_contains($r->url(), '/default_reply'))->values();
        $this->assertSame('https://botcake.io/api/public_api/v1/pages/4004/default_reply/content', $sent[0]->url());
        $this->assertSame(['name' => 'Default reply', 'blocks' => [app(BotcakeChatBlock::class)->block($key)]], $sent[0]->data());
        $this->assertSame(self::BODY, $sent[0]->data()['blocks'][0]['cards'][0]['body']);
        $this->assertSame(['update' => ['is_published' => true, 'unlimit_default_reply' => true]], $sent[1]->data());
        $this->assertSame('1', $this->settings()->get('Page Cuatro', 'chatbot', 'active'));
        $this->assertTrue(collect($this->getJson('/chatbot/data')->json('rows'))->firstWhere('page', 'Page Cuatro')['active']);
    }

    public static function addFails(): array
    {
        return [
            'the block write' => [['botcake.io/*/default_reply/content', ['success' => false, 'message' => 'Something wrong']], 'ok', 'Could not write the block'],
            'the token test' => [['botcake.io/*/custom_fields*', ['success' => false]], 'invalid', 'The token test failed'],
        ];
    }

    /** @dataProvider addFails */
    public function test_S_072_6_when_botcake_fails_on_add_the_page_is_saved_but_off_with_the_error(array $fail, string $tokenTest, string $error): void
    {
        Http::fake([$fail[0] => Http::response($fail[1]), 'botcake.io/*' => Http::response(['success' => true, 'data' => []])]);
        $this->actingAs($this->user());

        $res = $this->postJson('/chatbot/pages', ['page_name' => 'Page Cuatro', 'page_id' => '4004', 'token' => self::NEW_TOKEN])
            ->assertOk()->assertJson(['page' => 'Page Cuatro', 'token_test' => $tokenTest, 'active' => false]);

        $this->assertStringContainsString($error, (string) $res->json('error'));
        $this->assertStringNotContainsString(self::NEW_TOKEN, $res->getContent());
        $this->assertSame('4004', $this->settings()->get('Page Cuatro', 'botcake', 'page_id'));
        $this->assertSame('0', $this->settings()->get('Page Cuatro', 'chatbot', 'active'));
        $this->assertFalse(collect($this->getJson('/chatbot/data')->json('rows'))->firstWhere('page', 'Page Cuatro')['active']);
        if ($tokenTest !== 'ok') Http::assertNotSent(fn ($r) => str_contains($r->url(), '/default_reply'));
    }
}
