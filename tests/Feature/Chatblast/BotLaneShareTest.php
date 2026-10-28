<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\BotcakeMessage;
use App\Models\ChatbotFlow;
use App\Services\Chatbot\BotcakeSendPool;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\ChatbotRunner;
use App\Services\Chatbot\FlowGraph;
use App\Services\ChatblastSender;
use App\Services\PageSettings;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Psr\Http\Message\RequestInterface;

/**
 * Ang sagot ng bot ay laging una sa lane ng page (spec 179): may sariling puwesto ang mga sagot sa pool, ang chatblast ay
 * hanggang sa bahagi lang nito ng rate habang may ginagawa ang bot, at natatapos pa rin ang chatblast. Fake na Botcake
 * (handler na hawak ng test, sumasagot pagkalipas ng $latency) at orasan; isang page, 2 request kada segundo.
 */
class BotLaneShareTest extends ChatblastTestCase
{
    /** @var array<int, array{req: RequestInterface, p: Promise, t: float}> */
    private array $calls = [];
    private BotcakeSendPool $pool;
    private float $latency = 1.0; // ang Botcake ay sumasagot pagkalipas ng 1 s

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_25_100000_create_chatbot_waits_table.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
            'database/migrations/2026_10_28_100000_create_chatbot_ai_requests_table.php',
            'database/migrations/2026_10_29_100000_create_botcake_send_requests_table.php',
        ]]);
        config(['services.chatbot.replies_enabled' => true, 'services.botcake.async_sends' => true, 'services.botcake.rate_per_page' => 2]);
        Carbon::setTestNow(now()); // ang deadline ng ticket ay oras ng database; ang takbo ng test ay hindi ito dapat lampasan
        $this->page('Page Uno', '1001');
        app(PageSettings::class)->set('Page Uno', 'botcake', 'token', 'tok-1001');
        $flow = ChatbotFlow::create(['name' => 'Echo', 'graph' => FlowGraph::clean([
            'nodes' => [['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'You said: {{last_input}}']]]]],
            'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']],
        ])]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);
        app(ChatbotPage::class)->link('Page Uno', $flow->fresh());
        $this->pool = new BotcakeSendPool(function (RequestInterface $req) {
            $p = new Promise();
            $this->calls[] = ['req' => $req, 'p' => $p, 't' => $this->clock->now()];

            return $p;
        }, $this->clock);
        $this->assertTrue($this->pool->start());
    }

    private function says(string $psid): void
    {
        $chat = BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => $psid, 'name' => 'Ana Cruz', 'message' => 'hi', 'payload' => [], 'received_at' => now()]);
        app(ChatbotRunner::class)->handle($chat->id);
    }

    private function blasts(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $m = BotcakeMessage::create(['page_name' => 'Page Uno', 'waybill' => 'JT' . $i, 'kind' => 'in_transit', 'psid' => (string) (70000 + $i),
                'text' => 'Hello ' . (70000 + $i), 'status' => 'queued', 'is_test' => true, 'queued_at' => now()]);
            app(ChatblastSender::class)->process($m->id);
        }
    }

    /** @return array{kind: string, step: string, psid: string} */
    private static function req(array $call): array
    {
        $path = $call['req']->getUri()->getPath();
        $body = (array) json_decode((string) $call['req']->getBody(), true);
        if (str_ends_with($path, '/flows/send_content')) return ['kind' => 'chatbot', 'step' => 'send', 'psid' => (string) $body['psid']];
        if (str_ends_with($path, '/flows/send_flow')) return ['kind' => 'chatblast', 'step' => 'flow', 'psid' => (string) $body['psid']];
        $psid = (string) preg_replace('#.*/customer/([^/]+)/.*#', '$1', $path);

        return ['kind' => 'chatblast', 'step' => $call['req']->getMethod() === 'GET' ? 'read' : 'write', 'psid' => $psid];
    }

    /** Sagutin ang mga tawag na lampas na sa $latency (GET ng chatblast = ang text pabalik), saka isang ikot ng pool. */
    private function step(float $dt = 0.1, bool $claim = true): void
    {
        foreach ($this->calls as $c) {
            if ($c['p']->getState() !== 'pending' || $this->clock->now() - $c['t'] < $this->latency - 1e-6) continue;
            $r = self::req($c);
            $body = $r['step'] === 'read' ? ['success' => true, 'data' => [['id' => 7701, 'value' => 'Hello ' . $r['psid']]]] : ['success' => true];
            $c['p']->resolve(new Response(200, ['Content-Type' => 'application/json'], json_encode($body)));
        }
        $this->pool->tick($claim);
        $this->clock->time += $dt;
    }

    /** @return array<int, array{t: float, kind: string, step: string, psid: string}> */
    private function sends(): array
    {
        return array_map(fn ($c) => ['t' => $c['t']] + self::req($c), $this->calls);
    }

    /**
     * Ang 09:00 burst: $blasts na chatblast, at $replies na sagot ng bot, isa kada $every segundo mula 5 s.
     * @return array{waits: array<string, float>, blastDone: float|null}
     */
    private function burst(int $blasts, float $every, int $replies, float $limit): array
    {
        $this->blasts($blasts);
        $start = $this->clock->now();
        $ready = [];
        $next = 5.0;
        $n = 0;
        $blastDone = null;
        while ($this->clock->now() - $start < $limit) {
            $elapsed = $this->clock->now() - $start;
            if ($elapsed >= $next - 1e-6 && $n < $replies) {
                $psid = (string) (90000 + ++$n);
                $this->says($psid);
                $ready[$psid] = $this->clock->now();
                $next += $every;
            }
            $this->step();
            if ($blastDone === null && !BotcakeMessage::where('status', '!=', 'sent')->exists()) $blastDone = $this->clock->now() - $start;
            if ($blastDone !== null && $n >= $replies && $elapsed > $next + 2.0) break;
        }
        $waits = [];
        foreach ($this->sends() as $s) if ($s['kind'] === 'chatbot' && isset($ready[$s['psid']])) $waits[$s['psid']] = $s['t'] - $ready[$s['psid']];
        foreach (array_diff_key($ready, $waits) as $psid => $t) $waits[$psid] = INF; // hindi naipadala

        return ['waits' => $waits, 'blastDone' => $blastDone];
    }

    private function assertRate(): void
    {
        $t = array_column($this->sends(), 't');
        for ($i = 1; $i < count($t); $i++) $this->assertGreaterThanOrEqual(0.5 - 1e-6, $t[$i] - $t[$i - 1], 'at most 2 per second on the page');
    }

    public function test_S_179_1_three_replies_queued_on_a_lane_busy_with_chatblast_are_the_next_three_sends(): void
    {
        $this->blasts(20);
        for ($n = 0; $n < 30; $n++) $this->step(); // ang lane ay abala sa chatblast
        foreach (['8001', '8002', '8003'] as $psid) $this->says($psid);
        $ready = $this->clock->now();
        for ($n = 0; $n < 40; $n++) $this->step();

        $next = array_slice(array_values(array_filter($this->sends(), fn ($s) => $s['t'] >= $ready - 1e-9)), 0, 3);
        $this->assertSame(['8001', '8002', '8003'], array_column($next, 'psid'), 'the three replies go before any chatblast step');
        $this->assertSame(['chatbot', 'chatbot', 'chatbot'], array_column($next, 'kind'));
        $this->assertLessThanOrEqual(1.5 + 1e-6, $next[2]['t'] - $ready, 'three sends within three rate intervals');
        $this->assertRate();
    }

    public function test_S_179_2_each_chatblast_job_keeps_its_write_read_flow_order_between_replies(): void
    {
        $r = $this->burst(10, 0.5, 20, 120.0);
        $this->assertNotNull($r['blastDone'], 'every chatblast sent');
        $steps = [];
        foreach ($this->sends() as $s) if ($s['kind'] === 'chatblast') $steps[$s['psid']][] = $s['step'];
        $this->assertCount(10, $steps);
        foreach ($steps as $psid => $order) $this->assertSame(['write', 'read', 'flow'], $order, 'steps of ' . $psid);
    }

    /** [ang sagot ng bot kada ilang segundo (0 = wala), ang pinakamaraming chatblast na tawag sa 10 s] */
    public static function shares(): array
    {
        return [
            'S-179.3 no bot work: chatblast uses the full rate' => [0.0, 20],
            'S-179.3 bot work: chatblast uses at most its share (1 of 2 per second)' => [2.0, 11],
        ];
    }

    /** @dataProvider shares */
    public function test_S_179_3_chatblast_uses_the_full_rate_without_bot_work_and_its_share_with_it(float $every, int $max): void
    {
        $this->blasts(40);
        $start = $this->clock->now();
        $next = 0.0;
        $n = 0;
        while ($this->clock->now() - $start < 20.0) {
            if ($every > 0 && $this->clock->now() - $start >= $next - 1e-6) {
                $this->says((string) (90000 + ++$n));
                $next += $every;
            }
            $this->step();
        }
        // Ang 10 s sa gitna (5 s hanggang 15 s), kapag tuloy-tuloy na ang lane.
        $blast = array_filter($this->sends(), fn ($s) => $s['kind'] === 'chatblast' && $s['t'] >= $start + 5.0 - 1e-9 && $s['t'] < $start + 15.0 - 1e-9);
        if ($every > 0) {
            $this->assertLessThanOrEqual($max, count($blast));
        } else {
            $this->assertGreaterThanOrEqual($max - 1, count($blast), 'two chatblast calls per second');
        }
        $this->assertRate();
    }

    public function test_S_179_4_the_0900_burst_500_chatblast_and_50_replies_every_reply_within_one_second(): void
    {
        // 50 sagot sa 30 s (isa kada 0.6 s): mas mababa sa rate ng page, kaya walang sagot na dapat maghintay sa chatblast.
        $r = $this->burst(500, 0.6, 50, 1200.0);
        $waits = $r['waits'];
        $finite = array_filter($waits, 'is_finite');
        fwrite(STDERR, sprintf("\nS-179.4 burst: %d replies, %d sent, wait avg %.2f s, max %s s, chatblast done at %s s\n", count($waits), count($finite),
            $finite ? array_sum($finite) / count($finite) : 0, max($waits) === INF ? 'never' : round(max($waits), 2), $r['blastDone'] === null ? 'never' : round($r['blastDone'], 1)));

        $this->assertCount(50, $waits);
        $this->assertLessThanOrEqual(1.0 + 1e-6, max($waits), 'every reply within 1 s of being ready');
        $this->assertNotNull($r['blastDone'], 'every chatblast message sent');
        $this->assertRate();
    }

    public function test_S_179_5_chatblast_completes_under_continuous_bot_traffic(): void
    {
        // Isang sagot kada segundo (kalahati ng rate) mula 5 s hanggang matapos ang buong takbo: hindi natitigil ang chatblast.
        $r = $this->burst(60, 1.0, 300, 400.0);
        $this->assertNotNull($r['blastDone'], 'every chatblast message sent while the bot kept replying');
        $this->assertSame(60, BotcakeMessage::where('status', 'sent')->count());
    }
}
