<?php

namespace Tests\Feature\Chatblast;

use App\Models\ChatbotFlow;
use App\Services\Chatbot\BotcakeOutbox;
use App\Services\Chatbot\BotcakeSendPool;
use App\Services\Chatbot\BotcakeSendQueue;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\CustomerReach;
use App\Services\Chatbot\FlowEngine;
use App\Services\Chatbot\FlowGraph;
use App\Services\Chatbot\FlowRun;
use GuzzleHttp\Promise\Promise;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;

/**
 * Ang padalang hindi sagot sa customer na hindi maabot: ang sequence step (sa chatbot:sequence-pool at sa job na naghihintay),
 * ang wait na lumampas (botcake:send-pool at diretso) ay nilalaktawan at itinatala, walang tawag sa Botcake o sa gateway.
 * Ang sagot sa customer ay hindi kailanman nilalaktawan.
 */
class CustomerReachSendTest extends SequenceHandoffTestCase
{
    private const SEND = 'https://botcake.io/api/public_api/v1/pages/1001/flows/send_content';

    private function mark(string $psid, string $pageId = '1001', int $code = 551): void
    {
        DB::table('chatbot_customer_values')->insert(['page_id' => (int) $pageId, 'psid' => (int) $psid, 'key' => CustomerReach::KEY,
            'value' => json_encode(['code' => $code, 'at' => '2026-11-02 09:00:00']), 'updated_at' => now()]);
    }

    private const WHY = "Customer can't be reached (#551) since 2026-11-02 09:00";

    public function test_S_234_2_1_a_sequence_step_to_a_marked_customer_is_skipped_recorded_and_not_called(): void
    {
        $seq = $this->sequence('Follow', [$this->stepFlow(['Hi'])->id]);
        $this->mark('8001');
        $this->join($seq, '8001');

        $this->steps();
        $this->settle();

        $this->assertSame([], $this->calls);
        $this->assertSame(['skipped: Hi (' . self::WHY . ')'], $this->replies('8001'));
        $this->assertSame('completed', $this->sub($seq, '8001')->status, 'ang nilaktawang step ay tapos, gaya ng switched-off');
    }

    public function test_S_234_2_2_a_follow_up_sent_by_the_waiting_job_is_skipped_too(): void
    {
        config(['services.chatbot.sequence_handoff' => false]);
        Http::fake([self::SEND => Http::response(['success' => true])]);
        $seq = $this->sequence('Inline', [$this->stepFlow(['Hi'])->id]);
        $this->mark('8001');
        $this->join($seq, '8001');

        $this->steps();

        Http::assertNothingSent();
        $this->assertSame(['skipped: Hi (' . self::WHY . ')'], $this->replies('8001'));
    }

    public function test_S_234_2_3_a_customer_marked_on_page_a_is_still_messaged_on_page_b(): void
    {
        $seq = $this->sequence('Both', [$this->stepFlow(['Hi'])->id]);
        $this->mark('8001', '1001');
        $this->join($seq, '8001', '1001');
        $this->join($seq, '8001', '1002');

        $this->steps();
        $this->settle();

        $this->assertSame(['/api/public_api/v1/pages/1002/flows/send_content'], array_map(fn ($c) => self::req($c)['path'], $this->calls));
    }

    public function test_S_234_2_4_after_a_551_the_next_sends_of_that_customer_in_the_pool_are_all_skipped(): void
    {
        $seq = $this->sequence('Three', [$this->stepFlow(['A', 'B', 'C'])->id]);
        $this->join($seq, '8001');
        $this->steps();
        $this->round();
        $this->assertCount(1, $this->calls);

        $this->answer($this->calls[0], 200, ['success' => false, 'message' => "(#551) This person isn't available right now."]);
        $this->pool->tick(false);
        for ($i = 0; $i < 10; $i++) {
            $this->resumes();
            $this->round();
        }

        $this->assertCount(1, $this->calls, 'walang lumusot pagkatapos maitala ang marka');
        $this->assertSame(['failed', 'skipped', 'skipped'], array_map(fn ($r) => strtok($r, ':'), $this->replies('8001')));
    }

