<?php

namespace App\Jobs;

use App\Services\BotcakePageSetup;
use App\Services\PageSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * "Check all" ng /chatblast/page-settings (queue "default"): ang token test at ang setup check ng bawat page na may Botcake
 * ID at token, isa-isa. Ang takbo ay nasa cache (PROGRESS) para sa page: ilan na, ilan ang pumalya, kailan natapos.
 */
class RunChatblastSetupCheck implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const PROGRESS = 'chatblast:setup-check:progress';
    public int $timeout = 3000; // ~60 page × hanggang 25 s
    public int $tries = 1;

    public function __construct(public ?int $by = null)
    {
        $this->onQueue('default');
    }

    /** Itala na naka-queue (bago pa tumakbo), para makita agad sa page. */
    public static function queued(?int $by): void
    {
        Cache::put(self::PROGRESS, ['status' => 'queued', 'total' => null, 'done' => 0, 'x' => 0, 'failed' => 0, 'by' => $by,
            'queued_at' => now()->toIso8601String(), 'finished_at' => null], now()->addDay());
    }

    public static function progress(): ?array
    {
        $p = Cache::get(self::PROGRESS);

        return is_array($p) ? $p : null;
    }

    public function handle(PageSettings $settings, BotcakePageSetup $setup): void
    {
        $pages = $settings->pagesWith('botcake', ['page_id', 'token']);
        $p = ['status' => 'running', 'total' => count($pages), 'done' => 0, 'x' => 0, 'failed' => 0, 'by' => $this->by,
            'started_at' => now()->toIso8601String(), 'finished_at' => null];
        Cache::put(self::PROGRESS, $p, now()->addDay());
        foreach ($pages as $page) {
            try {
                $res = $setup->check($page);
                $check = $res['check'] ?? null;
                if ($res['status'] !== 'ok' || ($check['status'] ?? '') === 'error') $p['failed']++;
                elseif ($check && !$check['ok']) $p['x']++;
            } catch (Throwable $e) {
                report($e);
                $p['failed']++;
            }
            $p['done']++;
            Cache::put(self::PROGRESS, $p, now()->addDay());
        }
        Cache::put(self::PROGRESS, ['status' => 'done', 'finished_at' => now()->toIso8601String()] + $p, now()->addDays(7));
    }

    public function failed(Throwable $e): void
    {
        $p = self::progress() ?? [];
        Cache::put(self::PROGRESS, ['status' => 'failed', 'finished_at' => now()->toIso8601String()] + $p, now()->addDays(7));
    }
}
