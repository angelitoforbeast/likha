<?php

namespace Tests\Feature\Chatblast;

use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Spec 227: ang isang beses na ayos ng mga padala ng gateway na walang kumpirmasyon sa huling 24 oras. Walang basa ayon
 * sa client_ref ang gateway; ang ulit ng parehong client_ref sa POST ng messages ay "duplicate" na may estado ng padala at
 * walang ipinapadala. Dry run muna (bilang lang, walang tawag).
 */
class HealSendConfirmationsTest extends ChatblastTestCase
{
    private const GW = 'https://gw.example.test';

    /** client_ref => ang sagot ng gateway sa POST */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_inbox_indexes.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_30_098000_add_raw_to_chatbot_replies.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
            'database/migrations/2026_11_06_100000_add_chat_index_to_chatbot_replies.php',
            'database/migrations/2026_11_09_100000_add_via_to_chatbot_replies.php',
            'database/migrations/2026_11_11_100000_add_real_send_to_chatbot_replies.php',
        ]]);
        config(['services.gateway.url' => self::GW, 'services.gateway.key' => 'gw_test_key_227']);
        $this->travelTo('2026-11-04 08:00:00');
        $this->page('Page Uno', '1001');
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'provider', 'gateway');
        Http::preventStrayRequests();
        Http::fake([self::GW . '/v1/pages/*/messages' => function (HttpRequest $r) {
            return $this->answers[$r['client_ref']] ?? Http::response(['error' => ['code' => 'x', 'message' => 'not in the test']], 500);
        }]);
    }

    private function reply(string $ref, string $at, array $extra = []): int
    {
        return DB::table('chatbot_replies')->insertGetId($extra + ['page_id' => 1001, 'psid' => 7001, 'node_id' => 'n2', 'kind' => 'send', 'status' => 'sent', 'http' => 202,
            'sent_at' => $at, 'via' => 'forbeast', 'client_ref' => $ref, 'content' => json_encode([['type' => 'text', 'text' => 'Hi ' . $ref]])]);
    }

    /** Ang sagot ng gateway sa ulit na client_ref (SendController: 200, duplicate, ang send). */
    private static function duplicate(string $ref, string $status, ?string $sentAt, int $sent = 1, ?array $error = null): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['duplicate' => true, 'id' => 'snd_' . $ref, 'page_id' => '1001', 'psid' => '7001', 'client_ref' => $ref, 'status' => $status,
            'messages' => 1, 'sent' => $sent, 'message_ids' => [], 'attempts' => 1, 'error' => $error, 'created_at' => '2026-11-03T12:00:00.000Z',
            'sent_at' => $sentAt, 'finished_at' => $sentAt], 200);
    }

    private function row(int $id): object
    {
        return DB::table('chatbot_replies')->find($id);
    }

    /** Ang mga kandidato at ang hindi: [ids ng kandidato] */
    private function rows(): array
    {
        $this->reply('confirmed', '2026-11-04 07:00:00', ['confirmed_status' => 'sent', 'confirmed_at' => '2026-11-04 07:00:03']);
        $this->reply('botcake', '2026-11-04 07:00:00', ['via' => 'botcake']);
        $this->reply('failed-call', '2026-11-04 07:00:00', ['status' => 'failed', 'http' => 500]);
        $this->reply('too-old', '2026-11-03 07:59:00');
        $this->reply('', '2026-11-04 07:00:00', ['client_ref' => null]);

        return [$this->reply('likha-a', '2026-11-03 20:00:00'), $this->reply('likha-b', '2026-11-04 07:00:00'), $this->reply('likha-c', '2026-11-04 07:30:00')];
    }

    public function test_S_227_3_1_the_dry_run_counts_the_candidates_and_asks_and_writes_nothing(): void
    {
        $this->rows();

        $this->assertSame(0, Artisan::call('chatbot:heal-send-confirmations'));
        $out = Artisan::output();

        $this->assertStringContainsString('Unconfirmed gateway replies of the last 24 h: 3 (forbeast 3)', $out);
        $this->assertStringContainsString('Dry run', $out);
        Http::assertNothingSent();
        $this->assertSame(1, DB::table('chatbot_replies')->whereNotNull('confirmed_status')->count());
    }

    public function test_S_227_3_2_apply_fills_the_final_state_from_the_duplicate_answer_and_leaves_a_send_still_going(): void
    {
        [$a, $b, $c] = $this->rows();
        $this->answers = [
            'likha-a' => self::duplicate('likha-a', 'sent', '2026-11-03T12:00:03.000Z'),
            'likha-b' => self::duplicate('likha-b', 'failed', null, 0, ['code' => 551, 'subcode' => null, 'message' => 'This person isn\'t available right now.']),
            'likha-c' => self::duplicate('likha-c', 'sending', null, 0),
        ];

        $this->assertSame(0, Artisan::call('chatbot:heal-send-confirmations', ['--apply' => true, '--sleep-ms' => 0]));
        $out = Artisan::output();

        $this->assertSame(['sent', '2026-11-03 20:00:03'], [$this->row($a)->confirmed_status, $this->row($a)->confirmed_at]);
        $this->assertSame(['failed', 'failed'], [$this->row($b)->confirmed_status, $this->row($b)->status]);
        $this->assertStringContainsString('(#551)', $this->row($b)->error);
        $this->assertNull($this->row($c)->confirmed_status, 'still sending at the gateway: its event is still coming');
        $this->assertStringContainsString('confirmed sent 1, confirmed failed or partial 1, still sending 1', $out);

        Http::assertSentCount(3);
        Http::assertSent(fn (HttpRequest $r) => $r['client_ref'] === 'likha-a' && $r['psid'] === '7001' && $r['messages'] === [['type' => 'text', 'text' => 'Hi likha-a']]
            && $r->hasHeader('Authorization', 'Bearer gw_test_key_227') && str_ends_with($r->url(), '/v1/pages/1001/messages'));
    }

    public function test_S_227_3_3_an_answer_that_is_not_a_duplicate_stops_the_run(): void
    {
        [$a, $b] = $this->rows();
        $this->answers = [
            'likha-a' => Http::response(['id' => 'snd_new', 'status' => 'queued'], 202),
            'likha-b' => self::duplicate('likha-b', 'sent', '2026-11-03T23:00:03.000Z'),
        ];

        $this->assertSame(1, Artisan::call('chatbot:heal-send-confirmations', ['--apply' => true, '--sleep-ms' => 0]));
        $out = Artisan::output();

        $this->assertStringContainsString('Stopped: the gateway did not know client_ref likha-a', $out);
        Http::assertSentCount(1);
        $this->assertNull($this->row($b)->confirmed_status, 'not asked after the stop');
    }
}
