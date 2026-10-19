<?php

namespace App\Services\Chatbot;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang deadline ng "wait for reply" na may skip_after (chatbot_waits, isa kada customer). Ang wala pang table (hindi pa
 * na-migrate) ay hindi pumipigil sa takbo: walang timer lang, gaya ng dati.
 */
class ChatbotWaits
{
    private static function guard(callable $write): void
    {
        try {
            $write();
        } catch (\Illuminate\Database\QueryException $e) {
            if (Schema::hasTable('chatbot_waits')) throw $e;
        }
    }

    public static function park(int $pageId, int $psid, int $flowId, string $nodeId, CarbonInterface $due): void
    {
        self::guard(fn () => DB::table('chatbot_waits')->upsert(
            [['page_id' => $pageId, 'psid' => $psid, 'flow_id' => $flowId, 'node_id' => $nodeId, 'due_at' => $due, 'created_at' => now()]],
            ['page_id', 'psid'], ['flow_id', 'node_id', 'due_at', 'created_at'],
        ));
    }

    public static function forget(int $pageId, int $psid): void
    {
        self::guard(fn () => DB::table('chatbot_waits')->where('page_id', $pageId)->where('psid', $psid)->delete());
    }
}
