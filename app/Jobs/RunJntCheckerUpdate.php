<?php

namespace App\Jobs;

use App\Models\MacroOutput;
use App\Services\Jnt\JntCheckerRuns;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Isinusulat ang WAYBILL sa mga na-match na macro_output row ng isang J&T Checker run (spec 283).
 *
 * Dati: isang UPDATE kada waybill (libo-libong query sa malaki at abalang table, 50+ s). Ngayon: isang
 * UPDATE ... SET waybill = CASE id ... END WHERE id IN (...) kada BATCH ids, bawat isa sariling maikling
 * statement (walang transaction sa kabuuan, walang table lock), sa queue para hindi tumama sa 60 s ng nginx.
 */
class RunJntCheckerUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const QUEUE = 'default';
    public const BATCH = 500;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(public string $runId)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(JntCheckerRuns $runs): void
    {
        @set_time_limit(0);

        $state = $runs->getUpdate($this->runId);
        if ($state !== null && $state['status'] !== JntCheckerRuns::STATUS_QUEUED) {
            return; // tumatakbo na o tapos na: hindi uulitin, para isang beses lang ang bilang
        }

        $result = $runs->readResult($this->runId);
        if ($result === null) {
            $runs->patchUpdate($this->runId, [
                'status' => JntCheckerRuns::STATUS_ERROR,
                'error'  => 'This check expired, please upload again.',
            ]);
            return;
        }

        $runs->patchUpdate($this->runId, ['status' => JntCheckerRuns::STATUS_RUNNING, 'started_at' => now()->timestamp]);
        $started = microtime(true);

        $updated = $this->write($result['updatable'] ?? []);

        $runs->patchUpdate($this->runId, ['status' => JntCheckerRuns::STATUS_DONE, 'count' => $updated]);

        Log::info('JNT checker update done', [
            'run' => $this->runId, 'rows' => $updated, 'seconds' => round(microtime(true) - $started, 1),
        ]);
    }

    /**
     * @param array<int, array{id:int, waybill:string}> $updatable
     * @return int affected rows, gaya ng dati (ang nabura na row sa pagitan ng Upload at Update ay hindi kasama)
     */
    public function write(array $updatable): int
    {
        // id => waybill. Kapag lumabas ang id nang dalawang beses, ang huli ang nananaig, gaya ng dati
        // (ang dating loop kada waybill ay sumusulat sa pagkakasunod ng unang paglitaw ng waybill).
        $byWaybill = [];
        foreach ($updatable as $u) {
            if (!isset($u['id'], $u['waybill'])) continue;
            $byWaybill[(string) $u['waybill']][] = (int) $u['id'];
        }
        $map = [];
        foreach ($byWaybill as $waybill => $ids) {
            foreach ($ids as $id) {
                unset($map[$id]);
                $map[$id] = (string) $waybill;
            }
        }

        $model   = new MacroOutput();
        $grammar = DB::connection($model->getConnectionName())->getQueryGrammar();
        $table   = $grammar->wrapTable($model->getTable());
        $idCol   = $grammar->wrap('id');
        $wbCol   = $grammar->wrap('waybill');
        // Ang dating Eloquent update ay nagse-set din ng updated_at; ganoon pa rin.
        $tsSql   = $model->usesTimestamps() && $model->getUpdatedAtColumn()
            ? ', ' . $grammar->wrap($model->getUpdatedAtColumn()) . ' = ?'
            : '';

        $updated = 0;
        foreach (array_chunk($map, self::BATCH, true) as $chunk) {
            $case = [];
            $bindings = [];
            foreach ($chunk as $id => $waybill) {
                $case[] = 'WHEN ? THEN ?';
                $bindings[] = $id;
                $bindings[] = $waybill;
            }
            if ($tsSql !== '') $bindings[] = $model->freshTimestampString();
            $ids = array_keys($chunk);
            array_push($bindings, ...$ids);

            $sql = "UPDATE {$table} SET {$wbCol} = CASE {$idCol} " . implode(' ', $case) . " END{$tsSql}"
                . " WHERE {$idCol} IN (" . implode(',', array_fill(0, count($ids), '?')) . ')';

            $updated += DB::connection($model->getConnectionName())->update($sql, $bindings);
        }

        return $updated;
    }

    public function failed(?Throwable $e): void
    {
        app(JntCheckerRuns::class)->patchUpdate($this->runId, [
            'status' => JntCheckerRuns::STATUS_FAILED,
            'error'  => 'The update stopped: ' . RunJntCheckerUpload::shortReason($e),
        ]);
    }
}
