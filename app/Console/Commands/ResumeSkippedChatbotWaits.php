<?php

namespace App\Console\Commands;

use App\Jobs\ResumeChatbotWait;
use App\Services\Chatbot\BotcakeSendQueue;
use App\Services\Chatbot\ChatbotAiQueue;
use App\Services\Chatbot\SweepLimit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang "wait for reply" na lampas na sa skip_after: isang job kada hinihintay sa queue na "chatbot" (kada minuto, via
 * scheduler), ayon sa due_at (index), 500 kada batch, hanggang 50 segundo. Ang naipadala na ay hindi ipinapadala ulit
 * hangga't hindi lumilipas ang REDISPATCH_MINUTES (ang naiwan o nawalang job ay sinusubukan ulit). Kasabay: ang sweep ng
 * AI step (ChatbotAiQueue), para tapos pa rin sa failed port ang walang sagot kahit patay ang chatbot:ai-pool.
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
        $ai = ChatbotAiQueue::sweep();
        if ($ai['failed'] || $ai['redispatched']) $this->info("chatbot AI: {$ai['failed']} timed out, {$ai['redispatched']} resumed again");
        // Ang padala sa Botcake (botcake:send-pool): gaya ng AI, tapos pa rin kahit patay ang pool.
        $send = BotcakeSendQueue::sweep();
        if ($send['failed'] || $send['requeued'] || $send['redispatched']) $this->info("botcake sends: {$send['failed']} timed out, {$send['requeued']} chatblast queued again, {$send['redispatched']} resumed again");
        if (!Schema::hasTable('chatbot_waits')) return self::SUCCESS;
        $until = microtime(true) + self::SECONDS;
        $queued = 0;
        // Ang bawat batch ay may sariling hangganan ng oras (SweepLimit): kasama ng mga sweep sa itaas, laging natatapos ang
        // takbo bago mag-expire ang withoutOverlapping (5 min), kaya hindi ito nagpapatong kahit mabagal ang database.
        $more = true;
        while ($more) {
            $more = false;
            (new SweepLimit('chatbot waits'))->run(function (SweepLimit $limit) use (&$queued, &$more, $until) {
                $ids = $limit->cap(DB::table('chatbot_waits')->where('due_at', '<=', now())
                    ->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<', now()->subMinutes(self::REDISPATCH_MINUTES)))
                    ->orderBy('due_at')->limit(self::BATCH), ['id'])->pluck('id');
                if ($ids->isEmpty()) return;
                DB::table('chatbot_waits')->whereIn('id', $ids)->update(['dispatched_at' => now()]);
                foreach ($ids as $id) ResumeChatbotWait::dispatch((int) $id);
                $queued += $ids->count();
                $more = $ids->count() === self::BATCH && microtime(true) < $until;
            });
        }

        if ($queued) $this->info("chatbot waits: {$queued} queued");

        return self::SUCCESS;
    }
}
