<?php

namespace App\Services\Chatbot;

use App\Models\BotcakeChat;
use App\Models\ChatbotReply;
use App\Services\BotcakeCustomerMatcher;
use App\Services\OrderEncode\OrderVars;

/**
 * Ang isang takbo ng flow para sa isang chat: ang flow, ang page (pangalan, Botcake ID, botfields), ang customer
 * (PSID, mga field), ang chat (id, text, pangalan, oras), at ang nangyari (path, mga padala, sheet rows) para sa Test.
 */
class FlowRun
{
    /** @var array<int, array{node: string, type: string, port: ?string, note: ?string}> */
    public array $path = [];
    /** @var array<int, array{node: string, messages: array, status: string, error: ?string}> */
    public array $sends = [];
    /** @var array<int, array{node: string, sheet: string, tab: string, row: array, status: string, error: ?string}> */
    public array $sheetRows = [];
    public ?string $stopped = null; // bakit huminto nang maaga (hal. limit, error)
    public int $aiCalls = 0;
    /** @var array<int, array{type: string, node: ?string, detail: array}> ang executed actions (isinusulat nang sabay pagkatapos) */
    public array $events = [];
    public ?string $runId = null;
    /** Walang sagot sa takdang oras (hal. "2 h", ang hinintay talaga): nagpapatuloy ang hinihintay sa port na skipped. */
    public ?string $skippedAfter = null;
    /** Ang payload ng tap ng customer (postback o quick reply, galing sa gateway lang): ang exit ng button sa naghihintay na step. */
    public ?string $tap = null;
    /** Pinatakbo ng sequence (hal. "sequence X step 2"): mula sa Start, at ang __wait ng customer ay hindi ginagalaw. */
    public ?string $startedBy = null;
    /** Ilang hakbang na (ang takbong itinuloy pagkatapos ng AI ay nagpapatuloy sa bilang, para sa MAX_STEPS). */
    public int $steps = 0;
    /** Itinuloy pagkatapos ng AI (chatbot:ai-pool): ['node', 'ok', 'text', 'error'], ginagamit nang isang beses sa node na iyon. */
    public ?array $aiAnswer = null;
    /** Ang ticket ng sagot (ResumeChatbotAi): bawat padala ng takbong ito ay sinusuri muna laban dito. */
    public ?array $ticket = null;
    /** Huminto ang takbo para hintayin ang sagot ng AI. */
    public bool $aiPending = false;
    /** Ang mga order_* variable (OrderVars), isang basa kada takbo; null = basahin ulit (pagkatapos ng Upload o Encode order). */
    public ?array $orderVars = null;
    private ?bool $usesOrder = null;
    /** Ang ad_id ng customer (BotcakeCustomerMatcher::adOf), isang basa kada takbo; binabasa lang kapag binabanggit. */
    private ?string $adId = null;
    private ?bool $usesAd = null;
    /** Itinuloy pagkatapos ng padala (botcake:send-pool): ['node', 'status', 'error', 'messages'], ginagamit nang isang beses. */
    public ?array $sendResult = null;
    /** Huminto ang takbo para hintayin ang resulta ng padala sa Botcake. */
    public bool $sendPending = false;
    /** Test: ilang padala na ng takbong ito nang dumaan sa "Clear AI memory" (ang mga nauna ay hindi na alaala ng AI). */
    public ?int $aiClearedAt = null;
    /** Ang tags ng padalang ginagawa ngayon (ChatbotTags::forSend), itinatala kasama ng reply. */
    public ?array $tags = null;
    /** Pinatakbo ng sequence: ang sequence at ang puwesto ng step (para sa Results). */
    public ?int $sequenceId = null;
    public ?int $sequenceStep = null;
    /**
     * Ang step ng sequence na hawak ng takbo (subscription_id, current_step, step, due, name, sequence_id, stamp, request_id,
     * first_sent_at, side_effects): ang padala ay ipinapasa sa chatbot:sequence-pool kapag handa (BotcakeSendQueue::sequenceAsync).
     */
    public ?array $sequenceTicket = null;
    /** Ang ad group ng customer sa takbong ito (AdGroups); null = ang page values. Ang sequence na sinimulan dito ay ito ang itinatala. */
    public ?int $adGroup = null;
    /** Pumalya ang lookup ng ad sa Meta (page values ngayon): ang sequence na sinimulan dito ay hindi itinatala sa page values. */
    public bool $adGroupUnresolved = false;

    /**
     * "Clear AI memory": live, ang marker ng customer (__ai_since: oras, ang chat na ito, at ang huling padala sa kanya);
     * sa Test, ang usapan ng test customer at ang mga padala ng takbo hanggang dito. Walang binubura sa mga chat.
     */
    public function forgetAi(): void
    {
        if ($this->test) {
            $this->fields->clear(CustomerFields::TEST_HISTORY);
            $this->aiClearedAt = count($this->sends);
            return;
        }
        $chat = $this->chatId ?? (int) BotcakeChat::active()->where('page_id', $this->pageId)->where('psid', (string) $this->fields->psid)->max('id');
        $reply = (int) ChatbotReply::where('page_id', $this->fields->pageId)->where('psid', $this->fields->psid)->max('id');
        $this->fields->set(CustomerFields::AI_SINCE, json_encode(['at' => now()->toIso8601String(), 'chat' => $chat, 'reply' => $reply]));
    }

