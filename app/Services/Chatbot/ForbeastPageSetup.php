<?php

namespace App\Services\Chatbot;

use App\Models\PageSetting;
use App\Services\BotcakeClient;
use App\Services\ChatbotFields;
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
 * DELETE (subok lang) at tinatanggal ang token, secret at status. Ang on('incepxionbot') ay ang parehong setup sa
 * pangalawang gateway: ang URL nito, ang mga row na incepxionbot.*, at ang webhook na /api/gateway/events/incepxionbot.
 */
class ForbeastPageSetup
{
    public const PROVIDER = 'forbeast';
    public const NEEDS_TOKEN = 'Add the Forbeast token first';
    private const TIMEOUT = 15;

    /** Ang gateway na sakop (Gateways): ang mga row ng page ay <gateway>.token, …; ang default ay forbeast. */
    private string $gateway = Gateways::FORBEAST;

    public function __construct(private PageSettings $settings)
    {
    }

    /** Ang parehong setup para sa ibang gateway (ang sariling URL, mga row at webhook nito). */
    public function on(string $gateway): static
    {
        $c = clone $this;
        $c->gateway = Gateways::valid($gateway) ? $gateway : Gateways::FORBEAST;

        return $c;
    }

    public function gateway(): string
    {
        return $this->gateway;
    }

    /**
     * @return array{status: string, name: ?string, error: ?string, written: array<string, ?PageSetting>}
     *   status = ok | invalid | error | skipped (walang token, Botcake ID o GATEWAY_URL)
     */
    public function connect(string $page, ?int $userId = null): array
    {
        $url = $this->baseUrl($page);
        $pageId = trim((string) $this->settings->get($page, 'botcake', 'page_id'));
        $token = $this->token($page);
        if ($url === '' || $pageId === '' || $token === '') return ['status' => 'skipped', 'name' => null, 'error' => null, 'written' => []];

        $base = $url . '/v1/pages/' . rawurlencode($pageId);
        $res = $this->call(fn () => $this->http($token)->get($base));
        if ($res === null || !$res->successful()) {
            $status = $res !== null && in_array($res->status(), [401, 403, 404], true) ? 'invalid' : 'error';
            return $this->result($page, $status, null, $this->why($res), [], $userId);
        }
        $name = trim((string) ($res->json('name') ?? ''));
        $this->keepScopes($page, self::scopes($res));
        $apps = self::appStatuses($res);
        $this->keepApps($page, $apps);
        // Ang pangalan ng page sa Facebook na ibinalik na ng gateway: itinatabi, walang dagdag na tawag.
        app(FacebookName::class)->fromProbe($page, $pageId, ['status' => 'ok', 'name' => $name, 'id' => is_scalar($res->json('id')) ? (string) $res->json('id') : null], $this->gateway);
        // Ang webhook ay isinusulat sa address ng Meta app na may token ang page: ang page na naka-connect lang sa ibang
        // app (hal. Bot 2) ay tinatanggihan sa address ng Bot 1 (409). Kapag alam ng gateway ang token kada app at walang
        // gumaganang token sa kasalukuyang address, ang address na meron ang ginagamit at itinatala bilang address ng page.
        $base = $this->routeWithToken($page, $apps, $pageId) ?? $base;

        $secret = Str::random(64);
        $hook = $this->call(fn () => $this->http($token)->put($base . '/webhook',
            ['url' => Gateways::eventsUrl($this->gateway), 'secret' => $secret]));
        if ($hook === null || !$hook->successful()) return $this->result($page, 'error', $name, 'Webhook not set: ' . $this->why($hook), [], $userId);

        // Pagkatapos lang ng 2xx: ang hindi natanggap na secret ay hindi ginagamit sa pagsuri ng event.
        $written = [$this->gateway . '.webhook_secret' => $this->settings->set($page, $this->gateway, 'webhook_secret', $secret, true, null, true)['setting']];

        return $this->result($page, 'ok', $name, null, $written, $userId);
    }

    /**
     * Subok lang ng isang token bago i-save (walang isinusulat): GET /v1/pages/{id}. Ang pangalan, id at webhook URL ay
     * galing sa Forbeast (untrusted, text lang).
     * @return array{status: string, name: ?string, id: ?string, webhook: ?string, error: ?string, permissions?: ?array} status = ok | invalid | error | skipped;
     *   permissions (ok lang): ang scopes ng token, null kapag walang field
     */
    public function probe(string $pageId, string $token, ?string $page = null): array
    {
        $url = $this->baseUrl($page);
        if ($url === '' || $pageId === '' || $token === '') return ['status' => 'skipped', 'name' => null, 'id' => null, 'webhook' => null, 'error' => Gateways::label($this->gateway) . ' is not set up yet.'];

        $res = $this->call(fn () => $this->http($token)->get($url . '/v1/pages/' . rawurlencode($pageId)));
        if ($res === null || !$res->successful()) {
            $status = $res !== null && in_array($res->status(), [401, 403, 404], true) ? 'invalid' : 'error';
            return ['status' => $status, 'name' => null, 'id' => null, 'webhook' => null, 'error' => $this->why($res)];
        }
        $str = fn ($v, int $max) => is_scalar($v) && trim((string) $v) !== '' ? mb_substr(trim((string) $v), 0, $max) : null;

        return ['status' => 'ok', 'name' => $str($res->json('name'), 255), 'id' => $str($res->json('id'), 64), 'permissions' => self::scopes($res),
            'apps' => self::appStatuses($res), 'webhook' => $str($res->json('webhook.url'), 500), 'error' => null];
    }

