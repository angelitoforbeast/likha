<?php

namespace App\Services\Chatbot;

use App\Models\BotcakeChat;
use App\Models\PageSetting;
use App\Services\BotcakeChatBlock;
use App\Services\BotcakeClient;
use App\Services\BotcakeCustomerSync;
use App\Services\BotcakeException;
use App\Services\PageSettings;
use Throwable;

/**
 * Ang Connections ng isang page sa /chatbot/pages: Botcake at Forbeast (Page ID at token). Ang token ay sinusubukan muna
 * (walang isinusulat kapag hindi gumana o ibang page), saka tinitingnan kung nakakabit na ang page sa ibang site (Botcake
 * Default Reply na JSON API sa ibang host, o Forbeast webhook sa ibang URL): kailangan ng confirm bago ilipat. Pumasa:
 * isinusulat sa PageSettings (may user), saka ang chat block (Botcake, parehong chat.key) o ang webhook (Forbeast).
 * Ang token ay huling 4 lang sa anumang ibinabalik.
 */
class ChatbotConnections
{
    public function __construct(private PageSettings $settings, private ForbeastPageSetup $forbeast)
    {
    }

    /** Ang dalawang card ng popup (walang secret). */
    public function info(string $page): array
    {
        $pageId = $this->botcakePageId($page);
        $f = $this->forbeast->info($page);

        return [
            'page' => $page,
            'botcake' => ['page_id' => $pageId, 'token' => self::masked(trim((string) $this->settings->get($page, 'botcake', 'token'))),
                'status' => $this->settings->get($page, 'botcake', 'token_status'), 'chat_block' => $this->hasChatBlock($page),
                'connected' => $this->botcakeConnected($page)],
            'forbeast' => ['page_id' => $pageId, 'token' => $f['token'], 'status' => $f['status'], 'connected' => $f['connected']],
        ];
    }

    /** Kumpleto ang Botcake: may Page ID, ok ang huling token test, at may chat block (chat.key). */
    public function botcakeConnected(string $page): bool
    {
        return $this->botcakePageId($page) !== '' && trim((string) $this->settings->get($page, 'botcake', 'token')) !== ''
            && str_starts_with((string) $this->settings->get($page, 'botcake', 'token_status'), 'ok') && $this->hasChatBlock($page);
    }

    /** @return array<int, string> ang mga kumpletong koneksyon: "Botcake", "Forbeast" */
    public function connected(string $page): array
    {
        return array_values(array_filter([$this->botcakeConnected($page) ? 'Botcake' : null, $this->forbeast->info($page)['connected'] ? 'Forbeast' : null]));
    }

    /**
     * I-save ang Botcake Page ID at token (blangko = ang naka-save), pagkatapos ng token test at ng takeover check; saka
     * ang chat block (BotcakeChatBlock::turnOn, parehong key).
     * @return array{http: int, body: array<string, mixed>}
     */
    public function saveBotcake(string $page, string $pageId, string $token, bool $confirm, ?int $userId): array
    {
        $pageId = $pageId !== '' ? $pageId : $this->botcakePageId($page);
        $token = $token !== '' ? $token : trim((string) $this->settings->get($page, 'botcake', 'token'));
        if ($pageId === '' || $token === '') return $this->fail(422, 'error', 'A Botcake Page ID and a token are needed.');
        if ($errors = $this->settings->validate($page, 'botcake', 'page_id', $pageId)) return $this->fail(422, 'error', reset($errors));
        // Isang Botcake ID, isang page: ang pangalawang page na may parehong ID ay aagaw sa mga chat ng una.
        $owner = PageSetting::where('provider', 'botcake')->where('key', 'page_id')->where('active', true)->where('value', $pageId)
            ->pluck('page_name')->first(fn ($p) => $this->settings->pageKey($p) !== $this->settings->pageKey($page));
        if ($owner !== null) return $this->fail(422, 'error', "Botcake ID {$pageId} is already used by {$owner}.");

        $client = app(BotcakeClient::class)->once();
        try {
            $test = $client->tokenAndFields($pageId, $token);
        } catch (Throwable $e) {
            if (!$e instanceof BotcakeException) report($e);
            return $this->fail(502, 'error', $e instanceof BotcakeException ? $e->getMessage() : 'Botcake did not answer.');
        }
        if (!$test['ok']) return $this->fail(422, 'invalid', "This token does not work for Botcake page {$pageId}. Nothing was saved.");

        if (!$confirm && ($ask = $this->takeover($client, $pageId, $token)) !== null) return $ask;

        if ($pageId !== $this->botcakePageId($page)) {
            $this->settings->set($page, 'botcake', 'page_id', $pageId, false, $userId);
            BotcakeChat::forgetPageMap();
            // Ang Forbeast webhook ay nasa lumang Facebook page: hindi na konektado hanggang i-save ulit ang Forbeast token.
            $this->settings->pageQuery($page)->where('provider', ForbeastPageSetup::PROVIDER)->where('key', 'token_status')->where('active', true)
                ->get()->each(fn ($s) => $this->settings->deactivate($s, $userId));
        }
        $this->settings->set($page, 'botcake', 'token', $token, true, $userId);
        app(BotcakeCustomerSync::class)->tokenStatus($page, 'ok', true);

        return ['http' => 200, 'body' => ['status' => 'ok', 'chat_block' => $this->chatBlock($page, $userId)]];
    }

