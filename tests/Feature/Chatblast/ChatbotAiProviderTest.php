<?php

namespace Tests\Feature\Chatblast;

use App\Models\AppSetting;
use App\Services\Chatbot\ChatbotAiChain;
use App\Services\Chatbot\ChatbotAiProviders;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Spec 150, ang naghihintay na AI step (walang pool): ang chain ng page (Local → OpenAI), ang hindi magagamit na sagot,
 * ang cap at circuit breaker ng Local, ang mga key at ang URL ng Local, at ang mga page na nasa OpenAI na gaya ng dati.
 */
class ChatbotAiProviderTest extends ChatbotAiProviderTestCase
{
    public function test_S_150_C1_local_answers_and_openai_is_not_called(): void
    {
        $this->useLocal();
        $this->live();
        $this->localAnswers = [self::ai('Mayroon po, ₱350 lang.')];

        $this->says('may stock pa?');

        $this->assertSame(['Reply: Mayroon po, ₱350 lang.'], $this->sent());
        $this->assertSame([1, 0], [$this->localHits, $this->openaiHits]);
    }

    public function test_S_150_C2_local_500_or_refused_falls_back_to_openai_once(): void
    {
        $this->useLocal();
        $this->live();
        foreach (['http 500' => Http::response(['error' => 'boom'], 500), 'refused' => self::refused()] as $case => $local) {
            $psid = $case === 'http 500' ? '7001' : '7002';
            $this->localAnswers = [$local];
            $this->openaiAnswers = [self::ai('openai answer ' . $case)];

            $this->says('hello', self::TECH, $psid);

            $this->assertSame(['Reply: openai answer ' . $case], $this->sent($psid), $case);
        }
        $this->assertSame([2, 2], [$this->localHits, $this->openaiHits]);
    }

    public function test_S_150_C3_local_over_its_timeout_is_abandoned_and_openai_answers(): void
    {
        $this->useLocal();
        $this->live();
        $this->localAnswers = [self::timedOut()];

        $this->says('hello');

        $this->assertSame(['Reply: openai answer'], $this->sent());
        // Ang Local ay may sariling timeout (8 s), hindi ang 30 s ng buong step.
        $this->assertSame(8, ChatbotAiChain::forPage(self::TECH)[0]['timeout']);
    }

    public function test_S_150_C4_unusable_local_answers_go_to_the_next_provider_and_a_think_block_is_stripped(): void
    {
        $this->noBreaker();
        $this->useLocal();
        $this->live();
        $cases = [
            ['empty', self::ai(''), 'Reply: openai answer'],
            ['spaces', self::ai("  \n "), 'Reply: openai answer'],
            ['no choices', Http::response(['id' => 'x']), 'Reply: openai answer'],
            ['only think', self::ai('<think>iniisip ko pa</think>'), 'Reply: openai answer'],
            ['unclosed think', self::ai('<think>naputol sa max_tokens'), 'Reply: openai answer'],
            ['think then text', self::ai("<think>hmm</think>\nOpo, meron pa."), 'Reply: Opo, meron pa.'],
        ];
        foreach ($cases as $i => [$name, $local, $want]) {
            $psid = (string) (7100 + $i);
            $this->localAnswers = [$local];
            $this->says('hello', self::TECH, $psid);
            $this->assertSame([$want], $this->sent($psid), $name);
        }
    }

    public function test_S_150_C5_every_provider_failing_takes_the_failed_port_with_a_fixed_message(): void
    {
        $this->useLocal();
        $this->live();
        $this->localAnswers = [Http::response(['error' => 'x'], 500)];
        $this->openaiAnswers = [Http::response(['error' => ['message' => 'x']], 503)];

        $this->says('hello');

        $this->assertSame(['Sorry'], $this->sent());
        $this->assertSame(['AI step n2 → failed: OpenAI refused (HTTP 503).'], $this->events('ai'));
    }

    public function test_S_150_C7_a_chain_of_one_provider_fails_as_today(): void
    {
        $this->useLocal(self::TECH, 'qwen3:14b', 'none');
        $this->live();
        $this->localAnswers = [Http::response(['error' => 'x'], 500)];

        $this->says('hello');

        $this->assertSame(['Sorry'], $this->sent());
        $this->assertSame(['AI step n2 → failed: Local refused (HTTP 500).'], $this->events('ai'));
        $this->assertSame(0, $this->openaiHits);
    }

    public function test_S_150_C8_a_provider_that_is_not_set_up_is_skipped(): void
    {
        $this->useLocal();
        $this->live();
        config(['services.chatbot_ai.providers.local.url' => '']);

        $this->says('hello');

        $this->assertSame(['Reply: openai answer'], $this->sent());
        $this->assertSame(0, $this->localHits);
        $this->assertSame('Local is not set up.', ChatbotAiProviders::notReady('local'));
    }

