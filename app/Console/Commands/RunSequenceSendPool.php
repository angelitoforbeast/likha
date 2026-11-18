<?php

namespace App\Console\Commands;

use App\Services\Chatbot\SequenceSendPool;
use Illuminate\Console\Command;

/**
 * Ang pool ng padala ng mga sequence step (supervisor, hiwalay sa botcake:send-pool). Tumatakbo nang --max-time segundo,
 * saka lumalabas nang malinis (hinihintay ang mga nasa himpapawid) at binubuhay ulit ng supervisor. Tumatanggi kapag may
 * ibang buhay na sequence pool sa parehong bahagi, o buhay na pool na iba ang bilang ng proseso.
 * Spec 301: kapag services.botcake.sequence_pools = N > 1, N na proseso (--shard=0..N-1, hal. numprocs=N at
 * --shard=%(process_num)s sa supervisor), bawat isa sa sariling bahagi ng mga page. N = 1: iisang proseso, gaya ng dati.
 */
class RunSequenceSendPool extends Command
{
    protected $signature = 'chatbot:sequence-pool {--max-time=3600 : Seconds before a clean exit (supervisor starts it again)} {--shard=0 : This process\'s share of the pages (0 to BOTCAKE_SEQUENCE_POOLS - 1)}';
    protected $description = 'Send the chatbot sequence steps concurrently (Botcake or gateway), at the per-page rate, and resume each step with its result';

    private bool $stop = false;

    public function handle(): int
    {
        $shard = (int) $this->option('shard');
        $pool = new SequenceSendPool(null, null, $shard);
        if ($shard < 0 || $shard >= $pool->pools) {
            $this->error('chatbot:sequence-pool --shard=' . $shard . ' is outside BOTCAKE_SEQUENCE_POOLS=' . $pool->pools . ' (0 to ' . ($pool->pools - 1) . ').');
            sleep(10); // para hindi paulit-ulit na binubuhay ng supervisor
            return self::FAILURE;
        }
        if (!$pool->start()) {
            $this->error('Another chatbot:sequence-pool is running' . ($pool->pools > 1 ? ' for share ' . $shard . ', or with another number of processes' : '') . '.');
            sleep(10); // para hindi paulit-ulit na binubuhay ng supervisor
            return self::FAILURE;
        }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT, SIGQUIT] as $sig) pcntl_signal($sig, fn () => $this->stop = true);
        }

        $this->info('chatbot:sequence-pool started' . ($pool->pools > 1 ? ' (share ' . $shard . ' of ' . $pool->pools . ')' : '') . ', up to ' . $pool->max() . ' sequence sends at a time.');
        $pool->run(max(10, (int) $this->option('max-time')), fn () => $this->stop);
        $this->info('chatbot:sequence-pool stopped.');

        return self::SUCCESS;
    }
}
