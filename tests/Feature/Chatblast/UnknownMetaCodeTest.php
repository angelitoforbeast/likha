<?php

namespace Tests\Feature\Chatblast;

use App\Models\PageSetting;
use App\Services\Chatbot\BotHealth;
use App\Services\Chatbot\ChatbotProvider;
use App\Services\Chatbot\ForbeastWatch;
use App\Services\Chatbot\Gateways;
use App\Jobs\HandleGatewayEvent;
use App\Services\Chatbot\GatewayProvider;
use App\Services\Chatbot\MessagingProvider;
use App\Services\Chatbot\ProblemsPanel;
use App\Services\Chatbot\SendStatus;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Ang dahilan ng error ng Meta (ForbeastWatch::cause): ang #10 na may subcode 1893063 ay restriction ng page (pareho sa
 * Botcake, kaya walang lipat at hindi binibilang), at ang #10 na may 2018278 ay sa customer pa rin. Ang hindi kilalang code
 * ay binibilang kada page sa 10 minutong window: ang mga sagot (RESPONSE) ay lumilipat pagkatapos ng hold, ang UPDATE ay
 * orange na linya lang, at ang unang kita ng code sa araw ay isang puting linya. Fake na HTTP.
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

    /** $failed na palya na may $code muna (ang pagkakasunod ay hindi mahalaga), saka $sent na naipadala, sa window ng page. */
    private function tally(int $failed, ?int $code, int $sent, string $type = MessagingProvider::RESPONSE, string $page = 'Page Uno', ?int $sub = null): void
    {
        $watch = app(ForbeastWatch::class);
        for ($i = 0; $i < $failed; $i++) $watch->tally($page, Gateways::active($page), $type, true, $code, $sub);
        for ($i = 0; $i < $sent; $i++) $watch->tally($page, Gateways::active($page), $type, false, null, null);
    }

    /** Ang mga linya ng banner tungkol sa error ng Meta: [page, kulay, text]. */
    private function metaLines(): array
    {
        $out = [];
        foreach (app(ProblemsPanel::class)->panel()['pages'] ?? [] as $p) {
            foreach ($p['lines'] as $l) if (str_contains($l['text'], 'Meta error')) $out[] = [$p['page'], $l['level'], $l['text']];
        }

        return $out;
    }

    private function orange(): array
    {
        return array_values(array_filter($this->metaLines(), fn ($l) => $l[1] === 'orange'));
    }

    /** Isa pang page na nasa gateway, para sa test na maraming page. */
    private function gatewayPage(string $name, string $id): void
    {
        $this->page($name, $id);
        app(PageSettings::class)->set($name, 'chatbot', 'provider', 'gateway');
        PageSetting::where('provider', 'chatbot')->where('key', 'provider')->update(['created_at' => now()->subHours(3)]);
    }

    /** Ang huling lipat ng page (ang event), o null. */
    private function switched(string $page = 'Page Uno'): ?array
    {
        $s = PageSetting::where('provider', 'chatbot')->where('key', ForbeastWatch::KEY)->where('page_name', $page)->orderByDesc('id')->first();
        $e = $s ? json_decode((string) $s->plainValue(), true) : null;

        return is_array($e) && ($e['result'] ?? null) === ForbeastWatch::SWITCHED ? $e : null;
    }

    /** Isang sagot ng bot sa customer (may chat_id, walang sequence) na tinanggap ng gateway, saka ang send_status nito. */
    private function confirmed(int $i, string $status, ?int $code): void
    {
        $ref = 'likha-unk-' . $i;
        DB::table('chatbot_replies')->insert(['page_id' => 1001, 'psid' => (int) self::PSID, 'chat_id' => 500 + $i, 'node_id' => 'n2', 'kind' => 'send',
            'status' => 'sent', 'http' => 202, 'sent_at' => now()->toDateTimeString(), 'via' => 'forbeast', 'client_ref' => $ref]);
        app(SendStatus::class)->receive(HandleGatewayEvent::fields(['kind' => 'event', 'event_id' => 100 + $i, 'type' => 'send_status', 'page_id' => '1001',
            'psid' => self::PSID, 'send' => ['public_id' => 'snd_' . $ref, 'client_ref' => $ref, 'status' => $status, 'accepted_at' => now()->toIso8601String(),
                'sent_at' => $status === 'sent' ? now()->toIso8601String() : null, 'message_count' => 1, 'sent_count' => $status === 'sent' ? 1 : 0,
                'error_code' => $code, 'error_subcode' => null, 'error' => $code !== null ? 'Something new' : null]]));
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

        // Hindi rin ito hindi kilalang code: walang puting linya ng bagong code, at walang lipat mula sa window ng mga sagot.
        $this->tally(12, 10, 0, MessagingProvider::RESPONSE, 'Page Uno', 1893063);
        $this->travel(3)->minutes();
        app(ForbeastWatch::class)->settleUnknown();
        $this->assertNoSwitch('ang restriction ay hindi hindi-kilalang code');
        $this->assertSame([], $this->metaLines());

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

    public function test_S_235_2_1_four_of_twelve_replies_failing_with_an_unknown_code_switch_the_page(): void
    {
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_11_09_100000_add_via_to_chatbot_replies.php',
            'database/migrations/2026_11_11_100000_add_real_send_to_chatbot_replies.php',
        ]]);
        Http::fake(['*' => Http::response([], 404)]);
        // Ang tunay na daan: ang send_status ng bawat sagot. Ang mga palya muna, saka ang mga naipadala.
        for ($i = 0; $i < 4; $i++) $this->confirmed($i, 'failed', 9999);
        for ($i = 4; $i < 12; $i++) $this->confirmed($i, 'sent', null);
        $this->assertNoSwitch('naghihintay muna ng hold (baka marami pang page ang may parehong code)');

        $this->travel((int) config('services.gateway.auto_switch.hold_minutes') + 1)->minutes();
        $this->artisan('chatbot:forbeast-watch')->assertSuccessful();

        $e = $this->switched();
        $this->assertNotNull($e, 'lumipat ang page');
        $this->assertSame('unknown_error', $e['condition']);
        $this->assertStringContainsString('#9999', ForbeastWatch::reason($e));
        $this->assertStringContainsString('4 of 12', $e['error']);
        $this->assertSame('botcake', ChatbotProvider::name('Page Uno'));
    }

    public function test_S_235_2_2_two_of_twelve_replies_failing_do_not_switch(): void
    {
        $this->tally(2, 9999, 10);
        $this->travel(3)->minutes();
        app(ForbeastWatch::class)->settleUnknown();
        $this->assertNoSwitch('2 sa 12 ay kulang sa 30%');
    }

    public function test_S_235_2_3_customer_code_failures_make_no_switch_and_no_line(): void
    {
        $this->tally(15, 551, 5, MessagingProvider::UPDATE);
        $this->travel(3)->minutes();
        app(ForbeastWatch::class)->settleUnknown();
        $this->assertNoSwitch('sa customer ang #551');
        $this->assertSame([], $this->metaLines());
    }

    public function test_S_235_2_7_zero_percent_turns_the_switch_and_the_line_off(): void
    {
        config(['services.gateway.auto_switch.unknown_response_pct' => 0, 'services.gateway.auto_switch.unknown_update_pct' => 0]);
        $this->tally(12, 9999, 0);
        $this->tally(20, 9999, 0, MessagingProvider::UPDATE);
        $this->travel(3)->minutes();
        app(ForbeastWatch::class)->settleUnknown();

        $this->assertNoSwitch('0% = patay ang lipat');
        $this->assertSame([], $this->orange());
    }
}
