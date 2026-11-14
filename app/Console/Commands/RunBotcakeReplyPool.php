<?php

namespace App\Console\Commands;

use App\Services\Chatbot\BotcakeSendPool;
use Illuminate\Console\Command;

/**
 * The bot replies pool (BotcakeReplyPool; supervisor, one process: deploy/supervisor/*-botcake-reply.conf). Tumatakbo nang
 * --max-time segundo, saka lumalabas nang malinis (hinihintay ang mga nasa himpapawid) at binubuhay ulit ng supervisor.
 * Tumatanggi kapag may ibang buhay na pool.
 */
class RunBotcakeReplyPool extends Command
{
    protected $signature = 'botcake:reply-pool {--max-time=3600 : Seconds before a clean exit (supervisor starts it again)}';
    protected $description = 'Send the chatbot replies to Botcake in their own pool, apart from chatblast, at the per-page rate';

    private bool $stop = false;

    public function handle(): int
    {
        $pool = new BotcakeReplyPool();
        if (!$pool->start()) {
            $this->error('Another botcake:reply-pool is running.');
            sleep(10); // para hindi paulit-ulit na binubuhay ng supervisor
            return self::FAILURE;
        }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT, SIGQUIT] as $sig) pcntl_signal($sig, fn () => $this->stop = true);
        }

        $this->info('botcake:reply-pool started, up to ' . $pool->max() . ' bot replies at a time.');
        $pool->run(max(10, (int) $this->option('max-time')), fn () => $this->stop);
        $this->info('botcake:reply-pool stopped.');

        return self::SUCCESS;
    }
}
