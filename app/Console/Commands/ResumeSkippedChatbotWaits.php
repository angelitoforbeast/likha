<?php

namespace App\Console\Commands;

use App\Services\Chatbot\ChatbotRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang "wait for reply" na lampas na sa skip_after: nagpapatuloy sa port na skipped (kada minuto, via scheduler). Ayon
 * sa due_at (index), 500 kada batch, hanggang 50 segundo bawat tick; ang hindi natapos ay sa susunod na tick.
 */
class ResumeSkippedChatbotWaits extends Command
{
    public const BATCH = 500;
    public const SECONDS = 50;

    protected $signature   = 'chatbot:resume-skipped';
    protected $description = 'Resume chatbot waits past their skip_after deadline through the skipped port';

    public function handle(ChatbotRunner $runner): int
    {
        if (!Schema::hasTable('chatbot_waits')) return self::SUCCESS;
        $until = microtime(true) + self::SECONDS;
        $counts = [];
        $left = [];
        do {
            // Ang natapos ay nabubura; ang naiwan (locked, may chat pa sa queue) ay nilalaktawan sa tick na ito, para hindi umikot.
            $ids = DB::table('chatbot_waits')->where('due_at', '<=', now())->when($left, fn ($q) => $q->whereNotIn('id', $left))
                ->orderBy('due_at')->limit(self::BATCH)->pluck('id');
            foreach ($ids as $id) {
                $result = $runner->skip((int) $id);
                $counts[$result] = ($counts[$result] ?? 0) + 1;
                if (in_array($result, ['locked', 'waiting'], true)) $left[] = (int) $id;
                if (microtime(true) > $until || count($left) >= self::BATCH) break 2;
            }
        } while ($ids->count() === self::BATCH);

        if ($counts) $this->info('chatbot waits: ' . implode(', ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($counts), $counts)));

        return self::SUCCESS;
    }
}