    public function test_S_234_2_4_a_429_retry_after_another_send_stored_the_mark_is_skipped(): void
    {
        $seq = $this->sequence('Retry', [$this->stepFlow(['Hi'])->id]);
        $this->join($seq, '8001');
        $this->steps();
        $this->round();
        $this->answer($this->calls[0], 429, ['success' => false]);
        $this->pool->tick(false);

        $this->mark('8001'); // hal. ang Chatblast sa customer ay sinagot ng #551 habang naghihintay ang ulit
        $this->clock->time += 15;
        for ($i = 0; $i < 10; $i++) {
            $this->round();
            $this->resumes();
        }

        $this->assertCount(1, $this->calls);
        $this->assertSame(['skipped: Hi (' . self::WHY . ')'], $this->replies('8001'));
    }

    /** Ang takbo ng wait na lumampas (walang chat): send node na naghihintay ng sagot. */
    private function timeoutRun(string $psid, ?int $chatId = null): FlowRun
    {
        $flow = ChatbotFlow::create(['name' => 'Main ' . uniqid(), 'graph' => FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Size?']], 'wait' => true, 'save_to' => 'size']],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]])]);

        return new FlowRun($flow->id, $flow->graph, 'Page Uno', '1001', [], new CustomerFields(1001, (int) $psid), $chatId, '', 'Ana Cruz', '2026-11-02 10:00');
    }

    public function test_S_234_2_2_a_wait_timeout_send_without_a_chat_is_skipped_and_the_question_counts_as_unsent(): void
    {
        config(['services.botcake.async_sends' => false]);
        Http::fake([self::SEND => Http::response(['success' => true])]);
        $this->mark('8001');

        $run = app(FlowEngine::class)->run($this->timeoutRun('8001'), app(BotcakeOutbox::class));

        Http::assertNothingSent();
        $this->assertSame(['skipped: Size? (' . self::WHY . ')'], $this->replies('8001'));
        $fields = new CustomerFields(1001, 8001);
        $this->assertSame($fields->get(CustomerFields::WAIT), $fields->get(CustomerFields::WAIT_UNSENT), 'ang susunod na mensahe ay hindi sagot sa tanong na hindi dumating');
        $this->assertSame(self::WHY, collect($run->events)->firstWhere('type', 'skipped')['detail']['reason'] ?? null);
    }

    public function test_S_234_2_2_the_reply_pool_skips_a_wait_timeout_ticket_of_a_marked_customer(): void
    {
        $calls = [];
        $pool = new BotcakeSendPool(function (RequestInterface $req) use (&$calls) {
            $calls[] = $req;
            return new Promise();
        }, $this->clock);
        $this->replyPool->stop();
        $this->assertTrue($pool->start());
        $this->mark('8001');
        DB::table(BotcakeSendQueue::TABLE)->insert(['request_id' => (string) Str::uuid(), 'kind' => 'chatbot', 'priority' => 0,
            'customer' => BotcakeSendQueue::chatbotCustomer(1001, 8001), 'page_id' => 1001, 'page_name' => 'Page Uno', 'psid' => '8001', 'chat_id' => null,
            'flow_id' => 1, 'node_id' => 'n2', 'run_id' => 'r1', 'payload' => json_encode(['messages' => [['type' => 'text', 'text' => 'Size?']]]), 'context' => '{}',
            'status' => 'pending', 'deadline_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now()]);

        for ($i = 0; $i < 5; $i++) {
            $this->clock->time += 0.25;
            $pool->tick();
        }

        $this->assertSame([], $calls);
        $this->assertSame(['skipped: Size? (' . self::WHY . ')'], $this->replies('8001'));
    }

    public function test_an_answer_to_the_customer_is_never_skipped_even_while_marked(): void
    {
        config(['services.botcake.async_sends' => false]);
        Http::fake([self::SEND => Http::response(['success' => true])]);
        $this->mark('8001');
        $chat = $this->chat('8001');

        app(FlowEngine::class)->run($this->timeoutRun('8001', $chat->id), app(BotcakeOutbox::class));

        $this->assertCount(1, Http::recorded(fn (HttpRequest $r) => $r->url() === self::SEND));
        $this->assertSame(['sent: Size?'], $this->replies('8001'));
    }

    public function test_the_switch_off_sends_to_a_marked_customer(): void
    {
        config(['services.chatbot.stop_unreachable' => false]);
        $seq = $this->sequence('Off', [$this->stepFlow(['Hi'])->id]);
        $this->mark('8001');
        $this->join($seq, '8001');

        $this->steps();
        $this->settle();

        $this->assertCount(1, $this->calls);
    }
}
