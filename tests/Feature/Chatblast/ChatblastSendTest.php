<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\SendChatblastMessage;
use App\Models\BotcakeMessage;
use App\Services\Chatblast;
use App\Services\ChatblastSender;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Ang pagpapadala: isulat ang custom field, basahin ulit (dapat eksaktong pareho), saka i-send ang flow. Fake HTTP lang.
 */
class ChatblastSendTest extends ChatblastTestCase
{
    private const BASE = 'botcake.io/api/public_api/v1/pages/1001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->page('Page Uno');
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-001', 'psid-a');
        $this->shipped('Page Uno', 'Ben Reyes', 'WB-002', 'psid-b');
        app(Chatblast::class)->build('delivering');
    }

    private function row(string $waybill = 'WB-001'): BotcakeMessage
    {
        return BotcakeMessage::where('waybill', $waybill)->where('is_test', false)->sole();
    }

    /** Botcake na sumasagot ng tama: ang binasang field ay ang huling isinulat. */
    private function botcake(array $override = []): void
    {
        $written = [];
        Http::fake([self::BASE . '/*' => function (Request $req) use (&$written, $override) {
            $path = parse_url($req->url(), PHP_URL_PATH);
            $step = str_ends_with($path, '/flows/send_flow') ? 'flow' : ($req->method() === 'POST' ? 'write' : 'read');
            if (isset($override[$step])) {
                $o = $override[$step];
                return is_callable($o) ? $o($req) : $o;
            }
            preg_match('#/customer/([^/]+)/#', $path, $m);
            if ($step === 'write') $written[$m[1]] = $req->data()['data'][0]['value'];
            if ($step === 'read') return Http::response(['success' => true, 'data' => [['id' => 9, 'value' => 'iba'], ['custom_field_id' => 7701, 'value' => $written[$m[1]] ?? null]]]);
            return Http::response(['success' => true]);
        }]);
    }

    private function send(BotcakeMessage $row): BotcakeMessage
    {
        app(ChatblastSender::class)->queue([$row->id], 1);
        return $row->fresh();
    }

    /** @return array<int, string> "METHOD path" ng bawat request */
    private function calls(): array
    {
        return collect(Http::recorded())->map(fn ($r) => $r[0]->method() . ' ' . str_replace('/api/public_api/v1/pages/1001', '', parse_url($r[0]->url(), PHP_URL_PATH)))->all();
    }

    public function test_a_message_is_written_read_back_and_sent_and_every_step_is_recorded_without_the_token(): void
    {
        $this->botcake();

        $row = $this->send($this->row());

        $this->assertSame(['POST /customer/psid-a/customer_fields', 'GET /customer/psid-a/customer_fields', 'POST /flows/send_flow'], $this->calls());
        $sent = collect(Http::recorded())->map(fn ($r) => $r[0]);
        $this->assertSame(['data' => [['id' => '7701', 'value' => $row->text]]], $sent[0]->data());
        $this->assertSame(['psid' => 'psid-a', 'flow_id' => 5501], $sent[2]->data());
        $this->assertSame(self::TOKEN, $sent[0]->header('access-token')[0]);

        $this->assertSame(['sent', 200, 1, 1], [$row->status, $row->last_http, $row->attempts, $row->sent_by]);
        $this->assertNotNull($row->sent_at);
        $this->assertSame(['write', 'read', 'flow'], array_column($row->steps, 'step'));
        $this->assertSame([200, 200, 200], array_column($row->steps, 'http'));
        $this->assertStringNotContainsString(self::TOKEN, json_encode($row->steps));
    }

    public static function failures(): array
    {
        return [
            'hindi naisulat'      => ['write', [['success' => false, 'message' => 'bad field'], 400], 'failed', 1, 400],
            'iba ang nabasa'      => ['read', [['success' => true, 'data' => [['id' => 7701, 'value' => 'luma']]], 200], 'field_mismatch', 2, 200],
            'walang field'        => ['read', [['success' => true, 'data' => []], 200], 'field_mismatch', 2, 200],
            'hindi na-send ang flow' => ['flow', [['success' => false, 'message' => 'flow off'], 200], 'failed', 3, 200],
        ];
    }

    /** @dataProvider failures */
    public function test_a_failing_step_stops_the_message_and_says_why(string $step, array $answer, string $status, int $requests, int $http): void
    {
        $this->botcake([$step => Http::response(...$answer)]);

        $row = $this->send($this->row());

        $this->assertSame([$status, $http], [$row->status, $row->last_http]);
        $this->assertCount($requests, Http::recorded(), $status === 'field_mismatch' ? 'walang send_flow kapag iba ang nabasa' : '');
        $this->assertNotNull($row->reason);
        $this->assertNull($row->sent_at);
    }

    public function test_a_429_is_retried_after_a_backoff_then_sent(): void
    {
        $tries = 0;
        $this->botcake(['write' => function () use (&$tries) {
            return ++$tries === 1 ? Http::response(['message' => 'slow'], 429) : Http::response(['success' => true]);
        }, 'read' => fn () => Http::response(['success' => true, 'data' => [['id' => 7701, 'value' => $this->row()->text]]])]);

        $row = $this->send($this->row());

        $this->assertSame('sent', $row->status);
        $this->assertContains(2.0, $this->clock->sleeps, 'unang backoff: 2 segundo');
        $this->assertSame(2, $row->steps[0]['attempts']);
    }

    public function test_a_401_marks_the_token_invalid_and_stops_the_page(): void
    {
        Queue::fake();
        app(ChatblastSender::class)->queue([$this->row()->id, $this->row('WB-002')->id], 1);
        Queue::assertPushedOn('chatblast', SendChatblastMessage::class);
        $this->botcake(['write' => Http::response(['message' => 'Unauthorized'], 401)]);

        app(ChatblastSender::class)->process($this->row()->id);

        $this->assertSame(['failed', 401], [$this->row()->status, $this->row()->last_http]);
        $this->assertStringStartsWith('invalid ', (string) app(PageSettings::class)->get('Page Uno', 'botcake', 'token_status'));
        $this->assertSame(['ready', 'Huminto: token invalid'], [$this->row('WB-002')->status, $this->row('WB-002')->reason], 'ang iba sa page ay hindi na itinutuloy');
        app(ChatblastSender::class)->process($this->row('WB-002')->id);
        $this->assertCount(1, Http::recorded());
    }

    public function test_a_sent_message_is_never_sent_again(): void
    {
        $this->botcake();
        $this->send($this->row());

        $this->assertSame(0, app(ChatblastSender::class)->queue([$this->row()->id], 1));
        $this->assertSame(0, app(Chatblast::class)->build('delivering')['added']);
        $this->assertCount(3, Http::recorded());
        $this->assertSame(1, BotcakeMessage::where('waybill', 'WB-001')->count());
    }

    public function test_a_test_send_goes_to_the_test_psid_and_does_not_block_the_real_send(): void
    {
        $this->botcake();
        $sender = app(ChatblastSender::class);
        $this->assertNull($sender->sendTest($this->row()->id, 1), 'walang test PSID ang page');

        app(PageSettings::class)->set('Page Uno', 'botcake', 'test_psid', 'psid-owner');
        $test = $sender->sendTest($this->row()->id, 1);

        $this->assertSame(['sent', true, 'psid-owner', $this->row()->id, $this->row()->text], [$test->status, $test->is_test, $test->psid, $test->test_of, $test->text]);
        $this->assertSame('POST /customer/psid-owner/customer_fields', $this->calls()[0]);
        $this->assertSame('ready', $this->row()->status, 'ang totoong row ay hindi ginalaw');

        $this->assertSame('sent', $this->send($this->row())->status);
        $this->assertSame('POST /customer/psid-a/customer_fields', $this->calls()[3]);
    }

    public function test_pause_keeps_queued_messages_waiting_and_resume_sends_them(): void
    {
        $this->botcake();
        $sender = app(ChatblastSender::class);
        $sender->pause();

        $row = $this->send($this->row());

        $this->assertSame('queued', $row->status);
        Http::assertNothingSent();
        $this->assertTrue($sender->paused());

        $sender->resume();
        $this->assertSame('sent', $this->row()->status);
    }

    public function test_the_daily_cap_per_page_skips_the_rest(): void
    {
        $this->botcake();
        config(['services.botcake.chatblast_daily_cap' => 1]);
        $this->send($this->row());

        $second = $this->send($this->row('WB-002'));

        $this->assertSame('skipped', $second->status);
        $this->assertStringContainsString('1', $second->reason);
        $this->assertCount(3, Http::recorded());
    }

    public function test_requests_of_one_page_stay_half_a_second_apart_across_messages(): void
    {
        $this->botcake();

        $this->send($this->row());
        $this->send($this->row('WB-002'));

        $this->assertCount(6, Http::recorded());
        $this->assertSame(array_fill(0, 5, 0.5), array_map(fn ($s) => round($s, 3), $this->clock->sleeps), '2 request kada segundo, kahit magkaibang job');
    }
}
