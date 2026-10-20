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

    public function send(FlowRun $run, string $nodeId, array $messages): array
    {
        if (!self::enabled()) return $this->log($run, $nodeId, 'send', $messages, 'skipped', self::SWITCHED_OFF);

        $token = trim((string) $this->settings->get($run->pageName, 'botcake', 'token'));
        if ($token === '') return $this->log($run, $nodeId, 'send', $messages, 'failed', self::NO_TOKEN);

        $o = self::outcome(app(BotcakeClient::class)->shared(sha1($token), 15)->sendContent($run->pageId, $run->psid(), $messages, $token));

        return $this->log($run, $nodeId, 'send', $messages, $o['status'], $o['error'], $o['http']);
    }

    /**
     * Ang sagot ng send_content bilang resulta: sent (2xx at hindi success:false) o failed na may dahilan (walang token,
     * walang text ng Botcake na lampas 150 letra).
     * @param array{http: int, body: mixed} $r
     * @return array{status: string, error: ?string, http: ?int}
     */
    public static function outcome(array $r): array
    {
        $body = is_array($r['body']) ? $r['body'] : [];
        if ($r['http'] >= 200 && $r['http'] < 300 && ($body['success'] ?? null) !== false) return ['status' => 'sent', 'error' => null, 'http' => $r['http']];
        $msg = trim((string) ($body['message'] ?? ''));
        $why = $r['http'] === 0 ? 'Botcake did not answer' : ($msg !== '' ? 'Botcake said "' . mb_substr($msg, 0, 150) . '"' : 'Botcake refused it');

        return ['status' => 'failed', 'error' => $why . ' (HTTP ' . $r['http'] . ')', 'http' => $r['http'] ?: null];
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
        // Para sa "Open row": ang range ng naisulat at ang gid ng tab (mula sa naka-cache na listahan ng tab). Kapag
        // hindi makuha ang gid, walang link, pero naisulat pa rin ang row.
        if (is_string($range) && $range !== '') {
            $content['range'] = mb_substr($range, 0, 300);
            try {
                $gid = collect($this->sheet->info($spreadsheetId)['tabs'])->firstWhere('title', $tab)['gid'] ?? null;
                if ($gid !== null) $content['gid'] = $gid;
            } catch (Throwable) {
            }
        }

        return $this->log($run, $nodeId, 'sheet', $content, 'sent');
    }

    /** @return array{status: string, error: ?string} */
    private function log(FlowRun $run, string $nodeId, string $kind, array $content, string $status, ?string $error = null, ?int $http = null): array
    {
        return self::record((int) $run->pageId, (int) $run->fields->psid, $run->chatId, $run->flowId, $nodeId, $kind, $content, $status, $error, $http);
    }

    /** Ang talaan ng isang padala (chatbot_replies). @return array{status: string, error: ?string} */
    public static function record(int $pageId, int $psid, ?int $chatId, ?int $flowId, string $nodeId, string $kind, array $content, string $status, ?string $error = null, ?int $http = null): array
    {
        ChatbotReply::create([
            'page_id' => $pageId, 'psid' => $psid, 'chat_id' => $chatId, 'flow_id' => $flowId,
            'node_id' => $nodeId, 'kind' => $kind, 'content' => $content, 'status' => $status, 'error' => $error === null ? null : mb_substr($error, 0, 255),
            'http' => $http, 'sent_at' => now(),
        ]);

        return ['status' => $status, 'error' => $error];
    }
}