    public function test_S_150_C9_local_refusing_the_connection_falls_back_at_once(): void
    {
        $this->useLocal();
        $this->live();
        $this->localAnswers = [self::refused()];

        $t = microtime(true);
        $this->says('hello');

        $this->assertLessThan(2.0, microtime(true) - $t);
        $this->assertSame([1, 1], [$this->localHits, $this->openaiHits]);
        $this->assertSame(['Reply: openai answer'], $this->sent());
    }

    public function test_S_150_C10_a_slow_local_within_its_timeout_is_used(): void
    {
        $this->useLocal();
        $this->live();
        $this->localAnswers = [function () {
            usleep(300000);
            return self::ai('mabagal pero dumating');
        }];

        $this->says('hello');

        $this->assertSame(['Reply: mabagal pero dumating'], $this->sent());
        $this->assertSame(0, $this->openaiHits);
    }

    public function test_S_150_C13_beyond_the_local_cap_requests_go_straight_to_the_next_provider(): void
    {
        $this->useLocal();
        $this->live();
        $held = [];
        for ($i = 0; $i < 4; $i++) $held[] = ChatbotAiChain::slot('local', 30); // apat na tawag na nasa Local pa
        $this->assertNotContains(null, $held);

        $this->says('hello');

        $this->assertSame(['Reply: openai answer'], $this->sent());
        $this->assertSame(0, $this->localHits);
        foreach ($held as $lock) $lock->release();

        $this->says('again', self::TECH, '7002');
        $this->assertSame(['Reply: local answer'], $this->sent('7002'));
    }

    public function test_S_150_C13b_the_circuit_breaker_skips_local_after_failures_in_a_row_then_tries_again(): void
    {
        $this->useLocal();
        $this->live();
        foreach ([[3, 30, null], [2, 10, ['failures' => '2', 'seconds' => '10']]] as [$fails, $seconds, $site]) {
            if ($site) {
                AppSetting::set('chatbot.ai.breaker_failures', $site['failures']);
                AppSetting::set('chatbot.ai.breaker_seconds', $site['seconds']);
                ChatbotAiChain::forget();
            }
            for ($i = 0; $i < $fails; $i++) {
                $this->localAnswers = [Http::response(['error' => 'x'], 500)];
                $this->says('fail ' . $i, self::TECH, $fails . '720' . $i);
            }
            $before = $this->localHits;
            $this->says('while open', self::TECH, $fails . '7300');
            $this->assertSame($before, $this->localHits, "open after $fails");
            $this->assertSame(['Reply: openai answer'], $this->sent($fails . '7300'));

            $this->travel($seconds + 1)->seconds();
            $this->says('after', self::TECH, $fails . '7400');
            $this->assertSame($before + 1, $this->localHits, "tried again after $seconds s");
            $this->assertSame(['Reply: local answer'], $this->sent($fails . '7400'));
        }
    }

    public function test_S_150_C15_the_sync_paths_use_the_same_chain(): void
    {
        $this->useLocal();
        $this->live();
        // Naka-on ang async pero walang buhay na pool: naghihintay ang step, gaya ng CHATBOT_AI_ASYNC=false.
        foreach ([['async off', false, '7001'], ['no pool', true, '7002']] as [$name, $async, $psid]) {
            config(['services.chatbot.ai_async' => $async]);
            $this->localAnswers = [Http::response([], 500)];
            $this->says('hello', self::TECH, $psid);
            $this->assertSame(['Reply: openai answer'], $this->sent($psid), $name);
        }
        $this->assertSame([2, 2], [$this->localHits, $this->openaiHits]);
    }

    public function test_S_150_C16_non_ascii_text_passes_unchanged_through_local_and_the_fallback(): void
    {
        $this->useLocal();
        $this->live();
        $this->localAnswers = [self::ai('₱1,250 po, Señora. Salamat!')];
        $this->says('Magkano po ang ₱ sa Parañaque?');
        $this->assertSame(['Reply: ₱1,250 po, Señora. Salamat!'], $this->sent());
        $this->assertSame('Magkano po ang ₱ sa Parañaque?', $this->calls(self::LOCAL)[0]->data()['messages'][0]['content']);

        $this->localAnswers = [Http::response([], 500)];
        $this->openaiAnswers = [self::ai('Opo, ñ at ₱ ay buo.')];
        $this->says('ñ ₱ ulit', self::TECH, '7002');
        $this->assertSame(['Reply: Opo, ñ at ₱ ay buo.'], $this->sent('7002'));
        $this->assertSame('ñ ₱ ulit', $this->calls(self::OPENAI)[0]->data()['messages'][0]['content']);
    }

