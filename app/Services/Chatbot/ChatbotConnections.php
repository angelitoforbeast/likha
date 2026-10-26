<?php

namespace App\Services\Chatbot;

use App\Models\BotcakeChat;
use App\Models\PageSetting;
use App\Services\BotcakeChatBlock;
use App\Services\BotcakeClient;
use App\Services\BotcakeException;
use App\Services\PageSettings;
use Throwable;

/**
 * Ang Connections ng isang page sa /chatbot/pages: Botcake at Forbeast (Page ID at token). Ang token ay sinusubukan muna
 * (walang isinusulat kapag hindi gumana o ibang page), saka tinitingnan kung nakakabit na ang page sa ibang site (Botcake
 * Default Reply na JSON API sa ibang host, o Forbeast webhook sa ibang URL): kailangan ng confirm bago ilipat. Pumasa:
 * isinusulat sa PageSettings (may user), saka ang chat block (Botcake, parehong chat.key) o ang webhook (Forbeast).
 *
 * Talaan (para sa Changes): bawat Test ay bagong botcake.token_status / forbeast.token_status (resulta at oras, may user);
 * ang chat block (sinuri, isinulat o pumalya) ay botcake.chat_block; ang naka-on na Botcake keyword ay botcake.keywords.
 * Ang token ay huling 4 lang sa anumang ibinabalik.
 */
class ChatbotConnections
{
    /** Ang chat block na hindi gumagana: wala, luma ang key, hindi published, nasa ibang site, o pumalya ang sulat. */
    private const BLOCK_BAD = ['missing', 'unpublished', 'elsewhere', 'failed'];

    public function __construct(private PageSettings $settings, private ForbeastPageSetup $forbeast)
    {
    }

    /** Ang dalawang card ng popup (walang secret), kasama ang oras ng huling test. */
    public function info(string $page): array
    {
        $pageId = $this->botcakePageId($page);
        $f = $this->forbeast->info($page);

        return [
            'page' => $page,
            'botcake' => ['page_id' => $pageId, 'token' => self::masked(trim((string) $this->settings->get($page, 'botcake', 'token'))),
                'status' => $this->settings->get($page, 'botcake', 'token_status'), 'tested_at' => $this->testedAt($page, 'botcake'),
                'chat_block' => $this->hasChatBlock($page), 'chat_block_status' => $this->settings->get($page, 'botcake', 'chat_block'),
                'keywords_status' => $this->settings->get($page, 'botcake', 'keywords'),
                'connected' => $this->botcakeConnected($page)],
            'forbeast' => ['page_id' => $pageId, 'token' => $f['token'], 'status' => $f['status'], 'tested_at' => $this->testedAt($page, ForbeastPageSetup::PROVIDER),
                'connected' => $f['connected']],
        ];
    }

    /** Kumpleto ang Botcake: may Page ID, ok ang huling token test, at may chat block (chat.key, at hindi sira sa huling suri). */
    public function botcakeConnected(string $page): bool
    {
        return $this->botcakePageId($page) !== '' && trim((string) $this->settings->get($page, 'botcake', 'token')) !== ''
            && str_starts_with((string) $this->settings->get($page, 'botcake', 'token_status'), 'ok') && $this->hasChatBlock($page) && !$this->blockBad($page);
    }

    /** Sira ang chat block ayon sa huling suri (botcake.chat_block). */
    public function blockBad(string $page): bool
    {
        $s = (string) $this->settings->get($page, 'botcake', 'chat_block');
        foreach (self::BLOCK_BAD as $bad) if (str_starts_with($s, $bad)) return true;

        return false;
    }

    /** Ang mga bot na maaaring makakonekta (ang mga row ng Connected filter at ng Bot ng Health), sa ayos na ito. */
    public const BOTS = ['Botcake', 'Forbeast'];

