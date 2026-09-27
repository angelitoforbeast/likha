<?php

namespace App\Boardroom\Providers;

use App\Boardroom\Support\Secrets;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Pinagsasaluhang HTTP plumbing ng tatlong adapter: request, timeout, at normalized na error.
 * Lahat ng tawag ay server-side; ang API key ay nasa header lang at hindi kailanman nilalagay sa
 * payload, prompt, log, o error message.
 */
abstract class HttpAdapter implements ProviderAdapter
{
    /** Mga header ng authentication para sa provider na ito. */
    abstract protected function authHeaders(string $apiKey): array;

    abstract protected function baseUrl(): string;

    abstract protected function chatPath(): string;

    /** Gawing ProviderResult ang matagumpay (2xx) na sagot. */
    abstract protected function parse(array $body, array $sent): ProviderResult;

    protected function modelsPath(): string
    {
        return '/models';
    }

    public function send(ProviderRequest $request, string $apiKey): ProviderResult
    {
        $payload = $this->buildPayload($request);
        $sent    = $this->describeSent($payload);
        $timeout = (int) ($request->params['timeout_s'] ?? config('boardroom.limits.timeout_s', 240));

        try {
            $response = $this->client($apiKey, $timeout)->post($this->baseUrl() . $this->chatPath(), $payload);
        } catch (ConnectionException $e) {
            return $this->connectionFailure($e, $apiKey, $sent);
        } catch (\Throwable $e) {
            return ProviderResult::failure('network', Secrets::safeMessage($e->getMessage(), [$apiKey]), true, null, $sent);
        }

        if (! $response->successful()) {
            return $this->httpFailure($response, $apiKey, $sent);
        }

        $body = $response->json();
        if (! is_array($body)) {
            return ProviderResult::failure('invalid_response', 'Hindi JSON ang sagot ng provider.', true, $response->status(), $sent);
        }

        $result = $this->parse($body, $sent);
        $result->httpStatus = $response->status();

        return $result;
    }

    public function listModels(string $apiKey): array
    {
        try {
            $response = $this->client($apiKey, 30)->get($this->baseUrl() . $this->modelsPath(), $this->modelsQuery());
        } catch (\Throwable $e) {
            return ['ok' => false, 'models' => [], 'error_code' => 'network', 'error' => Secrets::safeMessage($e->getMessage(), [$apiKey])];
        }

        if (! $response->successful()) {
            $fail = $this->httpFailure($response, $apiKey, []);

            return ['ok' => false, 'models' => [], 'error_code' => $fail->errorCode, 'error' => $fail->errorMessage];
        }

        $ids = [];
        foreach ((array) ($response->json('data') ?? []) as $row) {
            if (is_array($row) && ! empty($row['id'])) {
                $ids[] = (string) $row['id'];
            }
        }
        sort($ids);

        return ['ok' => true, 'models' => array_values(array_unique($ids)), 'error_code' => null, 'error' => null];
    }

    protected function modelsQuery(): array
    {
        return [];
    }

    protected function client(string $apiKey, int $timeout): PendingRequest
    {
        return Http::withHeaders($this->authHeaders($apiKey))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(15)
            ->timeout($timeout);
    }

    /** Mga parameter na naipadala, WALANG prompt text (para sa turn record at sa tests). */
    protected function describeSent(array $payload): array
    {
        unset($payload['input'], $payload['instructions'], $payload['messages'], $payload['system']);

        return $payload;
    }

    protected function connectionFailure(ConnectionException $e, string $apiKey, array $sent): ProviderResult
    {
        $msg     = $e->getMessage();
        $timeout = (bool) preg_match('/timed? ?out|cURL error 28/i', $msg);

        return ProviderResult::failure(
            $timeout ? 'timeout' : 'network',
            $timeout ? 'Nag-timeout ang request sa provider.' : Secrets::safeMessage($msg, [$apiKey]),
            true,
            null,
            $sent
        );
    }

    /** Normalized na error mula sa non-2xx na sagot. */
    protected function httpFailure(Response $response, string $apiKey, array $sent): ProviderResult
    {
        $status = $response->status();
        $body   = $response->json();
        $err    = is_array($body) ? ($body['error'] ?? $body) : [];
        $type   = is_array($err) ? (string) ($err['type'] ?? '') : '';
        $pcode  = is_array($err) ? (string) ($err['code'] ?? '') : '';
        $text   = is_array($err) ? (string) ($err['message'] ?? '') : (string) $err;
        if ($text === '') {
            $text = mb_substr((string) $response->body(), 0, 300);
        }

        [$code, $retryable] = $this->classify($status, $type, $pcode, $text);
        $message = Secrets::safeMessage("HTTP {$status}" . ($type !== '' ? " {$type}" : '') . ': ' . $text, [$apiKey]);

        return ProviderResult::failure($code, $message, $retryable, $status, $sent);
    }

    /** @return array{0: string, 1: bool} [normalized code, retryable] */
    protected function classify(int $status, string $type, string $providerCode, string $text): array
    {
        $hay = strtolower($type . ' ' . $providerCode . ' ' . $text);

        if (str_contains($hay, 'insufficient_quota') || str_contains($hay, 'insufficient balance') || str_contains($hay, 'billing')) {
            return ['billing', false];
        }

        return match (true) {
            $status === 401                     => ['invalid_credentials', false],
            $status === 402                     => ['billing', false],
            $status === 403                     => ['permission_denied', false],
            $status === 404                     => ['model_not_found', false],
            $status === 408                     => ['timeout', true],
            $status === 413                     => ['request_too_large', false],
            $status === 429                     => ['rate_limited', true],
            $status === 529                     => ['overloaded', true],
            $status >= 500                      => ['provider_error', true],
            $status === 400 || $status === 422  => [$this->classifyBadRequest($hay), false],
            default                             => ['provider_error', false],
        };
    }

    protected function classifyBadRequest(string $hay): string
    {
        if (preg_match('/model.{0,40}(not (found|exist)|does not exist|not supported|invalid)|(unknown|invalid) model/', $hay)) {
            return 'model_not_found';
        }
        if (preg_match('/unsupported (parameter|value)|not supported|unknown parameter|unrecognized|extra inputs|invalid.{0,20}(reasoning|effort|thinking)/', $hay)) {
            return 'unsupported_parameter';
        }
        if (preg_match('/context (length|window)|too many tokens|maximum context|prompt is too long/', $hay)) {
            return 'context_too_long';
        }

        return 'invalid_request';
    }
}
