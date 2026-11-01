<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\ResumeChatbotAi;
use App\Models\PageSetting;
use App\Services\Chatbot\ChatbotAiKeys;
use App\Services\Chatbot\ChatbotAiPool;
use App\Services\Chatbot\ChatbotAiQueue;
use App\Services\Chatbot\ChatbotRunner;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Psr\Http\Message\RequestInterface;

/**
 * Spec 210, S-210.3.8 at S-210.3.12: ang chatbot:ai-pool ay sumusunod sa parehong chain ng key gaya ng naghihintay na tawag,
 * at ang maraming sabay na tawag sa key na kamamatay lang ay iisang marka at hindi lumalampas sa cap ng susunod na key.
 */
class AiKeyPoolPathTest extends AiKeyPoolTestCase
{
    /** @var array<int, array{req: RequestInterface, opt: array, p: Promise}> */
    private array $poolCalls = [];
    private ChatbotAiPool $pool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-15 10:00:00');
        config(['services.chatbot.ai_async' => true, 'services.chatbot.ai_concurrency' => 50]);
        $this->pool = new ChatbotAiPool(function (RequestInterface $req, array $options) {
            $p = new Promise();
            $this->poolCalls[] = ['req' => $req, 'opt' => $options, 'p' => $p];

            return $p;
        });
        $this->assertTrue($this->pool->start());
        $this->live('Page Uno');
    }

    private static function bearer(array $c): string
    {
        return substr(trim(substr($c['req']->getHeaderLine('Authorization'), 7)), -4);
    }

    private static function openai(string $text, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($status === 200
            ? ['choices' => [['message' => ['content' => $text]]], 'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 5]]
            : ['error' => ['message' => $text, 'type' => '', 'code' => '']]));
    }

    /** Ituloy ang lahat ng sagot (ResumeChatbotAi) para maipadala ang sagot sa customer. */
    private function resumeAll(): void
    {
        foreach (DB::table(ChatbotAiQueue::TABLE)->whereIn('status', ['answered', 'failed'])->get() as $row) {
            (new ResumeChatbotAi(ChatbotAiQueue::ticket($row)))->handle(app(ChatbotRunner::class));
        }
    }

    public function test_S_210_3_12_a_queued_ticket_follows_the_same_key_chain_and_outcome_as_a_sync_call(): void
    {
        $a = $this->addKey('openai', 'pool-1', self::KEY_A);
        $this->addKey('openai', 'pool-2', self::KEY_B);
        $this->says('hello', 'Page Uno');
        $this->assertSame('pending', DB::table(ChatbotAiQueue::TABLE)->value('status'));
        $this->pool->tick();
        $this->assertSame(['1111'], array_map(fn ($c) => self::bearer($c), $this->poolCalls));
        $this->poolCalls[0]['p']->resolve(self::openai('You exceeded your current quota', 402));
        $this->pool->tick(false);
        $this->assertSame(['1111', '2222'], array_map(fn ($c) => self::bearer($c), $this->poolCalls));
        $this->poolCalls[1]['p']->resolve(self::openai('pool answer'));
        $this->pool->tick(false);
        $this->resumeAll();

        $this->assertSame(['Reply: pool answer'], $this->sent());
        $row = $this->lastRow();
        $this->assertSame(['resumed', 'openai', 'pool-2', '2222', 'async'], [$row->status, $row->provider, $row->key_label, $row->key_last4, $row->path]);
        $this->assertSame([['pool-1', false], ['pool-2', true]], array_map(fn ($x) => [$x['key'], $x['ok']], json_decode($row->ai_attempts, true)));
        $this->assertNotContains($a, array_column($this->keys()->usable('openai'), 'id'));
        $this->assertSame(1, PageSetting::where('page_name', ChatbotAiKeys::PAGE)->where('key', 'event')->count());
    }

    public function test_S_210_3_8_twenty_calls_on_a_key_that_just_died_all_move_on_mark_it_once_and_keep_the_next_keys_cap(): void
    {
        config(['services.chatbot_ai.providers.openai.key_concurrency' => 12]);
        $a = $this->addKey('openai', 'pool-1', self::KEY_A);
        $this->addKey('openai', 'pool-2', self::KEY_B);
        for ($i = 0; $i < 20; $i++) $this->says('q' . $i, 'Page Uno', (string) (7500 + $i));
        $this->pool->tick();
        $this->assertCount(20, $this->poolCalls);
        $inflight = fn (string $last4) => count(array_filter($this->poolCalls, fn ($c) => self::bearer($c) === $last4 && $c['p']->getState() === 'pending'));
        $peak = $inflight('2222');
        // Namatay ang key 1: lahat ng tawag dito ay 402 nang sabay.
        foreach ($this->poolCalls as $c) if (self::bearer($c) === '1111') $c['p']->resolve(self::openai('no credit', 402));
        $this->pool->tick(false);
        $peak = max($peak, $inflight('2222'));
        $this->assertLessThanOrEqual(12, $peak);
        foreach ($this->poolCalls as $c) if ($c['p']->getState() === 'pending') $c['p']->resolve(self::openai('answer'));
        $this->pool->tick(false);

        $this->assertSame(0, DB::table(ChatbotAiQueue::TABLE)->whereIn('status', ['pending', 'sending'])->count());
        $this->assertSame(1, PageSetting::where('page_name', ChatbotAiKeys::PAGE)->where('key', 'k' . $a . '.health')->count());
        $this->assertSame(1, PageSetting::where('page_name', ChatbotAiKeys::PAGE)->where('key', 'event')->count());
        $this->assertSame(0, DB::table(ChatbotAiQueue::TABLE)->where('key_label', 'pool-1')->count());
        // Bawat isa ay natapos (sagot ng key 2, o "busy" kapag puno ang cap nito) at naituloy na (sync ang queue sa test).
        $this->assertSame(20, DB::table(ChatbotAiQueue::TABLE)->where('status', 'resumed')->count());
        $this->assertSame(12, DB::table(ChatbotAiQueue::TABLE)->where('key_label', 'pool-2')->whereNull('error')->count());
    }
}
