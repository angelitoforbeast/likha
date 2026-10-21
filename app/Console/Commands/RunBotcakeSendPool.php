<?php

namespace App\Console\Commands;

use App\Services\Chatbot\BotcakeSendPool;
use Illuminate\Console\Command;

/**
 * Ang pool ng padala sa Botcake (supervisor, isang proseso: deploy/supervisor/likha-botcake-send.conf). Tumatakbo nang
 * --max-time segundo, saka lumalabas nang malinis (hinihintay ang mga nasa himpapawid) at binubuhay ulit ng supervisor.
 * Tumatanggi kapag may ibang buhay na pool.
 */
class RunBotcakeSendPool extends Command
{
    protected $signature = 'botcake:send-pool {--max-time=3600 : Seconds before a clean exit (supervisor starts it again)}';
    protected $description = 'Send the chatbot and chatblast Botcake calls concurrently, at the per-page rate, and resume each run with its own result';

    private bool $stop = false;

    public function handle(): int
    {
        $pool = new BotcakeSendPool();
        if (!$pool->start()) {
            $this->error('Another botcake:send-pool is running.');
            sleep(10); // para hindi paulit-ulit na binubuhay ng supervisor
            return self::FAILURE;
        }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT, SIGQUIT] as $sig) pcntl_signal($sig, fn () => $this->stop = true);
        }

        $this->info('botcake:send-pool started, up to ' . $pool->max() . ' tickets at a time.');
        $pool->run(max(10, (int) $this->option('max-time')), fn () => $this->stop);
        $this->info('botcake:send-pool stopped.');

        return self::SUCCESS;
    }
}
