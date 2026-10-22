<?php

namespace App\Services\Chatbot;

use App\Models\BotcakeChat;
use App\Models\ChatbotReply;

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
        $chat = $this->chatId ?? (int) BotcakeChat::where('page_id', $this->pageId)->where('psid', (string) $this->fields->psid)->max('id');
        $reply = (int) ChatbotReply::where('page_id', $this->fields->pageId)->where('psid', $this->fields->psid)->max('id');
        $this->fields->set(CustomerFields::AI_SINCE, json_encode(['at' => now()->toIso8601String(), 'chat' => $chat, 'reply' => $reply]));
    }

    /** @return array{chat: int, reply: int}|null ang huling chat at padala na nakalimutan na ng AI (null = walang marker) */
    public function aiSince(): ?array
    {
        $m = json_decode((string) $this->fields->get(CustomerFields::AI_SINCE), true);

        return is_array($m) ? ['chat' => (int) ($m['chat'] ?? 0), 'reply' => (int) ($m['reply'] ?? 0)] : null;
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
            'last_interaction' => $this->receivedAt, 'replies_via' => ChatbotProvider::viaLabel($this->via()),
        ];
        $base = ChatbotVars::merge($this->botfields, $this->fields->fields(), $run);
        $bot = array_map(fn ($v) => ChatbotVars::interpolate((string) $v, $base), $this->botfields);

        return ChatbotVars::merge($bot, $this->fields->fields(), $run);
    }

    public function text(string $template): string
    {
        return ChatbotVars::interpolate($template, $this->vars());
    }
}
