<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Ang limiter ng /api/botcake/chat: walang limit (at walang cache write ng limiter) para sa IP ng Botcake at sa
 * pansamantalang test IP (botcake:chat-test-ip); ang ibang IP ay chat_rate kada minuto pa rin.
 */
class BotcakeChatLimitTest extends ChatblastTestCase
{
    private const KEY = 'chat-key-limit-test-77';
    private const BOTCAKE_IP = '203.171.22.6';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['botcake.io/*' => Http::response(['success' => true])]);
        Queue::fake();
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_20_100000_create_botcake_chats_table.php']]);
        config(['services.botcake.chat_key' => self::KEY, 'services.botcake.chat_ips' => [self::BOTCAKE_IP], 'services.botcake.chat_rate' => 3]);
        $this->page('Page Uno', '1001');
    }

    private function chat(string $ip, string $message)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/botcake/chat', ['page_id' => '1001', 'psid' => 'psid-a', 'name' => 'Ana Cruz', 'message' => $message], ['X-Chat-Key' => self::KEY]);
    }

    /** Ang mga cache key ng limiter (ang database cache ng test ay hindi ginagamit; tinitingnan ang Cache store mismo). */
    private function limiterHits(string $ip): int
    {
        return (int) app('cache')->get(md5('botcake-chat' . $ip));
    }

    public function test_S_083_1_botcakes_ip_is_never_limited_and_writes_no_limiter_entry(): void
    {
        for ($i = 1; $i <= 6; $i++) $this->chat(self::BOTCAKE_IP, "msg {$i}")->assertOk();

        $this->assertSame(6, BotcakeChat::count());
        $this->assertSame(0, $this->limiterHits(self::BOTCAKE_IP), 'no limiter cache write for Botcake');
    }

    public function test_S_083_2_another_ip_gets_429_after_chat_rate(): void
    {
        foreach ([1, 2, 3] as $i) $this->chat('198.51.100.9', "msg {$i}")->assertOk();
        $this->chat('198.51.100.9', 'msg 4')->assertStatus(429);
        $this->assertSame(3, $this->limiterHits('198.51.100.9'), 'the limiter does count other IPs (the refused one is not a hit)');

        $this->assertSame(3, BotcakeChat::count());
    }

    public function test_S_083_3_a_test_ip_is_unlimited_until_it_expires(): void
    {
        $this->assertSame(0, Artisan::call('botcake:chat-test-ip', ['ip' => '198.51.100.20', '--hours' => 2]));
        for ($i = 1; $i <= 5; $i++) $this->chat('198.51.100.20', "msg {$i}")->assertOk();
        $this->assertSame(5, BotcakeChat::count());

        Artisan::call('botcake:chat-test-ip', ['ip' => '_', '--list' => true]);
        $this->assertStringContainsString('198.51.100.20', Artisan::output());

        $this->travel(121)->minutes();
        foreach ([1, 2, 3] as $i) $this->chat('198.51.100.20', "late {$i}")->assertOk();
        $this->chat('198.51.100.20', 'late 4')->assertStatus(429);
    }

    public function test_S_083_4_the_command_removes_refuses_bad_input_and_caps_at_12_hours(): void
    {
        Artisan::call('botcake:chat-test-ip', ['ip' => '198.51.100.30']);
        $this->assertSame(0, Artisan::call('botcake:chat-test-ip', ['ip' => '198.51.100.30', '--remove' => true]));
        foreach ([1, 2, 3] as $i) $this->chat('198.51.100.30', "msg {$i}")->assertOk();
        $this->chat('198.51.100.30', 'msg 4')->assertStatus(429);

        $this->assertSame(1, Artisan::call('botcake:chat-test-ip', ['ip' => 'not-an-ip']));
        $this->assertSame(1, Artisan::call('botcake:chat-test-ip', ['ip' => '198.51.100.31', '--hours' => 13]));
        $this->assertSame(1, Artisan::call('botcake:chat-test-ip', ['ip' => '198.51.100.31', '--hours' => 0]));
        Artisan::call('botcake:chat-test-ip', ['ip' => '_', '--list' => true]);
        $this->assertStringNotContainsString('198.51.100.31', Artisan::output());
    }
}