    /**
     * "Fix chat block": ang takeover check muna (maliban kung may confirm), saka isulat ulit ang block at i-publish,
     * parehong chat.key.
     * @return array{http: int, body: array<string, mixed>}
     */
    public function fixChatBlock(string $page, bool $confirm, ?int $userId): array
    {
        $pageId = $this->botcakePageId($page);
        $token = trim((string) $this->settings->get($page, 'botcake', 'token'));
        if (!$confirm && $pageId !== '' && $token !== '' && ($ask = $this->takeover(app(BotcakeClient::class)->once(), $pageId, $token)) !== null) return $ask;

        return ['http' => 200, 'body' => ['status' => 'ok', 'chat_block' => $this->chatBlock($page, $userId)]];
    }

    /** Isulat ang block at i-publish (BotcakeChatBlock::turnOn, parehong chat.key). */
    public function chatBlock(string $page, ?int $userId): array
    {
        try {
            $r = app(BotcakeChatBlock::class)->turnOn($page, false, $userId);
        } catch (Throwable $e) {
            report($e);
            $r = ['ok' => false, 'error' => 'Could not set up the chat block: Botcake did not answer.'];
        }

        return ['ok' => $r['ok'], 'error' => $r['error']];
    }

    /**
     * I-save ang Forbeast token pagkatapos ng probe: ang pangalan at id ng page sa Forbeast ay dapat ito ring page, at ang
     * webhook sa ibang site ay kailangan ng confirm. Saka ForbeastPageSetup::connect (webhook at status).
     * @return array{http: int, body: array<string, mixed>}
     */
    public function saveForbeast(string $page, string $pageId, string $token, bool $confirm, ?int $userId): array
    {
        $botcakeId = $this->botcakePageId($page);
        $pageId = $pageId !== '' ? $pageId : $botcakeId;
        // Ang mga event ng Forbeast ay itinatapat sa page ayon sa Botcake ID (iisang Facebook page id).
        if ($pageId !== $botcakeId) return $this->fail(422, 'error', "The Forbeast Page ID must be this page's Facebook page id ({$botcakeId}), the same as the Botcake ID.");
        $token = $token !== '' ? $token : $this->forbeast->token($page);
        if ($token === '') return $this->fail(422, 'error', 'Paste the Forbeast token.');

        $probe = $this->forbeast->probe($pageId, $token);
        if ($probe['status'] !== 'ok') return $this->fail($probe['status'] === 'invalid' ? 422 : 502, $probe['status'], $probe['error'] . '. Nothing was saved.');
        $sameName = $probe['name'] === null || $this->settings->pageKey($this->squash($probe['name'])) === $this->settings->pageKey($this->squash($page));
        if (!$sameName || ($probe['id'] !== null && $probe['id'] !== $pageId)) {
            return ['http' => 422, 'body' => ['status' => 'mismatch', 'name' => $probe['name'], 'id' => $probe['id'],
                'error' => 'This token is for "' . ($probe['name'] ?? '?') . '" (' . ($probe['id'] ?? '?') . '), not this page. Nothing was saved.']];
        }
        $ours = rtrim((string) config('app.url'), '/') . '/api/gateway/events';
        if (!$confirm && $probe['webhook'] !== null && rtrim($probe['webhook'], '/') !== $ours) {
            return $this->askMove((string) (parse_url($probe['webhook'], PHP_URL_HOST) ?: $probe['webhook']));
        }

        $this->settings->set($page, ForbeastPageSetup::PROVIDER, 'token', $token, true, $userId);
        $res = $this->forbeast->connect($page);

        return ['http' => $res['status'] === 'ok' ? 200 : 502, 'body' => ['status' => $res['status'], 'name' => $res['name'], 'error' => $res['error']]];
    }