    /**
     * Ang Test at ang gabi-gabing subok ng naka-save na token (walang bagong secret, walang PUT): ok lang kapag ang webhook
     * ng page ay papunta pa rin sa app na ito. Isinusulat ang forbeast.token_status na may oras (bagong row bawat test, may
     * user; null = system). @return string ok | invalid | error | skipped
     */
    public function retest(string $page, ?int $userId = null): string
    {
        $pageId = trim((string) $this->settings->get($page, 'botcake', 'page_id'));
        $p = $this->probe($pageId, $this->token($page), $page);
        if ($p['status'] === 'skipped') return 'skipped';
        if ($p['status'] === 'ok') {
            $this->keepScopes($page, $p['permissions']);
            $this->keepApps($page, $p['apps'] ?? null);
        }
        // Nabasa ang page (kahit nasa ibang site ang webhook): ang pangalan nito sa Facebook, kapag ito ring page ID.
        app(FacebookName::class)->fromProbe($page, $pageId, $p, $this->gateway);
        $ours = Gateways::eventsUrl($this->gateway);
        if ($p['status'] === 'ok' && rtrim((string) $p['webhook'], '/') !== $ours) {
            $p = ['status' => 'error', 'error' => $p['webhook'] === null ? 'Webhook not set' : 'Webhook points to ' . (parse_url($p['webhook'], PHP_URL_HOST) ?: 'another site')] + $p;
        }
        $this->result($page, $p['status'], $p['name'], $p['error'], [], $userId, ' · ' . now('Asia/Manila')->format('Y-m-d H:i:s'));

        return $p['status'];
    }

    /** DELETE ng webhook (subok lang: ang pagkabigo ay hindi humaharang), saka tinatanggal ang token, secret at status. */
    public function disconnect(string $page, ?int $userId = null): void
    {
        $url = $this->baseUrl($page);
        $pageId = trim((string) $this->settings->get($page, 'botcake', 'page_id'));
        $token = $this->token($page);
        if ($url !== '' && $pageId !== '' && $token !== '') {
            $this->call(fn () => $this->http($token)->delete($url . '/v1/pages/' . rawurlencode($pageId) . '/webhook'));
        }

        $this->settings->pageQuery($page)->where('provider', $this->gateway)->whereIn('key', ['token', 'webhook_secret', 'token_status', 'permissions'])
            ->where('active', true)->get()->each(fn ($s) => $this->settings->deactivate($s, $userId));
    }

    /**
     * Ang ipinapakita sa row (walang secret): huling 4 ng token, ang status, kung konektado (ok + may secret), at kung
     * mapipili ang "Replies via: Forbeast" (may GATEWAY_URL at gumaganang token ng page).
     * @return array{token: ?string, status: ?string, connected: bool, ready: bool}
     */
    public function info(string $page): array
    {
        $token = $this->token($page);
        $connected = $token !== '' && $this->ok($page) && (string) $this->settings->get($page, $this->gateway, 'webhook_secret') !== '';
        // Gaya ng PageSetting::displayValue: •••• at ang huling 4 lang.
        $shown = $token === '' ? null : '••••' . (mb_strlen($token) > 4 ? mb_substr($token, -4) : '');

        return ['token' => $shown, 'status' => $this->settings->get($page, $this->gateway, 'token_status'),
            'connected' => $connected, 'ready' => $this->ready($page)];
    }

    public function ready(string $page): bool
    {
        if ($this->baseUrl() === '') return false;
        // Sariling gumaganang token lang ng page: ang global GATEWAY_KEY ay para sa mga page na ikinabit sa Forbeast
        // system noon (Tech Flask), kaya hindi ito patunay na handa ang ibang page (Alexa Angeles, 2026-10-23).
        return $this->token($page) !== '' && $this->ok($page);
    }

    public function token(string $page): string
    {
        return trim((string) $this->settings->get($page, $this->gateway, 'token'));
    }

    private function ok(string $page): bool
    {
        return str_starts_with((string) $this->settings->get($page, $this->gateway, 'token_status'), 'ok');
    }

