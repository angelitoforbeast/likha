<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\GatewayAfterSend;
use App\Jobs\ResumeChatbotSequenceSend;
use App\Jobs\RunChatbotSequenceStep;
use App\Services\Chatbot\BotcakeSendQueue;
use App\Services\ChatbotFields;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\ForbeastWatch;
use App\Services\Chatbot\GatewayProvider;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Spec 215, ang hand-off: ang sequence step ay nagpapasa ng padala sa chatbot:sequence-pool at hindi naghihintay; ang
 * resulta ay itinutuloy ng ResumeChatbotSequenceSend (queue chatbot) na umuusad nang isang beses. Botcake at gateway,
 * pareho ang daan. Ang inline (flag off o walang pool) ang reference ng "gaya ng dati".
 */
class SequenceHandoffTest extends SequenceHandoffTestCase
{
    /** Run the same step inline (the flag off) for another customer: the reference row. */
    private function inline(array $texts, string $psid, array $fake, string $pageId = '1001'): object
    {
        config(['services.chatbot.sequence_handoff' => false]);
        Http::fake(['botcake.io/*' => $fake[0] ?? Http::response(['success' => true]), 'gw.test/*' => $fake[1] ?? Http::response(['ok' => true])]);
        $seq = $this->sequence('Inline ' . $psid, [$this->stepFlow($texts)->id]);
        $this->join($seq, $psid, $pageId);
        $this->steps();
        config(['services.chatbot.sequence_handoff' => true]);

        return $seq;
    }

    private function row(string $psid): array
    {
        $r = (array) DB::table('chatbot_replies')->where('psid', (int) $psid)->orderBy('id')->first();
        unset($r['id'], $r['psid'], $r['flow_id'], $r['sent_at'], $r['created_at'], $r['updated_at'], $r['sequence_id'], $r['client_ref'], $r['confirmed_at']);

        return $r;
    }

    public function test_S_215_2_and_3_the_step_hands_its_send_off_and_advances_once_the_result_is_known(): void
    {
        $seq = $this->sequence('Promo', [$this->stepFlow(['Hello there'])->id, [$this->stepFlow(['Second'])->id, 2, 'hours']]);
        $id = $this->join($seq, '7001');

        $this->tick();
        $job = Queue::pushed(RunChatbotSequenceStep::class)->first();
        $start = microtime(true);
        $this->work(RunChatbotSequenceStep::class);
        $this->assertLessThan(0.5, microtime(true) - $start, 'handle() ends without waiting on the API');
        $this->assertSame(280, $job->timeout, 'the job keeps its timeout; a running job is protected by its marker');

        $t = $this->tickets()->sole();
        $this->assertSame(['sequence', 'pending'], [$t->kind, $t->status]);
        $sub = $this->sub($seq, '7001');
        $this->assertSame([1, $t->request_id], [(int) $sub->current_step, $sub->send_request_id], 'the pointer has not moved; the row holds the ticket');
        $this->assertSame([], $this->replies('7001'));

        $this->round();
        $this->assertCount(1, $this->calls);
        $this->assertSame(['/api/public_api/v1/pages/1001/flows/send_content', '7001', 'Hello there'], [self::req($this->calls[0])['path'], self::req($this->calls[0])['psid'], self::req($this->calls[0])['text']]);
        $this->answerAll();
        Queue::assertPushedOn('chatbot', ResumeChatbotSequenceSend::class);
        $this->assertSame(1, $this->resumes());

        $this->assertSame(['sent: Hello there'], $this->replies('7001'));
        $sub = $this->sub($seq, '7001');
        $this->assertSame([2, null, null, '2026-11-02 12:00:00'], [(int) $sub->current_step, $sub->send_request_id, $sub->dispatched_at, substr((string) $sub->next_send_at, 0, 19)]);
        $this->assertNotNull($sub->last_sent_at);
        $this->assertSame(['Subscribed to sequence Promo', 'Sequence Promo step 1 ran'], $this->events('7001'));
        $ran = DB::table('chatbot_events')->where('type', 'sequence')->get()->map(fn ($e) => json_decode($e->detail, true))->firstWhere('op', 'ran');
        $this->assertSame(['2026-11-02 10:00:00', $seq->id, 1], [$ran['due'], $ran['sequence_id'], $ran['step']]);

        $this->assertSame(0, $this->resumes(), 'nothing more to resume');
        app()->call([new ResumeChatbotSequenceSend(['request_id' => $t->request_id]), 'handle']);
        $this->assertSame(2, (int) $this->sub($seq, '7001')->current_step, 'a duplicate resume does nothing');
        $this->assertSame(1, $id > 0 ? 1 : 0);
    }

