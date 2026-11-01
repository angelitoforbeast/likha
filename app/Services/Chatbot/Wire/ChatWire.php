<?php

namespace App\Services\Chatbot\Wire;

use App\Boardroom\Providers\ProviderResult;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;

/**
 * Ang hugis ng chat ng isang provider para sa AI step ng chatbot, gamit ang Boardroom adapter: ang URL, header at body ng
 * request (mula sa chat messages), at ang pagbasa ng sagot at ng error (ang classify ng adapter). Pinagsasaluhan ng
 * naghihintay na tawag (Http) at ng chatbot:ai-pool (Guzzle async), kaya status at body lang ang pinapasok.
 */
trait ChatWire
{
    /** @return array{url: string, headers: array, body: array} */
    public function chatRequest(#[\SensitiveParameter] string $key, string $model, array $messages, int $maxTokens): array
    {
        return ['url' => $this->baseUrl() . $this->chatPath(), 'headers' => $this->authHeaders($key) + ['Accept' => 'application/json'],
            'body' => $this->chatBody($model, $messages, $maxTokens)];
    }

    abstract protected function chatBody(string $model, array $messages, int $maxTokens): array;

    /** Ang matagumpay na sagot (2xx, JSON) bilang ProviderResult ng adapter. */
    public function readOk(array $json): ProviderResult
    {
        return $this->parse($json, []);
    }

    /** Ang hindi 2xx na sagot: ang normalized na code ng adapter (billing, invalid_credentials, rate_limited, …). */
    public function readFailure(int $status, string $body, #[\SensitiveParameter] string $key): ProviderResult
    {
        return $this->httpFailure(new Response(new PsrResponse($status, ['Content-Type' => 'application/json'], $body)), $key, []);
    }
}
