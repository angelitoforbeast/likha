<?php

namespace App\Console\Commands;

use App\Services\Chatbot\SequenceSendPool;
use Illuminate\Console\Command;

/**
 * Ang pool ng padala ng mga sequence step (supervisor, isang proseso, hiwalay sa botcake:send-pool). Tumatakbo nang
 * --max-time segundo, saka lumalabas nang malinis (hinihintay ang mga nasa himpapawid) at binubuhay ulit ng supervisor.
 * Tumatanggi kapag may ibang buhay na sequence pool.
 */
class RunSequenceSendPool extends Command
{
    protected $signature = 'chatbot:sequence-pool {--max-time=3600 : Seconds before a clean exit (supervisor starts it again)}';
    protected $description = 'Send the chatbot sequence steps concurrently (Botcake or gateway), at the per-page rate, and resume each step with its result';

    private bool $stop = false;

    public function handle(): int
    {
        $pool = new SequenceSendPool();
        if (!$pool->start()) {
            $this->error('Another chatbot:sequence-pool is running.');
            sleep(10); // para hindi paulit-ulit na binubuhay ng supervisor
            return self::FAILURE;
        }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT, SIGQUIT] as $sig) pcntl_signal($sig, fn () => $this->stop = true);
        }

        $this->info('chatbot:sequence-pool started, up to ' . $pool->max() . ' sequence sends at a time.');
        $pool->run(max(10, (int) $this->option('max-time')), fn () => $this->stop);
        $this->info('chatbot:sequence-pool stopped.');

        return self::SUCCESS;
    }
}
