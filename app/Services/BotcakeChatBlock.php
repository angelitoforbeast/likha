<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Ang "Chat to site" block sa Default Reply ng isang page (JSON API papunta sa /api/botcake/chat, may sariling chat.key
 * ng page sa header X-Chat-Key). Ginagamit ng botcake:chat-setup at ng On/Off ng /chatbot.
 *
 * On: ang key ay ginagawa kapag wala pa (o laging bago kapag rotate) at itinatala lang pagkatapos maisulat ang block,
 * kaya ang pumalyang sulat ay hindi nagpapalit ng key; saka ipina-publish nang walang limit, at chatbot.active = 1.
 * Off: is_published = false lang (naiiwan ang block at ang key), at chatbot.active = 0. Ang pagsulat ay pinapalitan ang
 * buong Default Reply ng page. Walang key o token sa anumang ibinabalik.
 */
class BotcakeChatBlock
{
    /** Ang body na pinupunan ng Botcake (iisa sa bawat page). */
    public const BODY = ['page_id' => '{{page_id}}', 'psid' => '{{psid}}', 'name' => '{{user_full_name}}', 'message' => '{{last_text_input}}', 'source' => 'default_reply'];

    public function __construct(private PageSettings $settings)
    {
    }

    /** Ang gagawin sa key: 'create' (wala pa), 'rotate' (bago kahit mayroon), 'keep'. */
    public function keyPlan(string $page, bool $rotate = false): string
    {
        $old = (string) $this->settings->get($page, 'botcake', 'chat.key');

        return $old === '' ? 'create' : ($rotate ? 'rotate' : 'keep');
    }

    /**
     * Isulat ang block at i-publish. ok = parehong tumanggap ang Botcake. step = kung saan pumalya ('setup', 'write' o
     * 'publish'); sa 'publish' ay naisulat na ang block at naitala na ang key (kailangan iyon ng block).
     * @return array{ok: bool, key: string, step: ?string, error: ?string}
     */
    public function turnOn(string $page, bool $rotate = false): array
    {
        [$pageId, $token] = $this->ids($page);
        $plan = $this->keyPlan($page, $rotate);
        if ($pageId === '' || $token === '') return ['ok' => false, 'key' => $plan, 'step' => 'setup', 'error' => 'The page has no Botcake ID or token.'];

        $old = (string) $this->settings->get($page, 'botcake', 'chat.key');
        $key = $plan === 'keep' ? $old : Str::random(48);
        $client = app(BotcakeClient::class); // bago kada page: sariling rate at retry

        $r = $client->call('POST', '/pages/' . rawurlencode($pageId) . '/default_reply/content', ['name' => 'Default reply', 'blocks' => [$this->block($key)]], $token);
        if (!$this->ok($r)) return ['ok' => false, 'key' => $plan, 'step' => 'write', 'error' => $this->error('write the block', $r)];
        if ($key !== $old) $this->settings->set($page, 'botcake', 'chat.key', $key, true, null, true);

        $r = $client->call('POST', '/pages/' . rawurlencode($pageId) . '/default_reply', ['update' => ['is_published' => true, 'unlimit_default_reply' => true]], $token);
        if (!$this->ok($r)) return ['ok' => false, 'key' => $plan, 'step' => 'publish', 'error' => $this->error('publish the Default Reply', $r)];

        $this->settings->set($page, 'chatbot', 'active', '1');

        return ['ok' => true, 'key' => $plan, 'step' => null, 'error' => null];
    }

    /**
     * I-unpublish ang Default Reply lang (naiiwan ang block at ang key, kaya isang pindot lang ang On ulit).
     * @return array{ok: bool, error: ?string}
     */
    public function turnOff(string $page): array
    {
        [$pageId, $token] = $this->ids($page);
        if ($pageId === '' || $token === '') return ['ok' => false, 'error' => 'The page has no Botcake ID or token.'];

        $r = app(BotcakeClient::class)->call('POST', '/pages/' . rawurlencode($pageId) . '/default_reply', ['update' => ['is_published' => false]], $token);
        if (!$this->ok($r)) return ['ok' => false, 'error' => $this->error('unpublish the Default Reply', $r)];

        $this->settings->set($page, 'chatbot', 'active', '0');

        return ['ok' => true, 'error' => null];
    }

    /** Ang block na gumagana sa Botcake: kailangan ang body_preview at ang tatlong row na palaman, kung hindi ay walang body. */
    public function block(string $key): array
    {
        $pad = ['key' => '', 'value' => ''];

        return [
            'key' => 'chat_to_site', 'title' => 'Chat to site', 'message_tag' => 'OTHER', 'next_step' => true, 'gotos' => [],
            'cards' => [[
                'plugin_id' => 'json_api',
                'config' => ['method' => 'post', 'url' => rtrim((string) config('app.url'), '/') . '/api/botcake/chat'],
                'headers' => [['key' => 'X-Chat-Key', 'value' => $key], $pad, $pad],
                'params' => [$pad, $pad, $pad],
                'body' => json_encode(self::BODY, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'body_preview' => self::BODY,
                'response' => array_fill(0, 3, ['custom_field_id' => null, 'key' => '']),
                'buttons' => [],
            ]],
        ];
    }

    /** @return array{0: string, 1: string} [Botcake page ID, token] */
    private function ids(string $page): array
    {
        return [trim((string) $this->settings->get($page, 'botcake', 'page_id')), trim((string) $this->settings->get($page, 'botcake', 'token'))];
    }

    private function ok(array $r): bool
    {
        return $r['http'] >= 200 && $r['http'] < 300 && !(is_array($r['body']) && ($r['body']['success'] ?? null) === false);
    }

    /** Ang error sa simpleng English: ang ginawa, ang sagot ng Botcake (pinaikli), at ang HTTP. Walang token. */
    private function error(string $what, array $r): string
    {
        $msg = is_array($r['body']) ? trim((string) ($r['body']['message'] ?? '')) : '';
        $why = $r['http'] === 0 ? 'Botcake did not answer' : ($msg !== '' ? 'Botcake said "' . mb_substr($msg, 0, 120) . '"' : 'Botcake refused it');

        return "Could not {$what}: {$why} (HTTP {$r['http']}).";
    }
}
