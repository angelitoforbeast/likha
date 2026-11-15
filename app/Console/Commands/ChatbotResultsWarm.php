<?php

namespace App\Console\Commands;

use App\Services\Chatbot\ChatbotResults;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Spec 278, kada minuto: ang kopya ng /chatbot/results at results.json ay dito binubuo, hindi sa web request (lampas 2
 * minuto ang 7 araw sa malaking site). Ang default na view kapag wala o lampas 15 minuto na, saka hanggang 3 hiling mula
 * sa wanted (pinakamatanda muna). Isang takbo lang sa isang pagkakataon (lock, bukod sa withoutOverlapping ng schedule,
 * para pati ang manu-manong takbo); isang linya ng log kada takbo, may segundo kada build. Walang queue: ang queue ay
 * para sa live na chatbot.
 */
class ChatbotResultsWarm extends Command
{
    public const LOCK = 'chatbot-results:warm';
    /** Kapareho ng withoutOverlapping(30): ang lock ng namatay na proseso ay hindi haharang nang matagal. */
    private const LOCK_SECONDS = 1800;

    protected $signature = 'chatbot:results-warm';

    protected $description = 'Build the stored /chatbot/results copies: the default view when stale, then up to 3 wanted filters';

    public function handle(ChatbotResults $results): int
    {
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);
        if (!$lock->get()) {
            Log::info('chatbot:results-warm skipped: the previous run is still going');

            return self::SUCCESS;
        }
        $t = microtime(true);
        try {
            $r = $results->warm();
        } catch (\Throwable $e) {
            report($e);
            $r = ['built' => [], 'failed' => ['run'], 'dropped' => 0];
        } finally {
            $lock->release();
        }
        $line = sprintf('chatbot:results-warm built %s%s%s in %.1f s',
            $r['built'] ? implode(', ', array_map(fn ($b) => sprintf('%s (%.1f s)', $b[0], $b[1]), $r['built'])) : 'nothing',
            $r['dropped'] ? '; dropped ' . $r['dropped'] . ' wanted' : '', $r['failed'] ? '; failed ' . implode(', ', $r['failed']) : '', microtime(true) - $t);
        $r['failed'] ? Log::warning($line) : Log::info($line);
        $this->line($line);

        return $r['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
