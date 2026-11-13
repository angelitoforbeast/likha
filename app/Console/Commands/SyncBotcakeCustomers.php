<?php

namespace App\Console\Commands;

use App\Services\BotcakeCustomerSync;
use App\Services\PageSettings;
use Illuminate\Console\Command;

/**
 * Botcake customer list → botcake_psids, saka matching ng orders ng page. Lahat ng page na may botcake.page_id
 * at botcake.token, o ang isang --page. Isang lock kada page: ang page na hawak ng ibang proseso ay nilalaktawan.
 * --nightly: para sa simpleng cron line (walang schedule:run, hal. Incepxion): --full kapag Linggo sa Manila, by date sa
 * ibang araw; agad na lalabas kapag naka-off ang services.botcake.sync_enabled.
 * --all-time (spec 262): bawat page mula batch 1 hanggang dulo, itinutuloy mula sa naabot; --shard=K/N = ang mga page na
 * ang index sa pages() ay index % N == K-1, para sabay ang N proseso. Ang tapos na ay nilalaktawan, maliban sa --again:
 * ang all-time read na umabot sa end, o (amendment 262-1) ang malinis na --full mula batch 1 hanggang end.
 */
class SyncBotcakeCustomers extends Command
{
    protected $signature   = 'botcake:sync-customers {--page= : Isang page lang} {--full : Basahin hanggang dulo} {--nightly : Linggo sa Manila = --full, ibang araw = by date; wala kapag naka-off ang sync}'
        . ' {--all-time : Bawat page mula batch 1 hanggang dulo, walang age stop at cap; itinutuloy mula sa naabot}'
        . ' {--shard= : K/N, kasama ng --all-time: ang mga page na index % N == K-1}'
        . ' {--again : Kasama ng --all-time: basahin ulit ang mga page na tapos na}'
        . ' {--sleep-ms=0 : Hintay (ms) sa pagitan ng mga batch}';
    protected $description = 'Read each page\'s Botcake customers into botcake_psids and match recent orders without a PSID';

    public function handle(BotcakeCustomerSync $sync, PageSettings $settings): int
    {
        $allTime = (bool) $this->option('all-time');
        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $full = (bool) $this->option('full');
        if ($allTime && ($full || $this->option('nightly'))) {
            $this->error('Ang --all-time ay hindi kasama ng --full o --nightly.');
            return self::FAILURE;
        }
        if (!$allTime && ($this->option('shard') !== null || $this->option('again'))) {
            $this->error('Ang --shard at --again ay para sa --all-time lang.');
            return self::FAILURE;
        }
        if ($this->option('nightly')) {
            if (!config('services.botcake.sync_enabled')) {
                $this->line('Skipped: naka-off ang Botcake sync (BOTCAKE_SYNC_ENABLED).');
                return self::SUCCESS;
            }
            $full = $full || now('Asia/Manila')->isSunday();
        }

        $pages = $sync->pages();

        if (($shard = $this->option('shard')) !== null) {
            if (!preg_match('#^(\d+)/(\d+)$#', (string) $shard, $m) || (int) $m[2] < 1 || (int) $m[1] < 1 || (int) $m[1] > (int) $m[2]) {
                $this->error('--shard ay K/N, 1 <= K <= N (hal. --shard=1/6).');
                return self::FAILURE;
            }
            [$k, $n] = [(int) $m[1], (int) $m[2]];
            $pages = array_values(array_filter($pages, fn ($i) => $i % $n === $k - 1, ARRAY_FILTER_USE_KEY));
        }

        if (($only = $this->option('page')) !== null) {
            $key = $settings->pageKey((string) $only);
            $pages = array_values(array_filter($pages, fn ($p) => $settings->pageKey($p) === $key));
            if (!$pages) {
                $this->error('Walang botcake.page_id at botcake.token ang page na ito' . ($shard !== null ? ', o wala ito sa shard.' : '.'));
                return self::FAILURE;
            }
        }

        // Kapag pinatay ang run (SIGTERM / SIGINT) habang hawak nito ang lock ng page, bitawan ito para hindi ma-"Skipped"
        // ang susunod na run. Hindi ginagalaw ang lock ng ibang proseso. Walang pcntl (hal. Windows): ang TTL ang sasalo.
        $holding = null;
        if (extension_loaded('pcntl')) {
            $this->trap([SIGTERM, SIGINT], function () use ($sync, &$holding) {
                if ($holding !== null) $sync->forceRelease($holding);
                exit(1);
            });
        }

        $hold = function (string $page, callable $fn) use ($sync, &$holding) {
            return $sync->withLock($page, function () use ($page, $fn, &$holding) {
                $holding = $page;
                try {
                    return $fn();
                } finally {
                    $holding = null;
                }
            });
        };

        if ($allTime) return $this->allTime($sync, $pages, $hold, (bool) $this->option('again'), $sleepMs);

        foreach ($pages as $page) {
            $run = $hold($page, fn () => $sync->syncPage($page, $full, false, $sleepMs));

            // Abala ang lock ng page (hal. Sync lahat, ang row Sync, o isang --all-time): ang page lang na ito ang
            // nilalaktawan, tuloy sa susunod.
            if ($run === null) {
                $this->skippedLocked($sync, $page);
                continue;
            }
            $this->line(($run->status === 'done' ? 'OK ' : 'FAILED ') . $run->last_message . ($run->last_error ? ' | ' . $run->last_error : ''));
        }

        return self::SUCCESS;
    }

