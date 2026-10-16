<?php

namespace App\Console\Commands;

use App\Models\BotcakePsidSetting;
use App\Services\BotcakePsidMatcher;
use App\Services\BotcakePsidSheet;
use Illuminate\Console\Command;

/**
 * Isang beses: ang lumang "not existing" na PSID rows ng bawat setting ay itinatala bilang waiting
 * (at J = "waiting"), saka isang matching. Dati hindi na sila sinusubukan ulit kahit dumating na ang order.
 * Ang lumang "imported" rows ay itinatala bilang matched (o conflict) para mabura rin sa sheet sa 03:00.
 * Dry run ang default; --apply para sumulat.
 */
class BackfillBotcakePsidWaiting extends Command
{
    protected $signature   = 'botcake:psid-backfill {--apply : Isulat sa botcake_psids at sa sheet}';
    protected $description = 'Record old "not existing" Botcake PSID sheet rows as waiting and old "imported" rows as matched, then match once';

    public function handle(BotcakePsidSheet $sheet, BotcakePsidMatcher $matcher): int
    {
        $apply = (bool) $this->option('apply');
        $total = 0;
        $totalImported = 0;
        $importedCounts = ['matched' => 0, 'conflict' => 0, 'already' => 0];

        foreach (BotcakePsidSetting::all() as $setting) {
            $target = BotcakePsidSheet::target($setting);
            if (!$target) {
                $this->warn("Setting #{$setting->id}: invalid sheet URL, laktaw.");
                continue;
            }
            [$spreadsheetId, $sheetName] = $target;

            $found = 0;
            $imported = 0;
            $incomplete = 0;
            $statuses = [];
            foreach ($sheet->readRows($spreadsheetId, $sheetName) as $rowNo => $row) {
                if ($rowNo < 2) continue;
                $row = array_pad($row, 10, '');
                $j = mb_strtolower(trim((string) $row[9]), 'UTF-8');
                if ($j !== 'not existing' && $j !== 'imported') continue;

                $page = $matcher->norm($row[0]);
                $name = $matcher->norm($row[1]);
                $psid = $matcher->norm($row[2]);
                if ($page === '' || $name === '' || $psid === '') {
                    $incomplete++;
                    continue;
                }

                // Ang lumang "imported" ay naisulat na sa macro_output noon: itinatala lang, para mabura sa 03:00.
                if ($j === 'imported') {
                    $imported++;
                    if ($apply) {
                        $status = $matcher->recordImported($setting, $page, $name, $psid, trim((string) $row[3]));
                        $importedCounts[$status ?? 'already']++;
                    }
                    continue;
                }

                $found++;
                if ($apply) {
                    $matcher->recordWaiting($setting, $page, $name, $psid, trim((string) $row[3]));
                    $statuses[$rowNo] = 'waiting';
                }
            }

            if ($apply && $statuses) {
                $sheet->writeStatuses($spreadsheetId, $sheetName, $statuses);
            }

            $label = $setting->gsheet_name ? " ({$setting->gsheet_name} / {$sheetName})" : " ({$sheetName})";
            $this->line("Setting #{$setting->id}{$label}: {$found} na \"not existing\" row ang itatala bilang waiting"
                . "; {$imported} na \"imported\" row ang itatala bilang matched"
                . ($incomplete ? "; {$incomplete} kulang ang page/pangalan/PSID, hindi gagalawin" : ''));
            $total += $found;
            $totalImported += $imported;
        }

        if (!$apply) {
            $this->info("Dry run: walang isinulat. Kabuuan: {$total} \"not existing\" at {$totalImported} \"imported\" row. Patakbuhin ulit na may --apply para isulat.");
            return self::SUCCESS;
        }

        $this->info("Imported rows: matched {$importedCounts['matched']}, conflict {$importedCounts['conflict']}, naitala na dati {$importedCounts['already']}.");

        $retry = $matcher->retryWaiting();
        $this->info("Naitala: {$total}. Matched: {$retry['matched']}, conflict: {$retry['conflicts']}, expired: {$retry['expired']}.");

        return self::SUCCESS;
    }
}
