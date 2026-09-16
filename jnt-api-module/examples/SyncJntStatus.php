<?php

namespace App\Console\Commands;

use App\Services\Jnt\JntApi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Example: i-sync ang J&T order status papunta sa iyong shipments table.
 *
 *   php artisan jnt:sync-status
 *   php artisan jnt:sync-status --limit=500
 *
 * PALITAN ang $table / column names ayon sa schema ng target website mo.
 * Kailangan mong may column na nag-iimbak ng txlogisticid (ang serialnumber
 * na ginamit mo noong create order) + isang order_status column.
 */
class SyncJntStatus extends Command
{
    protected $signature = 'jnt:sync-status {--limit=0 : Max shipments (0 = lahat)}';
    protected $description = 'Kunin ang J&T order status (ORDERQUERY) at i-update ang shipments table';

    // ── I-EDIT ITO ayon sa schema mo ────────────────────────────────────────
    private string $table       = 'shipments';
    private string $keyColumn   = 'txlogisticid';   // = serialnumber na ginamit sa create
    private string $statusCol   = 'order_status';
    // ────────────────────────────────────────────────────────────────────────

    public function handle(): int
    {
        $jnt = JntApi::fromConfig();

        $q = DB::table($this->table)
            ->whereNotNull($this->keyColumn)
            ->where($this->keyColumn, '!=', '');

        $limit = (int) $this->option('limit');
        if ($limit > 0) $q->limit($limit);

        $serials = $q->pluck($this->keyColumn)->map(fn ($v) => trim((string) $v))->filter()->unique()->values()->all();

        if (empty($serials)) {
            $this->warn('Walang txlogisticid na makikita sa ' . $this->table);
            return self::SUCCESS;
        }

        $this->info('Sini-sync ang ' . count($serials) . ' shipment(s)…');
        $bar = $this->output->createProgressBar(count($serials));
        $ok = 0; $missing = 0;

        foreach (array_chunk($serials, 10) as $chunk) {
            $map = $jnt->statusMany($chunk);   // [txlogisticid => normalized status]

            foreach ($chunk as $tx) {
                $info = $map[$tx] ?? null;
                if ($info) {
                    DB::table($this->table)->where($this->keyColumn, $tx)->update([
                        $this->statusCol    => $info['order_status'],
                        // Alisin/idagdag ayon sa mga column na meron ka:
                        'send_end_time'     => $info['send_end_time'] ?? null,
                        'sumfreight'        => $info['sumfreight'] ?? null,
                        'orderquery_raw'    => json_encode($info['raw']),
                        'updated_at'        => now(),
                    ]);
                    $ok++;
                } else {
                    $missing++;
                }
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Tapos. OK: {$ok} · Walang order (missing): {$missing}");

        return self::SUCCESS;
    }
}