    /**
     * Bawat page hanggang dulo. Ang run na huminto sa oras (time) ay agad na itinutuloy (bagong lock, bagong run); ang
     * pumalya ay iniiwan para sa susunod na takbo, at tuloy sa susunod na page.
     */
    private function allTime(BotcakeCustomerSync $sync, array $pages, callable $hold, bool $again, int $sleepMs): int
    {
        $count = ['end' => 0, 'finished' => 0, 'locked' => 0, 'failed' => 0];
        foreach ($pages as $page) {
            $fresh = $again;
            $first = true;
            while (true) {
                // Sinusuri habang hawak ang lock: ang ibang proseso ay maaaring katatapos lang ng page. Ang malinis na
                // --full mula batch 1 hanggang end (amendment 262-1) ay sa unang run lang tinitingnan, para hindi maputol
                // ang chain na sinimulan ng --again.
                $run = $hold($page, function () use ($sync, $page, $fresh, $first, $sleepMs) {
                    if (!$fresh && $sync->allTimeFinished($page)) return 'all-time read';
                    if (!$fresh && $first && $sync->fullReadFinished($page)) return 'full read mula batch 1';
                    return $sync->syncPage($page, false, true, $sleepMs);
                });
                if (is_string($run)) {
                    $this->line('Laktaw ang ' . $page . ': tapos na ang ' . $run . ' (end); --again para basahin ulit.');
                    $count['finished']++;
                    break;
                }
                $first = false;
                if ($run === null) {
                    $this->skippedLocked($sync, $page);
                    $count['locked']++;
                    break;
                }
                $this->line(($run->status === 'done' ? 'OK ' : 'FAILED ') . $run->last_message . ($run->last_error ? ' | ' . $run->last_error : ''));
                $fresh = false; // ang tuloy ng chain na ito ay hindi na bagong simula
                if ($run->status === 'done' && $run->stop_reason === 'time') continue;
                $count[$run->status === 'done' && $run->stop_reason === 'end' ? 'end' : 'failed']++;
                break;
            }
        }

        $this->line(sprintf('All time: %d page, %d natapos ngayon, %d tapos na dati, %d hawak ng iba, %d pumalya (itutuloy sa susunod na takbo).',
            count($pages), $count['end'], $count['finished'], $count['locked'], $count['failed']));

        return self::SUCCESS;
    }

    private function skippedLocked(BotcakeCustomerSync $sync, string $page): void
    {
        $since = $sync->lockedSince($page);
        $this->line('Skipped: may tumatakbo pang Botcake customer sync' . ($since ? ' (mula ' . $since->copy()->timezone('Asia/Manila')->format('Y-m-d H:i') . ')' : '') . '. Laktaw ang ' . $page . '; tuloy sa susunod na page.');
    }
}