    /** @return array<int, string> ang mga kumpletong koneksyon, mula sa BOTS */
    public function connected(string $page): array
    {
        return array_values(array_filter([$this->botcakeConnected($page) ? self::BOTS[0] : null, $this->forbeast->info($page)['connected'] ? self::BOTS[1] : null]));
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

        if (!$confirm && ($ask = $this->takeover($client, $pageId, $token, (string) $this->settings->get($page, 'botcake', 'chat.key'))) !== null) return $ask;

        if ($pageId !== $this->botcakePageId($page)) {
            $this->settings->set($page, 'botcake', 'page_id', $pageId, false, $userId);
            BotcakeChat::forgetPageMap();
            // Ang Forbeast webhook ay nasa lumang Facebook page: hindi na konektado hanggang i-save ulit ang Forbeast token.
            $this->settings->pageQuery($page)->where('provider', ForbeastPageSetup::PROVIDER)->where('key', 'token_status')->where('active', true)
                ->get()->each(fn ($s) => $this->settings->deactivate($s, $userId));
        }
        $this->settings->set($page, 'botcake', 'token', $token, true, $userId);
        $this->stamp($page, 'token_status', 'ok', $userId);

        return ['http' => 200, 'body' => ['status' => 'ok', 'chat_block' => $this->chatBlock($page, $userId)] + $this->checkKeywords($page, $client, $pageId, $token, $userId)];
    }

    /**
     * Test ng naka-save na Botcake token (walang isinusulat sa Botcake): ang token test, saka kung nasa Default Reply pa ang
     * block namin at published, at ang mga naka-on na keyword. Ang resulta ay itinatala (token_status, chat_block at keywords,
     * may user at oras).
     * @return array{http: int, body: array<string, mixed>} body.chat_block = on | missing | unpublished | elsewhere | unknown | null
     */
    public function testBotcake(string $page, ?int $userId): array
    {
        $pageId = $this->botcakePageId($page);
        $token = trim((string) $this->settings->get($page, 'botcake', 'token'));
        if ($pageId === '' || $token === '') return $this->fail(422, 'error', 'Save a Botcake Page ID and token first.');

        $client = app(BotcakeClient::class)->once();
        try {
            $ok = $client->tokenAndFields($pageId, $token)['ok'];
        } catch (Throwable $e) {
            if (!$e instanceof BotcakeException) report($e);
            $error = $e instanceof BotcakeException ? $e->getMessage() : 'Botcake did not answer.';
            $this->stamp($page, 'token_status', 'error', $userId, $error);
            return ['http' => 200, 'body' => ['status' => 'error', 'error' => $error, 'chat_block' => null]];
        }
        $this->stamp($page, 'token_status', $ok ? 'ok' : 'invalid', $userId);
        if (!$ok) return ['http' => 200, 'body' => ['status' => 'invalid', 'error' => 'The token does not work for Botcake page ' . $pageId . '.', 'chat_block' => null]];

        $state = $this->blockState($client, $pageId, $token, (string) $this->settings->get($page, 'botcake', 'chat.key'));
        [$block, $note] = match (true) {
            // Hindi published: sira lang kapag On ang bot dito; ang page na sadyang Off ay "off".
            $state === 'ours' => $this->published($client, $pageId, $token) === false
                ? ($this->settings->get($page, 'chatbot', 'active') === '0' ? ['off', 'the bot is Off'] : ['unpublished', 'the Default Reply is not published'])
                : ['on', null],
            $state === 'none' => ['missing', 'no chat block in the Default Reply'],
            $state === 'stale' => ['missing', 'the chat block has an old key'],
            $state === 'unknown' => ['unknown', 'could not read the Default Reply'],
            default => ['elsewhere', 'the Default Reply points to ' . $state],
        };
        $this->stamp($page, 'chat_block', $block, $userId, $note);

        return ['http' => 200, 'body' => ['status' => 'ok', 'chat_block' => $block, 'block_state' => $state, 'error' => $note]
            + $this->checkKeywords($page, $client, $pageId, $token, $userId)];
    }

    /**
     * Ang naka-on na keyword ng page sa Botcake (GET /keywords: id, is_activated at flow_id lang, walang text): ang
     * mensaheng tumama sa keyword ay hindi umaabot sa Default Reply (sa block namin), kaya hindi pa gumagana nang buo ang
     * bot. Basa lang: hindi ito kayang i-off ng API, sa Botcake ito ino-off ng staff. Ang pangalan ng flow ay mula sa
     * GET /flows. Itinatala sa botcake.keywords (on, none o unknown, may user at oras); hindi nito binabago ang token_status.
     * @return array{keywords: string, keywords_note: ?string}
     */
    private function checkKeywords(string $page, BotcakeClient $client, string $pageId, string $token, ?int $userId): array
    {
        try {
            [$state, $note] = $this->keywords($client, $pageId, $token);
        } catch (Throwable $e) {
            report($e);
            [$state, $note] = ['unknown', 'could not read the keywords'];
        }
        $this->stamp($page, 'keywords', $state, $userId, $note);

        return ['keywords' => $state, 'keywords_note' => $note];
    }

