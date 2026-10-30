<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\BotHealth;
use App\Services\Chatbot\ChatbotInbound;
use App\Services\Chatbot\ChatEchoes;
use App\Services\Chatbot\LiveFeed;
use App\Services\Chatbot\ResponseSpeed;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Ang mga bumabasa ng chat: ang pagbukas ng ad (walang isinulat ang customer, hindi sinasagot) ay hindi mensahe ng
 * customer, kaya hindi ito "inquiry", hindi "not answered" at hindi mabagal; ang Health ay may bilang kada araw ng mga
 * nagbukas ng ad pero hindi sumulat.
 */
class AdOpenReadersTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
            'database/migrations/2026_10_28_100000_create_chatbot_ai_requests_table.php',
            'database/migrations/2026_10_30_098000_add_raw_to_chatbot_replies.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
            'database/migrations/2026_11_06_100000_add_chat_index_to_chatbot_replies.php',
            'database/migrations/2026_11_09_100000_add_via_to_chatbot_replies.php',
            'database/migrations/2026_11_11_100000_add_real_send_to_chatbot_replies.php',
        ]]);
        $this->travelTo('2026-10-21 12:30:00');
        $this->page('Page Uno', '1001');
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'provider', 'gateway');
        DB::table('page_settings')->insert(['page_name' => 'Page Uno', 'provider' => 'chatbot', 'key' => 'flow_id', 'value' => '1', 'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00']);

        // 7001: nagbukas ng ad, sumulat pagkalipas ng 5 s, sinagot pagkalipas ng 2 s.
        $this->chat('7001', '2026-10-21 12:00:00', true);
        $message = $this->chat('7001', '2026-10-21 12:00:05');
        DB::table('chatbot_replies')->insert(['page_id' => 1001, 'psid' => 7001, 'chat_id' => $message, 'node_id' => 'n2', 'kind' => 'send',
            'status' => 'sent', 'sent_at' => '2026-10-21 12:00:07', 'via' => 'forbeast']);
        DB::table('chatbot_events')->insert(['page_id' => 1001, 'psid' => 7001, 'type' => ChatEchoes::TYPE, 'detail' => json_encode(['app_id' => '1', 'sender' => 'bot']),
            'created_at' => '2026-10-21 12:00:07']);
        // 7002: nagbukas ng ad, hindi sumulat.
        $this->chat('7002', '2026-10-21 12:10:00', true);
    }

    private function chat(string $psid, string $at, bool $adOpen = false, string $page = '1001'): int
    {
        $payload = ['source' => 'gateway', '_via' => 'forbeast'] + ($adOpen ? ['ad_id' => '6045246247433', ChatbotInbound::AD_OPEN => true] : []);

        return DB::table('botcake_chats')->insertGetId(['page_id' => $page, 'page_name' => 'Page Uno', 'psid' => $psid, 'message' => $adOpen ? null : 'Hm',
            'payload' => json_encode($payload), 'received_at' => $at]);
    }

    public function test_S_204_6_an_ad_open_is_not_a_customer_message_on_health_speed_echoes_or_live(): void
    {
        $today = app(BotHealth::class)->output([])['tiles']['today'];
        $this->assertSame([1, 1, 0], [$today['inbound'], $today['answered'], $today['not_answered']]);

        $speed = app(ResponseSpeed::class)->output([])['total'];
        $this->assertSame([1, 1, 0], [$speed['messages'], $speed['answered'], $speed['unanswered']]);

        Cache::put('chat-echoes:page:1001', 1, 3600);
        $echoes = app(ChatEchoes::class)->health();
        $this->assertSame(0, collect($echoes['pages'])->sum('unanswered'));

        $this->assertSame(1, app(LiveFeed::class)->counts(null)['messages']['today']);
        // Ang aktibong chat (huling 5 minuto): ang sumulat lang, hindi ang nagbukas lang ng ad.
        $this->chat('7010', '2026-10-21 12:28:00', true);
        $this->chat('7011', '2026-10-21 12:29:00');
        $this->travel(11)->seconds();
        $this->assertSame(1, app(LiveFeed::class)->counts(null)['active']);
    }

    public function test_S_204_7_health_shows_the_customers_who_opened_an_ad_and_never_wrote_per_day(): void
    {
        // 7003: sumulat 30 s BAGO dumating ang referral (nauna ang mensahe): sumulat siya.
        $this->chat('7003', '2026-10-21 12:20:00');
        $this->chat('7003', '2026-10-21 12:20:30', true);
        // 7004: dalawang beses nagbukas ng ad, hindi sumulat: isang customer.
        $this->chat('7004', '2026-10-21 12:21:00', true);
        $this->chat('7004', '2026-10-21 12:22:00', true);
        // 7005: kahapon nagbukas, sumulat kinabukasan: sumulat siya (hindi binibilang).
        $this->chat('7005', '2026-10-20 18:00:00', true);
        $this->chat('7005', '2026-10-21 09:00:00');
        // 7006: sumulat kahapon, nagbukas ng ad ngayon, hindi na sumulat.
        $this->chat('7006', '2026-10-20 10:00:00');
        $this->chat('7006', '2026-10-21 12:25:00', true);

        $days = collect(app(BotHealth::class)->output([])['days'])->keyBy('date');
        $this->assertSame(3, $days['2026-10-21']['ad_opens']); // 7002, 7004, 7006
        $this->assertSame(0, $days['2026-10-20']['ad_opens']); // 7005 sumulat pagkatapos
        $this->assertSame(0, $days['2026-10-19']['ad_opens']);
    }

    public function test_S_204_6_an_ad_open_is_not_a_reply_for_results_or_a_burst_on_health(): void
    {
        // Results (hourly backfill): ang pagbukas ng ad ay hindi sagot sa naunang padala; ang mensahe ay sagot.
        $reply = DB::table('chatbot_replies')->insertGetId(['page_id' => 1001, 'psid' => 7020, 'node_id' => 'n2', 'kind' => 'send', 'status' => 'sent',
            'sent_at' => '2026-10-21 11:00:00', 'via' => 'forbeast']);
        $this->chat('7020', '2026-10-21 11:30:00', true);
        Artisan::call('chatbot:replied-backfill');
        $this->assertNull(DB::table('chatbot_replies')->where('id', $reply)->value('replied_at'));
        $this->chat('7020', '2026-10-21 11:40:00');
        Artisan::call('chatbot:replied-backfill');
        $this->assertSame('2026-10-21 11:40:00', substr((string) DB::table('chatbot_replies')->where('id', $reply)->value('replied_at'), 0, 19));

        // Ang pinakamabagal na listahan: ang mensahe ng 7001 (5 s pagkatapos ng pagbukas ng ad) ay hindi "burst".
        $message = DB::table('botcake_chats')->where('psid', '7001')->where('message', 'Hm')->value('id');
        $slow = collect(app(BotHealth::class)->output([])['slow'])->firstWhere('chat_id', $message);
        $this->assertFalse($slow['burst']);
    }
}
