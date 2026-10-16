<?php

namespace App\Services;

use App\Support\BotcakeClock;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Botcake public API. Hanggang 2 request kada segundo (kasama ang retry); sa 429, 5xx o putol na koneksyon:
 * hintay 2, 4, 8 segundo (max 3 retry), saka BotcakeException. Ang token ay nasa header lang: hindi ito
 * kasama sa anumang mensahe ng error o log.
 */
class BotcakeClient
{
    public const BASE = 'https://botcake.io/api/public_api/v1';
    public const MIN_INTERVAL = 0.5; // segundo sa pagitan ng mga request = max 2 kada segundo
    public const MAX_RETRIES = 3;

    private ?float $lastRequestAt = null;
    private int $retries = self::MAX_RETRIES;
    private int $timeout = 30;

    public function __construct(private BotcakeClock $clock)
    {
    }

    /**
     * Isang subok lang, walang retry, at mas maikling timeout: para sa web request na tumatawag sa Botcake
     * (hal. "Test lahat ng token", kada page), para hindi lumampas nang ~30 segundo ang isang request.
     */
    public function once(int $timeout = 20): static
    {
        $client = clone $this;
        $client->retries = 0;
        $client->timeout = $timeout;

        return $client;
    }

    /**
     * Isang page ng customer list (10 kada tawag, pinakabago muna). [] = lampas na sa huli.
     * @return array<int, array<string, mixed>>
     */
    public function customers(string $pageId, string $token, int $page): array
    {
        $body = $this->get('/pages/' . rawurlencode($pageId) . '/customer', ['page' => $page], $token)->json();

        if (is_array($body) && array_key_exists('success', $body) && $body['success'] === false) {
            throw new BotcakeException('Botcake: tinanggihan ang token (success:false)', true);
        }
        if (!is_array($body) || !array_is_list($body)) {
            throw new BotcakeException('Botcake: hindi listahan ang sagot ng customer list');
        }

        return $body;
    }

    /** Isang mababang tawag (custom_fields) para subukan ang token. False = invalid. */
    public function testToken(string $pageId, string $token): bool
    {
        try {
            $body = $this->get('/pages/' . rawurlencode($pageId) . '/custom_fields', ['page' => 1], $token)->json();
        } catch (BotcakeException $e) {
            if ($e->auth) return false;
            throw $e;
        }

        return is_array($body) && ($body['success'] ?? null) === true;
    }

    protected function get(string $path, array $query, string $token): Response
    {
        for ($attempt = 0; ; $attempt++) {
            $this->throttle();

            try {
                $res = Http::withHeaders(['access-token' => $token])
                    ->acceptJson()
                    ->connectTimeout(10)
                    ->timeout($this->timeout)
                    ->get(self::BASE . $path, $query);
                $status = $res->status();
            } catch (ConnectionException $e) {
                $res = null;
                $status = 0;
            }

            if ($status === 401 || $status === 403) {
                throw new BotcakeException('Botcake: HTTP ' . $status . ' (token)', true);
            }
            if ($res && $res->successful()) {
                return $res;
            }

            $retryable = $status === 0 || $status === 429 || $status >= 500;
            if (!$retryable) {
                throw new BotcakeException('Botcake: HTTP ' . $status);
            }
            if ($attempt >= $this->retries) {
                throw new BotcakeException('Botcake: HTTP ' . ($status ?: 'connection error') . ($attempt ? ' pagkatapos ng ' . $attempt . ' retry' : ''), false, $status === 429 ? 'rate_limited' : 'error');
            }

            $wait = 2 ** ($attempt + 1); // 2, 4, 8
            $after = $res?->header('Retry-After');
            if (is_numeric($after) && (float) $after > $wait) {
                $wait = min((float) $after, 60);
            }
            $this->clock->sleep((float) $wait);
        }
    }

    private function throttle(): void
    {
        if ($this->lastRequestAt !== null) {
            $wait = $this->lastRequestAt + self::MIN_INTERVAL - $this->clock->now();
            if ($wait > 0) $this->clock->sleep($wait);
        }
        $this->lastRequestAt = $this->clock->now();
    }
}
