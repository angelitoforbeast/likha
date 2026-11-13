<?php

namespace App\Jobs;

use App\Jobs\Concerns\RestoresMissingDefaults;
use App\Services\Jnt\JntLabelFetcher;
use App\Services\Jnt\JntLabelStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Spec 269: right after CreateJntOrder gets a mailno, this job fetches that waybill's label (BILLQUERY, read-only)
 * with the page's J&T account and keeps it (JntLabelStore), so a print run only stitches kept labels. When J&T has
 * no label yet it tries again after 1, 3 and 10 minutes; after the 4th try it stops quietly and the print run
 * fetches the label as before.
 */
class FetchJntLabel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RestoresMissingDefaults;

    public int $tries = 4;

    public int $timeout = 120;

    /** Seconds before the 2nd, 3rd and 4th try. */
    public const RETRY_AFTER = [60, 180, 600];

    /** How long one try waits for a free J&T label slot before trying later. */
    public const SLOT_WAIT_SECONDS = 30;

    public function __construct(public string $mailno, public ?string $page = null, public ?int $shipmentId = null)
    {
        $this->onQueue(config('jnt.queues.label', 'jnt-label'));
    }

    public function handle(JntLabelFetcher $fetcher, JntLabelStore $store): void
    {
        if ($this->mailno === '' || $store->path($this->mailno) !== null) {
            return; // kept already
        }

        // Only the mailno J&T gave this shipment: a shipment whose mailno changed or went away gets no label.
        if ($this->shipmentId !== null) {
            $current = (string) DB::table('jnt_shipments')->where('id', $this->shipmentId)->value('mailno');
            if ($current !== $this->mailno) {
                return;
            }
        }

        $result = $fetcher->fetchMany([$this->mailno => $this->page], self::SLOT_WAIT_SECONDS)[$this->mailno] ?? [];

        if (isset($result['path']) && empty($result['temp'])) {
            return; // kept
        }
        if (! empty($result['temp'])) {
            @unlink($result['path']);
            $result['error'] = 'label could not be kept';
        }

        $this->tryLater((string) ($result['error'] ?? 'no label'));
    }

    private function tryLater(string $why): void
    {
        $attempt = max(1, $this->attempts());
        if ($attempt >= $this->tries) {
            Log::info('J&T label not kept; the print run fetches it', ['mailno' => $this->mailno, 'reason' => $why]);

            return;
        }

        $this->release(self::RETRY_AFTER[min($attempt, count(self::RETRY_AFTER)) - 1]);
    }
}
