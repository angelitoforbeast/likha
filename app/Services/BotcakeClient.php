<?php

namespace App\Services;

use App\Support\BotcakeClock;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Botcake public API. Hanggang 2 request kada segundo (kasama ang retry); sa 429, 5xx o putol na koneksyon:
 * hintay 2, 4, 8 segundo (max 3 retry), saka BotcakeException. Ang token ay nasa header lang: hindi ito
 * kasama sa anumang mensahe ng error o log. Ang test page (BotcakeMock: botcake.mock = 1 at page_id na 99…) ay sinasagot
 * dito mismo, walang HTTP, pero dumadaan pa rin sa parehong throttle, retry at mga failed na daan.
 */
class BotcakeClient
{
    public const BASE = 'https://botcake.io/api/public_api/v1';
    public const MIN_INTERVAL = 0.5; // ang default: max 2 kada segundo (services.botcake.rate_per_page)
    public const MAX_RETRIES = 3;

    private ?float $lastRequestAt = null;
    private int $retries = self::MAX_RETRIES;
    private int $timeout = 30;
    private ?string $rateKey = null;
    private float $maxRetryAfter = 60;

    public function __construct(private BotcakeClock $clock)
    {
    }

    /**
     * Para sa mga job na tig-iisang mensahe (chatblast): ang 2 request kada segundo ay binabantayan sa cache para sa
     * $rateKey (hal. hash ng token ng page), kaya hati ang lahat ng job ng parehong page; 15 s kada request, at ang
     * Retry-After ay hanggang 10 s, para kasya ang tatlong hakbang sa timeout ng job.
     */
    public function shared(string $rateKey, int $timeout = 15): static
    {
        $client = clone $this;
        $client->rateKey = $rateKey;
        $client->timeout = $timeout;
        $client->maxRetryAfter = 10;

        return $client;
    }

    /**
     * Isang GET o POST (JSON) na may parehong retry (429, 5xx, putol na koneksyon: 2, 4, 8 s) at rate. Hindi nagtatapon
     * para sa HTTP na sagot: ibinabalik ang code (0 = walang koneksyon), ang body at ilang subok, para maitala ng tumawag.
     * Ang 401/403 ay ibinabalik agad. Ang token ay nasa header lang.
     * $onlyRateLimit: para sa POST na hindi ligtas ulitin (hal. send_flow): ang 429 lang ang inuulit (tinanggihan ng
     * Botcake, walang naipadala); ang 5xx o walang sagot ay maaaring natanggap na, kaya ibinabalik agad.
     * @return array{http:int, body:mixed, attempts:int}
     */
    public function call(string $method, string $path, array $data, string $token, bool $onlyRateLimit = false): array
    {
        for ($attempt = 0; ; $attempt++) {
            $this->throttle();

            try {
                if (($res = $this->mock($method, $path, $data)) === null) {
                    $req = Http::withHeaders(['access-token' => $token])->acceptJson()->connectTimeout(10)->timeout($this->timeout);
                    $res = $method === 'GET' ? $req->get(self::BASE . $path, $data) : $req->post(self::BASE . $path, $data);
                }
                $status = $res->status();
            } catch (ConnectionException $e) {
                $res = null;
                $status = 0;
            }

            $wait = self::retryWait($attempt, $status, $res?->header('Retry-After'), $onlyRateLimit, $this->retries, $this->maxRetryAfter);
            if ($wait === null) {
                return ['http' => $status, 'body' => $res ? ($res->json() ?? $res->body()) : null, 'attempts' => $attempt + 1];
            }
            $this->clock->sleep($wait);
        }
    }

    /**
     * Ang patakaran ng ulit ng call() (pareho sa botcake:send-pool): ilang segundo bago ulitin ang subok na $attempt
     * (0 = una), o null kapag hindi na inuulit. Ang 429, 5xx at walang sagot ay inuulit (ang 429 lang kapag $onlyRateLimit),
     * hanggang $retries: 2, 4, 8 s, o ang Retry-After kapag mas mahaba (hanggang $maxRetryAfter).
     */
    public static function retryWait(int $attempt, int $status, ?string $retryAfter, bool $onlyRateLimit, int $retries = self::MAX_RETRIES, float $maxRetryAfter = 10): ?float
    {
        $retryable = $onlyRateLimit ? $status === 429 : ($status === 0 || $status === 429 || $status >= 500);
        if (!$retryable || $attempt >= $retries) return null;
        $wait = 2 ** ($attempt + 1); // 2, 4, 8
        if (is_numeric($retryAfter) && (float) $retryAfter > $wait) $wait = min((float) $retryAfter, $maxRetryAfter);

        return (float) $wait;
    }

    /** Segundo sa pagitan ng mga request ng isang page (token): 1 / services.botcake.rate_per_page. */
    public static function interval(): float
    {
        $rate = (float) config('services.botcake.rate_per_page', 1 / self::MIN_INTERVAL);

        return $rate > 0 ? 1 / $rate : self::MIN_INTERVAL;
    }

    /** Ang cache key ng huling request ng isang page (kahati ng botcake:send-pool). */
    public static function rateCacheKey(string $rateKey): string
    {
        return 'botcake-rate:' . $rateKey;
    }

    /** Ang sagot ng BotcakeMock (test page) nang walang hintay, para sa pool na may sariling timer; null = totoong Botcake. */
    public static function mockAnswer(string $method, string $path, array $data): ?array
    {
        return BotcakeMock::answer($method, $path, $data, new class extends BotcakeClock { public function sleep(float $seconds): void {} });
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
            throw new BotcakeException('Botcake: token refused (success:false)', true);
        }
        if (!is_array($body) || !array_is_list($body)) {
            throw new BotcakeException('Botcake: the customer list answer is not a list');
        }

