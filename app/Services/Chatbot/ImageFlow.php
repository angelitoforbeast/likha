<?php

namespace App\Services\Chatbot;

use App\Jobs\CheckImageFlow;
use App\Services\BotcakeClient;
use App\Services\BotcakeException;
use App\Services\ChatblastSetupCheck;
use App\Services\PageSettings;
use Generator;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Bot images through the page's Botcake flow "SEND IMAGE" (spec 274). Some pages lose many send_content images to
 * "(#100) Upload attachment failure", while a Botcake-native flow whose image card reads the customer's custom field
 * "image_url" delivers reliably. When the page's stored check is OK, each image is sent as: write the link into the
 * customer's image_url field, then send_flow SEND IMAGE. Text keeps going by send_content. Without an OK check nothing
 * changes. A failed write or send_flow sends that image by send_content once, as before.
 *
 * The check (run()) is stored per page in page_settings botcake.image_flow.check: {ok, flow_id, field_id, reasons, at}.
 * steps() is a generator of Botcake requests (the same shape as ChatblastSender::stepper), so the send pools and the
 * direct send share one sequence of steps.
 */
class ImageFlow
{
    public const FLOW_NAME = 'SEND IMAGE';

    public const FIELD_NAME = 'image_url';

    public const CHECK_KEY = 'image_flow.check';

    /** Marker on a recorded image message: "flow" or "send_content", so reports can tell how it went. */
    public const VIA = 'sent_via';

    /** Marker on a recorded image message that fell back to send_content: the reason. */
    public const FALLBACK = 'flow_fallback';

    /** The field write and its send_flow hold this lock per page and PSID, so another send cannot overwrite the field in between. */
    public const LOCK_SECONDS = 60;

    public const LOCK_WAIT = 20;

    public const BUSY = 'Another image to this customer was still being sent';

    private const CALL_TIMEOUT = 8;

    public function __construct(private PageSettings $settings, private BotcakeClient $client) {}

    /**
     * Check one page: exactly one live flow named SEND IMAGE, exactly one custom field named image_url, the flow
     * published, and an image card whose link is that field's variable. Botcake not answering keeps the last result
     * (with the error), like the shipping setup check.
     *
     * @return array{status: string, ok?: bool, reasons?: array<int, string>, error?: string, check?: array}
     *                                                                                                       status = checked | error | skipped
     */
    public function run(string $page): array
    {
        $pageId = trim((string) $this->settings->get($page, 'botcake', 'page_id'));
        $token = trim((string) $this->settings->get($page, 'botcake', 'token'));
        if ($pageId === '' || $token === '') {
            return ['status' => 'skipped'];
        }
        $client = $this->client->forSetup($token)->once(self::CALL_TIMEOUT);
        $base = '/pages/'.rawurlencode($pageId);

        try {
            $reads = $client->reads(['flows' => [$base.'/flows', []], 'fields' => BotcakeClient::tokenRead($pageId)], $token);
            $fields = BotcakeClient::tokenFieldsFrom($reads['fields']);
            if (! $fields['ok']) {
                return $this->error($page, 'token invalid');
            }
            if ($fields['fields'] === null) {
                return $this->error($page, 'Botcake: custom fields not a list');
            }
            $flows = self::flowsFrom($reads['flows']);
            if (is_string($flows)) {
                return $this->error($page, $flows);
            }
        } catch (Throwable $e) {
            if (! $e instanceof BotcakeException) {
                report($e);
            }

            return $this->error($page, $e instanceof BotcakeException ? $e->getMessage() : 'Botcake: no answer');
        }

        $flowHits = array_values(array_filter($flows, fn ($f) => ChatblastSetupCheck::norm($f['name']) === ChatblastSetupCheck::norm(self::FLOW_NAME)));
        $fieldHits = array_values(array_filter($fields['fields'], fn ($f) => ChatblastSetupCheck::norm($f['name']) === ChatblastSetupCheck::norm(self::FIELD_NAME)));
        $reasons = [];
        if (! $flowHits) {
            $reasons[] = 'No flow named '.self::FLOW_NAME;
        } elseif (count($flowHits) > 1) {
            $reasons[] = self::count(count($flowHits)).' flows named '.self::FLOW_NAME;
        }
        if (! $fieldHits) {
            $reasons[] = 'No custom field named '.self::FIELD_NAME;
        } elseif (count($fieldHits) > 1) {
            $reasons[] = self::count(count($fieldHits)).' custom fields named '.self::FIELD_NAME;
        }
        $flowId = count($flowHits) === 1 ? $flowHits[0]['id'] : null;
        $fieldId = count($fieldHits) === 1 ? $fieldHits[0]['id'] : null;

        // The flow's content only when both are found: one more call.
        if ($flowId !== null && $fieldId !== null) {
            try {
                $r = $client->call('GET', $base.'/flows/'.rawurlencode($flowId).'/content', [], $token);
            } catch (Throwable $e) {
                report($e);

                return $this->error($page, 'Botcake: no answer');
            }
            if ($r['http'] === 0) {
                return $this->error($page, 'Botcake: no answer');
            }
            if ($r['http'] < 200 || $r['http'] >= 300 || ! is_array($r['body']) || ($r['body']['success'] ?? true) === false) {
                return $this->error($page, 'Botcake: HTTP '.$r['http'].' on the SEND IMAGE content');
            }
            $data = is_array($r['body']['data'] ?? null) ? $r['body']['data'] : $r['body'];
            $status = mb_strtolower(trim((string) ($data['status'] ?? $r['body']['status'] ?? '')));
            if ($status !== 'published') {
                $reasons[] = self::FLOW_NAME.' is not published';
            }
            if (! self::usesField($data['blocks'] ?? null, self::variable($fieldId))) {
                $reasons[] = 'The '.self::FLOW_NAME.' flow\'s image does not use '.self::variable($fieldId);
            }
        }

        $check = ['ok' => ! $reasons, 'flow_id' => $flowId, 'field_id' => $fieldId, 'reasons' => $reasons, 'at' => now()->format('Y-m-d H:i:s')];
        $row = $this->write($page, $check);

        return ['status' => 'checked', 'ok' => $check['ok'], 'reasons' => $reasons, 'check' => $check, 'row' => $row];
    }