    public function test_S_215_4_a_551_is_logged_failed_with_the_same_text_not_retried_and_the_step_advances(): void
    {
        $body = ['success' => false, 'error' => ['message' => "(#551) This person isn't available right now."]];
        $this->inline(['Promo'], '7101', [Http::response($body, 400)]);
        $seq = $this->sequence('Pool', [$this->stepFlow(['Promo'])->id, $this->stepFlow(['Next'])->id]);
        $this->join($seq, '7001');
        $this->steps();
        $this->roundsUntil(1); // the inline send took the page's slot just before
        $this->answer($this->calls[0], 400, $body);
        $this->settle(5);

        $this->assertCount(1, array_filter($this->calls, fn ($c) => self::req($c)['psid'] === '7001' && self::req($c)['text'] === 'Promo'), 'no retry');
        $this->assertSame($this->row('7101'), $this->row('7001'), 'same row as the inline path (status, error, http, raw, tags, via)');
        $this->assertSame('failed', $this->row('7001')['status']);
        $this->assertSame(2, (int) $this->sub($seq, '7001')->current_step, 'the step advances');
    }

    public function test_S_215_5_a_5xx_or_no_answer_is_one_failed_row_and_no_resend(): void
    {
        $seq = $this->sequence('Pool', [$this->stepFlow(['A'])->id, $this->stepFlow(['B'])->id]);
        $this->join($seq, '7001');
        $this->join($seq, '7002');
        $this->steps();
        $this->round();
        $this->clock->time += 1;
        $this->round();
        $this->assertCount(2, $this->calls);
        $this->answer($this->calls[0], 502, ['error' => 'bad gateway']);
        $this->noAnswer($this->calls[1]);
        $this->pool->tick(false);
        $this->resumes();
        $this->settle(5);

        $this->assertSame(['failed: A (Botcake refused it (HTTP 502))'], $this->replies((string) self::req($this->calls[0])['psid']));
        $this->assertSame(['failed: A (Botcake did not answer (HTTP 0))'], $this->replies((string) self::req($this->calls[1])['psid']));
        $this->assertCount(2, array_filter($this->calls, fn ($c) => self::req($c)['text'] === 'A'), 'one call each, no resend');
        foreach (['7001', '7002'] as $p) $this->assertSame(2, (int) $this->sub($seq, $p)->current_step);
    }

    public function test_S_215_6_a_multi_send_step_keeps_its_order_and_a_failed_send_does_not_stop_the_next(): void
    {
        $seq = $this->sequence('Three', [$this->stepFlow(['One', 'Two', 'Three'])->id, $this->stepFlow(['Later'])->id]);
        $this->join($seq, '7001');
        $this->steps();
        for ($i = 0; $i < 3; $i++) {
            $this->roundsUntil($i + 1);
            $this->round();
            $this->assertCount($i + 1, $this->calls, 'one send at a time, in order');
            $this->answer($this->calls[$i], $i === 1 ? 400 : 200, $i === 1 ? ['success' => false, 'error' => ['message' => '(#10) outside window']] : ['success' => true]);
            $this->pool->tick(false);
            $this->resumes();
        }
        $this->assertSame(['One', 'Two', 'Three'], array_map(fn ($c) => self::req($c)['text'], $this->calls));
        $this->assertSame(['sent: One', 'failed: Two (Botcake said "(#10) outside window" (HTTP 400))', 'sent: Three'], $this->replies('7001'));
        $this->assertSame([[$seq->id, 1]], DB::table('chatbot_replies')->get(['sequence_id', 'sequence_step'])->map(fn ($r) => [(int) $r->sequence_id, (int) $r->sequence_step])->unique()->values()->all());
        $this->assertSame(['Subscribed to sequence Three', 'Sequence Three step 1 ran'], $this->events('7001'));
        $this->assertSame(2, (int) $this->sub($seq, '7001')->current_step);
    }

