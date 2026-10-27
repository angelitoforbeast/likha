<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\BotcakeMessage;
use App\Models\ChatbotFlow;
use App\Services\Chatbot\BotcakeSendPool;
use App\Services\Chatbot\BotcakeSendQueue;
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
 * Ang malaking chatblast ay hindi nagpapabagal sa sagot ng bot (spec 165): ang handang sagot (priority 0) ang susunod na
 * padala ng page, nakukuha kahit puno ng chatblast ang bahagi ng page sa pool, at ang rate kada page ay pareho pa rin.
 * Fake na Botcake (handler na hawak ng test) at orasan.
 */
class BotRepliesBeforeBlastsTest extends ChatblastTestCase
{
    /** @var array<int, array{req: RequestInterface, p: Promise, t: float}> */
    private array $calls = [];
    private BotcakeSendPool $pool;

    private const PAGES = ['Page Uno' => '1001', 'Page Dos' => '1002', 'Page Tres' => '1003', 'Page Cuatro' => '1004', 'Page Cinco' => '1005'];

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
        foreach (self::PAGES as $page => $id) {
            $this->page($page, $id);
            app(PageSettings::class)->set($page, 'botcake', 'token', 'tok-' . $id); // a rate lane of its own
        }
        $this->pool = new BotcakeSendPool(function (RequestInterface $req) {
            $p = new Promise();
            $this->calls[] = ['req' => $req, 'p' => $p, 't' => $this->clock->now()];

            return $p;
        }, $this->clock);
        $this->assertTrue($this->pool->start());
    }

    /** start → send "You said: {{last_input}}". */
    private function echoFlow(string $page): void
    {
        $flow = ChatbotFlow::create(['name' => 'Flow ' . $page, 'graph' => FlowGraph::clean([
            'nodes' => [['id' => 'n1', 'type' => 'start'], ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'You said: {{last_input}}']]]]],
            'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']],
        ])]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);
        app(ChatbotPage::class)->link($page, $flow->fresh());
    }

    private function says(string $psid, string $page = 'Page Uno'): void
    {
        $chat = BotcakeChat::create(['page_id' => self::PAGES[$page], 'page_name' => $page, 'psid' => $psid, 'name' => 'Ana Cruz', 'message' => 'hi', 'payload' => [], 'received_at' => now()]);
        app(ChatbotRunner::class)->handle($chat->id);
    }

    private function blast(string $psid, string $page = 'Page Uno', string $kind = 'in_transit'): BotcakeMessage
    {
        $m = BotcakeMessage::create(['page_name' => $page, 'waybill' => 'JT' . $page . $psid, 'kind' => $kind, 'psid' => $psid,
            'text' => 'Hello ' . $psid, 'status' => 'queued', 'is_test' => true, 'queued_at' => now()]);
        app(ChatblastSender::class)->process($m->id);

        return $m;
    }

    /** @return array{page: string, psid: string, kind: string} */
    private static function req(array $call): array
    {
        $path = (string) preg_replace('#^.*?(?=/pages/)#', '', $call['req']->getUri()->getPath());
        if (str_ends_with($path, '/flows/send_content')) {
            return ['page' => explode('/', $path)[2], 'psid' => (string) json_decode((string) $call['req']->getBody(), true)['psid'], 'kind' => 'chatbot'];
        }

        return ['page' => explode('/', $path)[2], 'psid' => (string) preg_replace('#.*/customer/([^/]+)/.*#', '$1', $path), 'kind' => 'chatblast'];
    }

    /** Sagutin ang lahat ng hindi pa nasasagot (GET ng chatblast = ang text pabalik), at isang ikot na walang kuha. */
    private function answerAll(): void
    {
        foreach ($this->calls as $c) {
            if ($c['p']->getState() !== 'pending') continue;
            $r = self::req($c);
            $body = $c['req']->getMethod() === 'GET' ? ['success' => true, 'data' => [['id' => 7701, 'value' => 'Hello ' . $r['psid']]]] : ['success' => true];
            $c['p']->resolve(new Response(200, ['Content-Type' => 'application/json'], json_encode($body)));
        }
        $this->pool->tick(false);
    }

    /** @return array<int, array{t: float, kind: string, psid: string}> ang mga tawag sa isang page, sa ayos ng pagdating */
    private function sends(string $pageId, ?float $from = null): array
    {
        $out = [];
        foreach ($this->calls as $c) {
            $r = self::req($c);
            if ($r['page'] === $pageId && ($from === null || $c['t'] >= $from - 1e-9)) $out[] = ['t' => $c['t'], 'kind' => $r['kind'], 'psid' => $r['psid']];
        }

        return $out;
    }

    private function assertRate(string $pageId): void
    {
        $t = array_column($this->sends($pageId), 't');
        for ($i = 1; $i < count($t); $i++) $this->assertGreaterThanOrEqual(0.5 - 1e-6, $t[$i] - $t[$i - 1], 'at most 2 per second on one page');
    }

    public function test_S_165_3_a_page_whose_pool_share_is_full_of_chatblast_still_gets_its_new_reply_claimed_and_sent_first(): void
    {
        $this->echoFlow('Page Uno');
        for ($i = 1; $i <= 20; $i++) $this->blast((string) (7100 + $i));
        $this->pool->tick();
        $held = DB::table(BotcakeSendQueue::TABLE)->where('kind', 'chatblast')->where('status', 'sending')->count();
        $this->assertSame($this->pool->perPage(), $held, 'the page holds its full share of chatblast tickets');

        $this->clock->time += 0.2;
        $this->says('8001');
        $ready = $this->clock->now();
        $this->pool->tick();
        $this->assertSame('sending', DB::table(BotcakeSendQueue::TABLE)->where('kind', 'chatbot')->value('status'), 'the reply is claimed');

        for ($n = 0; $n < 40; $n++) {
            $this->answerAll();
            $this->clock->time += 0.1;
            $this->pool->tick();
        }
        $next = $this->sends('1001', $ready)[0];
        $this->assertSame(['chatbot', '8001'], [$next['kind'], $next['psid']], 'the reply is the next send of its page');
        $this->assertLessThanOrEqual(0.5 + 1e-6, $next['t'] - $ready, 'within one rate interval');
        $this->assertRate('1001'); // S-165.4
    }

    public function test_S_165_1_a_fresh_reply_goes_before_200_chatblast_jobs_that_all_waited_over_10_s(): void
    {
        $this->echoFlow('Page Uno');
        for ($i = 1; $i <= 200; $i++) $this->blast((string) (7000 + $i));
        $this->pool->tick();
        // A 429 stops the page for a while: every held chatblast job waits in the lane longer than 10 s.
        $this->calls[0]['p']->resolve(new Response(429, ['Retry-After' => '10'], '{}'));
        $this->pool->tick(false);
        $this->clock->time += 10.5; // the page is free again, and a customer writes
        $this->says('8001');
        $ready = $this->clock->now();
        for ($n = 0; $n < 20; $n++) {
            $this->pool->tick();
            $this->answerAll();
            $this->clock->time += 0.1;
        }
        $next = $this->sends('1001', $ready)[0];
        $this->assertSame(['chatbot', '8001'], [$next['kind'], $next['psid']], 'the reply is the next send of its page');
        $this->assertLessThanOrEqual(0.5 + 1e-6, $next['t'] - $ready, 'within one rate interval');
        $this->assertRate('1001'); // S-165.4
    }

    /** [ilang segundo naghintay ang naunang in-transit (priority 2) bago dumating ang Delivering (priority 1), ang unang padala] */
    public static function blastOrder(): array
    {
        return [
            'waited under 10 s: Delivering goes first' => [3.0, '7002'],
            'waited over 10 s: the older in-transit goes first' => [10.5, '7001'],
        ];
    }

    /** @dataProvider blastOrder */
    public function test_S_165_2_among_chatblast_the_over_10_s_promotion_works_as_before(float $waited, string $first): void
    {
        $this->blast('7001');
        $this->pool->tick();
        $this->calls[0]['p']->resolve(new Response(429, ['Retry-After' => '2'], '{}')); // the page stops; 7001 waits in the lane
        $this->pool->tick(false);
        $this->clock->time += $waited;
        $this->blast('7002', 'Page Uno', 'delivering');
        $this->pool->tick();

        $this->assertSame($first, self::req($this->calls[1])['psid']);
    }

    public function test_S_165_5_with_1000_chatblast_on_5_pages_every_reply_goes_out_within_1_s(): void
    {
        Carbon::setTestNow(now()); // ang deadline ng ticket ay oras ng database; ang takbo ng test ay hindi ito dapat lampasan
        foreach (self::PAGES as $page => $id) {
            $this->echoFlow($page);
            for ($i = 1; $i <= 200; $i++) $this->blast((string) ($id . sprintf('%03d', $i)), $page);
        }
        $ready = []; // psid => oras na handa
        $start = $this->clock->now();
        $step = 0.25;
        for ($n = 0; $n < 2400; $n++) {
            $elapsed = $this->clock->now() - $start;
            if ($elapsed < 60 && fmod($elapsed, 1.0) < 1e-6) {
                foreach (self::PAGES as $page => $id) {
                    $psid = '9' . $id . sprintf('%03d', (int) round($elapsed));
                    $this->says($psid, $page);
                    $ready[$psid] = $this->clock->now();
                }
            }
            $this->pool->tick();
            $this->answerAll();
            if ($elapsed > 60 && !BotcakeMessage::where('status', '!=', 'sent')->exists()) break;
            $this->clock->time += $step;
        }

        $this->assertCount(300, $ready);
        $late = [];
        foreach ($this->calls as $c) {
            $r = self::req($c);
            if ($r['kind'] !== 'chatbot') continue;
            if ($c['t'] - $ready[$r['psid']] > 1.0 + 1e-6) $late[$r['psid']] = round($c['t'] - $ready[$r['psid']], 2);
            unset($ready[$r['psid']]);
        }
        $this->assertSame([], $ready, 'every reply was sent');
        $this->assertSame([], $late, 'every reply within 1 s of being ready');
        $this->assertSame(1000, BotcakeMessage::where('status', 'sent')->count(), 'every chatblast message eventually sent');
        foreach (self::PAGES as $id) $this->assertRate($id);
    }
}
