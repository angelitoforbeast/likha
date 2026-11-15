<?php

namespace App\Console\Commands;

use App\Services\Chatbot\ChatbotResults;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
    private const MEMORY = '1024M';
    /** Amendment 278-3: ang pinakamatagal na isang SELECT ng takbo (MySQL). */
    public const STATEMENT_SECONDS = 60;

    protected $signature = 'chatbot:results-warm';

    protected $description = 'Build the stored /chatbot/results copies: the default view when stale, then up to 3 wanted filters';

    /**
     * Amendment 278-3: sa MySQL, ang bawat SELECT ng takbong ito ay may hangganang 60 s (session max_execution_time; sa
     * MariaDB ay max_statement_time), para ang masamang plano ay pumalya agad at hindi humawak sa database ng live na
     * padala. Ibinabalik pagkatapos. Kapag hindi ma-set (ibang server), tuloy pa rin ang takbo.
     * @return array{0: string, 1: mixed}|null ang variable at ang dating value
     */
    public static function guard(): ?array
    {
        if (DB::connection()->getDriverName() !== 'mysql') return null;
        try {
            $maria = str_contains(strtolower((string) DB::selectOne('SELECT VERSION() AS v')->v), 'mariadb');
            [$var, $value] = $maria ? ['max_statement_time', self::STATEMENT_SECONDS] : ['max_execution_time', self::STATEMENT_SECONDS * 1000];
            $was = DB::selectOne("SELECT @@SESSION.{$var} AS v")->v;
            DB::statement("SET SESSION {$var} = " . $value);

            return [$var, $was];
        } catch (\Throwable $e) {
            Log::warning('chatbot:results-warm could not set the statement time limit', ['error' => mb_substr($e->getMessage(), 0, 200)]);

            return null;
        }
    }

    private static function unguard(array $guard): void
    {
        try {
            DB::statement("SET SESSION {$guard[0]} = " . (is_numeric($guard[1]) ? $guard[1] : 0));
        } catch (\Throwable) {
            // Ang session ay para sa prosesong ito lang; matatapos din kasama nito.
        }
    }

    public function handle(ChatbotResults $results): int
    {
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);
        if (!$lock->get()) {
            Log::info('chatbot:results-warm skipped: the previous run is still going');

            return self::SUCCESS;
        }
        // Ang unyon ng mga set ng customer ng 7 araw ay nasa memory (kada page): hindi bababa sa MEMORY ang takbong ito.
        $limit = (int) ini_parse_quantity((string) ini_get('memory_limit'));
        if ($limit > 0 && $limit < ini_parse_quantity(self::MEMORY)) ini_set('memory_limit', self::MEMORY);
        $t = microtime(true);
        $guard = self::guard();
        try {
            $r = $results->warm();
        } catch (\Throwable $e) {
            report($e);
            $r = ['built' => [], 'failed' => ['run'], 'dropped' => 0, 'pieces' => 0, 'pending' => []];
        } finally {
            if ($guard) self::unguard($guard);
            $lock->release();
        }
        // Spec 278-2: pati ilang bahagi ng araw ang nabasa, at ang hiling na kulang pa (itutuloy sa susunod na takbo).
        $line = sprintf('chatbot:results-warm built %s%s%s%s%s in %.1f s',
            $r['built'] ? implode(', ', array_map(fn ($b) => sprintf('%s (%.1f s)', $b[0], $b[1]), $r['built'])) : 'nothing',
            $r['pieces'] ? '; ' . $r['pieces'] . ' day ' . ($r['pieces'] === 1 ? 'piece' : 'pieces') : '',
            $r['pending'] ? '; still building ' . implode(', ', $r['pending']) : '',
            $r['dropped'] ? '; dropped ' . $r['dropped'] . ' wanted' : '', $r['failed'] ? '; failed ' . implode(', ', $r['failed']) : '', microtime(true) - $t);
        $r['failed'] ? Log::warning($line) : Log::info($line);
        $this->line($line);

        return $r['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