    public function test_S_150_C17_failures_keep_only_fixed_reasons(): void
    {
        $this->noBreaker();
        $this->useLocal(self::TECH, 'qwen3:14b', 'none');
        $this->live();
        $cases = [
            [Http::response(['error' => 'stack trace with sk-test-not-real and the customer text'], 500), 'Local refused (HTTP 500).'],
            [self::refused('secret host detail'), 'Local did not answer.'],
            [self::timedOut(), 'Local did not answer in time.'],
            [self::ai(''), 'Local gave an empty answer.'],
        ];
        foreach ($cases as $i => [$local, $want]) {
            $psid = (string) (7500 + $i);
            $this->localAnswers = [$local];
            $this->says('ang lihim kong tanong ' . $i, self::TECH, $psid);
            $this->assertSame(['AI step n2 → failed: ' . $want], $this->events('ai', $psid));
        }
        $all = DB::table('chatbot_events')->pluck('detail')->implode(' ');
        $this->assertStringNotContainsString('stack trace', $all);
        $this->assertStringNotContainsString('secret host', $all);
    }

    public function test_S_150_C18_local_gets_the_same_messages_and_max_tokens_with_its_own_model(): void
    {
        $this->useLocal(self::TECH, 'hf.co/org/model:Q4_K_M');
        $this->live();

        $this->says('hello po');

        $body = $this->calls(self::LOCAL)[0]->data();
        $this->assertSame(['model' => 'hf.co/org/model:Q4_K_M', 'messages' => [['role' => 'user', 'content' => 'hello po']], 'max_tokens' => 250], $body);
    }

    public function test_S_150_E1_each_provider_uses_only_its_own_key(): void
    {
        config(['services.chatbot_ai.providers.local.key' => 'local-test-key']);
        $this->useLocal();
        $this->live();
        $this->localAnswers = [Http::response([], 500)];

        $this->says('hello');

        $this->assertSame(['Bearer local-test-key'], $this->calls(self::LOCAL)[0]->header('Authorization'));
        $this->assertSame(['Bearer sk-test-not-real'], $this->calls(self::OPENAI)[0]->header('Authorization'));
    }

    public function test_S_150_E2_local_without_a_key_sends_no_authorization_header(): void
    {
        $this->useLocal();
        $this->live();

        $this->says('hello');

        $this->assertSame(['Reply: local answer'], $this->sent());
        $this->assertFalse($this->calls(self::LOCAL)[0]->hasHeader('Authorization'));
    }

    public function test_S_150_E5_a_local_url_off_the_servers_localhost_is_refused_and_never_called(): void
    {
        $this->useLocal();
        $this->live();
        $bad = ['https://127.0.0.1:11435/v1', 'http://10.0.0.5:11435/v1', 'http://203.0.113.9/v1', 'http://ollama.example.com/v1',
            'http://169.254.169.254/latest', 'file:///etc/passwd', 'http://localhost.example.com/v1', 'http://user:pw@127.0.0.1:11435/v1', 'gopher://127.0.0.1:11435'];
        foreach ($bad as $i => $url) {
            config(['services.chatbot_ai.providers.local.url' => $url]);
            $this->assertNull(ChatbotAiProviders::url('local'), $url);
            $this->says('hello', self::TECH, (string) (7600 + $i));
            $this->assertSame(['Reply: openai answer'], $this->sent((string) (7600 + $i)), $url);
        }
        // Ang OpenAI lang ang tinawag (ang '*' ng fake ay sasagot ng 599 sa ibang URL, at walang ganoong request).
        $this->assertCount(0, Http::recorded(fn (HttpRequest $r) => !str_contains($r->url(), '/flows/send_content') && $r->url() !== self::OPENAI));
        $this->assertSame(count($bad), $this->openaiHits);
        foreach (['http://127.0.0.1:11435/v1', 'http://localhost:8080', 'http://127.0.0.1/v1/'] as $ok) {
            config(['services.chatbot_ai.providers.local.url' => $ok]);
            $this->assertNotNull(ChatbotAiProviders::url('local'), $ok);
        }
    }

