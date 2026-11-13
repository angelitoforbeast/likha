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

    /**
     * Ang mga mensahe bilang ipinapadala sa Botcake/Forbeast: walang marka ng ating talaan (ai, ai_text, text_cut, left_out,
     * at ang cut ng bawat button). Ang button at quick reply ay sa address lang ng gateway na nasa services.gateway.buttons
     * ($route, Gateways::buttonsOn): ang text na may button ay {type: buttons, text, buttons}. Sa iba (Botcake, walang
     * $route, o wala sa listahan) ay tinatanggal ang mga iyon: ang text o larawan lang, gaya ng dati.
     */
    public static function wire(array $messages, ?string $route = null): array
    {
        $on = Gateways::buttonsOn($route);
        $plain = fn (array $list) => array_values(array_map(fn ($t) => is_array($t) ? array_diff_key($t, ['cut' => 1]) : $t, $list));

        return array_map(function ($m) use ($on, $plain) {
            if (!is_array($m)) return $m;
            $buttons = is_array($m['buttons'] ?? null) ? $m['buttons'] : [];
            $quick = is_array($m['quick_replies'] ?? null) ? $m['quick_replies'] : [];
            $m = array_diff_key($m, ['ai' => 1, 'ai_text' => 1, 'text_cut' => 1, 'left_out' => 1, 'buttons' => 1, 'quick_replies' => 1, ImageFlow::VIA => 1, ImageFlow::FALLBACK => 1]);
            if (!$on) return $m;
            if ($buttons && ($m['type'] ?? '') === 'text') return ['type' => 'buttons', 'text' => (string) ($m['text'] ?? ''), 'buttons' => $plain($buttons)];

            return $m + ($quick ? ['quick_replies' => $plain($quick)] : []);
        }, array_values($messages));
    }

    /** Ang talaan ng padala: ang mensaheng may button o quick reply na hindi naipadala kasama ang mga iyon ay may left_out. */
    private static function leftOut(array $messages, ?string $via, ?string $route): array
    {
        if (in_array($via, Gateways::ALL, true) && Gateways::buttonsOn($route)) return $messages;

        return array_map(fn ($m) => is_array($m) && (!empty($m['buttons']) || !empty($m['quick_replies'])) ? $m + ['left_out' => true] : $m, $messages);
    }

    public function send(FlowRun $run, string $nodeId, array $messages): array
    {
        if (!self::enabled()) return $this->log($run, $nodeId, 'send', $messages, 'skipped', self::SWITCHED_OFF);

        // Ang daan ng page (Botcake o ang Forbeast Gateway): ChatbotProvider. RESPONSE lang ang sagot sa chat ng customer;
        // ang sequence at ang takbong walang chat (hal. ang wait na lumampas) ay UPDATE.
        $type = $run->chatId !== null && $run->startedBy === null ? MessagingProvider::RESPONSE : MessagingProvider::UPDATE;
        // Ang hindi maabot na customer (CustomerReach): ang UPDATE lang ang nilalaktawan; ang sagot sa chat niya ay laging ipinapadala.
        if ($type === MessagingProvider::UPDATE && !$run->test && ($why = CustomerReach::skipReason($run->pageId, $run->psid())) !== null) {
            return $this->log($run, $nodeId, 'send', $messages, 'skipped', $why);
        }
        $provider = ChatbotProvider::for($run->pageName);
        $o = $provider->send($run->pageName, $run->pageId, $run->psid(), $messages, $ref = ChatbotProvider::ref(), $type);
        // Nasagot ng Forbeast ang chat: ang standby na kopya ng Botcake ay hindi na kailangan (ForbeastWatch).
        if ($o['status'] === 'sent' && $run->chatId !== null && !$run->test && $provider instanceof GatewayProvider) {
            try {
                app(ForbeastWatch::class)->answered($run->chatId);
            } catch (Throwable $e) {
                report($e);
            }
        }

        // An image sent through the SEND IMAGE flow comes back marked (ImageFlow::VIA), so the record tells how it went.
        return $this->log($run, $nodeId, 'send', (array) ($o['messages'] ?? $messages), $o['status'], $o['error'], $o['http'], $o['raw'] ?? null, ChatbotProvider::viaOf($provider), $ref, ChatbotProvider::routeOf($provider));
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
        // Spec 273: every Botcake send result is counted, so the gateway watcher can see when our own network is down.
        try {
            ForbeastWatch::botcakeSend((int) $r['http']);
        } catch (Throwable $e) {
            report($e);
        }
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

        return $this->log($run, $nodeId, 'sheet', $content, 'sent') + array_filter(['range' => $content['range'] ?? null]);
    }

    /** @return array{status: string, error: ?string} */
    private function log(FlowRun $run, string $nodeId, string $kind, array $content, string $status, ?string $error = null, ?int $http = null, ?string $raw = null, ?string $via = null, ?string $clientRef = null, ?string $route = null): array
    {
        return self::record((int) $run->pageId, (int) $run->fields->psid, $run->chatId, $run->flowId, $nodeId, $kind, $content, $status, $error, $http, $raw,
            $kind === 'send' ? $run->tags : null, $via, $clientRef, $route);
    }

    /**
     * Ang talaan ng isang padala (chatbot_replies). $raw: ang buong sagot ng pumalyang padala. $tags: ang
     * ChatbotTags::forSend() ng padala (Results). $via: ang bot na nagdala (botcake o forbeast; null = hindi padala sa bot).
     * $clientRef: ang ID ng padala (para itugma ang send_status ng Forbeast). $route: ang address ng forbeast gateway na
     * tinawag (Gateways::route); itinatala lang kapag forbeast ang via.
     * @return array{status: string, error: ?string}
     */
    public static function record(int $pageId, int $psid, ?int $chatId, ?int $flowId, string $nodeId, string $kind, array $content, string $status, ?string $error = null, ?int $http = null, ?string $raw = null, ?array $tags = null, ?string $via = null, ?string $clientRef = null, ?string $route = null): array
    {
        $now = now();
        if ($kind === 'send') $content = self::leftOut($content, $via, $route);
        $reply = ChatbotReply::create([
            'page_id' => $pageId, 'psid' => $psid, 'chat_id' => $chatId, 'flow_id' => $flowId,
            'node_id' => $nodeId, 'kind' => $kind, 'content' => $content, 'status' => $status, 'error' => $error === null ? null : mb_substr($error, 0, 255),
            'http' => $http, 'sent_at' => $now,
        ] + ($raw !== null && ChatbotReply::hasRaw() ? ['raw' => $raw] : []) + ChatbotTags::columns($tags) + ChatbotReply::viaColumn($via) + ChatbotReply::routeColumn($via, $route)
            + ($kind === 'send' ? ChatbotReply::confirmColumns($clientRef, $via, $status, $now) : []));
        ChatbotTags::storeRows($reply, $tags);
        // Ang #2022 ng page (PageRestriction). Ang "sent" ng gateway ay tinanggap pa lang, kaya ang send_status ang bibilang nito.
        if ($kind === 'send' && ($status === 'failed' || !in_array($via, Gateways::ALL, true))) app(PageRestriction::class)->note($pageId, $status, $error);
        // Ang customer na hindi maabot (#551, #10 ng window): wala nang padalang hindi sagot hanggang sumulat siya (CustomerReach).
        if ($kind === 'send' && $status === 'failed') CustomerReach::note($pageId, $psid, $error, $raw);

        return ['status' => $status, 'error' => $error];
    }
}
