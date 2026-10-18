<?php

namespace App\Services\Chatbot;

use App\Models\ChatbotReply;
use App\Services\BotcakeClient;
use App\Services\PageSettings;
use Throwable;

/**
 * Ang totoong padala: send_content sa Botcake at ang row sa Google Sheet, kapag naka-on ang global switch
 * (services.chatbot.replies_enabled). Bawat subok ay itinatala sa chatbot_replies (sent, failed o skipped), walang token.
 */
class BotcakeOutbox implements Outbox
{
    public const SWITCHED_OFF = 'Replies are switched off';

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
        if ($token === '') return $this->log($run, $nodeId, 'send', $messages, 'failed', 'The page has no Botcake token.');

        $r = app(BotcakeClient::class)->shared(sha1($token), 15)->sendContent($run->pageId, $run->psid(), $messages, $token);
        $body = is_array($r['body']) ? $r['body'] : [];
        if ($r['http'] >= 200 && $r['http'] < 300 && ($body['success'] ?? null) !== false) {
            return $this->log($run, $nodeId, 'send', $messages, 'sent', null, $r['http']);
        }
        $msg = trim((string) ($body['message'] ?? ''));
        $why = $r['http'] === 0 ? 'Botcake did not answer' : ($msg !== '' ? 'Botcake said "' . mb_substr($msg, 0, 150) . '"' : 'Botcake refused it');

        return $this->log($run, $nodeId, 'send', $messages, 'failed', $why . ' (HTTP ' . $r['http'] . ')', $r['http'] ?: null);
    }

    public function appendRow(FlowRun $run, string $nodeId, string $spreadsheetId, string $tab, array $row): array
    {
        $content = ['sheet' => $spreadsheetId, 'tab' => $tab, 'row' => $row];
        if (!self::enabled()) return $this->log($run, $nodeId, 'sheet', $content, 'skipped', self::SWITCHED_OFF);

        try {
            $this->sheet->append($spreadsheetId, $tab, $row);
        } catch (Throwable $e) {
            report($e);
            return $this->log($run, $nodeId, 'sheet', $content, 'failed', 'Google refused the row: ' . mb_substr($e->getMessage(), 0, 150));
        }

        return $this->log($run, $nodeId, 'sheet', $content, 'sent');
    }

    /** @return array{status: string, error: ?string} */
    private function log(FlowRun $run, string $nodeId, string $kind, array $content, string $status, ?string $error = null, ?int $http = null): array
    {
        ChatbotReply::create([
            'page_id' => (int) $run->pageId, 'psid' => (int) $run->fields->psid, 'chat_id' => $run->chatId, 'flow_id' => $run->flowId,
            'node_id' => $nodeId, 'kind' => $kind, 'content' => $content, 'status' => $status, 'error' => $error === null ? null : mb_substr($error, 0, 255),
            'http' => $http, 'sent_at' => now(),
        ]);

        return ['status' => $status, 'error' => $error];
    }
}
