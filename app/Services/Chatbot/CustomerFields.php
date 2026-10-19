<?php

namespace App\Services\Chatbot;

use Illuminate\Support\Facades\DB;

/**
 * Ang mga customer field ng isang page + PSID sa chatbot_customer_values: isang row kada key, pinapalitan sa lugar
 * (upsert). Ang key ay naka-norm (ChatbotVars::norm). Ang "__" sa unahan ay run state (__wait, __last_chat), hindi
 * customer field: hindi ito lumalabas sa variables at hindi binubura ng "clear all fields" maliban sa __wait.
 */
class CustomerFields
{
    public const WAIT = '__wait';
    public const LAST_CHAT = '__last_chat';
    public const BOT_PAUSED = '__bot_paused'; // '1' = tao ang sumasagot sa customer na ito (/chatbot/inbox): walang takbo ang bot
    public const TEST_PSID = 0; // ang test customer ng Test tab

    /** @var array<string, string> */
    private array $values;

    /** @var array<int, array{key: string, from: ?string, to: ?string}> ang mga pagbabago sa takbong ito (para sa Test) */
    public array $changes = [];

    public function __construct(public readonly int $pageId, public readonly int $psid)
    {
        $this->values = DB::table('chatbot_customer_values')->where('page_id', $pageId)->where('psid', $psid)
            ->pluck('value', 'key')->map(fn ($v) => (string) $v)->all();
    }

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    /** @return array<string, string> ang mga customer field lang (walang run state) */
    public function fields(): array
    {
        return array_filter($this->values, fn ($k) => !str_starts_with((string) $k, '__'), ARRAY_FILTER_USE_KEY);
    }

    public function set(string $key, string $value): void
    {
        $key = str_starts_with($key, '__') ? $key : ChatbotVars::norm($key);
        if ($key === '') return;
        $value = mb_substr($value, 0, 16000); // TEXT: 64 KB kahit 4 byte ang bawat letra
        if (($this->values[$key] ?? null) === $value) return;

        if (!str_starts_with($key, '__')) $this->changes[] = ['key' => $key, 'from' => $this->values[$key] ?? null, 'to' => $value];
        $this->values[$key] = $value;
        DB::table('chatbot_customer_values')->upsert(
            [['page_id' => $this->pageId, 'psid' => $this->psid, 'key' => $key, 'value' => $value, 'updated_at' => now()]],
            ['page_id', 'psid', 'key'], ['value', 'updated_at'],
        );
    }

    public function clear(string $key): void
    {
        $key = str_starts_with($key, '__') ? $key : ChatbotVars::norm($key);
        if (!array_key_exists($key, $this->values)) return;

        if (!str_starts_with($key, '__')) $this->changes[] = ['key' => $key, 'from' => $this->values[$key], 'to' => null];
        unset($this->values[$key]);
        DB::table('chatbot_customer_values')->where('page_id', $this->pageId)->where('psid', $this->psid)->where('key', $key)->delete();
        // Ang timer ng skip ay kasabay ng __wait: tapos na ang paghihintay (sagot, skip, "clear all fields").
        if ($key === self::WAIT) ChatbotWaits::forget($this->pageId, $this->psid);
    }

    /** "Clear all fields": lahat ng customer field at ang paghihintay (__wait); naiiwan ang __last_chat at ang pause ng tao. */
    public function clearAll(): void
    {
        foreach (array_keys($this->values) as $key) {
            if (!in_array($key, [self::LAST_CHAT, self::BOT_PAUSED], true)) $this->clear((string) $key);
        }
    }
}
