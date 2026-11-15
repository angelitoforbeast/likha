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
 * (lock, bukod sa withoutOverlapping ng schedule, para pati ang manu-manong takbo); isang linya ng log kada takbo, may
 * segundo ng bawat bahagi. Ang pumalyang bahagi ay hindi isinusulat.
 */
class ChatbotSpeedWarm extends Command
{
    public const LOCK = 'response-speed:warm';
    /** Ang lock ay mas mahaba sa pinakamabagal na takbo; ang lock ng namatay na proseso ay hindi haharang nang matagal. */
    private const LOCK_SECONDS = 600;
    /**
     * Ang memory_limit ng takbo kapag mas mababa o walang hangganan ang sa CLI: ang badyet ng Sequences ay 3/4 nito. Ang
     * buong araw ay umabot nang 417M sa dating 512M (badyet 384M), kaya too_large at sayang ang buong basa.
     */
    public const MEMORY_LIMIT = '1024M';

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
        $was = (string) ini_get('memory_limit');
        $limit = (int) ini_parse_quantity($was);
        if ($limit <= 0 || $limit < ini_parse_quantity(self::MEMORY_LIMIT)) ini_set('memory_limit', self::MEMORY_LIMIT);
        $t = microtime(true);
        try {
            $r = $speed->warmDefault();
        } catch (Throwable $e) {
            report($e);
            $r = ['warmed' => [], 'failed' => ['run'], 'too_large' => [], 'secs' => []];
        } finally {
            $lock->release();
            // Ibinabalik (ang proseso ng test o ng manu-manong takbo ay tuloy pa); hindi bababa sa nagamit na.
            @ini_set('memory_limit', $was);
        }
        // Bawat bahagi ay may sariling segundo, para makita ang mabagal. Ang too_large ay hindi pumalya, pero nakikita sa linya.
        $parts = fn (array $labels) => implode(', ', array_map(fn ($l) => isset($r['secs'][$l]) ? sprintf('%s (%.1f s)', $l, $r['secs'][$l]) : $l, $labels));
        $line = sprintf('chatbot:speed-warm warmed %s%s%s in %.1f s', $r['warmed'] ? $parts($r['warmed']) : 'nothing',
            $r['too_large'] ? '; too large ' . $parts($r['too_large']) : '', $r['failed'] ? '; failed ' . $parts($r['failed']) : '', microtime(true) - $t);
        $r['failed'] || $r['too_large'] ? Log::warning($line) : Log::info($line);
        $this->line($line);

        return $r['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
