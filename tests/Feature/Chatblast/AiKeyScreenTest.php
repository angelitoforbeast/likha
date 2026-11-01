<?php

namespace Tests\Feature\Chatblast;

use App\Models\PageSetting;
use App\Services\Chatbot\ChatbotAiKeys;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Spec 210, S-210.1 at S-210.7.4: ang mga key ng kumpanya sa /chatbot/ai-keys (add, Test, disable, delete), naka-encrypt
 * sa page_settings ng "_all", may history, at ang huling 4 lang ang lumalabas.
 */
class AiKeyScreenTest extends AiKeyPoolTestCase
{
    private $ana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-15 10:00:00');
        $this->ana = $this->user('CEO', 'ana@example.test');
        $this->actingAs($this->ana);
    }

    private function add(string $provider, string $label, string $key)
    {
        return $this->postJson('/chatbot/ai-keys', ['provider' => $provider, 'label' => $label, 'key' => $key]);
    }

    /** @return array<int, array> ang mga key sa screen */
    private function listed(): array
    {
        return $this->getJson('/chatbot/ai-keys/data')->assertOk()->json('keys');
    }

    private function rows(string $like)
    {
        return PageSetting::where('page_name', ChatbotAiKeys::PAGE)->where('provider', ChatbotAiKeys::PROVIDER)->where('key', 'like', $like)->orderBy('id')->get();
    }

    public function test_S_210_1_1_an_added_key_is_saved_encrypted_and_listed_with_only_its_last_4(): void
    {
        $this->add('openai', 'pool-1', self::KEY_A)->assertOk()->assertJsonPath('status', 'added');

        $secret = $this->rows('%.secret')->sole();
        $this->assertTrue($secret->is_secret);
        $this->assertNotSame(self::KEY_A, $secret->getRawOriginal('value'));
        $this->assertSame(self::KEY_A, Crypt::decryptString($secret->getRawOriginal('value')));

        $keys = $this->listed();
        $this->assertCount(1, $keys);
        $this->assertSame(['openai', 'OpenAI', 'pool-1', '1111', 'Not tested', null], [$keys[0]['provider'], $keys[0]['provider_label'], $keys[0]['label'], $keys[0]['last4'], $keys[0]['status'], $keys[0]['last_used']]);
        $this->get('/chatbot/ai-keys')->assertOk()->assertSee('AI keys')->assertDontSee(self::KEY_A);
        $this->assertStringNotContainsString(self::KEY_A, $this->getJson('/chatbot/ai-keys/data')->getContent());
    }

    public function test_S_210_1_2_test_runs_one_cheap_call_and_a_failing_key_is_kept_but_never_used_until_a_test_passes(): void
    {
        $id = $this->addKey('openai', 'pool-1', self::KEY_A);
        $this->byKey[self::KEY_A] = [self::error(401, 'Incorrect API key provided: sk-test-****1111')];
        $this->postJson('/chatbot/ai-keys/' . $id . '/test')->assertOk()->assertJsonPath('result', 'invalid');
        $this->assertCount(1, $this->providerCalls);
        $this->assertSame(1, $this->providerCalls[0]['body']['max_tokens']);
        $key = $this->listed()[0];
        $this->assertSame(['Down: invalid key', 'invalid', '2026-11-15 10:00:00'], [$key['status'], $key['test'], $key['tested_at']]);
        $this->assertSame([], $this->keys()->usable('openai'));

        // Lumipas ang oras: hindi pa rin ginagamit (hanggang pumasa ang Test).
        $this->travel(2)->days();
        $this->assertSame([], $this->keys()->usable('openai'));

        foreach ([[self::error(402, 'billing'), 'no_credit', 'Down: no credit'], [self::error(429, 'slow down'), 'rate_limited', 'Down: rate limited'], [null, 'ok', 'OK']] as [$answer, $result, $status]) {
            if ($answer) $this->byKey[self::KEY_A] = [$answer];
            $this->postJson('/chatbot/ai-keys/' . $id . '/test')->assertOk()->assertJsonPath('result', $result);
            $this->assertSame($status, $this->listed()[0]['status']);
        }
        $this->assertSame([$id], array_column($this->keys()->usable('openai'), 'id'));
    }

    public function test_S_210_1_3_a_disabled_key_is_skipped_and_enabling_puts_it_back(): void
    {
        $a = $this->addKey('openai', 'pool-1', self::KEY_A);
        $b = $this->addKey('openai', 'pool-2', self::KEY_B);
        $this->postJson('/chatbot/ai-keys/' . $a . '/enabled', ['enabled' => false])->assertOk();
        $this->assertSame([$b], array_column($this->keys()->usable('openai'), 'id'));
        $this->assertSame('Disabled', collect($this->listed())->firstWhere('id', $a)['status']);
        $this->postJson('/chatbot/ai-keys/' . $a . '/enabled', ['enabled' => true])->assertOk();
        $this->assertEqualsCanonicalizing([$a, $b], array_column($this->keys()->usable('openai'), 'id'));
    }

    public function test_S_210_1_4_claude_and_deepseek_keys_are_added_and_tested_the_same_way(): void
    {
        $c = $this->addKey('anthropic', 'claude-1', self::KEY_B);
        $d = $this->addKey('deepseek', 'deepseek-1', self::KEY_C);
        $this->postJson('/chatbot/ai-keys/' . $c . '/test')->assertOk()->assertJsonPath('result', 'ok');
        $this->byKey[self::KEY_C] = [Http::response(['error' => ['message' => 'Insufficient Balance', 'type' => 'unknown_error']], 402)];
        $this->postJson('/chatbot/ai-keys/' . $d . '/test')->assertOk()->assertJsonPath('result', 'no_credit');
        $this->assertSame([self::CLAUDE, self::DEEPSEEK], array_column($this->providerCalls, 'url'));
        $this->assertSame(self::KEY_B, $this->providerCalls[0]['key']); // x-api-key
        $this->assertSame([1, 1], [$this->providerCalls[0]['body']['max_tokens'], $this->providerCalls[1]['body']['max_tokens']]);
        $listed = collect($this->listed())->keyBy('id');
        $this->assertSame(['Claude', 'OK'], [$listed[$c]['provider_label'], $listed[$c]['status']]);
        $this->assertSame(['DeepSeek', 'Down: no credit'], [$listed[$d]['provider_label'], $listed[$d]['status']]);
    }

    /** Ang link na /chatblast sa nav para sa mga role (ang access ng chatbot section at ng AI default). */
    private function navLink(string $url, array $roles): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('nav_links')) {
            \Illuminate\Support\Facades\Artisan::call('migrate', ['--path' => 'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php', '--force' => true]);
        }
        $id = DB::table('nav_links')->insertGetId(['key' => 'chatblast', 'label' => 'Chatblast', 'route_url' => $url, 'icon' => 'fa-solid fa-link',
            'active_pattern' => 'chatblast*', 'is_active' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($roles as $role) {
            DB::table('nav_link_role_visibility')->insert(['nav_link_id' => $id, 'role' => $role, 'is_visible' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Amendment 210-1: ang pool ng key ay CEO lang; ang may /chatblast (hal. Marketing) ay 403 at walang link, pero ang AI default ay gaya ng dati. */
    public function test_S_210_1_5_anyone_but_the_ceo_gets_403_on_the_key_screen_sees_no_link_and_nothing_changes(): void
    {
        $id = $this->addKey('openai', 'pool-1', self::KEY_A);
        $this->get('/chatbot/pages')->assertOk()->assertSee('/chatbot/ai-keys', false); // ang CEO ay may link
        $this->navLink('/chatblast', ['Marketing']);
        foreach ([['Data Encoder', 'enc@example.test'], ['Marketing', 'mkt@example.test'], ['  ceo office  ', 'not-ceo@example.test']] as [$role, $email]) {
            $before = PageSetting::count();
            $this->actingAs($this->user($role, $email));
            $this->get('/chatbot/ai-keys')->assertForbidden();
            $this->getJson('/chatbot/ai-keys/data')->assertForbidden();
            $this->add('openai', 'x', self::KEY_B)->assertForbidden();
            $this->postJson('/chatbot/ai-keys/' . $id . '/test')->assertForbidden();
            $this->postJson('/chatbot/ai-keys/' . $id . '/enabled', ['enabled' => false])->assertForbidden();
            $this->postJson('/chatbot/ai-keys/' . $id . '/label', ['label' => 'x'])->assertForbidden();
            $this->deleteJson('/chatbot/ai-keys/' . $id)->assertForbidden();
            $this->assertSame($before, PageSetting::count(), $role);
        }
        $this->assertSame([], $this->providerCalls);
        // Ang Marketing (may /chatblast) ay nakikita pa rin ang /chatbot/pages at nagpapalit ng AI default, pero walang link sa mga key.
        $this->actingAs(\App\Models\User::where('email', 'mkt@example.test')->first());
        $this->get('/chatbot/pages')->assertOk()->assertDontSee('/chatbot/ai-keys', false);
        $this->postJson('/chatbot/ai/site', ['provider' => 'anthropic'])->assertOk();
    }

    public function test_S_210_1_6_the_same_key_pasted_twice_is_refused_as_a_duplicate(): void
    {
        $this->add('openai', 'pool-1', self::KEY_A)->assertOk();
        $res = $this->add('anthropic', 'again', '  ' . self::KEY_A . '  ')->assertStatus(422);
        $this->assertSame('This key is already in the pool (pool-1).', $res->json('error'));
        $this->assertStringNotContainsString(self::KEY_A, $res->getContent());
        $this->assertCount(1, $this->rows('%.secret'));
        // Ang paghahambing ay sa hash: walang key sa info.
        $this->assertStringNotContainsString(self::KEY_A, (string) $this->rows('%.info')->sole()->value);
    }

    public function test_S_210_1_7_empty_whitespace_multi_line_or_over_long_input_is_refused_with_a_message(): void
    {
        $cases = [
            [['provider' => 'openai', 'label' => 'a', 'key' => ''], 'Paste the key.'],
            [['provider' => 'openai', 'label' => 'a', 'key' => "   \t "], 'Paste the key.'],
            [['provider' => 'openai', 'label' => 'a', 'key' => "sk-test-one\nsk-test-two"], 'Paste one key on one line.'],
            [['provider' => 'openai', 'label' => 'a', 'key' => 'sk-' . str_repeat('x', 400)], 'The key is too long (300 characters at most).'],
            [['provider' => 'openai', 'label' => 'a', 'key' => 'sk test'], 'Paste one key on one line.'],
            [['provider' => 'openai', 'label' => '', 'key' => self::KEY_A], 'Give the key a label.'],
            [['provider' => 'openai', 'label' => str_repeat('l', 61), 'key' => self::KEY_A], 'Label: 60 characters at most.'],
            [['provider' => 'openai', 'label' => "two\nlines", 'key' => self::KEY_A], 'Label: one line, no control characters.'],
            [['provider' => 'local', 'label' => 'a', 'key' => self::KEY_A], 'Pick OpenAI, Claude or DeepSeek.'],
            [['provider' => ['openai'], 'label' => 'a', 'key' => self::KEY_A], 'Pick OpenAI, Claude or DeepSeek.'],
        ];
        foreach ($cases as [$body, $message]) {
            $this->postJson('/chatbot/ai-keys', $body)->assertStatus(422)->assertJsonPath('error', $message);
        }
        $this->assertSame(0, $this->rows('%')->count());
    }

    public function test_S_210_1_8_a_deleted_key_loses_its_encrypted_value_and_old_usage_keeps_its_label_and_last_4(): void
    {
        $id = $this->addKey('openai', 'pool-1', self::KEY_A);
        $this->addKey('openai', 'pool-2', self::KEY_B);
        DB::table('chatbot_ai_requests')->insert(['request_id' => 'r-1', 'page_id' => 1001, 'psid' => 7001, 'flow_id' => 1, 'node_id' => 'n2', 'page_name' => 'Page Uno',
            'model' => 'gpt-4o-mini', 'max_tokens' => 10, 'status' => 'sync', 'deadline_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            'key_id' => $id, 'key_label' => 'pool-1', 'key_last4' => '1111']);
        // Ang palit ng label ay bagong info row lang; ang secret ay iisang row pa rin.
        $this->postJson('/chatbot/ai-keys/' . $id . '/label', ['label' => 'main'])->assertOk();
        $this->deleteJson('/chatbot/ai-keys/' . $id)->assertOk();

        $secrets = PageSetting::where('page_name', ChatbotAiKeys::PAGE)->where('key', 'k' . $id . '.secret')->get();
        $this->assertNotEmpty($secrets);
        foreach ($secrets as $s) $this->assertNull($s->getRawOriginal('value'));
        $this->assertSame(['pool-2'], array_column($this->listed(), 'label'));
        $this->assertSame(['pool-1', '1111'], [DB::table('chatbot_ai_requests')->value('key_label'), DB::table('chatbot_ai_requests')->value('key_last4')]);
        $this->assertNotContains($id, array_column($this->keys()->usable('openai'), 'id'));
        // Ang parehong key ay maaaring idagdag ulit pagkatapos ng delete.
        $this->add('openai', 'pool-1 again', self::KEY_A)->assertOk();
    }

    public function test_S_210_1_9_a_key_that_cannot_be_decrypted_shows_cannot_decrypt_and_the_chain_skips_it(): void
    {
        $a = $this->addKey('openai', 'pool-1', self::KEY_A);
        $b = $this->addKey('openai', 'pool-2', self::KEY_B);
        // Ang APP_KEY ay napalitan: ang naka-encrypt na value ay hindi na mabasa.
        PageSetting::where('key', 'k' . $a . '.secret')->update(['value' => (new \Illuminate\Encryption\Encrypter(random_bytes(32), 'aes-256-cbc'))->encryptString(self::KEY_A)]);
        \App\Services\PageSettings::forget(ChatbotAiKeys::PAGE);

        $this->get('/chatbot/ai-keys')->assertOk();
        $this->assertSame('Cannot decrypt', collect($this->listed())->firstWhere('id', $a)['status']);
        $this->assertSame([$b], array_column($this->keys()->usable('openai'), 'id'));
        $this->postJson('/chatbot/ai-keys/' . $a . '/test')->assertStatus(422)->assertJsonPath('error', 'This key cannot be decrypted (the app key changed). Delete it and add it again.');
        $this->assertSame([], $this->providerCalls);
    }

    public function test_S_210_7_4_every_add_edit_test_disable_and_delete_is_in_the_history_with_who_and_when_and_no_key_text(): void
    {
        $this->add('openai', 'pool-1', self::KEY_A)->assertOk();
        $id = $this->listed()[0]['id'];
        $this->travel(1)->minutes();
        $this->postJson('/chatbot/ai-keys/' . $id . '/label', ['label' => 'main'])->assertOk();
        $this->postJson('/chatbot/ai-keys/' . $id . '/test')->assertOk();
        $this->postJson('/chatbot/ai-keys/' . $id . '/enabled', ['enabled' => false])->assertOk();
        $this->deleteJson('/chatbot/ai-keys/' . $id)->assertOk();

        $info = $this->rows('k' . $id . '.info');
        $this->assertSame([[true, null], ['main', null], ['main', false], ['main', true]], [
            [json_decode($info[0]->value, true)['enabled'], json_decode($info[0]->value, true)['deleted'] ?? null],
            [json_decode($info[1]->value, true)['label'], json_decode($info[1]->value, true)['deleted'] ?? null],
            [json_decode($info[2]->value, true)['label'], json_decode($info[2]->value, true)['enabled']],
            [json_decode($info[3]->value, true)['label'], json_decode($info[3]->value, true)['deleted']],
        ]);
        $tests = $this->rows('k' . $id . '.health');
        $this->assertSame('ok', json_decode($tests->first()->value, true)['test']);
        foreach ($this->rows('%') as $row) {
            if (!str_ends_with($row->key, '.secret')) $this->assertSame($this->ana->id, $row->created_by, $row->key);
            $this->assertStringNotContainsString(self::KEY_A, (string) $row->getRawOriginal('value'));
            $this->assertNotNull($row->created_at);
        }
        $this->assertSame($this->ana->id, $this->rows('k' . $id . '.secret')->first()->created_by);
    }
}
