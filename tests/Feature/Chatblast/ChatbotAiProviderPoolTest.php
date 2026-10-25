<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\ResumeChatbotAi;
use App\Services\Chatbot\ChatbotAiPool;
use App\Services\Chatbot\ChatbotAiQueue;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Psr\Http\Message\RequestInterface;

/**
 * Spec 150, ang chatbot:ai-pool: ang chain ng page sa loob ng deadline ng row, ang huling sagot na hindi na binibilang,
 * ang restart, ang cap ng Local at ang 429. Ang "OpenAI" at "Local" ay iisang Guzzle handler na hawak ng test.
 */
class ChatbotAiProviderPoolTest extends ChatbotAiProviderTestCase
{
    /** @var array<int, array{req: RequestInterface, opt: array, p: Promise}> */
    private array $poolCalls = [];
    private ChatbotAiPool $pool;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.chatbot.ai_async' => true, 'services.chatbot.ai_concurrency' => 50]);
        $this->pool = $this->newPool();
        $this->assertTrue($this->pool->start());
    }

    private function newPool(): ChatbotAiPool
    {
        return new ChatbotAiPool(function (RequestInterface $req, array $options) {
            $p = new Promise();
            $this->poolCalls[] = ['req' => $req, 'opt' => $options, 'p' => $p];

            return $p;
        });
    }

    /** @return array<int, array> ang mga tawag sa isang host (127.0.0.1 = Local, api.openai.com = OpenAI) */
    private function to(string $host): array
    {
        return array_values(array_filter($this->poolCalls, fn ($c) => $c['req']->getUri()->getHost() === $host));
    }

    private static function answer(string $text, int $status = 200, array $headers = []): Response
    {
        return new Response($status, $headers + ['Content-Type' => 'application/json'], json_encode($status === 200 ? ['choices' => [['message' => ['content' => $text]]]] : ['error' => 'x']));
    }

    private function timeout(array $call): void
    {
        $call['p']->reject(new ConnectException('cURL error 28: Operation timed out', $call['req']));
    }

    public function test_S_150_C3_local_over_its_timeout_gives_openai_the_rest_of_the_deadline(): void
    {
        $this->useLocal();
        $this->live();
        $this->says('hello');
        $this->pool->tick();

        $local = $this->to('127.0.0.1');
        $this->assertCount(1, $local);
        $this->assertSame([8, 5, false], [$local[0]['opt']['timeout'], $local[0]['opt']['connect_timeout'], $local[0]['opt']['allow_redirects']]);
        $this->assertSame('none', json_decode((string) $local[0]['req']->getBody(), true)['reasoning_effort']); // S-150C.19 sa pool din
        $this->travel(8)->seconds();
        $this->timeout($local[0]);
        $this->pool->tick(false);

        $openai = $this->to('api.openai.com');
        $this->assertCount(1, $openai);
        $this->assertEqualsWithDelta(52, $openai[0]['opt']['timeout'], 1.0); // 60 s mula sa submit, bawas ang 8 s ng Local
        $openai[0]['p']->resolve(self::answer('openai sa oras'));
        $this->pool->tick(false);

        $this->assertSame(['Reply: openai sa oras'], $this->sent());
    }

    public function test_S_150_C6_a_late_local_answer_after_openai_answered_is_ignored(): void
    {
        $this->useLocal();
        $this->live();
        $this->says('hello');
        $this->pool->tick();
        $local = $this->to('127.0.0.1')[0];

        // Hindi sumagot ang Local sa sariling oras nito: ang pool mismo ang lumilipat (kahit hindi pa sumusuko ang koneksyon).
        $this->travel(9)->seconds();
        $this->pool->tick(false);
        $openai = $this->to('api.openai.com');
        $this->assertCount(1, $openai);
        $openai[0]['p']->resolve(self::answer('openai answer'));
        $this->pool->tick(false);

        $local['p']->resolve(self::answer('huling sagot ng Local'));
        $this->pool->tick(false);
        $row = DB::table(ChatbotAiQueue::TABLE)->first();
        (new ResumeChatbotAi(ChatbotAiQueue::ticket($row)))->handle(app(\App\Services\Chatbot\ChatbotRunner::class));

        $this->assertSame(['Reply: openai answer'], $this->sent());
        $this->assertSame('resumed', DB::table(ChatbotAiQueue::TABLE)->value('status'));
    }

    public function test_S_150_C11_the_chain_fits_the_deadline_and_never_resumes_twice(): void
    {
        $this->useLocal();
        $this->live();

        // Naubos ng Local ang halos lahat ng oras: ang OpenAI ay ang natitira lang.
        $this->says('one', self::TECH, '7001');
        $this->pool->tick();
        $this->travel(55)->seconds();
        $this->timeout($this->to('127.0.0.1')[0]);
        $this->pool->tick(false);
        $this->assertEqualsWithDelta(5, $this->to('api.openai.com')[0]['opt']['timeout'], 1.0);
        $this->to('api.openai.com')[0]['p']->resolve(self::answer('mabilis na OpenAI'));
        $this->pool->tick(false);
        $this->assertSame(['Reply: mabilis na OpenAI'], $this->sent('7001'));

        // Wala nang isang segundo: isang malinis na palya, at ang sweep ay walang idinadagdag.
        $this->travelTo(now()->startOfSecond());
        $this->pool->beat(); // buhay pa ang pool pagkatapos ng paglipas ng oras
        $this->says('two', self::TECH, '7002');
        $this->pool->tick();
        $this->travel(59)->seconds();
        $this->travel(500)->milliseconds(); // kalahating segundo na lang (buong segundo ang deadline_at)
        $this->timeout($this->to('127.0.0.1')[1]);
        $this->pool->tick(false);
        $this->assertCount(1, $this->to('api.openai.com'));
        $this->travel(5)->seconds();
        Artisan::call('chatbot:resume-skipped');
        $this->assertSame(['Sorry'], $this->sent('7002'));
        $this->assertSame(['AI step n2 → failed: OpenAI did not answer in time.'], $this->events('ai', '7002'));

        // Ang sweep sa deadline habang nasa OpenAI pa: Sorry, at ang huling sagot ng OpenAI ay hindi na binibilang.
        $this->pool->beat();
        $this->says('three', self::TECH, '7003');
        $this->pool->tick();
        $this->timeout($this->to('127.0.0.1')[2]);
        $this->pool->tick(false);
        $this->travel(61)->seconds();
        Artisan::call('chatbot:resume-skipped');
        $this->to('api.openai.com')[1]['p']->resolve(self::answer('huli na'));
        $this->pool->tick(false);
        $this->assertSame(['Sorry'], $this->sent('7003'));
        $this->assertSame(['AI step n2 → failed: OpenAI did not answer in time.'], $this->events('ai', '7003'));
    }

    public function test_S_150_C12_a_pool_restart_while_on_the_second_provider_gives_one_reply(): void
    {
        $this->useLocal();
        $this->live();
        $this->says('hello');
        $old = $this->pool;
        $old->tick();
        $this->timeout($this->to('127.0.0.1')[0]);
        $old->tick(false);
        $oldOpenai = $this->to('api.openai.com')[0];

        $this->travel(11)->seconds();
        $new = $this->newPool();
        $this->assertTrue($new->start());
        $new->tick();
        $this->to('127.0.0.1')[1]['p']->resolve(self::answer('sagot ng bagong pool'));
        $new->tick(false);

        $oldOpenai['p']->resolve(self::answer('sagot ng patay na pool'));
        $old->tick(false);
        ResumeChatbotAi::dispatch(ChatbotAiQueue::ticket(DB::table(ChatbotAiQueue::TABLE)->first()));

        $this->assertSame(['Reply: sagot ng bagong pool'], $this->sent());
    }

    public function test_S_150_C13_requests_beyond_the_local_cap_go_straight_to_openai_and_each_is_answered_once(): void
    {
        $this->useLocal();
        $this->live();
        foreach (range(1, 5) as $i) $this->says('q' . $i, self::TECH, '700' . $i);
        $this->pool->tick();

        $this->assertCount(4, $this->to('127.0.0.1'));
        $this->assertCount(1, $this->to('api.openai.com'));
        foreach ($this->poolCalls as $c) {
            $q = end(json_decode((string) $c['req']->getBody(), true)['messages'])['content'];
            $c['p']->resolve(self::answer('sagot sa ' . $q));
        }
        $this->pool->tick(false);

        foreach (range(1, 5) as $i) $this->assertSame(['Reply: sagot sa q' . $i], $this->sent('700' . $i));
        $this->assertSame(array_fill(0, 5, 'resumed'), DB::table(ChatbotAiQueue::TABLE)->pluck('status')->all());
    }

    public function test_S_150_C14_openai_429_keeps_the_requeue_rule_and_local_429_falls_back_at_once(): void
    {
        $this->useLocal();
        $this->live();

        $this->says('one', self::TECH, '7001');
        $this->pool->tick();
        $this->to('127.0.0.1')[0]['p']->resolve(self::answer('Too many', 429));
        $this->pool->tick(false);
        $this->assertCount(1, $this->to('api.openai.com'), 'Local 429: OpenAI at once');
        $this->to('api.openai.com')[0]['p']->resolve(self::answer('openai answer'));
        $this->pool->tick(false);
        $this->assertSame(['Reply: openai answer'], $this->sent('7001'));

        $this->pool->beat(); // buhay pa ang pool pagkatapos ng paglipas ng oras
        $this->says('two', self::TECH, '7002');
        $this->pool->tick();
        $this->to('127.0.0.1')[1]['p']->reject(new ConnectException('cURL error 7: Failed to connect', $this->to('127.0.0.1')[1]['req']));
        $this->pool->tick(false);
        $this->to('api.openai.com')[1]['p']->resolve(self::answer('Rate limit', 429, ['Retry-After' => '2']));
        $this->pool->tick(false);
        $row = DB::table(ChatbotAiQueue::TABLE)->where('psid', 7002)->first();
        $this->assertSame(['pending', null], [$row->status, $row->error], 'back in the queue, not failed');
        $this->assertSame(25, $this->pool->limit());

        $this->travel(2)->seconds();
        $this->pool->tick();
        $this->assertCount(2, $this->to('127.0.0.1'), 'the retry goes on from OpenAI, not from Local');
        $this->assertCount(3, $this->to('api.openai.com'));
        // Ang lumang timer ng Local (mula sa unang kuha) ay hindi dapat tumama sa bagong subok ng OpenAI.
        $this->travel(7)->seconds();
        $this->pool->tick(false);
        $this->to('api.openai.com')[2]['p']->resolve(self::answer('pagkatapos ng 429'));
        $this->pool->tick(false);
        $this->assertSame(['Reply: pagkatapos ng 429'], $this->sent('7002'));
    }

    public function test_S_150_F3_a_local_page_does_not_change_another_pages_request(): void
    {
        $this->useLocal();
        $this->live(self::TECH);
        $this->live('Page Uno');

        $this->says('uno', 'Page Uno', '7002');
        $this->pool->tick();

        $this->assertCount(0, $this->to('127.0.0.1'));
        $call = $this->to('api.openai.com')[0];
        $this->assertSame((string) $call['req']->getUri(), self::OPENAI);
        $this->assertSame(['Bearer sk-test-not-real'], $call['req']->getHeader('Authorization'));
        $this->assertSame(self::todayBody('gpt-4o-mini', [['role' => 'user', 'content' => 'uno']], 250), (string) $call['req']->getBody());
        $this->assertEqualsWithDelta(60, $call['opt']['timeout'], 1.0);
        $this->assertEqualsWithDelta(10, $call['opt']['connect_timeout'], 0.01);
        $this->assertNotSame(false, $call['opt']['allow_redirects'] ?? null, 'redirects as today (Guzzle default)');

        // Sa huling segundo ng deadline, ipinapadala pa rin sa OpenAI na may 1 s (gaya ng dati), hindi palya agad.
        $this->travelTo(now()->startOfSecond()); // ang deadline_at ay buong segundo: kalahating segundo talaga ang natitira
        $this->pool->beat();
        $this->says('huling segundo', 'Page Uno', '7003');
        $this->travel(59)->seconds();
        $this->travel(500)->milliseconds();
        $this->pool->tick();
        $this->assertCount(2, $this->to('api.openai.com'));
        $this->assertSame(1, end($this->poolCalls)['opt']['timeout']);
    }
}