    public function test_S_150_E6_a_redirect_from_local_is_not_followed(): void
    {
        $this->useLocal();
        $this->live();
        $this->localAnswers = [Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data'])];

        $this->says('hello');

        $this->assertSame(['Reply: openai answer'], $this->sent());
        $this->assertCount(0, Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), '169.254')));
    }

    public function test_S_150_E7_a_missing_openai_key_is_not_set_up_and_the_step_fails_as_today(): void
    {
        config(['services.openai.key' => '']);
        $this->live('Page Uno');

        $this->says('hello', 'Page Uno');

        $this->assertSame(['Sorry'], $this->sent());
        $this->assertSame(['AI step n2 → failed: No OpenAI key is set on the server.'], $this->events('ai'));
        $this->assertSame('OpenAI is not set up.', ChatbotAiProviders::notReady('openai'));
        $this->assertSame(0, $this->openaiHits);
    }

    public function test_S_150_E8_an_error_body_echoing_the_key_stores_only_the_fixed_message(): void
    {
        $this->live('Page Uno');
        $this->openaiAnswers = [Http::response(['error' => ['message' => 'Incorrect API key provided: sk-test-not-real']], 401)];

        $this->says('hello', 'Page Uno');

        $this->assertSame(['AI step n2 → failed: OpenAI refused (HTTP 401).'], $this->events('ai'));
        $this->assertStringNotContainsString('sk-test', DB::table('chatbot_events')->pluck('detail')->implode(' '));
    }

    public function test_S_150_E9_provider_values_come_from_config_only(): void
    {
        foreach (glob(app_path('Services/Chatbot/ChatbotAi*.php')) as $file) {
            $this->assertDoesNotMatchRegularExpression('/\benv\s*\(/', file_get_contents($file), basename($file));
        }
        config(['services.chatbot_ai.providers.local.url' => 'http://localhost:9999']);
        $this->assertSame('http://localhost:9999/v1/chat/completions', ChatbotAiProviders::url('local'));
    }

    public function test_S_150_E10_the_local_url_gets_one_chat_completions_path(): void
    {
        foreach (['http://127.0.0.1:11435', 'http://127.0.0.1:11435/', 'http://127.0.0.1:11435/v1', 'http://127.0.0.1:11435/v1/', 'http://127.0.0.1:11435/v1//'] as $base) {
            config(['services.chatbot_ai.providers.local.url' => $base]);
            $this->assertSame(self::LOCAL, ChatbotAiProviders::url('local'), $base);
        }
        $this->useLocal();
        $this->live();
        $this->says('hello');
        $this->assertSame(1, $this->localHits);
    }

    public function test_S_150_F1_no_setting_anywhere_sends_one_request_to_openai_as_today(): void
    {
        $this->live('Page Uno');

        $this->says('hello', 'Page Uno');

        $calls = Http::recorded(fn (HttpRequest $r) => !str_contains($r->url(), '/flows/send_content'));
        $this->assertCount(1, $calls);
        $req = $calls->first()[0];
        $this->assertSame(['POST', self::OPENAI], [$req->method(), $req->url()]);
        $this->assertSame(['Bearer sk-test-not-real'], $req->header('Authorization'));
        $this->assertSame(['model' => 'gpt-4o-mini', 'messages' => [['role' => 'user', 'content' => 'hello']], 'max_tokens' => 250], $req->data());
        $this->assertSame(['Reply: openai answer'], $this->sent());
    }

    public function test_S_150_F2_the_openai_body_is_byte_for_byte_todays_payload(): void
    {
        $this->live('Page Uno');

        $this->says('Kumusta, ₱ ñ "quoted" / slash', 'Page Uno');

        $this->assertSame(self::todayBody('gpt-4o-mini', [['role' => 'user', 'content' => 'Kumusta, ₱ ñ "quoted" / slash']], 250), $this->calls(self::OPENAI)[0]->body());
    }

    public function test_S_150_F4_an_openai_only_page_whose_openai_fails_takes_the_failed_port(): void
    {
        $this->useLocal(); // ibang page ang nasa Local
        $this->live('Page Uno');
        $this->openaiAnswers = [Http::response(['error' => ['message' => 'x']], 500)];

        $this->says('hello', 'Page Uno');

        $this->assertSame(['Sorry'], $this->sent());
        $this->assertSame(['AI step n2 → failed: OpenAI refused (HTTP 500).'], $this->events('ai'));
        $this->assertSame(0, $this->localHits);
    }

    public function test_S_150_F5_with_local_down_other_pages_never_call_local(): void
    {
        $this->useLocal();
        $this->live('Page Uno');
        $this->live(self::TECH);
        $this->localAnswers = array_fill(0, 5, self::refused());
        $this->says('tech', self::TECH, '7001');

        $this->says('uno 1', 'Page Uno', '7002');
        $this->says('uno 2', 'Page Uno', '7003');

        $this->assertSame(1, $this->localHits);
        $this->assertSame(['Reply: openai answer'], $this->sent('7002'));
    }

    public function test_S_150_F10_openai_pages_make_one_call_and_touch_no_local_capacity(): void
    {
        $this->live('Page Uno');
        $this->openaiAnswers = [Http::response([], 500)];
        $store = Cache::store()->getStore();

        $this->says('hello', 'Page Uno');

        $this->assertSame([0, 1], [$this->localHits, $this->openaiHits]);
        // Walang slot o bilang ng breaker na isinulat para sa OpenAI, kahit pumalya ito.
        $keys = (fn () => array_merge(array_keys($this->storage), array_keys($this->locks)))->call($store);
        $this->assertSame([], array_values(array_filter($keys, fn ($k) => str_starts_with($k, 'chatbot-ai:'))));
    }
}
