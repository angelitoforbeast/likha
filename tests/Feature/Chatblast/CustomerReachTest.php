<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\BotcakeOutbox;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\CustomerReach;
use App\Services\Chatbot\SendStatus;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ang customer na hindi maabot (Meta #551, o #10 na labas sa messaging window): ang marka kada page + PSID, mula sa
 * pumalyang padala ng Botcake, ng gateway at ng send_status. Fake na HTTP lang.
 */
class CustomerReachTest extends ChatblastTestCase
{
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
        $this->page('Page Uno', '1001');
        $this->travelTo('2026-11-05 14:03:00');
    }

    private function failed(string $error, ?string $via = null, ?string $raw = null, int $page = 1001): void
    {
        BotcakeOutbox::record($page, 7001, null, null, 'n2', 'send', [['type' => 'text', 'text' => 'Hi']], 'failed', $error, 400, $raw, null, $via);
    }

    /** @return ?array{code: int, at: string} */
    private function mark(int $page = 1001, int $psid = 7001): ?array
    {
        $v = DB::table('chatbot_customer_values')->where('page_id', $page)->where('psid', $psid)->where('key', CustomerReach::KEY)->value('value');

        return $v === null ? null : json_decode($v, true);
    }

    public function test_S_234_1_1_a_send_that_fails_with_551_marks_the_customer_with_the_time_and_551(): void
    {
        $this->failed('Botcake said "(#551) This person isn\'t available right now." (HTTP 200)');

        $this->assertSame(['code' => 551, 'at' => '2026-11-05 14:03:00'], $this->mark());
        $this->assertNull($this->mark(1001, 7002), 'ibang customer: walang marka');
    }

    public static function windowTens(): array
    {
        return [
            'botcake window text' => ['Botcake said "(#10) This message is sent outside of allowed window." (HTTP 200)', null],
            'gateway window subcode' => ['Gateway said "sendMessage failed (code 10, subcode 2018278)" (HTTP 400)', 'forbeast'],
            'window text only in raw' => ['Botcake said "(#10) Message failed" (HTTP 200)', null, '{"error":{"message":"(#10) This message is sent outside of allowed window.","code":10}}'],
        ];
    }

    #[DataProvider('windowTens')]
    public function test_S_234_1_2_a_send_that_fails_with_the_window_10_marks_the_customer_with_10(string $error, ?string $via, ?string $raw = null): void
    {
        $this->failed($error, $via, $raw);

        $this->assertSame(['code' => 10, 'at' => '2026-11-05 14:03:00'], $this->mark());
    }

    public static function notMarked(): array
    {
        return [
            '#100' => ['Botcake said "(#100) No matching user found" (HTTP 200)'],
            '#2022' => ['Botcake said "(#2022) You\'re temporarily restricted from messaging users until December 1 at 3:10 AM." (HTTP 200)'],
            'timeout' => ['Botcake did not answer (HTTP 0)'],
            'restriction 10 from the gateway' => ['Gateway said "sendMessage failed (code 10, subcode 1893063)" (HTTP 400)'],
            'restriction 10 send_status form' => ['Forbeast could not send: "sendMessage failed (code 10, subcode 1893063)" (#10) subcode 1893063'],
            'permission 10' => ['Botcake said "(#10) Application does not have permission for this action" (HTTP 200)'],
            'bare 10' => ['Gateway said "sendMessage failed (code 10)" (HTTP 400)'],
            '#1000 is not 10' => ['Botcake said "(#1000) outside of allowed window" (HTTP 200)'],
            '#5510 is not 551' => ['Botcake said "(#5510) Something" (HTTP 200)'],
            'code 5512 is not 551' => ['Gateway said "sendMessage failed (code 5512)" (HTTP 400)'],
            'available alone' => ['Botcake said "The page isn\'t available right now" (HTTP 200)'],
        ];
    }

    #[DataProvider('notMarked')]
    public function test_S_234_1_3_other_errors_do_not_mark(string $error): void
    {
        $this->failed($error);
        BotcakeOutbox::record(1001, 7001, null, null, 'n2', 'send', [], 'sent', null, 200);

        $this->assertNull($this->mark());
    }

    public static function wordings(): array
    {
        return [
            'botcake isn\'t available, no code' => ['Botcake said "This person isn\'t available right now." (HTTP 200)', null],
            'botcake curly apostrophe' => ["Botcake said \"This person isn\u{2019}t available right now.\" (HTTP 200)", null],
            'gateway sendMessage code 551' => ['Gateway said "sendMessage failed (code 551, subcode 1545041)" (HTTP 400)', 'forbeast'],
        ];
    }

    #[DataProvider('wordings')]
    public function test_S_234_1_4_the_botcake_and_gateway_wordings_are_both_recognised(string $error, ?string $via): void
    {
        $this->failed($error, $via);

        $this->assertSame(551, $this->mark()['code'] ?? null);
    }

    public function test_S_234_1_4_the_gateway_send_status_failure_marks_and_the_1893063_one_does_not(): void
    {
        $s = app(PageSettings::class);
        $s->set('Page Uno', 'chatbot', 'provider', 'gateway');
        $event = function (string $ref, int $psid, int $code, int $sub) {
            DB::table('chatbot_replies')->insert(['page_id' => 1001, 'psid' => $psid, 'node_id' => 'n2', 'kind' => 'send', 'status' => 'sent', 'http' => 202,
                'sent_at' => '2026-11-05 14:00:00', 'via' => 'forbeast', 'client_ref' => $ref]);

            return app(SendStatus::class)->receive(['page_id' => '1001', 'psid' => (string) $psid, 'send' => ['client_ref' => $ref, 'status' => 'failed',
                'accepted_at' => '2026-11-05T06:00:00Z', 'sent_at' => null, 'message_count' => 1, 'sent_count' => 0,
                'error_code' => $code, 'error_subcode' => $sub, 'error' => 'sendMessage failed (code ' . $code . ', subcode ' . $sub . ')']]);
        };

        $this->assertSame('send_status', $event('r1', 7001, 551, 1545041));
        $this->assertSame('send_status', $event('r2', 7002, 10, 1893063));

        $this->assertSame(551, $this->mark(1001, 7001)['code'] ?? null);
        $this->assertNull($this->mark(1001, 7002), 'ang page na restricted (1893063) ay hindi marka ng customer');
    }

    public function test_S_234_3_4_clear_all_fields_keeps_the_mark(): void
    {
        $this->failed('Botcake said "(#551) This person isn\'t available right now." (HTTP 200)');
        $fields = new CustomerFields(1001, 7001);
        $fields->set('size', 'L');

        $fields->clearAll();

        $this->assertNull((new CustomerFields(1001, 7001))->get('size'));
        $this->assertSame(551, $this->mark()['code'] ?? null);
    }

    public function test_the_switch_off_stops_new_marks(): void
    {
        config(['services.chatbot.stop_unreachable' => false]);

        $this->failed('Botcake said "(#551) This person isn\'t available right now." (HTTP 200)');

        $this->assertNull($this->mark());
    }
}
