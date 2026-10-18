<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\BotcakePsid;
use App\Services\BotcakeChatGuard;
use App\Services\PageSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Ang tatlong harang ng /api/botcake/chat: IP allowlist (log o enforce), key kada page na nakatali sa page_id (at ang
 * lumang shared key habang naka-on ang flag), at ang PSID na dapat nasa page (botcake_psids o isang tawag sa Botcake).
 * Pati ang botcake:chat-setup na sumusulat ng Default Reply block. Fake na HTTP; walang tawag sa totoong Botcake.
 */
class BotcakeChatSecurityTest extends ChatblastTestCase
{
    private const SHARED = 'shared-key-test-1q2w3e4r5t6y7u8i9o0p';
    private const KEY_UNO = 'page-key-uno-test-a1s2d3f4g5h6j7k8l9';
    private const KEY_DOS = 'page-key-dos-test-z1x2c3v4b5n6m7q8w9';
    private const BOTCAKE_IP = '203.171.22.6';
    private const INFO = 'botcake.io/api/public_api/v1/pages/*/customer/*/info';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_20_100000_create_botcake_chats_table.php']]);
        config([
            'services.botcake.chat_key' => self::SHARED, 'services.botcake.chat_shared_key_ok' => true,
            'services.botcake.chat_ips' => [self::BOTCAKE_IP], 'services.botcake.chat_ip_mode' => 'log',
            'app.url' => 'https://app.example.test',
        ]);
        $this->page('Page Uno', '1001');
        $this->page('Page Dos', '2002');
        $s = app(PageSettings::class);
        $s->set('Page Uno', 'botcake', 'chat.key', self::KEY_UNO, true, null, true);
        $s->set('Page Dos', 'botcake', 'chat.key', self::KEY_DOS, true, null, true);
        BotcakePsid::create(['page' => 'Page Uno', 'fb_name' => 'Ana Cruz', 'psid' => 'psid-known', 'status' => 'available', 'source' => 'api']);
    }

    private function send(array $body = [], ?string $key = self::KEY_UNO, string $ip = self::BOTCAKE_IP)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/botcake/chat',
            $body + ['page_id' => '1001', 'psid' => 'psid-known', 'name' => 'Ana Cruz', 'message' => 'Hello po', 'source' => 'default_reply'],
            $key === null ? [] : ['X-Chat-Key' => $key]);
    }

    private function counts(): array
    {
        return array_filter(app(BotcakeChatGuard::class)->counts());
    }

    public function test_S_067_1_an_ip_off_the_list_is_logged_and_counted_in_log_mode_and_refused_in_enforce_mode(): void
    {
        Http::fake();
        Log::spy();

        $this->send(['message' => 'from list'])->assertOk();
        $this->send(['message' => 'off list'], self::KEY_UNO, '198.51.100.7')->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSame(['from list', 'off list'], BotcakeChat::orderBy('id')->pluck('message')->all());
        $this->assertSame(['bad_ip' => 1], $this->counts());
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx = []) => str_contains($msg, 'IP') && ($ctx['ip'] ?? null) === '198.51.100.7'
            && !str_contains(json_encode($ctx), self::KEY_UNO))->once();

        config(['services.botcake.chat_ip_mode' => 'enforce', 'services.botcake.chat_ips' => ['203.171.22.0/24']]);
        $this->send(['message' => 'in range'], self::KEY_UNO, '203.171.22.9')->assertOk();
        $this->send(['message' => 'refused'], self::KEY_UNO, '198.51.100.7')->assertStatus(403);
        $this->assertSame(['from list', 'off list', 'in range'], BotcakeChat::orderBy('id')->pluck('message')->all());
        $this->assertSame(['bad_ip' => 2], $this->counts());
    }

    public function test_S_067_2_a_page_key_only_opens_its_own_page_and_an_unknown_page_is_refused(): void
    {
        Http::fake();

        $this->send([], self::KEY_DOS)->assertStatus(401);                       // key ng Page Dos, page_id ng Page Uno
        $this->send([], 'not-a-key-of-any-page-0000000000')->assertStatus(401);
        $this->send(['page_id' => '9999'], self::KEY_UNO)->assertStatus(401);     // walang ganitong page
        $this->send([], null)->assertStatus(401);
        $this->assertSame(0, BotcakeChat::count());
        $this->assertSame(['bad_key' => 3, 'unknown_page' => 1], $this->counts());

        $this->send([], self::KEY_UNO)->assertOk();
        $this->assertSame(['Page Uno'], BotcakeChat::pluck('page_name')->all());
    }

    public function test_S_067_3_the_shared_key_works_only_while_the_flag_is_on(): void
    {
        Http::fake();

        $this->send(['message' => 'shared on'], self::SHARED)->assertOk();
        config(['services.botcake.chat_shared_key_ok' => false]);
        $this->send(['message' => 'shared off'], self::SHARED)->assertStatus(401);
        $this->send(['message' => 'page key'], self::KEY_UNO)->assertOk();

        $this->assertSame(['shared on', 'page key'], BotcakeChat::orderBy('id')->pluck('message')->all());
    }

    public function test_S_067_4_a_psid_already_in_botcake_psids_needs_no_api_call(): void
    {
        Http::fake();

        $this->send(['psid' => 'psid-known'])->assertOk();

        Http::assertNothingSent();
        $this->assertSame(1, BotcakeChat::count());
    }

    public function test_S_067_5_an_unknown_psid_is_checked_once_with_the_page_token_and_remembered(): void
    {
        Http::fake([self::INFO => Http::response(['success' => true, 'data' => ['psid' => 'psid-new', 'id' => 'c-77']])]);

        $this->send(['psid' => 'psid-new', 'message' => 'first'])->assertOk();
        $this->send(['psid' => 'psid-new', 'message' => 'second'])->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/pages/1001/customer/psid-new/info') && $r->hasHeader('access-token', self::TOKEN));
        $row = BotcakePsid::where('psid', 'psid-new')->sole();
        $this->assertSame(['Page Uno', 'chat_verify', 'Ana Cruz'], [$row->page, $row->source, $row->fb_name]);
        $this->assertSame(['first', 'second'], BotcakeChat::orderBy('id')->pluck('message')->all());
    }

    public static function notOnPage(): array
    {
        return ['not found' => ['Not found customer'], 'wrong format' => ['psid wrong format']];
    }

    /** @dataProvider notOnPage */
    public function test_S_067_6_a_psid_not_on_the_page_is_not_stored_counted_and_not_asked_again_for_an_hour(string $message): void
    {
        Http::fake([self::INFO => Http::response(['success' => false, 'message' => $message])]);
        $this->travelTo('2026-10-19 10:00:00');

        $this->send(['psid' => 'psid-foreign'])->assertOk()->assertExactJson(['ok' => true]);
        $this->travelTo('2026-10-19 10:59:00');
        $this->send(['psid' => 'psid-foreign'])->assertOk()->assertExactJson(['ok' => true]);

        Http::assertSentCount(1);
        $this->assertSame(0, BotcakeChat::count());
        $this->assertSame(0, BotcakePsid::where('psid', 'psid-foreign')->count());
        $this->assertSame(['psid_not_found' => 2], $this->counts());

        $this->travelTo('2026-10-19 11:01:00');
        $this->send(['psid' => 'psid-foreign'])->assertOk();
        Http::assertSentCount(2);
    }

    public static function botcakeDown(): array
    {
        return [
            'timeout'    => [fn () => throw new ConnectionException('timed out')],
            'HTTP 500'   => [fn () => Http::response('oops', 500)],
            'token gone' => [fn () => Http::response(['success' => false, 'message' => 'Invalid access token'], 401)],
        ];
    }

    /** @dataProvider botcakeDown */
    public function test_S_067_7_when_botcake_cannot_answer_the_chat_is_stored_and_logged(\Closure $answer): void
    {
        $calls = 0; // ang itinapong timeout ay hindi naitatala ng Http::fake, kaya binibilang dito
        Http::fake([self::INFO => function () use (&$calls, $answer) {
            $calls++;
            return $answer();
        }]);
        Log::spy();

        $this->send(['psid' => 'psid-new'])->assertOk();

        $this->assertSame(1, $calls, 'isang subok lang, walang retry (naghihintay ang Botcake)');
        $this->assertSame(['psid-new'], BotcakeChat::pluck('psid')->all());
        $this->assertSame(0, BotcakePsid::where('psid', 'psid-new')->count(), 'hindi pa napatunayan: susubukan ulit sa susunod');
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx = []) => str_contains($msg, 'not verified')
            && !str_contains(json_encode($ctx), self::TOKEN) && !str_contains(json_encode($ctx), self::KEY_UNO))->once();
    }

    public function test_S_067_8_the_setup_command_dry_run_writes_nothing_and_prints_no_key(): void
    {
        Http::fake();
        $this->page('Page Tres', '3003');

        $code = Artisan::call('botcake:chat-setup', ['--all' => true, '--dry-run' => true]);
        $out = Artisan::output();

        $this->assertSame(0, $code);
        Http::assertNothingSent();
        $this->assertNull(app(PageSettings::class)->get('Page Tres', 'botcake', 'chat.key'));
        $this->assertStringContainsString('Page Tres', $out);
        $this->assertStringContainsString('would create a key', $out);
        $this->assertStringNotContainsString(self::KEY_UNO, $out);

        $this->assertSame(1, Artisan::call('botcake:chat-setup', ['--all' => true]), '--all na walang --confirm o --dry-run');
        Http::assertNothingSent();
    }

    public function test_S_067_9_the_setup_command_writes_the_exact_block_and_publishes_it(): void
    {
        Http::fake(['botcake.io/*' => Http::response(['success' => true])]);
        $this->page('Page Tres', '3003');

        $this->assertSame(0, Artisan::call('botcake:chat-setup', ['page' => 'page tres']));
        $out = Artisan::output();

        $key = app(PageSettings::class)->get('Page Tres', 'botcake', 'chat.key');
        $this->assertGreaterThanOrEqual(32, strlen((string) $key));
        $this->assertStringNotContainsString($key, $out);
        $this->assertStringContainsString('Page Tres', $out);

        $sent = Http::recorded();
        $this->assertCount(2, $sent);
        [$content] = $sent[0];
        $this->assertSame('https://botcake.io/api/public_api/v1/pages/3003/default_reply/content', $content->url());
        $this->assertTrue($content->hasHeader('access-token', self::TOKEN));
        $body = '{"page_id":"{{page_id}}","psid":"{{psid}}","name":"{{user_full_name}}","message":"{{last_text_input}}","source":"default_reply"}';
        $pad = ['key' => '', 'value' => ''];
        $this->assertSame(['name' => 'Default reply', 'blocks' => [[
            'key' => 'chat_to_site', 'title' => 'Chat to site', 'message_tag' => 'OTHER', 'next_step' => true, 'gotos' => [],
            'cards' => [[
                'plugin_id' => 'json_api', 'config' => ['method' => 'post', 'url' => 'https://app.example.test/api/botcake/chat'],
                'headers' => [['key' => 'X-Chat-Key', 'value' => $key], $pad, $pad],
                'params' => [$pad, $pad, $pad],
                'body' => $body, 'body_preview' => json_decode($body, true),
                'response' => array_fill(0, 3, ['custom_field_id' => null, 'key' => '']), 'buttons' => [],
            ]],
        ]]], $content->data());
        [$settings] = $sent[1];
        $this->assertSame('https://botcake.io/api/public_api/v1/pages/3003/default_reply', $settings->url());
        $this->assertSame(['update' => ['is_published' => true, 'unlimit_default_reply' => true]], $settings->data());

        // Pangalawang takbo: ang key ay nananatili; --rotate: bagong key.
        Artisan::call('botcake:chat-setup', ['page' => 'Page Tres']);
        $this->assertSame($key, app(PageSettings::class)->get('Page Tres', 'botcake', 'chat.key'));
        Artisan::call('botcake:chat-setup', ['page' => 'Page Tres', '--rotate' => true]);
        $this->assertNotSame($key, app(PageSettings::class)->get('Page Tres', 'botcake', 'chat.key'));
    }

    public function test_S_067_10_a_failed_block_write_keeps_the_old_key(): void
    {
        Http::fake(['botcake.io/*' => Http::response(['success' => false, 'message' => 'Something wrong'])]);

        $this->assertSame(1, Artisan::call('botcake:chat-setup', ['page' => 'Page Uno', '--rotate' => true]));
        $this->assertSame(self::KEY_UNO, app(PageSettings::class)->get('Page Uno', 'botcake', 'chat.key'));
        $this->assertStringContainsString('failed', Artisan::output());
    }

    public function test_S_067_11_the_setup_box_shows_which_pages_have_a_key_and_todays_refusals_but_no_key(): void
    {
        Http::fake();
        $this->page('Page Tres', '3003');
        $this->send([], 'wrong-key-0000000000000000000000000')->assertStatus(401);
        $this->actingAs($this->user());

        $html = $this->get('/chatblast/chats')->assertOk()
            ->assertSee('botcake:chat-setup')
            ->assertSee('{"page_id":"{{page_id}}","psid":"{{psid}}","name":"{{user_full_name}}","message":"{{last_text_input}}","source":"default_reply"}')
            ->assertSeeInOrder(['Page Dos', 'yes', 'Page Tres', 'no', 'Page Uno', 'yes'])
            ->assertSeeInOrder(['Refused today', 'Bad key', '1'])
            ->getContent();
        foreach ([self::SHARED, self::KEY_UNO, self::KEY_DOS, '••••'] as $secret) $this->assertStringNotContainsString($secret, $html);
    }
}