    /** @return array{chat: int, reply: int}|null ang huling chat at padala na nakalimutan na ng AI (null = walang marker) */
    public function aiSince(): ?array
    {
        $m = json_decode((string) $this->fields->get(CustomerFields::AI_SINCE), true);

        return is_array($m) ? ['chat' => (int) ($m['chat'] ?? 0), 'reply' => (int) ($m['reply'] ?? 0)] : null;
    }

    /** Ang simula ng takbo (oras at dahilan, hal. "resumed after wait"), isinusulat kasama ng dulo sa iisang `run` event. */
    private ?array $start = null;
    private bool $ended = false;

    public function started(string $reason): void
    {
        $this->start ??= ['at' => now(), 'reason' => $reason];
    }

    /**
     * Ang dulo ng takbo: isang `run` event na may simula at dulo (ang dating run_started + run_ended), isang beses lang. Ang
     * "events" ay ilan ang event ng takbo bago ito (sa iisang insert), para mailagay ng Inbox ang linya ng simula bago sila.
     */
    public function ended(string $reason): void
    {
        if ($this->ended) return;
        $this->ended = true;
        $this->event('run', null, ['started_at' => ($this->start['at'] ?? now())->format('Y-m-d H:i:s'), 'ended_at' => now()->format('Y-m-d H:i:s'),
            'start' => $this->start['reason'] ?? 'new', 'reason' => $reason, 'flow' => $this->flowId, 'path' => array_slice(array_column($this->path, 'node'), 0, 100),
            'events' => count($this->events)]);
    }

    /** Isang executed action; ang bawat value ng detail ay hanggang 200 letra. */
    public function event(string $type, ?string $node = null, array $detail = []): void
    {
        $detail = array_map(fn ($v) => is_string($v) ? mb_substr($v, 0, 200) : $v, $detail);
        // Ang oras ng bawat event ay ang oras nito sa takbo (hindi ang oras ng insert), para nasa tamang puwesto sa usapan.
        $this->events[] = ['type' => $type, 'node' => $node, 'detail' => $detail + ['run' => $this->runId ??= bin2hex(random_bytes(4))], 'at' => now()];
    }

    public function __construct(
        public readonly int $flowId,
        public readonly array $graph,
        public readonly string $pageName,
        public readonly string $pageId,
        public readonly array $botfields,      // key o label => value
        public readonly CustomerFields $fields,
        public readonly ?int $chatId,
        public readonly string $input,
        public readonly string $fullName,
        public readonly string $receivedAt,     // Y-m-d H:i (Manila)
        public readonly bool $test = false,
    ) {
    }

    private ?string $via = null;

    /** Ang bot na may dala ng chat na nagsimula ng takbo (botcake o forbeast); naka-memo, isang basa lang. */
    public function via(): string
    {
        return $this->via ??= ChatbotProvider::via($this->pageName, $this->chatId ? BotcakeChat::find($this->chatId) : null);
    }

    public function psid(): string
    {
        return $this->test ? 'TEST' : (string) $this->fields->psid;
    }

    /**
     * Lahat ng variable: botfields, saka customer fields, saka ang run (ang huli ang nananaig). Ang botfield na may
     * variable (hal. "Only {{PRICE}} pesos") ay pinupunan nang isang antas lang.
     */
    public function vars(): array
    {
        $first = trim(explode(' ', trim($this->fullName))[0] ?? '');
        $run = [
            'last_input' => $this->input, 'full_name' => $this->fullName, 'first_name' => $first, 'psid' => $this->psid(),
            'page_id' => $this->pageId, 'page_name' => $this->pageName, 'current_date' => now('Asia/Manila')->toDateString(),
            'last_interaction' => $this->receivedAt, 'replies_via' => ChatbotProvider::repliesVia($this->pageName, $this->via()),
        ] + ($this->usesOrderVars() ? ($this->orderVars ??= OrderVars::read($this->pageName, (string) $this->fields->psid)) : [])
            + ($this->usesAdId() ? ['ad_id' => $this->adId ??= app(BotcakeCustomerMatcher::class)->adOf($this->pageName, (string) $this->fields->psid, $this->pageId)] : []);
        $base = ChatbotVars::merge($this->botfields, $this->fields->fields(), $run);
        $bot = array_map(fn ($v) => ChatbotVars::interpolate((string) $v, $base), $this->botfields);

        return ChatbotVars::merge($bot, $this->fields->fields(), $run);
    }

    /** Ang order_* ay binabasa lang kapag binabanggit ng flow o ng botfields (walang query sa ibang takbo). */
    private function usesOrderVars(): bool
    {
        return $this->usesOrder ??= OrderVars::named((string) json_encode([$this->graph, $this->botfields], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Ang ad_id ay binabasa lang kapag binabanggit ng flow o ng botfields, o kapag may Upload to macro na walang "Ad ID" na
     * key (ang lumang step: {{ad_id}} ang gamit).
     */
    private function usesAdId(): bool
    {
        return $this->usesAd ??= preg_match('/\{\{\s*ad[\s_]+id\s*\}\}/i',(string) json_encode([$this->graph, $this->botfields], JSON_UNESCAPED_UNICODE)) === 1
            || collect((array) ($this->graph['nodes'] ?? []))->contains(fn ($n) => ($n['type'] ?? '') === 'upload_macro' && !array_key_exists('ad_id', (array) ($n['config'] ?? [])));
    }

    public function text(string $template): string
    {
        return ChatbotVars::interpolate($template, $this->vars());
    }
}