    /** @return array{0: string, 1: ?string} [on, ang mga pangalan ng flow] | [none, null] | [unknown, ang dahilan] */
    private function keywords(BotcakeClient $client, string $pageId, string $token): array
    {
        $r = $client->call('GET', '/pages/' . rawurlencode($pageId) . '/keywords', [], $token);
        if ($r['http'] < 200 || $r['http'] >= 300 || !is_array($r['body']) || ($r['body']['success'] ?? null) === false || !is_array($r['body']['data'] ?? null)) {
            return ['unknown', 'could not read the keywords'];
        }
        $on = [];
        foreach ($r['body']['data'] as $k) {
            if (is_array($k) && ($k['is_activated'] ?? null) === true) $on[] = is_scalar($k['flow_id'] ?? null) ? (string) $k['flow_id'] : '';
        }
        if (!$on) return ['none', null];

        $f = $client->call('GET', '/pages/' . rawurlencode($pageId) . '/flows', [], $token);
        $names = [];
        foreach (is_array($f['body']) && is_array($f['body']['data']['flows'] ?? null) ? $f['body']['data']['flows'] : [] as $flow) {
            if (is_array($flow) && is_scalar($flow['id'] ?? null) && is_string($flow['name'] ?? null)) $names[(string) $flow['id']] = trim($flow['name']);
        }

        return ['on', implode(', ', array_unique(array_map(fn ($id) => $id === '' ? 'no flow' : (($names[$id] ?? '') !== '' ? $names[$id] : 'flow ' . $id), $on)))];
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
        if (!$confirm && $pageId !== '' && $token !== '') {
            $ask = $this->takeover(app(BotcakeClient::class)->once(), $pageId, $token, (string) $this->settings->get($page, 'botcake', 'chat.key'));
            if ($ask !== null) return $ask;
        }

        return ['http' => 200, 'body' => ['status' => 'ok', 'chat_block' => $this->chatBlock($page, $userId)]];
    }

    /**
     * Ang takeover check bago isulat ang block sa On at + Page: ang tanong (409) kapag ang Default Reply ay nasa ibang site,
     * o null. Ang hindi masuring sagot ay hindi humaharang dito (gaya ng On at + Page bago ang check na ito); ang
     * Connections popup lang ang nagtatanong din doon.
     */
    public function guard(string $pageId, string $token, string $chatKey): ?array
    {
        if ($pageId === '' || $token === '') return null;
        $state = $this->blockState(app(BotcakeClient::class)->once(), $pageId, $token, $chatKey);

        return in_array($state, ['ours', 'stale', 'none', 'unknown'], true) ? null : $this->askMove($state);
    }

    /** Ang Off ng bot (naiiwan ang block, hindi published): itinatala sa botcake.chat_block para sa Changes. */
    public function stampOff(string $page, ?int $userId): void
    {
        $this->stamp($page, 'chat_block', 'off', $userId);
    }

    /** Isulat ang block at i-publish (BotcakeChatBlock::turnOn, parehong chat.key); itinatala sa botcake.chat_block. */
    public function chatBlock(string $page, ?int $userId): array
    {
        try {
            $r = app(BotcakeChatBlock::class)->turnOn($page, false, $userId);
        } catch (Throwable $e) {
            report($e);
            $r = ['ok' => false, 'error' => 'Could not set up the chat block: Botcake did not answer.'];
        }
        $this->stamp($page, 'chat_block', $r['ok'] ? 'applied' : 'failed', $userId, $r['error']);

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
        $res = $this->forbeast->connect($page, $userId);

        return ['http' => $res['status'] === 'ok' ? 200 : 502, 'body' => ['status' => $res['status'], 'name' => $res['name'], 'error' => $res['error']]];
    }

    /** Test ng naka-save na Forbeast token: ForbeastPageSetup::retest (walang bagong secret), may user. */
    public function testForbeast(string $page, ?int $userId): array
    {
        if ($this->forbeast->token($page) === '') return $this->fail(422, 'error', 'Save a Forbeast token first.');
        $status = $this->forbeast->retest($page, $userId);
        $text = (string) $this->settings->get($page, ForbeastPageSetup::PROVIDER, 'token_status');

        return ['http' => 200, 'body' => ['status' => $status, 'error' => $status === 'ok' ? null : trim((string) (explode(' · ', $text)[1] ?? ''))]];
    }

