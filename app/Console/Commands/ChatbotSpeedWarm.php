<?php

namespace App\Console\Commands;

use App\Services\Chatbot\ResponseSpeed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Kada 2 minuto: ang default na view ng /chatbot/speed (ngayon, at ang kahapon kapag oras na) ay kinukuwenta bago ito
 * mag-expire, para ang nagbubukas ng page ay hindi naghihintay ng buong kuwenta. Isang takbo lang sa isang pagkakataon
 * (lock, bukod sa withoutOverlapping ng schedule, para pati ang manu-manong takbo); isang linya ng log kada takbo. Ang
 * pumalyang bahagi ay hindi isinusulat: ang page ang kukuwenta nito gaya ng dati.
 */
class ChatbotSpeedWarm extends Command
{
    public const LOCK = 'response-speed:warm';
    /** Ang lock ay mas mahaba sa pinakamabagal na takbo; ang lock ng namatay na proseso ay hindi haharang nang matagal. */
    private const LOCK_SECONDS = 600;

    protected $signature = 'chatbot:speed-warm';

    protected $description = 'Build the default /chatbot/speed view (today, and yesterday when due) before its cache expires';

    public function handle(ResponseSpeed $speed): int
    {
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);
        if (!$lock->get()) {
            Log::info('chatbot:speed-warm skipped: the previous run is still going');

            return self::SUCCESS;
        }
        $t = microtime(true);
        try {
            $r = $speed->warmDefault();
        } catch (Throwable $e) {
            report($e);
            $r = ['warmed' => [], 'failed' => ['run']];
        } finally {
            $lock->release();
        }
        $line = sprintf('chatbot:speed-warm warmed %s%s in %.1f s', $r['warmed'] ? implode(', ', $r['warmed']) : 'nothing',
            $r['failed'] ? '; failed ' . implode(', ', $r['failed']) : '', microtime(true) - $t);
        $r['failed'] ? Log::warning($line) : Log::info($line);
        $this->line($line);

        return $r['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
