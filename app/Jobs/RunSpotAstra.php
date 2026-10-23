<?php

namespace App\Jobs;

use App\Models\MacroOutput;
use App\Models\OrderEncode;
use App\Services\AiCheckerRowRunner;
use App\Services\OrderEncode\AddressQuestion;
use App\Services\OrderEncode\LiveOrder;
use App\Services\OrderEncode\RowLock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Astra on the spot: ang live na order na hindi PROCEED ay inaayos agad ni Astra, sa parehong run-one-row ng night run
 * (AiCheckerRowRunner, ang prompt at rules ni Astra ay hindi binago, isang ai_checker_logs row). Hawak ang lock ng row habang
 * tumatakbo, kaya walang dalawang Astra (on the spot, re-trigger, night run) na sabay na sumusulat. Pagkatapos (o kapag pumalya),
 * ang tanong sa address; ang order ay hindi kailanman nawawala at hindi naiiwang "running".
 */
class RunSpotAstra implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const QUEUE = 'astra';
    public const BUSY_DELAY_S = 60;
    public const RETRY_DELAY_S = 65;

    public int $timeout = 540;
    public int $tries = 3;

    public function __construct(public int $orderEncodeId)
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(): void
    {
        $o = OrderEncode::find($this->orderEncodeId);
        if (!$o || !$o->macro_output_id) return;
        $id = (int) $o->macro_output_id;

        try {
            $out = RowLock::hold($id, 0, function () use ($id, $o) {
                $row = MacroOutput::find($id);
                if (!$row || trim((string) $row->STATUS) !== '') return null; // may STATUS na (tao o ang encode): walang Astra

                return app(AiCheckerRowRunner::class)->run($id, 'astra', (string) config('app.url'), [
                    'source' => 'spot', 'user_id' => null, 'user_name' => LiveOrder::USER, 'batch_id' => 'spot-' . $o->id,
                    'http_timeout' => 120, 'only_when_status_blank' => true,
                ]);
            });
        } catch (LockTimeoutException) {
            // May ibang Astra (o ang night run) sa row: mamaya ulit, hindi sabay.
            if ($this->attempts() < $this->tries) {
                $this->release(self::BUSY_DELAY_S);

                return;
            }
            $this->finish($o, ['status' => 'busy']);

            return;
        }

        $result = is_array($out['result'] ?? null) ? $out['result'] : null;
        $done = in_array($result['status'] ?? null, ['fixed', 'partial', 'skipped'], true);
        if ($out !== null && !$done && self::transient($out['last_error'] ?? null) && $this->attempts() < $this->tries) {
            $this->release(self::RETRY_DELAY_S);

            return;
        }
        $this->finish($o, $out === null ? ['status' => 'skipped'] : [
            'status' => (string) ($result['status'] ?? 'failed'), 'final_code' => $result['final_code'] ?? null, 'log_id' => $out['log_id'] ?? null,
        ]);
    }

    /** Pagkatapos ng huling subok (timeout o error): pumalya si Astra, pero ang tanong sa address ay tumutuloy pa rin. */
    public function failed(Throwable $e): void
    {
        $o = OrderEncode::find($this->orderEncodeId);
        if ($o) $this->finish($o, ['status' => 'failed']);
    }

    /** Ang resulta sa order (makikita sa /order-encode), saka ang tanong sa address. */
    private function finish(OrderEncode $o, array $astra): void
    {
        $o->refresh();
        $notes = array_merge((array) ($o->input['live_notes'] ?? []), ['Astra on the spot: ' . $astra['status'] . (!empty($astra['final_code']) ? ' ' . $astra['final_code'] : '')]);
        $o->update(['input' => array_merge((array) $o->input, ['astra' => $astra + ['at' => now()->toDateTimeString()], 'live_notes' => $notes])]);
        app(AddressQuestion::class)->ask($o, in_array($astra['status'], ['fixed', 'partial'], true) ? $astra : null);
    }

    /** Gaya ng night run: timeout/connection, 5xx at 429 na hindi credit ay maaaring ulitin. */
    private static function transient(?array $error): bool
    {
        if ($error === null) return false;
        if (($error['kind'] ?? null) === 'exception') return true;
        $http = (int) ($error['status'] ?? 0);

        return $http >= 500 || ($http === 429 && !in_array($error['code'] ?? null, ['insufficient_quota', 'billing_hard_limit_reached', 'billing_not_active'], true));
    }
}