    /** Clear ng Forbeast token: DELETE ng webhook (subok lang), saka tinatanggal ang token, secret at status (may user). */
    public function clearForbeast(string $page, ?int $userId): array
    {
        $this->forbeast->disconnect($page, $userId);

        return ['http' => 200, 'body' => ['status' => 'cleared']];
    }

    /**
     * Ang Default Reply ng page ayon sa GET /default_reply/content (ang may blocks; ang /default_reply ay settings lang):
     * 'ours' (JSON API card papunta sa app na ito, X-Chat-Key = chat.key ng page), 'stale' (sa app na ito pero ibang key),
     * 'none' (walang JSON API card), 'unknown' (walang sagot o kakaibang sagot), o ang host ng ibang site.
     */
    public function blockState(BotcakeClient $client, string $pageId, string $token, string $chatKey): string
    {
        $r = $client->call('GET', '/pages/' . rawurlencode($pageId) . '/default_reply/content', [], $token);
        if ($r['http'] < 200 || $r['http'] >= 300 || !is_array($r['body']) || ($r['body']['success'] ?? null) === false || !is_array($r['body']['blocks'] ?? null)) return 'unknown';
        $ours = mb_strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $state = 'none';
        foreach (self::jsonApiCards($r['body']['blocks']) as $card) {
            $host = mb_strtolower((string) parse_url($card['config']['url'], PHP_URL_HOST));
            if ($host === '') return 'unknown'; // walang mabasang host: hindi alam kung saan papunta
            if ($host !== $ours) return mb_substr($host, 0, 255);
            $key = collect(is_array($card['headers'] ?? null) ? $card['headers'] : [])->first(fn ($h) => is_array($h) && ($h['key'] ?? null) === 'X-Chat-Key')['value'] ?? null;
            if ($chatKey !== '' && $key === $chatKey) $state = 'ours';
            elseif ($state === 'none') $state = 'stale';
        }

        return $state;
    }

    /** settings.is_published ng Default Reply (GET /default_reply), o null kapag hindi masabi. */
    private function published(BotcakeClient $client, string $pageId, string $token): ?bool
    {
        $r = $client->call('GET', '/pages/' . rawurlencode($pageId) . '/default_reply', [], $token);
        $v = is_array($r['body']) ? ($r['body']['settings']['is_published'] ?? null) : null;

        return $r['http'] >= 200 && $r['http'] < 300 && is_bool($v) ? $v : null;
    }

    /** Ang tanong bago isulat ang block, o null kapag walang ibang site. Hindi masuri = tinatanong din. */
    private function takeover(BotcakeClient $client, string $pageId, string $token, string $chatKey): ?array
    {
        $state = $this->blockState($client, $pageId, $token, $chatKey);
        if (in_array($state, ['ours', 'stale', 'none'], true)) return null;
        if ($state === 'unknown') return ['http' => 409, 'body' => ['status' => 'confirm', 'host' => null,
            'message' => "Could not check whether this page's Default Reply points to another site. Replace it anyway?"]];

        return $this->askMove($state);
    }

    /** @return array<int, array> ang bawat card na plugin_id = json_api (may config.url), saan man sa blocks */
    private static function jsonApiCards(array $node, int $depth = 0): array
    {
        if ($depth > 12) return [];
        $out = [];
        if (($node['plugin_id'] ?? null) === 'json_api' && is_string($node['config']['url'] ?? null)) $out[] = $node;
        foreach ($node as $child) if (is_array($child)) array_push($out, ...self::jsonApiCards($child, $depth + 1));

        return $out;
    }

    /**
     * Isang bagong status row ng Botcake (resulta, oras, at ang maikling dahilan), may user: lumalabas sa Changes. Ang
     * dahilan ay galing sa Botcake (text lang, walang token).
     */
    private function stamp(string $page, string $key, string $result, ?int $userId, ?string $note = null): void
    {
        $text = $result . ' ' . now('Asia/Manila')->format('Y-m-d H:i:s') . ($note !== null && $note !== '' ? ' · ' . $note : '');
        $this->settings->set($page, 'botcake', $key, mb_substr($text, 0, 250), false, $userId, true);
    }

    /** Ang oras ng huling test (ang active na token_status row), Manila. */
    private function testedAt(string $page, string $provider): ?string
    {
        $at = $this->settings->pageQuery($page)->where('provider', $provider)->where('key', 'token_status')->where('active', true)->orderByDesc('id')->value('created_at');

        return $at ? \Carbon\Carbon::parse($at)->timezone('Asia/Manila')->format('Y-m-d H:i') : null;
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
