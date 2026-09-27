<?php

namespace Tests\Feature\Boardroom;

use App\Boardroom\Capabilities\CapabilityRegistry;
use App\Boardroom\Providers\AdapterFactory;
use App\Boardroom\Providers\ProviderRequest;

/**
 * LIVE SMOKE TEST — OPT-IN. Ito LANG ang test na gumagawa ng TOTOONG request sa provider,
 * at kumokonsumo ito ng kaunting credits. Naka-skip ito maliban kung tahasang binuksan.
 *
 * Paano patakbuhin (sa sarili mong terminal; huwag ilagay ang key sa kahit anong file o chat):
 *
 *   BOARDROOM_LIVE_TEST=1 BOARDROOM_LIVE_OPENAI_KEY=<key> php artisan test --filter=BoardroomLiveSmokeTest
 *
 * Mga variable (lahat opsyonal maliban sa flag):
 *   BOARDROOM_LIVE_OPENAI_KEY     + BOARDROOM_LIVE_OPENAI_MODEL     (default gpt-6-astra)
 *   BOARDROOM_LIVE_ANTHROPIC_KEY  + BOARDROOM_LIVE_ANTHROPIC_MODEL  (default claude-opus-5)
 *   BOARDROOM_LIVE_DEEPSEEK_KEY   + BOARDROOM_LIVE_DEEPSEEK_MODEL   (default deepseek-flash)
 *
 * Ang provider na walang key ay naka-SKIP — hindi "passed".
 */
class BoardroomLiveSmokeTest extends BoardroomTestCase
{
    public static function providers(): array
    {
        return [
            'openai'    => ['openai', 'BOARDROOM_LIVE_OPENAI_KEY', 'BOARDROOM_LIVE_OPENAI_MODEL', 'gpt-6-astra', ['effort' => 'low']],
            'anthropic' => ['anthropic', 'BOARDROOM_LIVE_ANTHROPIC_KEY', 'BOARDROOM_LIVE_ANTHROPIC_MODEL', 'claude-opus-5', ['effort' => 'low', 'thinking' => 'adaptive']],
            'deepseek'  => ['deepseek', 'BOARDROOM_LIVE_DEEPSEEK_KEY', 'BOARDROOM_LIVE_DEEPSEEK_MODEL', 'deepseek-flash', ['thinking' => 'disabled']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function test_live_provider_answers_a_tiny_request(string $provider, string $keyVar, string $modelVar, string $defaultModel, array $settings): void
    {
        if (! filter_var(getenv('BOARDROOM_LIVE_TEST'), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('LIVE test — naka-off. (BOARDROOM_LIVE_TEST=1 para buksan.)');
        }
        $key = (string) getenv($keyVar);
        if ($key === '') {
            $this->markTestSkipped("LIVE {$provider}: walang {$keyVar} — HINDI nasubok.");
        }

        $model    = (string) (getenv($modelVar) ?: $defaultModel);
        $resolved = app(CapabilityRegistry::class)->resolve($provider, $model, $settings + ['max_output_tokens' => 400, 'timeout_s' => 60]);

        $result = app(AdapterFactory::class)->make($provider)->send(
            new ProviderRequest($provider, $model, 'This is a connection test. Reply briefly.', 'Reply with the single word: OK', $resolved['applied']),
            $key
        );

        $this->assertTrue($result->ok, "LIVE {$provider}/{$model} — {$result->errorCode}: {$result->errorMessage}");
        $this->assertGreaterThan(0, $result->tokensIn + $result->tokensOut, 'Dapat may usage na ibinalik ang provider.');
        $this->assertStringNotContainsString($key, json_encode($result->sent));
    }
}
