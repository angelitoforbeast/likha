<?php

namespace App\Services\Chatbot;

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
            'last_interaction' => $this->receivedAt,
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
