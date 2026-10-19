<?php

namespace App\Console\Commands;

use App\Jobs\ResumeChatbotWait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang "wait for reply" na lampas na sa skip_after: isang job kada hinihintay sa queue na "chatbot" (kada minuto, via
 * scheduler), ayon sa due_at (index), 500 kada batch, hanggang 50 segundo. Ang naipadala na ay hindi ipinapadala ulit
 * hangga't hindi lumilipas ang REDISPATCH_MINUTES (ang naiwan o nawalang job ay sinusubukan ulit).
 */
class ResumeSkippedChatbotWaits extends Command
{
    public const BATCH = 500;
    public const SECONDS = 50;
    public const REDISPATCH_MINUTES = 5;

    protected $signature   = 'chatbot:resume-skipped';
    protected $description = 'Queue a resume through the skipped port for each chatbot wait past its skip_after deadline';

    public function handle(): int
    {
        if (!Schema::hasTable('chatbot_waits')) return self::SUCCESS;
        $until = microtime(true) + self::SECONDS;
        $queued = 0;
        do {
            $ids = DB::table('chatbot_waits')->where('due_at', '<=', now())
                ->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<', now()->subMinutes(self::REDISPATCH_MINUTES)))
                ->orderBy('due_at')->limit(self::BATCH)->pluck('id');
            if ($ids->isEmpty()) break;
            DB::table('chatbot_waits')->whereIn('id', $ids)->update(['dispatched_at' => now()]);
            foreach ($ids as $id) ResumeChatbotWait::dispatch((int) $id);
            $queued += $ids->count();
        } while ($ids->count() === self::BATCH && microtime(true) < $until);

        if ($queued) $this->info("chatbot waits: {$queued} queued");

        return self::SUCCESS;
    }
}
