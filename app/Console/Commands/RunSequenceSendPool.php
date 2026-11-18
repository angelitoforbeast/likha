<?php

namespace App\Console\Commands;

use App\Services\Chatbot\SequenceSendPool;
use Illuminate\Console\Command;

/**
 * Ang pool ng padala ng mga sequence step (supervisor, hiwalay sa botcake:send-pool). Tumatakbo nang --max-time segundo,
 * saka lumalabas nang malinis (hinihintay ang mga nasa himpapawid) at binubuhay ulit ng supervisor. Tumatanggi kapag may
 * ibang buhay na sequence pool sa parehong bahagi, o buhay na pool na iba ang bilang ng proseso.
 * Spec 301: kapag ang bilang (SequenceSendPool::pools(): app_settings chatbot.sequence_pools, saka BOTCAKE_SEQUENCE_POOLS)
 * ay N > 1, N na proseso (--shard=0..N-1, hal. numprocs=N at --shard=%(process_num)s sa supervisor), bawat isa sa sariling
 * bahagi ng mga page. N = 1: iisang proseso, gaya ng dati. Kapag nagbago ang bilang habang tumatakbo, humihinto nang malinis
 * at sa pagbuhay ng supervisor ay sa bagong bilang na (ang bahaging lampas sa bilang ay hindi nagsisimula).
 */
class RunSequenceSendPool extends Command
{
    protected $signature = 'chatbot:sequence-pool {--max-time=3600 : Seconds before a clean exit (supervisor starts it again)} {--shard=0 : This process\'s share of the pages (0 to the number of sequence pools - 1)}';
    protected $description = 'Send the chatbot sequence steps concurrently (Botcake or gateway), at the per-page rate, and resume each step with its result';

    private bool $stop = false;

    public function handle(): int
    {
        $shard = (int) $this->option('shard');
        $pool = new SequenceSendPool(null, null, $shard);
        if ($shard < 0 || $shard >= $pool->pools) {
            $this->error('chatbot:sequence-pool --shard=' . $shard . ' is outside the number of sequence pools, ' . $pool->pools . ' (app setting '
                . SequenceSendPool::POOLS_SETTING . '; shares 0 to ' . ($pool->pools - 1) . ').');
            sleep(10); // para hindi paulit-ulit na binubuhay ng supervisor
            return self::FAILURE;
        }
        if (!$pool->start()) {
            $this->error('Another chatbot:sequence-pool is running' . ($pool->pools > 1 ? ' for share ' . $shard . ', or with another number of processes' : ', or with another number of processes') . '.');
            sleep(10); // para hindi paulit-ulit na binubuhay ng supervisor
            return self::FAILURE;
        }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT, SIGQUIT] as $sig) pcntl_signal($sig, fn () => $this->stop = true);
        }

        $this->info('chatbot:sequence-pool started' . ($pool->pools > 1 ? ' (share ' . $shard . ' of ' . $pool->pools . ')' : '') . ', up to ' . $pool->max() . ' sequence sends at a time.');
        $changed = false;
        $pool->run(max(10, (int) $this->option('max-time')), function () use ($pool, &$changed) {
            if (!$changed && $pool->poolsChanged()) $changed = true;

            return $this->stop || $changed;
        });
        $this->info('chatbot:sequence-pool stopped' . ($changed ? ' (the number of sequence pools changed; supervisor starts it again with the new number)' : '') . '.');

        return self::SUCCESS;
    }
}
