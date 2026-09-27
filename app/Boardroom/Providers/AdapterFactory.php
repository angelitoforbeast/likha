<?php

namespace App\Boardroom\Providers;

use InvalidArgumentException;

/**
 * Provider → adapter. Walang default at walang fallback: ang hindi kilalang provider ay error,
 * hindi tahimik na inililipat sa iba.
 */
class AdapterFactory
{
    public const MAP = [
        'openai'    => OpenAIAdapter::class,
        'anthropic' => AnthropicAdapter::class,
        'deepseek'  => DeepSeekAdapter::class,
    ];

    public function make(string $provider): ProviderAdapter
    {
        $class = self::MAP[$provider] ?? null;
        if (! $class) {
            throw new InvalidArgumentException("Hindi kilalang provider: {$provider}");
        }

        return app($class);
    }

    public static function providers(): array
    {
        return array_keys(self::MAP);
    }
}