    /** Ang URL ng gateway; may $page: ang address ng page (chatbot.gateway_app), gaya ng padala. */
    /**
     * Ang /v1/pages/{id} ng address na may gumaganang token ang page, kapag ang kasalukuyang address ng page ay wala; null
     * kapag ayos na ang kasalukuyan, hindi alam ($apps null), iisa ang address, o walang address na may token.
     */
    private function routeWithToken(string $page, ?array $apps, string $pageId): ?string
    {
        $routes = Gateways::routes();
        if ($this->gateway !== Gateways::FORBEAST || $apps === null || count($routes) < 2) return null;
        $current = Gateways::route($page);
        if (($apps[$current] ?? null) === 'ok') return null;
        foreach (array_keys($routes) as $key) {
            if (($apps[$key] ?? null) !== 'ok') continue;
            $this->settings->set($page, ChatbotFields::PROVIDER, Gateways::ROUTE_KEY, $key, false, null, true);

            return $routes[$key] . '/v1/pages/' . rawurlencode($pageId);
        }

        return null;
    }

    private function baseUrl(?string $page = null): string
    {
        if ($page === null || Gateways::url($this->gateway) === '') return Gateways::url($this->gateway);

        return Gateways::pageUrl($this->gateway, $page);
    }

    private function http(string $token)
    {
        return Http::withToken($token)->acceptJson()->withOptions(Gateways::httpOptions())->connectTimeout(5)->timeout(self::TIMEOUT);
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

    /**
     * Ang "permissions" ng page check (listahan ng scope ng token; untrusted, maiikling text lang), o null kapag wala ang
     * field (ang lumang Forbeast): hindi alam, hindi "walang permission".
     * @return array<int, string>|null
     */
    private static function scopes(Response $res): ?array
    {
        $list = $res->json('permissions');
        if (!is_array($list)) return null;
        $out = [];
        foreach (array_slice(array_values($list), 0, 50) as $s) {
            if (is_string($s) && preg_match('/^[a-z0-9_]{1,100}$/', trim($s))) $out[] = trim($s);
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /**
     * Itinatabi ang scopes sa forbeast.permissions (JSON), para sa Check ng mga comment column; isinusulat lang kapag
     * nagbago. null = tinatanggal (hindi alam).
     */
    private function keepScopes(string $page, ?array $scopes): void
    {
        if ($scopes !== null) {
            $this->settings->set($page, $this->gateway, 'permissions', json_encode($scopes), false, null, true);
            return;
        }
        $this->settings->pageQuery($page)->where('provider', $this->gateway)->where('key', 'permissions')->where('active', true)
            ->get()->each(fn ($s) => $this->settings->deactivate($s));
    }

    /**
     * Ang token ng page kada Meta app ng gateway (ang "apps" ng page check: {key: ok | reconnect_needed | null}), o null
     * kapag wala ang field (ang lumang gateway): hindi alam. Untrusted: maiikling key at kilalang status lang.
     * @return array<string, ?string>|null
     */
    public static function appStatuses(Response $res): ?array
    {
        $apps = $res->json('apps');
        if (!is_array($apps)) return null;
        $out = [];
        foreach (array_slice($apps, 0, 10, true) as $key => $status) {
            if (!is_string($key) || !preg_match('/^[a-z0-9_]{1,30}$/', $key)) continue;
            $out[$key] = in_array($status, ['ok', 'reconnect_needed'], true) ? $status : null;
        }
        ksort($out);

        return $out;
    }

    /** Itinatabi ang "apps" sa <gateway>.apps (JSON) ng page; isinusulat lang kapag nagbago. null (hindi alam): walang galaw. */
    public function keepApps(string $page, ?array $apps): void
    {
        if ($apps === null) return;
        $this->settings->set($page, $this->gateway, 'apps', json_encode($apps), false, null, true);
    }

    /** @return array<string, ?string>|null ang naitabing token ng page kada Meta app ng gateway; null = hindi pa alam */
    public function apps(string $page): ?array
    {
        $v = json_decode((string) $this->settings->get($page, $this->gateway, 'apps'), true);

        return is_array($v) ? $v : null;
    }

    private function why(?Response $res): string
    {
        if ($res === null) return Gateways::label($this->gateway) . ' did not answer';
        $msg = BotcakeClient::messageText(is_array($res->json()) ? $res->json() : []);

        return ($msg !== '' ? Gateways::label($this->gateway) . ' said "' . mb_substr($msg, 0, 150) . '"' : Gateways::label($this->gateway) . ' refused it') . ' (HTTP ' . $res->status() . ')';
    }

    private function result(string $page, string $status, ?string $name, ?string $error, array $written = [], ?int $userId = null, string $suffix = ''): array
    {
        $text = $status . ' · ' . ($status === 'ok' ? Gateways::label($this->gateway) . ' connected' . ($name !== null && $name !== '' ? ' (' . $name . ')' : '') : $error);
        $written[$this->gateway . '.token_status'] = $this->settings->set($page, $this->gateway, 'token_status', mb_substr($text, 0, 250 - mb_strlen($suffix)) . $suffix, false, $userId, true)['setting'];

        return ['status' => $status, 'name' => $name, 'error' => $error, 'written' => $written];
    }
}
