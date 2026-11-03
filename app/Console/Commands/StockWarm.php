<?php

namespace App\Console\Commands;

use App\Services\ItemStockService;
use App\Services\Nexus\StockInSync;
use Illuminate\Console\Command;

/**
 * Pinapainit ang stock inputs ng /item (Nexus mode) para halos hindi tamaan ng page ang cold na kuwenta: kada 10 minuto
 * (bago lumipas ang 15-minutong TTL) at pagkatapos ng bawat matagumpay na nexus:sync-stock. Araw-araw 02:55 na may
 * --closed-days=7: ang huling 7 saradong araw ay kinukuwenta ulit, kahit bumagsak ang Nexus. Walang Nexus = walang gawa.
 */
class StockWarm extends Command
{
    protected $signature = 'stock:warm {--closed-days=0 : also recompute the last N closed days (before yesterday)}';

    protected $description = 'Compute and cache the /item stock inputs (Nexus mode) before a page needs them';

    public function handle(): int
    {
        if (!StockInSync::configured()) {
            $this->line('Nexus not configured: the /item stock has no inputs to warm.');
            return self::SUCCESS;
        }

        return self::report($this, app(ItemStockService::class)->warmInputs(max(0, (int) $this->option('closed-days'))));
    }

    /** Isang linya ng resulta; FAILURE kapag bumagsak (ang lumang cache ay nananatili). */
    public static function report(Command $cmd, array $r): int
    {
        if (!$r['ok']) {
            $cmd->warn("The /item stock inputs were not warmed ({$r['error']}); the cached values are kept.");
            return self::FAILURE;
        }
        $cmd->line('Warmed the /item stock inputs in ' . $r['ms'] . ' ms: ' . (implode(', ', $r['keys']) ?: 'none'));
        if ($r['busy']) $cmd->line('Skipped (busy: a page request was computing them): ' . implode(', ', $r['busy']));

        return self::SUCCESS;
    }
}