    /** The field's variable as Botcake writes it in content: {{<field id>//image_url}}. */
    public static function variable(string $fieldId): string
    {
        return '{{'.$fieldId.'//'.self::FIELD_NAME.'}}';
    }

    private static function count(int $n): string
    {
        return $n === 2 ? 'Two' : (string) $n;
    }

    /** @return array<int, array{id: string, name: string}>|string the live flows, or the reason they could not be read */
    private static function flowsFrom(?array $r): array|string
    {
        if ($r === null || $r['http'] === 0) {
            return 'Botcake: no answer';
        }
        if ($r['http'] === 401 || $r['http'] === 403) {
            return 'token invalid';
        }
        if ($r['http'] < 200 || $r['http'] >= 300) {
            return 'Botcake: HTTP '.$r['http'];
        }
        $list = is_array($r['body']) ? ($r['body']['data']['flows'] ?? null) : null;
        if (! is_array($list) || ! array_is_list($list)) {
            return 'Botcake: flows not a list';
        }
        $out = [];
        foreach ($list as $f) {
            if (! is_array($f) || ! isset($f['id']) || ! is_scalar($f['id']) || ! empty($f['is_removed'])) {
                continue;
            }
            $out[] = ['id' => (string) $f['id'], 'name' => is_string($f['name'] ?? null) ? $f['name'] : ''];
        }

        return $out;
    }

