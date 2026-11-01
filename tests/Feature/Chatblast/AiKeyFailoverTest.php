<?php

namespace Tests\Feature\Chatblast;

use App\Models\AppSetting;
use App\Models\PageSetting;
use App\Services\Chatbot\AdGroups;
use App\Services\Chatbot\ChatbotAiChain;
use App\Services\Chatbot\ChatbotAiKeys;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Spec 210, S-210.1.10, S-210.2 at S-210.3 (naghihintay na takbo): ang chain ng page na may mga key ng pool, ang ikot ng
 * key sa loob ng provider, ang lipat sa susunod na key sa iisang tawag, ang marka at pahinga, at ang event ng lipat.
 */
class AiKeyFailoverTest extends AiKeyPoolTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-15 10:00:00');
        $this->live('Page Uno');
    }

    private function ask(string $text = 'hello', string $psid = '7001'): void
    {
        $this->says($text, 'Page Uno', $psid);
    }

    /** @return array ang health ng key mula sa table (hindi cache) */
    private function health(string $id): array
    {
        $v = PageSetting::where('page_name', ChatbotAiKeys::PAGE)->where('key', 'k' . $id . '.health')->where('active', true)->value('value');

        return (array) json_decode((string) $v, true);
    }

    public function test_S_210_1_10_without_an_openai_key_in_the_pool_the_server_key_is_used_as_before(): void
    {
        $this->ask();
        $this->assertSame(['sk-test-not-real'], array_column($this->providerCalls, 'key'));
        $this->assertSame(['Reply: answer by real'], $this->sent());
        $row = $this->lastRow();
        $this->assertSame(['openai', 'Server key', 'real', null], [$row->provider, $row->key_label, $row->key_last4, $row->key_id]);
    }

    public function test_S_210_2_1_a_page_set_to_claude_then_openai_tries_claude_first(): void
    {
        $this->addKey('anthropic', 'claude-1', self::KEY_B);
        $this->addKey('openai', 'pool-1', self::KEY_A);
        $this->useProvider('Page Uno', 'anthropic', 'openai');
        $this->ask();
        $this->assertSame([self::CLAUDE], array_column($this->providerCalls, 'url'));
        $this->assertSame(['Reply: answer by 2222'], $this->sent());
        $this->assertSame([['anthropic', 'claude-haiku-4-5'], ['openai', null]], array_map(fn ($s) => [$s['provider'], $s['model']], ChatbotAiChain::forPage('Page Uno')));
        $this->assertSame(['anthropic', 'claude-haiku-4-5', 'claude-1', '2222'], [$this->lastRow()->provider, $this->lastRow()->model_used, $this->lastRow()->key_label, $this->lastRow()->key_last4]);
    }

    public function test_S_210_2_2_calls_are_spread_across_the_healthy_keys_of_a_provider(): void
    {
        $this->addKey('openai', 'pool-1', self::KEY_A);
        $this->addKey('openai', 'pool-2', self::KEY_B);
        $this->addKey('openai', 'pool-3', self::KEY_C);
        for ($i = 0; $i < 6; $i++) $this->ask('q' . $i, (string) (7100 + $i));
        $counts = array_count_values($this->calledKeys());
        ksort($counts);
        $this->assertSame(['1111' => 2, '2222' => 2, '3333' => 2], $counts);
    }

    public function test_S_210_2_3_the_site_default_applies_to_a_page_without_its_own_and_an_ad_group_still_wins(): void
    {
        $this->addKey('deepseek', 'ds-1', self::KEY_C);
        $this->addKey('anthropic', 'claude-1', self::KEY_B);
        AppSetting::set('chatbot.ai.provider', 'deepseek');
        ChatbotAiChain::forget();
        $this->ask();
        $this->assertSame([self::DEEPSEEK], array_column($this->providerCalls, 'url'));
        $this->assertSame('deepseek-chat', $this->providerCalls[0]['body']['model']);

        $saved = app(AdGroups::class)->save('Page Uno', null, 'Serum', ['120000000000001'], ['ai_provider' => 'anthropic']);
        $group = (int) ($saved['id'] ?? array_key_first(app(AdGroups::class)->groups('Page Uno')));
        $this->assertSame('anthropic', ChatbotAiChain::forPage('Page Uno', $group)[0]['provider']);
        $this->assertSame('deepseek', ChatbotAiChain::forPage('Page Uno')[0]['provider']);
    }

    public function test_S_210_2_4_a_provider_with_no_usable_key_is_skipped_and_the_next_one_answers(): void
    {
        $this->addKey('openai', 'pool-1', self::KEY_A);
        $this->useProvider('Page Uno', 'anthropic', 'openai'); // walang key ng Claude
        $this->ask();
        $this->assertSame([self::OPENAI], array_column($this->providerCalls, 'url'));
        $this->assertSame(['Reply: answer by 1111'], $this->sent());
        $attempts = json_decode($this->lastRow()->ai_attempts, true);
        $this->assertSame([['anthropic', false, 'Claude has no usable key.', true], ['openai', true, null, false]],
            array_map(fn ($a) => [$a['provider'], $a['ok'], $a['why'], $a['skipped'] ?? false], $attempts));
    }

    public function test_S_210_2_5_a_model_that_does_not_exist_is_a_model_error_and_no_key_is_marked(): void
    {
        $a = $this->addKey('openai', 'pool-1', self::KEY_A);
        $b = $this->addKey('openai', 'pool-2', self::KEY_B);
        $this->byKey[self::KEY_A] = [self::error(404, 'The model `gpt-nope` does not exist or you do not have access to it.', 'invalid_request_error', 'model_not_found')];
        $this->byKey[self::KEY_B] = [self::error(404, 'The model `gpt-nope` does not exist or you do not have access to it.', 'invalid_request_error', 'model_not_found')];
        $this->ask();
        $this->assertCount(1, $this->providerCalls); // ang ibang key ng parehong provider ay hindi na sinusubukan
        $this->assertSame(['Sorry'], $this->sent());
        $this->assertSame([[], []], [$this->health($a), $this->health($b)]);
        $this->assertCount(2, $this->keys()->usable('openai'));
        $this->assertSame('OpenAI refused (HTTP 404).', $this->lastRow()->error);
    }

    public function test_S_210_3_1_no_credit_on_key_1_is_answered_by_key_2_in_the_same_call_and_key_1_is_marked_down(): void
    {
        $a = $this->addKey('openai', 'pool-1', self::KEY_A);
        $this->addKey('openai', 'pool-2', self::KEY_B);
        $this->byKey[self::KEY_A] = [self::error(429, 'You exceeded your current quota, please check your plan and billing details.', 'insufficient_quota', 'insufficient_quota')];
        $this->ask();
        $this->assertSame(['1111', '2222'], $this->calledKeys());
        $this->assertSame(['Reply: answer by 2222'], $this->sent());
        $h = $this->health($a);
        $this->assertSame(['down', 'no_credit'], [$h['state'], $h['reason']]);
        $this->assertSame(now()->getTimestamp() + 1800, $h['until']);
        $this->assertSame('pool-2', $this->lastRow()->key_label);
    }

    public function test_S_210_3_2_a_429_moves_to_the_next_key_and_rests_key_1_shorter_than_402_or_401(): void
    {
        $a = $this->addKey('openai', 'pool-1', self::KEY_A);
        $this->addKey('openai', 'pool-2', self::KEY_B);
        $this->byKey[self::KEY_A] = [self::error(429, 'Rate limit reached for gpt-4o-mini', 'requests', 'rate_limit_exceeded')];
        $this->ask();
        $this->assertSame(['Reply: answer by 2222'], $this->sent());
        $h = $this->health($a);
        $this->assertSame(['down', 'rate_limited', 120], [$h['state'], $h['reason'], $h['until'] - now()->getTimestamp()]);
        $this->assertLessThan(config('services.chatbot_ai.keys.rest.no_credit'), 120);
    }

    public function test_S_210_3_3_a_401_marks_the_key_invalid_and_it_waits_for_a_test(): void
    {
        $a = $this->addKey('openai', 'pool-1', self::KEY_A);
        $this->addKey('openai', 'pool-2', self::KEY_B);
        $this->byKey[self::KEY_A] = [self::error(401, 'Incorrect API key provided: sk-test-****1111.', 'invalid_request_error', 'invalid_api_key')];
        $this->ask();
        $this->assertSame(['Reply: answer by 2222'], $this->sent());
        $this->assertSame(['down', 'invalid', null], [$this->health($a)['state'], $this->health($a)['reason'], $this->health($a)['until']]);
        $this->travel(3)->days();
        for ($i = 0; $i < 4; $i++) $this->ask('q' . $i, (string) (7200 + $i));
        $this->assertSame(['1111', '2222', '2222', '2222', '2222', '2222'], $this->calledKeys());
        $this->assertSame('ok', $this->keys()->test($a, null)['result']);
        $this->assertContains($a, array_column($this->keys()->usable('openai'), 'id'));
    }

    public function test_S_210_3_4_a_rested_key_is_tried_again_when_its_time_passed_and_success_clears_the_mark(): void
    {
        $a = $this->addKey('openai', 'pool-1', self::KEY_A);
        $this->addKey('openai', 'pool-2', self::KEY_B);
        $this->byKey[self::KEY_A] = [self::error(429, 'Rate limit reached', 'requests', 'rate_limit_exceeded')];
        $this->ask();
        $this->assertNotContains($a, array_column($this->keys()->usable('openai'), 'id'));
        $this->travel(121)->seconds();
        $this->ask('again', '7002');
        $this->ask('third', '7003');
        $this->assertContains('1111', array_slice($this->calledKeys(), 2));
        $this->assertSame('ok', $this->health($a)['state']);
        $this->assertNull($this->health($a)['until']);
    }

    public function test_S_210_3_5_a_switch_writes_an_event_row_without_key_text_and_it_shows_on_the_key_screen(): void
    {
        $this->addKey('openai', 'pool-1', self::KEY_A);
        $this->addKey('openai', 'pool-2', self::KEY_B);
        $this->byKey[self::KEY_A] = [self::error(402, 'Payment required')];
        $this->ask();
        $row = PageSetting::where('page_name', ChatbotAiKeys::PAGE)->where('provider', ChatbotAiKeys::PROVIDER)->where('key', 'event')->sole();
        $e = json_decode($row->value, true);
        $this->assertSame(['Page Uno', 'openai', 'pool-1', 'pool-2', 'no credit'], [$e['page'], $e['provider'], $e['from'], $e['to'], $e['reason']]);
        $this->assertSame(now()->toIso8601String(), $e['at']);
        $this->assertStringNotContainsString(self::KEY_A, $row->value);
        $this->actingAs($this->user('CEO', 'ana@example.test'));
        $shown = $this->getJson('/chatbot/ai-keys/data')->assertOk()->json('events.events');
        $this->assertSame(['pool-1', 'pool-2', 'no credit'], [$shown[0]['from'], $shown[0]['to'], $shown[0]['reason']]);
    }

    public function test_S_210_3_6_a_400_caused_by_the_request_marks_no_key(): void
    {
        $a = $this->addKey('openai', 'pool-1', self::KEY_A);
        $b = $this->addKey('openai', 'pool-2', self::KEY_B);
        $this->byKey[self::KEY_A] = [self::error(400, "Invalid 'messages[1].content': string too long.", 'invalid_request_error', 'string_above_max_length')];
        $this->ask();
        $this->assertSame(['1111'], $this->calledKeys());
        $this->assertSame(['Sorry'], $this->sent());
        $this->assertSame([[], []], [$this->health($a), $this->health($b)]);
    }

    public function test_S_210_3_7_one_5xx_529_or_timeout_moves_on_and_the_key_is_marked_only_after_the_threshold(): void
    {
        $a = $this->addKey('openai', 'pool-1', self::KEY_A);
        $this->addKey('openai', 'pool-2', self::KEY_B);
        $this->byKey[self::KEY_A] = [self::error(500, 'server error'), self::error(529, 'Overloaded'), Http::failedConnection('cURL error 28: Operation timed out after 30001 milliseconds')];
        // Ang ikot ay A, B, A, B…: bawat ikalawang tanong ay nagsisimula sa A.
        for ($i = 0; $i < 6; $i++) {
            $this->ask('q' . $i, (string) (7300 + $i));
            if ($i < 4) $this->assertSame([], $this->health($a), 'not marked after ' . ($i + 1) . ' asks');
        }
        foreach (range(7300, 7305) as $psid) $this->assertSame(['Reply: answer by 2222'], $this->sent((string) $psid));
        $this->assertSame(['down', 'failing', 60], [$this->health($a)['state'], $this->health($a)['reason'], $this->health($a)['until'] - now()->getTimestamp()]);
    }

    public function test_S_210_3_9_a_down_mark_survives_a_cache_clear(): void
    {
        $a = $this->addKey('openai', 'pool-1', self::KEY_A);
        $this->addKey('openai', 'pool-2', self::KEY_B);
        $this->byKey[self::KEY_A] = [self::error(402, 'Payment required')];
        $this->ask();
        Cache::flush();
        app()->forgetInstance(PageSettings::class);
        $this->assertNotContains($a, array_column(app(ChatbotAiKeys::class)->usable('openai'), 'id'));
        $this->ask('after restart', '7002');
        $this->assertSame(['1111', '2222', '2222'], $this->calledKeys());
    }

    public function test_S_210_3_10_a_recovering_key_that_fails_again_rests_longer_up_to_the_cap(): void
    {
        config(['services.chatbot_ai.keys.max_rest' => 300]);
        $a = $this->addKey('openai', 'pool-1', self::KEY_A);
        $rests = [];
        foreach (range(1, 4) as $i) {
            $this->byKey[self::KEY_A] = [self::error(429, 'Rate limit reached', 'requests', 'rate_limit_exceeded')];
            $this->ask('q' . $i, (string) (7400 + $i));
            $h = $this->health($a);
            $rests[] = $h['until'] - now()->getTimestamp();
            $this->travel($rests[array_key_last($rests)] + 1)->seconds();
        }
        $this->assertSame([120, 240, 300, 300], $rests);
        // Ang sagot ay naglilinis: ang susunod na palya ay bumabalik sa simula.
        $this->ask('ok now', '7499');
        $this->byKey[self::KEY_A] = [self::error(429, 'Rate limit reached', 'requests', 'rate_limit_exceeded')];
        $this->ask('again', '7498');
        $this->assertSame(120, $this->health($a)['until'] - now()->getTimestamp());
    }

    public function test_S_210_3_11_with_every_key_down_the_step_fails_cleanly_with_all_keys_failed_nothing_billed_and_one_alert(): void
    {
        $this->addKey('openai', 'pool-1', self::KEY_A);
        $this->addKey('anthropic', 'claude-1', self::KEY_B);
        $this->useProvider('Page Uno', 'openai', 'anthropic');
        $this->byKey[self::KEY_A] = [self::error(402, 'no credit')];
        $this->byKey[self::KEY_B] = [Http::response(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], 401)];
        foreach (['7001', '7002', '7003'] as $psid) $this->ask('q', $psid);
        $this->assertSame(['Sorry'], $this->sent('7001'));
        $this->assertSame(['Sorry'], $this->sent('7003'));
        $rows = DB::table('chatbot_ai_requests')->orderBy('id')->get();
        $this->assertSame(['All AI keys failed.', 'All AI keys failed.', 'All AI keys failed.'], $rows->pluck('error')->all());
        $this->assertSame([null, null, null], $rows->pluck('usage_status')->all());
        $this->assertSame([null, null, null], $rows->pluck('cost_micros')->all());
        $this->assertSame(1, PageSetting::where('page_name', ChatbotAiKeys::PAGE)->where('key', 'alert')->count());
        $this->actingAs($this->user('CEO', 'ana@example.test'));
        $this->assertSame('All AI keys failed.', $this->getJson('/chatbot/ai-keys/data')->json('alert.error'));
    }

    public function test_S_210_4_3_claude_and_deepseek_answers_and_errors_reach_the_same_shape_as_openai(): void
    {
        $this->addKey('anthropic', 'claude-1', self::KEY_B);
        $this->addKey('deepseek', 'ds-1', self::KEY_C);
        $this->useProvider('Page Uno', 'anthropic', 'deepseek');
        $this->byKey[self::KEY_B] = [$this->okFor(self::CLAUDE, 'Claude says hi', ['in' => 120, 'out' => 30]),
            Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529)];
        $this->byKey[self::KEY_C] = [$this->okFor(self::DEEPSEEK, 'DeepSeek says hi', ['in' => 80, 'out' => 10])];
        $this->ask('first', '7001');
        $row = $this->lastRow();
        $this->assertSame(['Reply: Claude says hi'], $this->sent('7001'));
        $this->assertSame(['anthropic', 120, 30], [$row->provider, $row->prompt_tokens, $row->completion_tokens]);
        $sent = $this->providerCalls[0]['body'];
        $this->assertSame(['claude-haiku-4-5', 250], [$sent['model'], $sent['max_tokens']]);
        $this->assertSame('user', $sent['messages'][0]['role']);

        $this->ask('second', '7002');
        $row = $this->lastRow();
        $this->assertSame(['Reply: DeepSeek says hi'], $this->sent('7002'));
        $this->assertSame(['deepseek', 80, 10, 'fell back from Claude (claude-1): Claude refused (HTTP 529).'], [$row->provider, $row->prompt_tokens, $row->completion_tokens, $row->fallback_reason]);
    }
}
