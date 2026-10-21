<?php

namespace App\Console\Commands;

use App\Services\Chatbot\ChatbotAiPool;
use Illuminate\Console\Command;

/**
 * Ang pool ng AI step ng chatbot (supervisor, isang proseso: deploy/supervisor/likha-chatbot-ai.conf). Tumatakbo nang
 * --max-time segundo, saka lumalabas nang malinis (hinihintay ang mga nasa himpapawid) at binubuhay ulit ng supervisor.
 * Tumatanggi kapag may ibang buhay na pool.
 */
class RunChatbotAiPool extends Command
{
    protected $signature = 'chatbot:ai-pool {--max-time=3600 : Seconds before a clean exit (supervisor starts it again)}';
    protected $description = 'Send the chatbot AI steps to OpenAI concurrently and resume each run with its own answer';

    private bool $stop = false;

    public function handle(): int
    {
        $pool = new ChatbotAiPool();
        if (!$pool->start()) {
            $this->error('Another chatbot:ai-pool is running.');
            sleep(10); // para hindi paulit-ulit na binubuhay ng supervisor
            return self::FAILURE;
        }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT, SIGQUIT] as $sig) pcntl_signal($sig, fn () => $this->stop = true);
        }

        $this->info('chatbot:ai-pool started, up to ' . $pool->max() . ' requests at a time.');
        $pool->run(max(10, (int) $this->option('max-time')), fn () => $this->stop);
        $this->info('chatbot:ai-pool stopped.');

        return self::SUCCESS;
    }
}