    /**
     * Ang Default Reply ng page ayon sa Botcake: 'ours' (may JSON API block papunta sa app na ito), 'none' (walang JSON
     * API block), 'unknown' (walang sagot o kakaibang sagot), o ang host ng ibang site.
     */
    public function botcakeReply(BotcakeClient $client, string $pageId, string $token): string
    {
        $r = $client->call('GET', '/pages/' . rawurlencode($pageId) . '/default_reply', [], $token);
        if ($r['http'] < 200 || $r['http'] >= 300 || !is_array($r['body']) || ($r['body']['success'] ?? null) === false) return 'unknown';
        $ours = mb_strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $state = 'none';
        foreach (self::jsonApiUrls($r['body']) as $url) {
            $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host === '') return 'unknown'; // walang mabasang host: hindi alam kung saan papunta
            if ($host !== $ours) return mb_substr($host, 0, 255);
            $state = 'ours';
        }

        return $state;
    }

    /** Ang tanong bago isulat ang block, o null kapag walang ibang site. Hindi masuri = tinatanong din. */
    private function takeover(BotcakeClient $client, string $pageId, string $token): ?array
    {
        $state = $this->botcakeReply($client, $pageId, $token);
        if ($state === 'ours' || $state === 'none') return null;
        if ($state === 'unknown') return ['http' => 409, 'body' => ['status' => 'confirm', 'host' => null,
            'message' => "Could not check whether this page's Default Reply points to another site. Replace it anyway?"]];

        return $this->askMove($state);
    }

    /** @return array<int, string> ang config.url ng bawat card na plugin_id = json_api, saan man sa sagot */
    private static function jsonApiUrls(array $node, int $depth = 0): array
    {
        if ($depth > 12) return [];
        $out = [];
        if (($node['plugin_id'] ?? null) === 'json_api' && is_string($node['config']['url'] ?? null)) $out[] = $node['config']['url'];
        foreach ($node as $child) if (is_array($child)) array_push($out, ...self::jsonApiUrls($child, $depth + 1));

        return $out;
    }

    private function askMove(string $host): array
    {
        return ['http' => 409, 'body' => ['status' => 'confirm', 'host' => $host, 'message' => "Connected to {$host} now. Move it to this site?"]];
    }

    private function fail(int $http, string $status, string $error): array
    {
        return ['http' => $http, 'body' => ['status' => $status, 'error' => $error]];
    }

    private function hasChatBlock(string $page): bool
    {
        return trim((string) $this->settings->get($page, 'botcake', 'chat.key')) !== '';
    }

    private function botcakePageId(string $page): string
    {
        return trim((string) $this->settings->get($page, 'botcake', 'page_id'));
    }

    private function squash(string $s): string
    {
        return (string) preg_replace('/\s+/u', '', $s);
    }

    private static function masked(string $token): ?string
    {
        // Gaya ng PageSetting::displayValue: •••• at ang huling 4 lang.
        return $token === '' ? null : '••••' . (mb_strlen($token) > 4 ? mb_substr($token, -4) : '');
    }
}