        return $body;
    }

    /**
     * Ang sagot ng chatbot sa customer (send_content, sa loob ng 24 oras ng Messenger, walang message tag). Ang 429 lang
     * ang inuulit: ang 5xx o walang sagot ay maaaring natanggap na, kaya hindi inuulit (walang dobleng sagot).
     * @param array<int, array{type: string, text?: string, url?: string}> $messages
     * @return array{http: int, body: mixed, attempts: int}
     */
    public function sendContent(string $pageId, string $psid, array $messages, string $token): array
    {
        return $this->call('POST', '/pages/' . rawurlencode($pageId) . '/flows/send_content', [
            'psid' => $psid, 'data' => ['version' => 'v2', 'content' => ['messages' => $messages]],
        ], $token, true);
    }

    /** Ang sagot ng Botcake kapag wala ang PSID sa page. */
    public const NOT_ON_PAGE = ['not found customer', 'psid wrong format'];

    /**
     * Nasa page ba ang PSID (customer info)? 'found'; 'not_found' ("Not found customer" o "psid wrong format"); 'down'
     * (walang koneksyon, timeout o 5xx: hindi nalaman); 'refused' (anumang ibang sagot: token, 429, 404, iba). Gamitin
     * kasama ang shared() at once() para sa web.
     */
    public function customerInfo(string $pageId, string $psid, string $token): string
    {
        $r = $this->call('GET', '/pages/' . rawurlencode($pageId) . '/customer/' . rawurlencode($psid) . '/info', [], $token);
        $body = is_array($r['body']) ? $r['body'] : [];
        if ($r['http'] === 200 && ($body['success'] ?? null) === true) return 'found';
        if (($body['success'] ?? null) === false && in_array(mb_strtolower(trim((string) ($body['message'] ?? ''))), self::NOT_ON_PAGE, true)) return 'not_found';
        if ($r['http'] === 0 || $r['http'] >= 500) return 'down';

        return 'refused';
    }

    /** Isang mababang tawag (custom_fields) para subukan ang token. False = invalid. */
    public function testToken(string $pageId, string $token): bool
    {
        return $this->tokenAndFields($pageId, $token)['ok'];
    }

    /**
     * Ang parehong tawag ng testToken, kasama ang mga custom field ng page (id at pangalan), para sa auto-detect ng
     * message field nang walang dagdag na tawag. fields = null kapag invalid o hindi listahan ang sagot.
     * @return array{ok: bool, fields: ?array<int, array{id: string, name: string}>}
     */
    public function tokenAndFields(string $pageId, string $token): array
    {
        try {
            $body = $this->get('/pages/' . rawurlencode($pageId) . '/custom_fields', ['page' => 1], $token)->json();
        } catch (BotcakeException $e) {
            if ($e->auth) return ['ok' => false, 'fields' => null];
            throw $e;
        }

        $ok = is_array($body) && ($body['success'] ?? null) === true;
        $list = $ok ? ($body['data'] ?? null) : null;
        if (!is_array($list) || !array_is_list($list)) return ['ok' => $ok, 'fields' => null];

        $fields = [];
        foreach ($list as $f) {
            if (!is_array($f) || !isset($f['id']) || !is_scalar($f['id']) || !is_string($f['name'] ?? null)) continue;
            $fields[] = ['id' => (string) $f['id'], 'name' => mb_substr(trim($f['name']), 0, 255)];
        }

        return ['ok' => true, 'fields' => $fields];
    }

    protected function get(string $path, array $query, string $token): Response
    {
        for ($attempt = 0; ; $attempt++) {
            $this->throttle();

            try {
                $res = $this->mock('GET', $path, $query) ?? Http::withHeaders(['access-token' => $token])
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
                throw new BotcakeException('Botcake: HTTP ' . ($status ?: 'connection error') . ($attempt ? ' after ' . $attempt . ' retries' : ''), false, $status === 429 ? 'rate_limited' : 'error');
            }

            $wait = 2 ** ($attempt + 1); // 2, 4, 8
            $after = $res?->header('Retry-After');
            if (is_numeric($after) && (float) $after > $wait) {
                $wait = min((float) $after, 60);
            }
            $this->clock->sleep((float) $wait);
        }
    }

    /** Ang sagot ng BotcakeMock bilang totoong Response (test page lang), o null para sa totoong Botcake. */
    private function mock(string $method, string $path, array $data): ?Response
    {
        $a = BotcakeMock::answer($method, $path, $data, $this->clock);

        return $a === null ? null : new Response(new \GuzzleHttp\Psr7\Response($a[0], ['Content-Type' => 'application/json'], json_encode($a[1])));
    }

    private function throttle(): void
    {
        if ($this->rateKey !== null) {
            $key  = self::rateCacheKey($this->rateKey);
            $last = Cache::get($key);
            if (is_numeric($last)) {
                $wait = (float) $last + self::interval() - $this->clock->now();
                if ($wait > 0) $this->clock->sleep($wait);
            }
            Cache::put($key, $this->clock->now(), 60);
            return;
        }

        if ($this->lastRequestAt !== null) {
            $wait = $this->lastRequestAt + self::interval() - $this->clock->now();
            if ($wait > 0) $this->clock->sleep($wait);
        }
        $this->lastRequestAt = $this->clock->now();
    }
}
