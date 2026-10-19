<?php

namespace App\Services\Chatbot;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang executed actions ng bot (chatbot_events): isinusulat nang sabay pagkatapos ng bawat takbo (isang insert), at ang
 * text ng bawat isa para sa /chatbot/inbox. 30 araw lang ang itinatago.
 */
class ChatbotEvents
{
    public const KEEP_DAYS = 30;

    /** Isang insert; ang wala pang table (hindi pa na-migrate) ay hindi pumipigil sa takbo. */
    private static function insert(array $rows): void
    {
        try {
            DB::table('chatbot_events')->insert($rows);
        } catch (\Illuminate\Database\QueryException $e) {
            if (Schema::hasTable('chatbot_events')) report($e);
        }
    }

    /** Ang lahat ng event ng isang takbo, sa isang insert. */
    public static function store(FlowRun $run, int $pageId, int $psid): void
    {
        if (!$run->events) return;
        $now = now();
        self::insert(array_map(fn ($e) => [
            'page_id' => $pageId, 'psid' => $psid, 'chat_id' => $run->chatId, 'flow_id' => $run->flowId, 'node_id' => $e['node'],
            'type' => $e['type'], 'detail' => json_encode($e['detail'], JSON_UNESCAPED_UNICODE), 'created_at' => $now,
        ], $run->events));
    }

    /** Ang chat na hindi pinatakbo (bot paused, chat too old). */
    public static function skipped(int $pageId, int $psid, ?int $chatId, ?int $flowId, string $reason): void
    {
        self::insert(['page_id' => $pageId, 'psid' => $psid, 'chat_id' => $chatId, 'flow_id' => $flowId, 'node_id' => null,
            'type' => 'skipped', 'detail' => json_encode(['reason' => $reason, 'run' => bin2hex(random_bytes(4))]), 'created_at' => now()]);
    }

    public static function prune(): int
    {
        if (!Schema::hasTable('chatbot_events')) return 0;
        $deleted = 0;
        do {
            $ids = DB::table('chatbot_events')->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->orderBy('id')->limit(5000)->pluck('id');
            if ($ids->isNotEmpty()) $deleted += DB::table('chatbot_events')->whereIn('id', $ids)->delete();
        } while ($ids->count() === 5000);

        return $deleted;
    }

    /** Ang linya sa usapan (gaya ng CBB). $flows: id => pangalan. */
    public static function text(string $type, ?string $node, array $d, array $flows = []): string
    {
        $field = fn () => mb_strtoupper((string) ($d['field'] ?? ''));

        return match ($type) {
            'run_started' => 'Flow ' . ($flows[(int) ($d['flow'] ?? 0)] ?? '#' . ($d['flow'] ?? '?')) . ' started (' . ($d['reason'] ?? 'new') . ')',
            'condition' => 'Condition ' . $node . ' → ' . (($d['result'] ?? 'no') === 'no' ? 'no match' : $d['result']),
            'field' => match ($d['op'] ?? '') {
                'clear_all' => 'Custom field values changed — all fields cleared',
                'clear' => 'Custom field value changed — ' . $field() . ' cleared',
                'append' => 'Custom field value changed — ' . $field() . ' appended',
                default => 'Custom field value changed — ' . $field() . ' was set to ' . ($d['value'] ?? ''),
            },
            'ai' => 'AI step ' . $node . ' → ' . (($d['result'] ?? '') === 'success'
                ? 'success' . (($d['saved_to'] ?? '') !== '' ? ' (saved to ' . mb_strtoupper($d['saved_to']) . ')' : '')
                : 'failed: ' . ($d['error'] ?? '')),
            'sheet' => 'Sheet row ' . $node . ' → ' . ($d['result'] ?? '') . (($d['error'] ?? '') !== '' ? ': ' . $d['error'] : ''),
            'waiting' => 'Waiting for reply at step ' . $node,
            'run_ended' => 'Run ended (' . ($d['reason'] ?? 'end') . ')',
            'skipped' => 'Skipped: ' . ($d['reason'] ?? '') . ($node ? ' (' . $node . ')' : ''),
            default => $type,
        };
    }
}
