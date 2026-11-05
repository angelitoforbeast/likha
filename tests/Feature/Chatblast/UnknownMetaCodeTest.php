<?php

namespace Tests\Feature\Chatblast;

use App\Models\PageSetting;
use App\Services\Chatbot\BotHealth;
use App\Services\Chatbot\ChatbotProvider;
use App\Services\Chatbot\ForbeastWatch;
use App\Services\Chatbot\Gateways;
use App\Services\Chatbot\GatewayProvider;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Ang dahilan ng error ng Meta (ForbeastWatch::cause): ang #10 na may subcode 1893063 ay restriction ng page (pareho sa
 * Botcake, kaya walang lipat at hindi binibilang), at ang #10 na may 2018278 ay sa customer pa rin. Fake na HTTP.
 */
class UnknownMetaCodeTest extends ChatblastTestCase
{
    private const GW = 'https://gw.example.test';
    private const GW_SEND = self::GW . '/v1/pages/1001/messages';
    private const PSID = '6012345678901234';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_inbox_indexes.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_28_100000_create_chatbot_ai_requests_table.php',
            'database/migrations/2026_10_29_100000_create_botcake_send_requests_table.php',
            'database/migrations/2026_10_30_098000_add_raw_to_chatbot_replies.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
        ]]);
        config(['services.chatbot.replies_enabled' => true, 'services.gateway.url' => self::GW,
            'services.gateway.key' => 'gw_test_key_1q2w3e4r', 'services.gateway.timeout' => 10]);
        $this->page('Page Uno', '1001');
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'provider', 'gateway');
        PageSetting::where('provider', 'chatbot')->where('key', 'provider')->update(['created_at' => now()->subHours(3)]);
    }

    private function send(): array
    {
        return (new GatewayProvider())->send('Page Uno', '1001', self::PSID, [['type' => 'text', 'text' => 'Hi']], ChatbotProvider::ref());
    }

    /** $n na palyang padala na may $body bilang sagot ng gateway (HTTP 400). */
    private function failSends(int $n, array $body): void
    {
        Http::fake([self::GW_SEND => Http::response($body, 400)]);
        for ($i = 0; $i < $n; $i++) $this->send();
    }

    private function meta10(int $sub, string $message): array
    {
        return ['error' => ['code' => 10, 'error_subcode' => $sub, 'message' => $message]];
    }

    private function switchEvents(): int
    {
        return PageSetting::where('provider', 'chatbot')->where('key', ForbeastWatch::KEY)->count();
    }

    private function assertNoSwitch(string $why): void
    {
        $this->assertSame('gateway', ChatbotProvider::name('Page Uno'), $why);
        $this->assertSame(0, $this->switchEvents(), $why);
    }

    public function test_S_235_3_1_code_10_subcode_1893063_is_a_restriction_that_never_switches(): void
    {
        $this->assertSame('restricted', ForbeastWatch::cause(10, 1893063));

        $limit = (int) config('services.gateway.auto_switch.failed_sends');
        $this->failSends($limit + 1, $this->meta10(1893063, 'You are temporarily restricted from sending messages'));
        $this->assertNoSwitch('ang restriction ay pareho sa Botcake: walang lipat');

        // Hindi nadagdagan ang bilang ng palyang padala: ang kulang ng isa sa bilang na ordinaryong palya ay wala pang lipat.
        $this->failSends($limit - 1, ['message' => 'x']);
        $this->assertNoSwitch('walang naiwang bilang mula sa restriction');

        app(ForbeastWatch::class)->afterResult('1001', Gateways::active('Page Uno'), 10, 1893063);
        $this->assertNoSwitch('ang restriction sa resulta ng Meta: walang lipat');

        // Ang BotHealth ay binibilang ito na "Page restricted" mula sa text ng error (characterisation).
        DB::table('chatbot_replies')->delete();
        DB::table('chatbot_replies')->insert(['page_id' => 1001, 'psid' => (int) self::PSID, 'node_id' => 'n2', 'kind' => 'send', 'status' => 'failed',
            'error' => 'Forbeast could not send: "You are temporarily restricted from sending messages" (#10) subcode 1893063', 'http' => 400,
            'sent_at' => now()->toDateTimeString()]);
        Cache::flush();
        $reasons = collect(app(BotHealth::class)->output([])['failures'])->pluck('reason')->all();
        $this->assertSame([BotHealth::REASONS['restricted']], $reasons);
    }

    public function test_S_235_3_2_code_10_subcode_2018278_stays_a_customer_cause(): void
    {
        $this->assertSame('customer', ForbeastWatch::cause(10, 2018278));

        app(ForbeastWatch::class)->afterResult('1001', Gateways::active('Page Uno'), 10, 2018278);
        $this->assertNoSwitch('sa customer ang dahilan sa resulta ng Meta');

        $this->failSends((int) config('services.gateway.auto_switch.failed_sends') + 1,
            $this->meta10(2018278, 'This message is sent outside of allowed window.'));
        $this->assertNoSwitch('sa customer ang dahilan sa padala');
    }
}