    /** Does at least one image card (plugin_id "image") of the live blocks take its link from the variable? */
    private static function usesField(mixed $blocks, string $variable): bool
    {
        foreach (is_array($blocks) ? $blocks : [] as $block) {
            foreach ((array) (is_array($block) ? ($block['cards'] ?? []) : []) as $card) {
                if (! is_array($card) || ($card['plugin_id'] ?? null) !== 'image') {
                    continue;
                }
                $url = $card['config']['url'] ?? null;
                if (is_string($url) && mb_strtolower(trim($url)) === mb_strtolower($variable)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Botcake did not answer: the last result stays (images keep their way), with the error and when. */
    private function error(string $page, string $reason): array
    {
        $check = $this->check($page) ?? ['ok' => false, 'flow_id' => null, 'field_id' => null, 'reasons' => []];
        $check['error'] = mb_substr($reason, 0, 200);
        $check['error_at'] = now()->format('Y-m-d H:i:s');
        $row = $this->write($page, $check);

        return ['status' => 'error', 'error' => $reason, 'check' => $check, 'row' => $row];
    }

    private function write(string $page, array $check)
    {
        return $this->settings->set($page, 'botcake', self::CHECK_KEY, json_encode($check, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false, null, true)['setting'];
    }

    /** The page's stored check, or null. */
    public function check(string $page): ?array
    {
        $v = json_decode((string) $this->settings->get($page, 'botcake', self::CHECK_KEY), true);

        return is_array($v) ? $v : null;
    }

    /** The stored check as a badge for screens: null when never checked. @return array{ok: bool, reasons: array<int, string>, at: ?string}|null */
    public static function badge(?string $json): ?array
    {
        $c = json_decode((string) $json, true);
        if (! is_array($c)) {
            return null;
        }
        $ok = ($c['ok'] ?? false) === true && (string) ($c['flow_id'] ?? '') !== '' && (string) ($c['field_id'] ?? '') !== '';
        $reasons = array_values(array_map('strval', (array) ($c['reasons'] ?? [])));
        if (! $ok && ! $reasons) {
            $reasons = ['The image setup check did not pass'];
        }
        if (isset($c['error'])) {
            $reasons[] = 'Last check failed: '.$c['error'];
        }

        return ['ok' => $ok, 'reasons' => $reasons, 'at' => $c['error_at'] ?? $c['at'] ?? null];
    }

    /**
     * The flow and field for this send, or null to send as today: no image among the messages, or the page's stored
     * check is not OK. One page_settings read; no Botcake call.
     *
     * @return array{flow_id: string, field_id: string}|null
     */
    public static function forSend(string $pageName, array $messages): ?array
    {
        if (! self::hasImage($messages)) {
            return null;
        }
        $c = json_decode((string) app(PageSettings::class)->get($pageName, 'botcake', self::CHECK_KEY), true);
        if (! is_array($c) || ($c['ok'] ?? false) !== true) {
            return null;
        }
        $flow = trim((string) ($c['flow_id'] ?? ''));
        $field = trim((string) ($c['field_id'] ?? ''));

        return $flow !== '' && $field !== '' ? ['flow_id' => $flow, 'field_id' => $field] : null;
    }

    public static function isImage(mixed $m): bool
    {
        return is_array($m) && ($m['type'] ?? '') === 'image' && trim((string) ($m['url'] ?? '')) !== '';
    }

    public static function hasImage(array $messages): bool
    {
        foreach ($messages as $m) {
            if (self::isImage($m)) {
                return true;
            }
        }

        return false;
    }

    public static function lockKey(int|string $pageId, int|string $psid): string
    {
        return 'image-flow:'.$pageId.':'.$psid;
    }

    /** After this answer, does the send still hold the page+PSID lock? Only after a good field write (its send_flow is next). */
    public static function holds(array $req, array $r): bool
    {
        return ($req['step'] ?? '') === 'image_write' && self::ok($r);
    }

    private static function ok(array $r): bool
    {
        return $r['http'] >= 200 && $r['http'] < 300 && is_array($r['body']) && ($r['body']['success'] ?? null) === true;
    }

    private static function said(array $r): string
    {
        $msg = BotcakeClient::messageText($r['body'] ?? null);

        return 'HTTP '.$r['http'].($msg !== '' ? ': '.mb_substr($msg, 0, 120) : '');
    }

    /** A send_flow answer that says the flow itself is gone or not valid (not a customer or window error). */
    public static function flowGone(array $r): bool
    {
        if ($r['http'] === 404) {
            return true;
        }
        $msg = mb_strtolower(BotcakeClient::messageText($r['body'] ?? null));

        return str_contains($msg, 'flow') && (bool) preg_match('/not found|not exist|does not exist|invalid|deleted|removed|unpublish/', $msg);
    }

    /**
     * The requests of one send, in order: consecutive non-image messages as one send_content (as today); each image as
     * field write then send_flow, or send_content once when either fails. Receives each answer {http, body, attempts}
     * (or {busy: reason} when the page+PSID lock was not free: that image goes by send_content).
     * Returns the outcome like BotcakeOutbox::outcome (the first failure, else sent) plus the messages with their
     * markers, flow_failed and flow_gone (the reason, when send_flow said the flow is gone).
     */
    public static function steps(string $pageId, string $psid, array $messages, array $cfg, ?string $token = null): Generator
    {
        $base = '/pages/'.rawurlencode($pageId);
        $content = fn (array $list) => ['step' => 'send', 'method' => 'POST', 'path' => $base.'/flows/send_content',
            'data' => ['psid' => $psid, 'data' => ['version' => 'v2', 'content' => ['messages' => BotcakeOutbox::wire($list)]]], 'onlyRateLimit' => true];
        $messages = array_values($messages);
        $segments = [];
        foreach ($messages as $i => $m) {
            if (self::isImage($m)) {
                $segments[] = ['image', $i];
            } elseif ($segments && end($segments)[0] === 'content') {
                $segments[count($segments) - 1][1][] = $i;
            } else {
                $segments[] = ['content', [$i]];
            }
        }

        $first = $last = null;
        $flowFailed = false;
        $flowGone = null;
        foreach ($segments as [$kind, $at]) {
            if ($kind === 'content') {
                $r = yield $content(array_map(fn ($i) => $messages[$i], $at));
                $o = BotcakeOutbox::outcome($r, $token);
                $last = $o;
                $first ??= $o['status'] === 'failed' ? $o : null;

                continue;
            }
            $url = trim((string) $messages[$at]['url']);
            $w = yield ['step' => 'image_write', 'method' => 'POST', 'path' => $base.'/customer/'.rawurlencode($psid).'/customer_fields',
                'data' => ['data' => [['id' => $cfg['field_id'], 'value' => $url]]], 'onlyRateLimit' => true];
            if (isset($w['busy'])) {
                $why = (string) $w['busy'];
            } elseif (! self::ok($w)) {
                $why = 'The image_url field was not written ('.self::said($w).')';
            } else {
                $f = yield ['step' => 'image_flow', 'method' => 'POST', 'path' => $base.'/flows/send_flow',
                    // Botcake refuses a string here ("flow_id must be an integer", HTTP 200), as chatblast's send_flow knows.
                    'data' => ['psid' => $psid, 'flow_id' => (int) $cfg['flow_id']], 'onlyRateLimit' => true];
                if (self::ok($f)) {
                    $messages[$at][self::VIA] = 'flow';
                    $last = ['status' => 'sent', 'error' => null, 'http' => $f['http']];

                    continue;
                }
                $why = self::FLOW_NAME.' was not sent ('.self::said($f).')';
                $flowFailed = true;
                if (self::flowGone($f)) {
                    $flowGone = $why;
                }
            }
            // The fallback: this image by send_content once, as before.
            $r = yield $content([$messages[$at]]);
            $o = BotcakeOutbox::outcome($r, $token);
            $messages[$at][self::VIA] = 'send_content';
            $messages[$at][self::FALLBACK] = mb_substr($why, 0, 200);
            $last = $o;
            $first ??= $o['status'] === 'failed' ? $o : null;
        }

        return ($first ?? $last ?? ['status' => 'sent', 'error' => null, 'http' => null])
            + ['messages' => $messages, 'flow_failed' => $flowFailed, 'flow_gone' => $flowGone];
    }

    /**
     * The direct send (no pool): runs steps() with the client, holding the page+PSID lock from each field write until its
     * send_flow answered. A lock not free within LOCK_WAIT seconds sends that image by send_content.
     */
    public static function sendNow(BotcakeClient $client, string $token, string $pageName, string $pageId, string $psid, array $messages, array $cfg): array
    {
        $gen = self::steps($pageId, $psid, $messages, $cfg, $token);
        $lock = null;
        try {
            while ($gen->valid()) {
                $q = $gen->current();
                if ($q['step'] === 'image_write') {
                    $lock = Cache::lock(self::lockKey($pageId, $psid), self::LOCK_SECONDS);
                    try {
                        $lock->block((int) config('services.botcake.image_flow_lock_wait', self::LOCK_WAIT));
                    } catch (LockTimeoutException) {
                        $lock = null;
                        $gen->send(['busy' => self::BUSY, 'http' => 0, 'body' => null, 'attempts' => 0]);

                        continue;
                    }
                }
                $r = $client->call($q['method'], $q['path'], $q['data'], $token, $q['onlyRateLimit']);
                if ($lock !== null && ! self::holds($q, $r)) {
                    $lock->release();
                    $lock = null;
                }
                $gen->send($r);
            }
        } finally {
            $lock?->release();
        }

        return self::after($pageName, $gen->getReturn());
    }

    /**
     * After a send by flow: a send_flow error that says the flow is gone marks the page's check as failed; any send_flow
     * failure queues a re-check of the page. Returns the outcome for the record (messages kept, the flags removed).
     */
    public static function after(string $pageName, array $o): array
    {
        try {
            if ($o['flow_gone'] ?? null) {
                app(self::class)->markFailed($pageName, (string) $o['flow_gone']);
            }
            if ($o['flow_failed'] ?? false) {
                CheckImageFlow::soon($pageName);
            }
        } catch (Throwable $e) {
            report($e); // the send is done; the check is the daily one then
        }

        return array_diff_key($o, ['flow_failed' => 1, 'flow_gone' => 1]);
    }

    /** The flow failed as if gone: the page's check is not OK until the next check passes (images go by send_content). */
    public function markFailed(string $page, string $reason): void
    {
        $check = $this->check($page) ?? ['flow_id' => null, 'field_id' => null];
        $check['ok'] = false;
        $check['reasons'] = [mb_substr($reason, 0, 200)];
        $check['at'] = now()->format('Y-m-d H:i:s');
        unset($check['error'], $check['error_at']);
        $this->write($page, $check);
    }
}