    public function test_S_215_7_a_wait_for_reply_in_a_step_does_not_park(): void
    {
        $flow = $this->stepFlow([['type' => 'text', 'value' => 'Reply?']]);
        $graph = $flow->graph;
        foreach ($graph['nodes'] as &$n) if ($n['id'] === 'n10') $n['config'] += ['wait' => true, 'save_to' => 'answer', 'skip_after' => ['amount' => 2, 'unit' => 'hours']];
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['graph' => json_encode($graph)]);
        \App\Services\Chatbot\BotConfigCache::forgetFlow($flow->id);
        $seq = $this->sequence('Ask', [$flow->id]);
        $this->join($seq, '7001');
        $this->steps();
        $this->settle(3);

        $this->assertSame(['sent: Reply?'], $this->replies('7001'));
        $this->assertNull((new CustomerFields(1001, 7001))->get(CustomerFields::WAIT));
        $this->assertSame(0, DB::table('chatbot_waits')->count());
        $this->assertSame('completed', $this->sub($seq, '7001')->status);
    }

    public function test_S_215_8_nodes_before_the_send_do_not_run_again_after_the_result(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'AI line']]]])]);
        config(['services.openai.key' => 'sk-test-not-real']);
        $before = [['type' => 'set', 'config' => ['ops' => [['op' => 'append', 'field' => 'visits', 'value' => 'x', 'separator' => '']]]],
            ['type' => 'ai', 'config' => ['user' => 'hi', 'save_to' => 'line'], 'port' => 'success']];
        $seq = $this->sequence('Once', [$this->stepFlow(['{{line}}', 'After'], $before)->id]);
        $this->join($seq, '7001');
        $this->steps();
        $this->settle(6);

        $this->assertSame(['sent: AI line', 'sent: After'], $this->replies('7001'));
        $this->assertSame('x', (new CustomerFields(1001, 7001))->get('visits'), 'the Set ran once');
        Http::assertSentCount(1); // one AI call
        $this->assertTrue(json_decode($this->tickets()->first()->context, true)['side_effects'], 'append and AI are side effects');
    }

    public function test_S_215_9_non_ascii_long_and_image_messages_are_byte_identical_to_the_inline_send(): void
    {
        $items = ['Salamat po! ñ — 😊 ' . str_repeat('é', 1990), ['type' => 'image', 'value' => 'https://img.test/a.jpg']];
        $this->inline($items, '7101', []);
        $inline = Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'send_content'))->map(fn ($p) => $p[0]->body())->values()->all();

        $seq = $this->sequence('Bytes', [$this->stepFlow($items)->id]);
        $this->join($seq, '7001');
        $this->steps();
        $this->settle(4);
        $pool = array_map(fn ($c) => self::req($c)['raw'], $this->calls);

        $this->assertCount(2, $pool);
        $this->assertSame(str_replace('7101', '7001', $inline), $pool);
    }

    public function test_S_215_10_and_c_5_flag_off_pool_down_or_an_old_reply_pool_send_inline_as_before(): void
    {
        Http::fake(['botcake.io/*' => Http::response(['success' => true])]);
        $seq = $this->sequence('Fallback', [$this->stepFlow(['Hi'])->id]);

        config(['services.chatbot.sequence_handoff' => false]);
        $this->join($seq, '7001');
        $this->steps();
        config(['services.chatbot.sequence_handoff' => true]);

        $this->pool->stop();
        $this->join($seq, '7002');
        $this->steps();
        $this->assertTrue($this->pool->start());

        // A reply pool from before this change (no flag in its beat) would take a sequence ticket as chatblast.
        \Illuminate\Support\Facades\Cache::put(\App\Services\Chatbot\BotcakeSendPool::BEAT, ['owner' => 'old', 'at' => now()->getTimestamp()], 60);
        $this->join($seq, '7003');
        $this->steps();

        $this->assertSame(0, $this->tickets()->count());
        foreach (['7001', '7002', '7003'] as $p) {
            $this->assertSame(['sent: Hi'], $this->replies($p));
            $this->assertSame('completed', $this->sub($seq, $p)->status);
        }
        $this->assertSame(280, Queue::pushed(RunChatbotSequenceStep::class)->first()->timeout, 'the inline job keeps its old timeout');
    }

    public function test_S_215_11_the_row_and_the_event_carry_the_same_fields_as_the_inline_send(): void
    {
        $this->inline(['Same'], '7101', []);
        $seq = $this->sequence('Pool', [$this->stepFlow(['Same'])->id]);
        $this->join($seq, '7001');
        $this->steps();
        $this->settle(3);

        $this->assertSame($this->row('7101'), $this->row('7001'));
        $this->assertSame('botcake', $this->row('7001')['via']);
        $ran = fn (string $p) => array_keys(array_diff_key(json_decode(DB::table('chatbot_events')->where('psid', (int) $p)->where('type', 'sequence')->where('detail', 'like', '%"ran"%')->value('detail'), true), ['run' => 1]));
        $this->assertSame($ran('7101'), $ran('7001'), 'the ran event has the same fields (name, step, sequence id, due)');
    }

    private function gateway(): void
    {
        config(['services.gateway.url' => 'https://gw.test', 'services.gateway.key' => 'gk-test-not-real']);
        app(PageSettings::class)->set('Page Dos', ChatbotFields::PROVIDER, 'provider', 'gateway');
    }

    public function test_S_215_e_1_and_e_2_botcake_pages_go_via_botcake_and_gateway_pages_via_the_gateway_with_update_and_client_ref(): void
    {
        $this->gateway();
        $seq = $this->sequence('Both', [$this->stepFlow(['Hi'])->id]);
        $this->join($seq, '7001');
        $this->join($seq, '8001', '1002');
        $this->steps();
        $this->round();
        $this->assertCount(2, $this->calls);
        $by = collect($this->calls)->keyBy(fn ($c) => self::req($c)['host']);
        $this->assertSame('/api/public_api/v1/pages/1001/flows/send_content', self::req($by['botcake.io'])['path']);
        $gw = self::req($by['gw.test']);
        $this->assertSame('/v1/pages/1002/messages', $gw['path']);
        $this->assertSame(['8001', 'UPDATE'], [$gw['body']['psid'], $gw['body']['messaging_type']]);
        $this->assertStringStartsWith('likha-', $gw['body']['client_ref']);
        $this->assertSame(['Bearer gk-test-not-real'], $gw['headers']['Authorization']);
        $this->answer($by['botcake.io']);
        $this->answer($by['gw.test'], 202, ['accepted' => true]);
        $this->pool->tick(false);
        $this->resumes();

        $this->assertSame(['botcake', 'forbeast'], DB::table('chatbot_replies')->orderBy('psid')->pluck('via')->all());
        $this->assertSame($gw['body']['client_ref'], DB::table('chatbot_replies')->where('psid', 8001)->value('client_ref'));
        $after = Queue::pushed(GatewayAfterSend::class);
        $this->assertCount(1, $after, 'the gateway watch hook once per real attempt');
        $this->assertSame(['Page Dos', 'sent', ['accepted' => true], 'forbeast'], [$after[0]->page, $after[0]->outcome['status'], $after[0]->body, $after[0]->gateway]);
        Queue::assertPushedOn('chatbot', GatewayAfterSend::class);
        $watch = \Mockery::spy(ForbeastWatch::class);
        $this->app->instance(ForbeastWatch::class, $watch);
        $this->work(GatewayAfterSend::class);
        $watch->shouldHaveReceived('afterSend')->once()->with('Page Dos', \Mockery::on(fn ($o) => $o['status'] === 'sent'), ['accepted' => true], 'forbeast');
    }

    public function test_S_215_e_3_a_gateway_that_is_not_set_up_or_fails_is_logged_failed_without_holding_a_worker(): void
    {
        $this->gateway();
        config(['services.gateway.url' => '']);
        $seq = $this->sequence('GW', [$this->stepFlow(['Hi'])->id]);
        $this->join($seq, '8001', '1002');
        $this->steps();
        $this->settle(3);
        $this->assertSame([], $this->calls);
        $this->assertSame(['failed: Hi (' . GatewayProvider::NOT_SET_UP . ')'], $this->replies('8001'));
        $this->assertSame('completed', $this->sub($seq, '8001', '1002')->status);

        config(['services.gateway.url' => 'https://gw.test']);
        $this->join($seq, '8002', '1002');
        $this->steps();
        $this->round();
        $this->answer($this->calls[0], 500, ['error' => ['message' => 'down']]);
        $this->pool->tick(false);
        $this->resumes();
        $this->assertSame(['failed: Hi (Gateway said "down" (HTTP 500))'], $this->replies('8002'));
        $this->assertCount(1, $this->calls, 'no retry');
        $this->assertCount(1, Queue::pushed(GatewayAfterSend::class), 'not set up: no call, no watch');
    }
}
