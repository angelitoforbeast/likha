<?php

namespace App\Services\Chatbot;

use App\Models\ChatbotReply;
use App\Services\BotcakeClient;
use App\Services\PageSettings;
use Throwable;

/**
 * Ang totoong padala: send_content sa Botcake at ang row sa Google Sheet, kapag naka-on ang global switch
 * (services.chatbot.replies_enabled). Bawat subok ay itinatala sa chatbot_replies (sent, failed o skipped), walang token.
 * Ang pagbasa ng sagot (outcome) at ang talaan (record) ay pareho para sa botcake:send-pool.
 */
class BotcakeOutbox implements Outbox
{
    public const SWITCHED_OFF = 'Replies are switched off';
    public const NO_TOKEN = 'The page has no Botcake token.';

    public function __construct(private PageSettings $settings, private ChatbotSheet $sheet)
    {
    }

    public static function enabled(): bool
    {
        return (bool) config('services.chatbot.replies_enabled');
    }

    /** Ang mga mensahe bilang ipinapadala sa Botcake/Forbeast: walang "ai" at "ai_text" na marka (sa ating talaan lang iyon). */
    public static function wire(array $messages): array
    {
        return array_map(fn ($m) => is_array($m) ? array_diff_key($m, ['ai' => 1, 'ai_text' => 1]) : $m, array_values($messages));
    }

    public function send(FlowRun $run, string $nodeId, array $messages): array
    {
        if (!self::enabled()) return $this->log($run, $nodeId, 'send', $messages, 'skipped', self::SWITCHED_OFF);

        // Ang daan ng page (Botcake o ang Forbeast Gateway): ChatbotProvider. RESPONSE lang ang sagot sa chat ng customer;
        // ang sequence at ang takbong walang chat (hal. ang wait na lumampas) ay UPDATE.
        $type = $run->chatId !== null && $run->startedBy === null ? MessagingProvider::RESPONSE : MessagingProvider::UPDATE;
        $provider = ChatbotProvider::for($run->pageName);
        $o = $provider->send($run->pageName, $run->pageId, $run->psid(), $messages, ChatbotProvider::ref(), $type);
        // Nasagot ng Forbeast ang chat: ang standby na kopya ng Botcake ay hindi na kailangan (ForbeastWatch).
        if ($o['status'] === 'sent' && $run->chatId !== null && !$run->test && $provider instanceof GatewayProvider) {
            try {
                app(ForbeastWatch::class)->answered($run->chatId);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $this->log($run, $nodeId, 'send', $messages, $o['status'], $o['error'], $o['http'], $o['raw'] ?? null);
    }

    /**
     * Ang sagot ng send_content bilang resulta: sent (2xx at hindi success:false) o failed na may dahilan (walang token,
     * walang text ng Botcake na lampas 150 letra). Kapag ibinigay ang $token (ang mga padala): kasama ang buong sagot ng
     * pumalya (raw, hanggang RAW_MAX letra, nakatago ang token).
     * @param array{http: int, body: mixed} $r
     * @return array{status: string, error: ?string, http: ?int, raw?: string}
     */
    public static function outcome(array $r, ?string $token = null): array
    {
        $body = is_array($r['body']) ? $r['body'] : [];
        if ($r['http'] >= 200 && $r['http'] < 300 && ($body['success'] ?? null) !== false) return ['status' => 'sent', 'error' => null, 'http' => $r['http']];
        $msg = BotcakeClient::messageText($body);
        $why = $r['http'] === 0 ? 'Botcake did not answer' : ($msg !== '' ? 'Botcake said "' . mb_substr($msg, 0, 150) . '"' : 'Botcake refused it');
        $o = ['status' => 'failed', 'error' => $why . ' (HTTP ' . $r['http'] . ')', 'http' => $r['http'] ?: null];
        if ($token !== null && ($raw = self::raw($r['body'] ?? null, $token)) !== null) $o['raw'] = $raw;

        return $o;
    }

    public const RAW_MAX = 1000;

    /** Ang sagot bilang text para sa talaan: compact JSON (o ang text), walang token, hanggang RAW_MAX letra; null kapag wala. */
    public static function raw(mixed $body, string $token = ''): ?string
    {
        if ($body === null || $body === '' || $body === []) return null;
        $text = is_string($body) ? $body : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (trim($token) !== '') $text = str_replace(trim($token), '[token]', $text);

        return mb_substr($text, 0, self::RAW_MAX);
    }

    public function appendRow(FlowRun $run, string $nodeId, string $spreadsheetId, string $tab, array $row): array
    {
        $content = ['sheet' => $spreadsheetId, 'tab' => $tab, 'row' => $row];
        if (!self::enabled()) return $this->log($run, $nodeId, 'sheet', $content, 'skipped', self::SWITCHED_OFF);

        try {
            $range = $this->sheet->append($spreadsheetId, $tab, $row);
        } catch (Throwable $e) {
            report($e);
            return $this->log($run, $nodeId, 'sheet', $content, 'failed', 'Google refused the row: ' . mb_substr($e->getMessage(), 0, 150));
        }
        // Para sa "Open row": ang range ng naisulat at ang gid ng tab (mula sa naka-cache na listahan ng tab; binabasa
        // muli nang isang beses kapag wala roon ang tab, hal. bagong tab). Kapag hindi makuha ang gid, walang link, pero
        // naisulat pa rin ang row.
        if (is_string($range) && $range !== '') {
            $content['range'] = mb_substr($range, 0, 300);
            try {
                $gid = fn (bool $refresh) => collect($this->sheet->info($spreadsheetId, $refresh)['tabs'])->firstWhere('title', $tab)['gid'] ?? null;
                $gid = $gid(false) ?? $gid(true);
                if ($gid !== null) $content['gid'] = $gid;
            } catch (Throwable) {
            }
        }

        return $this->log($run, $nodeId, 'sheet', $content, 'sent');
    }

    /** @return array{status: string, error: ?string} */
    private function log(FlowRun $run, string $nodeId, string $kind, array $content, string $status, ?string $error = null, ?int $http = null, ?string $raw = null): array
    {
        return self::record((int) $run->pageId, (int) $run->fields->psid, $run->chatId, $run->flowId, $nodeId, $kind, $content, $status, $error, $http, $raw,
            $kind === 'send' ? $run->tags : null);
    }

    /**
     * Ang talaan ng isang padala (chatbot_replies). $raw: ang buong sagot ng pumalyang padala. $tags: ang
     * ChatbotTags::forSend() ng padala (Results).
     * @return array{status: string, error: ?string}
     */
    public static function record(int $pageId, int $psid, ?int $chatId, ?int $flowId, string $nodeId, string $kind, array $content, string $status, ?string $error = null, ?int $http = null, ?string $raw = null, ?array $tags = null): array
    {
        $reply = ChatbotReply::create([
            'page_id' => $pageId, 'psid' => $psid, 'chat_id' => $chatId, 'flow_id' => $flowId,
            'node_id' => $nodeId, 'kind' => $kind, 'content' => $content, 'status' => $status, 'error' => $error === null ? null : mb_substr($error, 0, 255),
            'http' => $http, 'sent_at' => now(),
        ] + ($raw !== null && ChatbotReply::hasRaw() ? ['raw' => $raw] : []) + ChatbotTags::columns($tags));
        ChatbotTags::storeRows($reply, $tags);

        return ['status' => $status, 'error' => $error];
    }
}
