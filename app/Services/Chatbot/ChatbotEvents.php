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
            'type' => $e['type'], 'detail' => json_encode($e['detail'], JSON_UNESCAPED_UNICODE), 'created_at' => $e['at'] ?? $now,
        ], $run->events));
    }

    /** Ang chat na hindi pinatakbo (bot paused, chat too old), o ang skip na hindi itinuloy ($node). */
    public static function skipped(int $pageId, int $psid, ?int $chatId, ?int $flowId, string $reason, ?string $node = null): void
    {
        self::insert(['page_id' => $pageId, 'psid' => $psid, 'chat_id' => $chatId, 'flow_id' => $flowId, 'node_id' => $node,
            'type' => 'skipped', 'detail' => json_encode(['reason' => $reason, 'run' => bin2hex(random_bytes(4))]), 'created_at' => now()]);
    }

    /** Isang event na hindi bahagi ng takbo (hal. sequence mula sa inbox o sa tick). */
    public static function write(int $pageId, int $psid, string $type, array $detail, ?int $flowId = null, ?string $node = null): void
    {
        $detail = array_map(fn ($v) => is_string($v) ? mb_substr($v, 0, 200) : $v, $detail);
        self::insert(['page_id' => $pageId, 'psid' => $psid, 'chat_id' => null, 'flow_id' => $flowId, 'node_id' => $node,
            'type' => $type, 'detail' => json_encode($detail + ['run' => bin2hex(random_bytes(4))], JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
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

    private static function sequenceText(array $d): string
    {
        $name = (string) ($d['name'] ?? '?');
        $step = 'Sequence ' . $name . ' step ' . ($d['step'] ?? '?');

        return match ($d['op'] ?? '') {
            'subscribed' => 'Subscribed to sequence ' . $name,
            'already' => 'Already in sequence ' . $name,
            'refused' => 'Not subscribed to ' . $name . ': ' . ($d['reason'] ?? ''),
            'unsubscribed' => 'Unsubscribed from ' . $name . ' (' . ($d['reason'] ?? '') . ')',
            'ran' => $step . ' ran',
            'skipped' => $step . ' skipped (' . ($d['reason'] ?? '') . ')',
            'held' => $step . ' held (' . ($d['reason'] ?? '') . ')',
            'failed' => $step . ' failed: ' . ($d['error'] ?? ''),
            'paused' => 'Sequence ' . $name . ' paused (' . ($d['reason'] ?? '') . ')',
            'stopped' => 'Sequence ' . $name . ' stopped (' . ($d['reason'] ?? '') . ')',
            'completed' => 'Sequence ' . $name . ' completed',
            'test' => 'Test: would ' . ($d['action'] ?? 'subscribe') . ' ' . $name,
            default => 'Sequence ' . $name,
        };
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
            'ai_pending' => 'AI step ' . $node . ' → waiting for the answer',
            'ai_dropped' => 'AI answer at step ' . $node . ' dropped: ' . ($d['reason'] ?? ''),
            'ai_mismatch' => 'AI answer at step ' . $node . ' stopped: it belongs to another customer (nothing sent)',
            'sheet' => 'Sheet row ' . $node . ' → ' . ($d['result'] ?? '') . (($d['error'] ?? '') !== '' ? ': ' . $d['error'] : ''),
            'waiting' => 'Waiting for reply at step ' . $node . (($d['skip_after'] ?? '') !== '' ? ' (skips after ' . $d['skip_after'] . ')' : ''),
            'wait_skipped' => 'No reply in ' . ($d['after'] ?? '?') . ' → skipped at step ' . $node,
            'run_ended' => 'Run ended (' . ($d['reason'] ?? 'end') . ')',
            'skipped' => 'Skipped: ' . ($d['reason'] ?? '') . ($node ? ' (' . $node . ')' : ''),
            'sequence' => self::sequenceText($d),
            default => $type,
        };
    }
}
