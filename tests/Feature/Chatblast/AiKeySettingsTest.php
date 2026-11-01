<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\ChatbotAiChain;

/**
 * Spec 210, S-210.2.1 at S-210.2.6 sa /chatbot/pages: ang page at ang site default ay may OpenAI, Claude, DeepSeek at
 * Local, ang fallback ay listahan, at ang model ng Claude at DeepSeek ay sa site default o sa page.
 */
class AiKeySettingsTest extends AiKeyPoolTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-15 10:00:00');
        $this->actingAs($this->user('CEO', 'ana@example.test'));
    }

    public function test_S_210_2_1_the_page_settings_and_the_site_default_offer_openai_claude_deepseek_and_local(): void
    {
        $data = $this->getJson('/chatbot/data')->assertOk()->json();
        $this->assertSame(['openai', 'anthropic', 'deepseek', 'local'], array_column($data['ai_site']['providers'], 'name'));
        $this->assertSame(['OpenAI', 'Claude', 'DeepSeek', 'Local'], array_column($data['ai_site']['providers'], 'label'));

        $this->postJson('/chatbot/ai', ['page_name' => 'Page Uno', 'provider' => 'anthropic', 'fallback' => 'deepseek,openai'])->assertOk()
            ->assertJsonPath('ai.Page Uno.chain', 'Claude → DeepSeek → OpenAI');
        $this->assertSame(['anthropic', 'deepseek', 'openai'], array_column(ChatbotAiChain::forPage('Page Uno'), 'provider'));
        $this->postJson('/chatbot/ai/site', ['provider' => 'deepseek', 'fallback' => 'anthropic'])->assertOk();
        $this->assertSame(['deepseek', 'anthropic'], array_column(ChatbotAiChain::forPage(self::TECH), 'provider'));

        foreach ([['fallback' => 'gemini'], ['fallback' => 'openai,gemini'], ['provider' => 'claude']] as $bad) {
            $this->postJson('/chatbot/ai', ['page_name' => 'Page Uno'] + $bad)->assertStatus(422)
                ->assertJsonPath('error', isset($bad['provider']) ? 'Pick OpenAI, Claude, DeepSeek or Local.' : 'Pick OpenAI, Claude, DeepSeek or Local (or None), in order.');
        }
        $this->get('/chatbot/pages')->assertOk()->assertSee('anthropic_model', false)->assertSee('deepseek_model', false)->assertSee('AI keys');
    }

    public function test_S_210_2_6_claude_and_deepseek_use_the_model_of_the_site_default_or_the_page_and_openai_keeps_the_nodes(): void
    {
        $this->postJson('/chatbot/ai', ['page_name' => 'Page Uno', 'provider' => 'anthropic', 'fallback' => 'deepseek,openai'])->assertOk();
        $models = fn () => array_column(ChatbotAiChain::forPage('Page Uno'), 'model', 'provider');
        $this->assertSame(['anthropic' => 'claude-haiku-4-5', 'deepseek' => 'deepseek-chat', 'openai' => null], $models());

        $this->postJson('/chatbot/ai/site', ['anthropic_model' => 'claude-sonnet-4-5', 'deepseek_model' => 'deepseek-reasoner'])->assertOk()
            ->assertJsonPath('ai_site.anthropic_model', 'claude-sonnet-4-5');
        $this->assertSame(['anthropic' => 'claude-sonnet-4-5', 'deepseek' => 'deepseek-reasoner', 'openai' => null], $models());

        $this->postJson('/chatbot/ai', ['page_name' => 'Page Uno', 'anthropic_model' => 'claude-haiku-4-5'])->assertOk();
        $this->assertSame('claude-haiku-4-5', $models()['anthropic']);
        $this->postJson('/chatbot/ai', ['page_name' => 'Page Uno', 'anthropic_model' => 'default'])->assertOk(); // ang blangko sa dialog ay "default" = ang site default
        $this->assertSame('claude-sonnet-4-5', $models()['anthropic']);
        $this->postJson('/chatbot/ai', ['page_name' => 'Page Uno', 'anthropic_model' => 'claude haiku; drop'])->assertStatus(422);
        // Ang Local ay gaya ng dati (sariling model sa page).
        $this->postJson('/chatbot/ai', ['page_name' => self::TECH, 'provider' => 'local', 'local_model' => 'qwen3:14b'])->assertOk();
        $this->assertSame('qwen3:14b', ChatbotAiChain::forPage(self::TECH)[0]['model']);
    }
}
