<?php

namespace App\Console\Commands;

use App\Models\PageSetting;
use App\Services\PageSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pinupunan ang page_id ng botcake_psids na wala pa nito, mula sa pangalan ng page (pageKey) at sa botcake.page_id ng
 * page settings, pati ang history (ang lumang pangalan ng page na pinalitan na). Ang pangalang may iisang id lang ang
 * pinupunan; ang walang id o higit sa isang id ay naiiwang null at inililista. Kada chunk ayon sa id, may pahinga sa
 * pagitan para hindi maagawan ang live server; ligtas ulitin at ituloy (page_id IS NULL lang ang ginagalaw).
 */
class BackfillBotcakePsidPageId extends Command
{
    private const REPORT_NAMES = 50;

    protected $signature = 'botcake:psid-page-id-backfill
        {--chunk=5000 : Rows per chunk}
        {--sleep=200 : Milliseconds between chunks}
        {--max-chunks=0 : Stop after this many chunks (0 = until done)}';
    protected $description = 'Fill botcake_psids.page_id from the page name through the botcake.page_id page settings (resumable)';

    public function handle(PageSettings $settings): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $sleep = max(0, (int) $this->option('sleep'));
        $max   = max(0, (int) $this->option('max-chunks'));

        // pageKey => [id => true], mula sa lahat ng botcake.page_id (active at history).
        $ids = [];
        foreach (PageSetting::where('provider', 'botcake')->where('key', 'page_id')->orderBy('id')->get() as $s) {
            $id = trim((string) $s->plainValue());
            if ($id !== '') $ids[$settings->pageKey((string) $s->page_name)][$id] = true;
        }

        $counts = ['filled' => 0, 'no_page' => 0, 'ambiguous' => 0];
        $missing = []; // pageKey => [pangalan, bilang, ambiguous]
        $last = 0;
        $chunks = 0;
        while (true) {
            $rows = DB::table('botcake_psids')->whereNull('page_id')->where('id', '>', $last)
                ->orderBy('id')->limit($chunk)->get(['id', 'page']);
            if ($rows->isEmpty()) break;
            $last = (int) $rows->last()->id;
            $chunks++;

            $byPage = [];
            foreach ($rows as $r) {
                $key = $settings->pageKey((string) $r->page);
                $byPage[$key]['ids'][] = (int) $r->id;
                $byPage[$key]['name'] ??= (string) $r->page;
            }
            foreach ($byPage as $key => $p) {
                $found = array_keys($ids[$key] ?? []);
                if (count($found) === 1) {
                    // page_id IS NULL ulit sa UPDATE: ang naisulat na ng live writer sa pagitan ay hindi pinapalitan.
                    $counts['filled'] += DB::table('botcake_psids')->whereIn('id', $p['ids'])->whereNull('page_id')->update(['page_id' => (string) $found[0]]);
                    continue;
                }
                $ambiguous = count($found) > 1;
                $counts[$ambiguous ? 'ambiguous' : 'no_page'] += count($p['ids']);
                $missing[$key] ??= [$p['name'], 0, $ambiguous];
                $missing[$key][1] += count($p['ids']);
            }

            if ($max > 0 && $chunks >= $max) break;
            if ($sleep > 0) usleep($sleep * 1000);
        }

        $left = DB::table('botcake_psids')->whereNull('page_id')->count();
        $this->line('Filled: ' . $counts['filled']);
        $this->line('No matching page: ' . $counts['no_page']);
        $this->line('More than one page id: ' . $counts['ambiguous']);
        $this->line('Left without page id: ' . $left);
        if ($missing) {
            uasort($missing, fn ($a, $b) => $b[1] <=> $a[1]);
            $this->line('Names without one page id (rows):');
            // Galing sa sheet o sa chat ang ilang pangalan: walang control character, at may hangganan ang haba at bilang.
            foreach (array_slice($missing, 0, self::REPORT_NAMES) as [$name, $n, $ambiguous]) {
                $clean = mb_strimwidth(preg_replace('/[\p{C}]+/u', ' ', $name) ?? '', 0, 80, '…');
                $this->line('  ' . $clean . ': ' . $n . ($ambiguous ? ' (more than one page id)' : ''));
            }
            if (count($missing) > self::REPORT_NAMES) $this->line('  … ' . (count($missing) - self::REPORT_NAMES) . ' more names');
        }

        // Bilang lang ang nasa log: walang pangalan ng customer o PSID.
        Log::info('botcake:psid-page-id-backfill done', $counts + ['left' => $left, 'chunks' => $chunks]);

        return self::SUCCESS;
    }
}
