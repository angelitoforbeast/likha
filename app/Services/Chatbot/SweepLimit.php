<?php

namespace App\Services\Chatbot;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ang hangganan ng oras ng isang sweep (queue.pools.sweep_ms, mga 10 s): ang bawat SELECT ng sweep ay may
 * MAX_EXECUTION_TIME ng MySQL na katumbas ng natitirang oras, kaya hindi kailanman humahaba ang sweep (at ang pagharang
 * nito sa pool) nang lampas dito, at walang query na naiiwang tumatakbo sa server nang isang oras. Kapag naubos ang oras:
 * isang warning, at ang susunod na sweep ang susubok ulit.
 */
final class SweepLimit
{
    /** Ang error ng MySQL (ER_QUERY_TIMEOUT) at ng MariaDB (ER_STATEMENT_TIMEOUT) kapag lumampas ang query sa oras. */
    public const TIMEOUT_CODES = [3024, 1969];

    public readonly int $ms;
    private float $until;

    public function __construct(public readonly string $name, ?int $ms = null)
    {
        $this->ms = $ms ?? self::ms();
        $this->until = microtime(true) + $this->ms / 1000;
    }

    /** Ang oras ng isang sweep sa ms (queue.pools.sweep_ms), 100 ms hanggang 60 s. */
    public static function ms(): int
    {
        return min(60000, max(100, (int) config('queue.pools.sweep_ms', 10000)));
    }

    /** Ang natitirang oras ng sweep sa ms (0 = ubos na). */
    public function left(): int
    {
        return max(0, (int) floor(($this->until - microtime(true)) * 1000));
    }

    /**
     * Ang $q na babasa ng $columns, may hangganan ng natitirang oras. Sa MySQL lang: ang hint ay kasunod mismo ng "select"
     * (bago ang unang column), kung saan lang ito binabasa ng MySQL; ang ibang driver (SQLite sa test) ay walang hint.
     * Ubos na ang oras: humihinto ang sweep bago pa ang query.
     */
    public function cap(Builder $q, array $columns = ['*']): Builder
    {
        $left = $this->left();
        if ($left <= 0) throw new SweepTimedOut("{$this->name}: the {$this->ms} ms limit is used up");
        if ($q->getConnection()->getDriverName() !== 'mysql') return $q->select($columns);
        $first = array_shift($columns);

        return $q->select(array_merge([DB::raw('/*+ MAX_EXECUTION_TIME(' . $left . ') */ ' . $q->getGrammar()->wrap($first))], $columns));
    }

    /**
     * Ang USE INDEX ($index ng $table) kapag mayroon ang index sa database na ito: ang pangalang wala ay error ng MySQL sa
     * bawat query. Isang tingin kada proseso (naka-memo sa app), dahil tinatawag ito sa bawat ikot ng pool.
     */
    public static function useIndex(Builder $q, string $table, string $index): Builder
    {
        $key = 'sweep-limit.index.' . $table . '.' . $index;
        if (!app()->bound($key)) app()->instance($key, \Illuminate\Support\Facades\Schema::hasIndex($table, $index));

        return app($key) ? $q->useIndex($index) : $q;
    }

    /** Ang error ba ay ang hangganan ng oras (ng MySQL o ng cap)? */
    public static function timedOut(Throwable $e): bool
    {
        if ($e instanceof SweepTimedOut) return true;

        return $e instanceof QueryException
            && (in_array((int) ($e->errorInfo[1] ?? 0), self::TIMEOUT_CODES, true) || str_contains($e->getMessage(), 'maximum statement execution time exceeded'));
    }

    /** Patakbuhin ang $steps; ang hangganan ng oras ay isang warning lang (ang susunod na sweep ang susubok), ang ibang error ay ibinabato. */
    public function run(callable $steps): void
    {
        try {
            $steps($this);
        } catch (Throwable $e) {
            if (!self::timedOut($e)) throw $e;
            Log::warning("{$this->name} stopped at its {$this->ms} ms limit; the next sweep tries again");
        }
    }
}
