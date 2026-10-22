<?php

namespace App\Services\Chatbot;

use App\Models\PageSetting;
use App\Services\BotcakeClient;
use App\Services\PageSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ang Forbeast ng isang page, gaya ng Botcake token: ang forbeast.token (secret, sariling page lang ang sakop) ay sinusubukan
 * sa GET /v1/pages/{id} (id = botcake.page_id). Kapag ok: bagong webhook secret ng page (forbeast.webhook_secret) at
 * PUT /v1/pages/{id}/webhook, para ang mga event ng page ay dumating sa /api/gateway/events na pinirmahan ng secret na iyon.
 * Ang resulta ay nasa forbeast.token_status ("ok · Forbeast connected (<pangalan>)", "invalid · …" o "error · …"). Ang pagbura ng token ay
 * DELETE (subok lang) at tinatanggal ang token, secret at status.
 */
class ForbeastPageSetup
{
    public const PROVIDER = 'forbeast';
    public const NEEDS_TOKEN = 'Add the Forbeast token first';
    private const TIMEOUT = 15;

    public function __construct(private PageSettings $settings)
    {
    }

    /**
     * @return array{status: string, name: ?string, error: ?string, written: array<string, ?PageSetting>}
     *   status = ok | invalid | error | skipped (walang token, Botcake ID o GATEWAY_URL)
     */
    public function connect(string $page): array
    {
        $url = $this->baseUrl();
        $pageId = trim((string) $this->settings->get($page, 'botcake', 'page_id'));
        $token = $this->token($page);
        if ($url === '' || $pageId === '' || $token === '') return ['status' => 'skipped', 'name' => null, 'error' => null, 'written' => []];

        $base = $url . '/v1/pages/' . rawurlencode($pageId);
        $res = $this->call(fn () => $this->http($token)->get($base));
        if ($res === null || !$res->successful()) {
            $status = $res !== null && in_array($res->status(), [401, 403, 404], true) ? 'invalid' : 'error';
            return $this->result($page, $status, null, self::why($res));
        }
        $name = trim((string) ($res->json('name') ?? ''));

        $secret = Str::random(64);
        $hook = $this->call(fn () => $this->http($token)->put($base . '/webhook',
            ['url' => rtrim((string) config('app.url'), '/') . '/api/gateway/events', 'secret' => $secret]));
        if ($hook === null || !$hook->successful()) return $this->result($page, 'error', $name, 'Webhook not set: ' . self::why($hook));

        // Pagkatapos lang ng 2xx: ang hindi natanggap na secret ay hindi ginagamit sa pagsuri ng event.
        $written = ['forbeast.webhook_secret' => $this->settings->set($page, self::PROVIDER, 'webhook_secret', $secret, true, null, true)['setting']];

        return $this->result($page, 'ok', $name, null, $written);
    }

    /** DELETE ng webhook (subok lang: ang pagkabigo ay hindi humaharang), saka tinatanggal ang token, secret at status. */
    public function disconnect(string $page, ?int $userId = null): void
    {
        $url = $this->baseUrl();
        $pageId = trim((string) $this->settings->get($page, 'botcake', 'page_id'));
        $token = $this->token($page);
        if ($url !== '' && $pageId !== '' && $token !== '') {
            $this->call(fn () => $this->http($token)->delete($url . '/v1/pages/' . rawurlencode($pageId) . '/webhook'));
        }

        $this->settings->pageQuery($page)->where('provider', self::PROVIDER)->whereIn('key', ['token', 'webhook_secret', 'token_status'])
            ->where('active', true)->get()->each(fn ($s) => $this->settings->deactivate($s, $userId));
    }

    /**
     * Ang ipinapakita sa row (walang secret): huling 4 ng token, ang status, kung konektado (ok + may secret), at kung
     * mapipili ang "Replies via: Forbeast" (may GATEWAY_URL, at gumaganang token o ang global GATEWAY_KEY).
     * @return array{token: ?string, status: ?string, connected: bool, ready: bool}
     */
    public function info(string $page): array
    {
        $token = $this->token($page);
        $connected = $token !== '' && $this->ok($page) && (string) $this->settings->get($page, self::PROVIDER, 'webhook_secret') !== '';
        // Gaya ng PageSetting::displayValue: •••• at ang huling 4 lang.
        $shown = $token === '' ? null : '••••' . (mb_strlen($token) > 4 ? mb_substr($token, -4) : '');

        return ['token' => $shown, 'status' => $this->settings->get($page, self::PROVIDER, 'token_status'),
            'connected' => $connected, 'ready' => $this->ready($page)];
    }

    public function ready(string $page): bool
    {
        if ($this->baseUrl() === '') return false;
        if (trim((string) config('services.gateway.key')) !== '') return true;

        return $this->token($page) !== '' && $this->ok($page);
    }

    public function token(string $page): string
    {
        return trim((string) $this->settings->get($page, self::PROVIDER, 'token'));
    }

    private function ok(string $page): bool
    {
        return str_starts_with((string) $this->settings->get($page, self::PROVIDER, 'token_status'), 'ok');
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.gateway.url'), '/');
    }

    private function http(string $token)
    {
        return Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(self::TIMEOUT);
    }

    /** null = walang sagot. */
    private function call(callable $fn): ?Response
    {
        try {
            return $fn();
        } catch (ConnectionException) {
            return null;
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }

    private static function why(?Response $res): string
    {
        if ($res === null) return 'Forbeast did not answer';
        $msg = BotcakeClient::messageText(is_array($res->json()) ? $res->json() : []);

        return ($msg !== '' ? 'Forbeast said "' . mb_substr($msg, 0, 150) . '"' : 'Forbeast refused it') . ' (HTTP ' . $res->status() . ')';
    }

    private function result(string $page, string $status, ?string $name, ?string $error, array $written = []): array
    {
        $text = $status . ' · ' . ($status === 'ok' ? 'Forbeast connected' . ($name !== null && $name !== '' ? ' (' . $name . ')' : '') : $error);
        $written['forbeast.token_status'] = $this->settings->set($page, self::PROVIDER, 'token_status', mb_substr($text, 0, 250), false, null, true)['setting'];

        return ['status' => $status, 'name' => $name, 'error' => $error, 'written' => $written];
    }
}
