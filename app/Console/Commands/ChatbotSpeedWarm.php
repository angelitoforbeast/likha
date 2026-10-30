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
    /** Ang memory_limit ng takbo kapag walang hangganan ang CLI (-1): ang badyet ng Sequences ay 3/4 nito. */
    private const MEMORY_WHEN_UNLIMITED = '512M';

    protected $signature = 'chatbot:speed-warm';

    protected $description = 'Build the default /chatbot/speed view (today, and yesterday when due) before its cache expires';

    public function handle(ResponseSpeed $speed): int
    {
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);
        if (!$lock->get()) {
            Log::info('chatbot:speed-warm skipped: the previous run is still going');

            return self::SUCCESS;
        }
        // Ang badyet ng Sequences ay 3/4 ng memory_limit: kapag walang hangganan ang CLI, may hangganan pa rin ang takbong ito.
        if ((int) ini_parse_quantity((string) ini_get('memory_limit')) <= 0) ini_set('memory_limit', self::MEMORY_WHEN_UNLIMITED);
        $t = microtime(true);
        try {
            $r = $speed->warmDefault();
        } catch (Throwable $e) {
            report($e);
            $r = ['warmed' => [], 'failed' => ['run'], 'too_large' => []];
        } finally {
            $lock->release();
        }
        // Ang too_large ay hindi pumalya (ang endpoint ang kukuwenta nito), pero nakikita sa linya.
        $line = sprintf('chatbot:speed-warm warmed %s%s%s in %.1f s', $r['warmed'] ? implode(', ', $r['warmed']) : 'nothing',
            $r['too_large'] ? '; too large ' . implode(', ', $r['too_large']) : '', $r['failed'] ? '; failed ' . implode(', ', $r['failed']) : '', microtime(true) - $t);
        $r['failed'] || $r['too_large'] ? Log::warning($line) : Log::info($line);
        $this->line($line);

        return $r['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
