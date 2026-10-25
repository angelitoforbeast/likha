<?php

namespace Tests\Feature\Chatblast;

use App\Models\PageSetting;
use App\Services\Chatbot\ChatbotAiChain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Spec 150, ang pagpili ng AI sa /chatbot/pages: ang site default at ang override kada page (lahat o wala), ang Local na
 * naka-set up lang, ang Changes, at walang key kahit saan.
 */
class ChatbotAiSettingsTest extends ChatbotAiProviderTestCase
{
    private $ana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-12 10:00:00');
        $this->ana = $this->user('CEO', 'ana@example.test');
        DB::table('users')->where('id', $this->ana->id)->update(['name' => 'Ana']);
        $this->actingAs($this->ana);
    }

    /** Ang link na /chatblast sa nav para sa mga role (ang access ng chatbot section). */
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

    private function save(array $body)
    {
        return $this->postJson('/chatbot/ai', $body);
    }

    /** @return array<string, array> page => ai ng row sa /chatbot/data */
    private function shown(): array
    {
        return collect($this->getJson('/chatbot/data')->assertOk()->json('rows'))->mapWithKeys(fn ($r) => [$r['page'] => $r['ai']])->all();
    }

    private function changes(string $query = ''): array
    {
        return $this->getJson('/chatbot/changes' . ($query !== '' ? '?' . $query : ''))->assertOk()->json('entries');
    }

    private function assertFields(array $want, array $got): void
    {
        ksort($want);
        ksort($got);
        $this->assertSame($want, $got);
    }

    private function history(string $page, string $key)
    {
        return PageSetting::where('page_name', $page)->where('provider', 'chatbot')->where('key', $key)->orderBy('id')->get();
    }

    public function test_S_150_A1_every_page_shows_the_default_until_one_is_set_to_local(): void
    {
        $this->assertSame(['Page Uno' => 'OpenAI (default)', self::TECH => 'OpenAI (default)'], array_map(fn ($a) => $a['label'], $this->shown()));

        $this->save(['page_name' => self::TECH, 'provider' => 'local', 'local_model' => 'qwen3:14b'])->assertOk();

        $shown = $this->shown();
        $this->assertSame(['local', true, 'Local'], [$shown[self::TECH]['provider'], $shown[self::TECH]['override'], $shown[self::TECH]['label']]);
        $this->assertSame(['openai', false, 'OpenAI (default)'], [$shown['Page Uno']['provider'], $shown['Page Uno']['override'], $shown['Page Uno']['label']]);
        $this->assertSame('local', ChatbotAiChain::forPage(self::TECH)[0]['provider']);
    }

    public function test_S_150_A2_clearing_an_override_returns_to_the_default_and_keeps_history(): void
    {
        $this->save(['page_name' => self::TECH, 'provider' => 'local', 'local_model' => 'qwen3:14b'])->assertOk();

        $this->save(['page_name' => self::TECH, 'provider' => 'default'])->assertOk();

        $this->assertSame('OpenAI (default)', $this->shown()[self::TECH]['label']);
        $rows = $this->history(self::TECH, 'ai_provider');
        $this->assertSame([['local', false]], $rows->map(fn ($s) => [$s->plainValue(), (bool) $s->active])->all());
        $this->assertSame('openai', ChatbotAiChain::forPage(self::TECH)[0]['provider']);
    }

    public function test_S_150_A3_changing_the_site_default_changes_only_pages_without_override(): void
    {
        $this->save(['page_name' => 'Page Uno', 'provider' => 'openai'])->assertOk();

        $this->postJson('/chatbot/ai/site', ['provider' => 'local', 'local_model' => 'llama3.1:8b'])->assertOk();

        $shown = $this->shown();
        $this->assertSame(['OpenAI', 'Local (default)'], [$shown['Page Uno']['label'], $shown[self::TECH]['label']]);
        $this->assertSame(['openai', 'local'], [ChatbotAiChain::forPage('Page Uno')[0]['provider'], ChatbotAiChain::forPage(self::TECH)[0]['provider']]);
        $this->assertSame('llama3.1:8b', ChatbotAiChain::forPage(self::TECH)[0]['model']);
    }

    public function test_S_150_A4_without_chatblast_access_nothing_changes_and_with_it_anyone_may_change(): void
    {
        $this->actingAs($this->user('Data Encoder', 'enc@example.test'));
        $this->save(['page_name' => self::TECH, 'provider' => 'local', 'local_model' => 'qwen3:14b'])->assertForbidden();
        $this->postJson('/chatbot/ai/site', ['provider' => 'local'])->assertForbidden();
        $this->getJson('/chatbot/ai/compare?page=' . urlencode(self::TECH))->assertForbidden();
        $this->assertSame(0, PageSetting::where('key', 'like', 'ai_%')->count());

        auth()->logout();
        $this->post('/chatbot/ai', ['page_name' => self::TECH, 'provider' => 'local'])->assertRedirect();

        $this->navLink('/chatblast', ['Data Encoder']);
        $this->actingAs($this->user('Data Encoder', 'enc2@example.test'));
        $this->save(['page_name' => self::TECH, 'provider' => 'local', 'local_model' => 'qwen3:14b'])->assertOk();
        $this->assertSame('local', ChatbotAiChain::forPage(self::TECH)[0]['provider']);
    }

    public function test_S_150_A6_a_bad_provider_an_unlisted_page_or_too_many_pages_changes_nothing(): void
    {
        $cases = [
            ['page_names' => ['Page Uno', self::TECH], 'provider' => 'gemini'],
            ['page_names' => ['Page Uno', 'Not A Page'], 'provider' => 'openai'],
            ['page_names' => array_merge(['Page Uno'], array_fill(0, 200, self::TECH)), 'provider' => 'openai'],
            ['page_names' => ['Page Uno'], 'fallback' => 'gemini'],
            ['page_names' => ['Page Uno'], 'local_timeout' => '0'],
            ['page_names' => ['Page Uno'], 'local_timeout' => '61'],
            ['page_names' => ['Page Uno']],
        ];
        foreach ($cases as $i => $body) $this->save($body)->assertStatus(422)->assertJsonStructure(['error']);
        $this->assertSame(0, PageSetting::where('key', 'like', 'ai_%')->count());
    }

    public function test_S_150_A7_local_cannot_be_chosen_before_it_is_set_up(): void
    {
        $this->save(['page_name' => self::TECH, 'provider' => 'openai'])->assertOk();
        config(['services.chatbot_ai.providers.local.url' => '']);

        $this->save(['page_name' => self::TECH, 'provider' => 'local', 'local_model' => 'qwen3:14b'])->assertStatus(422)->assertJson(['error' => 'Local AI is not set up yet.']);
        $this->postJson('/chatbot/ai/site', ['provider' => 'local', 'local_model' => 'qwen3:14b'])->assertStatus(422)->assertJson(['error' => 'Local AI is not set up yet.']);

        $this->assertSame('OpenAI', $this->shown()[self::TECH]['label']);
        $this->assertFalse($this->getJson('/chatbot/data')->json('ai_site.local_ready'));
    }

    public function test_S_150_A8_the_page_name_is_case_and_space_insensitive(): void
    {
        $this->save(['page_name' => '  tech   flask PH ', 'provider' => 'local', 'local_model' => 'qwen3:14b'])->assertOk()->assertJson(['pages' => [self::TECH]]);

        $this->assertSame('Local', $this->shown()[self::TECH]['label']);
    }

    public function test_S_150_A9_two_saves_leave_one_active_row_and_both_in_history(): void
    {
        $this->save(['page_name' => self::TECH, 'provider' => 'local', 'local_model' => 'qwen3:14b'])->assertOk();
        $this->save(['page_name' => 'tech flask ph', 'provider' => 'openai'])->assertOk();

        $rows = $this->history(self::TECH, 'ai_provider');
        $this->assertSame([['local', false], ['openai', true]], $rows->map(fn ($s) => [$s->plainValue(), (bool) $s->active])->all());
    }

    public function test_S_150_A10_ollama_model_names_are_stored_exactly(): void
    {
        foreach (['qwen3:14b', 'hf.co/org/model', 'hf.co/bartowski/Llama-3.2-3B-Instruct-GGUF:Q4_K_M', str_repeat('a', 100)] as $model) {
            $this->save(['page_name' => self::TECH, 'provider' => 'local', 'local_model' => $model])->assertOk();
            $this->assertSame($model, ChatbotAiChain::forPage(self::TECH)[0]['model'], $model);
            $this->assertSame($model, $this->shown()[self::TECH]['local_model']);
        }
        foreach ([str_repeat('a', 101), 'bad model', '-x', 'a;rm', '<b>'] as $bad) {
            $this->save(['page_name' => self::TECH, 'local_model' => $bad])->assertStatus(422);
        }
        $this->assertSame(str_repeat('a', 100), ChatbotAiChain::forPage(self::TECH)[0]['model']);
    }

    public function test_S_150_A11_a_failed_save_returns_the_old_value_and_an_error(): void
    {
        $this->save(['page_name' => self::TECH, 'provider' => 'local', 'local_model' => 'qwen3:14b'])->assertOk();

        $res = $this->save(['page_name' => self::TECH, 'local_model' => 'bad model'])->assertStatus(422);

        $this->assertNotSame('', (string) $res->json('error'));
        $this->assertSame('qwen3:14b', $this->shown()[self::TECH]['local_model']);
        // Ang screen: ang AI control ng page at ang site default ay nasa markup, at ang palyang save ay ibinabalik ang dati.
        $html = $this->get('/chatbot/pages')->assertOk()->getContent();
        $this->assertStringContainsString('data-ai-url="' . route('chatbot.ai') . '"', $html);
        $this->assertStringContainsString('data-ai-site-url="' . route('chatbot.ai.site') . '"', $html);
        $this->assertStringContainsString('aiRevert', $html);
    }

    public function test_S_150_B1_changes_shows_the_ai_provider_change(): void
    {
        $this->save(['page_name' => self::TECH, 'provider' => 'openai'])->assertOk();
        $this->travel(5)->minutes();
        $this->save(['page_name' => self::TECH, 'provider' => 'local', 'local_model' => 'qwen3:14b'])->assertOk();

        $e = collect($this->changes())->firstWhere(fn ($e) => $e['key'] === 'ai_provider' && $e['new'] === 'Local');
        $this->assertSame([self::TECH, 'AI provider', 'OpenAI', 'Local', 'Ana', '2026-11-12 10:05'], [$e['page'], $e['field'], $e['old'], $e['new'], $e['by'], $e['at']]);
    }

    public function test_S_150_B2_model_timeout_and_chain_changes_each_get_an_entry(): void
    {
        $this->save(['page_name' => self::TECH, 'provider' => 'local', 'local_model' => 'qwen3:14b', 'local_timeout' => '12', 'fallback' => 'none'])->assertOk();

        $fields = collect($this->changes())->where('page', self::TECH)->filter(fn ($e) => str_starts_with($e['key'], 'ai_'))->pluck('new', 'field')->all();
        $this->assertFields(['AI provider' => 'Local', 'Local model' => 'qwen3:14b', 'Local timeout' => '12 s', 'AI fallback' => 'None'], $fields);
    }

    public function test_S_150_B3_a_site_default_change_is_logged_and_shown(): void
    {
        $this->postJson('/chatbot/ai/site', ['provider' => 'local', 'local_model' => 'qwen3:14b', 'local_concurrency' => '2', 'breaker_failures' => '5', 'breaker_seconds' => '60'])->assertOk();

        $site = collect($this->changes())->where('page', 'Site default');
        $this->assertFields(['AI provider' => 'Local', 'Local model' => 'qwen3:14b', 'Local concurrency' => '2', 'Breaker failures' => '5', 'Breaker seconds' => '60 s'],
            $site->pluck('new', 'field')->all());
        $this->assertSame(['Ana'], $site->pluck('by')->unique()->values()->all());
        $this->assertSame(['local', '2'], [ChatbotAiChain::site()['provider'], ChatbotAiChain::site()['local_concurrency']]);
    }

    public function test_S_150_B4_saving_the_same_value_is_unchanged_with_no_new_entry(): void
    {
        $this->save(['page_name' => self::TECH, 'provider' => 'openai'])->assertOk();
        $this->postJson('/chatbot/ai/site', ['provider' => 'openai'])->assertOk();
        $before = count($this->changes());

        $this->save(['page_name' => self::TECH, 'provider' => 'openai'])->assertOk()->assertJson(['status' => 'unchanged']);
        $this->postJson('/chatbot/ai/site', ['provider' => 'openai'])->assertOk()->assertJson(['status' => 'unchanged']);

        $this->assertCount($before, $this->changes());
    }

    public function test_S_150_B5_a_rejected_save_leaves_no_entry(): void
    {
        $this->save(['page_name' => self::TECH, 'provider' => 'gemini'])->assertStatus(422);
        $this->postJson('/chatbot/ai/site', ['provider' => 'gemini'])->assertStatus(422);
        $this->postJson('/chatbot/ai/site', ['breaker_seconds' => '1'])->assertStatus(422);

        $this->assertSame([], collect($this->changes())->filter(fn ($e) => str_starts_with($e['key'], 'ai_'))->values()->all());
    }

    public function test_S_150_B6_the_manila_date_filter_includes_the_whole_end_day(): void
    {
        $this->travelTo('2026-11-12 23:58:00');
        $this->save(['page_name' => self::TECH, 'provider' => 'openai'])->assertOk();
        $this->travelTo('2026-11-13 00:01:00');
        $this->save(['page_name' => 'Page Uno', 'provider' => 'openai'])->assertOk();

        $pages = collect($this->changes('field=ai_provider&from=2026-11-12&to=2026-11-12'))->pluck('page')->all();
        $this->assertSame([self::TECH], $pages);
    }

    public function test_S_150_B7_a_deleted_users_entry_still_shows_with_a_placeholder(): void
    {
        $ben = $this->user('CEO', 'ben@example.test');
        $this->actingAs($ben);
        $this->save(['page_name' => self::TECH, 'provider' => 'openai'])->assertOk();
        $this->actingAs($this->ana);
        DB::table('users')->where('id', $ben->id)->delete();

        $e = collect($this->changes('field=ai_provider'))->first();
        $this->assertSame('user #' . $ben->id, $e['by']);
    }

    public function test_S_150_E3_no_key_value_appears_anywhere(): void
    {
        config(['services.chatbot_ai.providers.local.key' => 'local-secret-value']);
        Log::spy();
        $this->save(['page_name' => self::TECH, 'provider' => 'local', 'local_model' => 'qwen3:14b'])->assertOk();
        $this->postJson('/chatbot/ai/site', ['provider' => 'openai'])->assertOk();
        $this->live();
        $this->localAnswers = [\Illuminate\Support\Facades\Http::response(['error' => 'local-secret-value'], 500)];
        $this->says('hello');

        $out = $this->getJson('/chatbot/data')->getContent() . $this->getJson('/chatbot/changes')->getContent()
            . $this->getJson('/chatbot/ai/compare?page=' . urlencode(self::TECH))->getContent() . $this->get('/chatbot/pages')->getContent();
        $db = collect(['chatbot_ai_requests', 'chatbot_events', 'page_settings', 'app_settings'])->map(fn ($t) => json_encode(DB::table($t)->get()))->implode(' ');
        foreach (['local-secret-value', 'sk-test-not-real'] as $key) {
            $this->assertStringNotContainsString($key, $out, $key);
            $this->assertStringNotContainsString($key, $db, $key);
        }
        Log::shouldNotHaveReceived('info', [\Mockery::on(fn ($m) => str_contains((string) $m, 'secret')), \Mockery::any()]);
        $this->assertDoesNotMatchRegularExpression('/name="[^"]*(api_?key|ai_key)[^"]*"/i', $out);
    }

    public function test_S_150_E4_settings_named_like_a_key_are_refused_by_the_generic_endpoints(): void
    {
        foreach (['ai_key', 'api_key', 'openai_api_key', 'ai_provider', 'ai_local_model'] as $key) {
            // Ang form ng page settings ay bumabalik na may error (ang sarili nitong paraan ng pagtanggi).
            $this->post('/chatblast/page-settings', ['page_name' => self::TECH, 'provider' => 'chatbot', 'key' => $key, 'value' => 'x'])->assertSessionHasErrors();
        }
        foreach (['AI Key', 'API Key', 'Ai Provider'] as $name) $this->postJson('/chatbot/column', ['name' => $name])->assertStatus(422);
        $this->assertSame(0, PageSetting::where('provider', 'chatbot')->where('key', 'like', '%key%')->count());
        $this->assertSame(0, PageSetting::where('key', 'like', 'ai_%')->count());

        // Ang column na gawa na ng staff na nagsisimula sa "ai_" (hal. AI Prompt) ay nae-edit pa rin.
        $this->post('/chatblast/page-settings', ['page_name' => self::TECH, 'provider' => 'chatbot', 'key' => 'ai_prompt', 'value' => 'Sagutin nang magalang.'])->assertSessionHasNoErrors();
        $this->assertSame('Sagutin nang magalang.', app(\App\Services\PageSettings::class)->get(self::TECH, 'chatbot', 'ai_prompt'));
    }

    public function test_S_150_F8_with_no_new_env_vars_openai_is_the_default_and_only_the_controls_show(): void
    {
        config(['services.chatbot_ai.providers.local.url' => '']);

        $data = $this->getJson('/chatbot/data')->assertOk()->json();

        $this->assertSame(['OpenAI (default)'], collect($data['rows'])->pluck('ai.label')->unique()->values()->all());
        $this->assertSame(['provider' => 'openai', 'local_ready' => false], array_intersect_key($data['ai_site'], ['provider' => 1, 'local_ready' => 1]));
        $this->assertSame('openai', ChatbotAiChain::forPage(self::TECH)[0]['provider']);
        $this->assertSame(0, PageSetting::where('key', 'like', 'ai_%')->count() + DB::table('app_settings')->where('key', 'like', 'chatbot.ai.%')->count());
    }
}
