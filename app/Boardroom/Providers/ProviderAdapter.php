<?php

namespace App\Boardroom\Providers;

interface ProviderAdapter
{
    public function provider(): string;

    /** Pangalan ng endpoint na ginagamit (para sa registry / display). */
    public function endpointName(): string;

    /** Ang eksaktong JSON body na ipapadala. Walang API key rito. */
    public function buildPayload(ProviderRequest $request): array;

    /** Isang totoong model call. Ang API key ay ginagamit lang sa header ng request na ito. */
    public function send(ProviderRequest $request, string $apiKey): ProviderResult;

    /**
     * Mga model ID na naa-access ng credential na ito.
     *
     * @return array{ok: bool, models: array<int, string>, error_code: ?string, error: ?string}
     */
    public function listModels(string $apiKey): array;
}
