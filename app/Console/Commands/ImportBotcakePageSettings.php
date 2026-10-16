<?php

namespace App\Console\Commands;

use App\Models\MacroOutput;
use App\Services\BotcakePsidSheet;
use App\Services\PageSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Isang beses: ang "BOTCAKE API" tab (A Page Name, B API KEY, C BOTCAKE ID, D FLOW ID) papunta sa page_settings
 * (botcake.page_id, botcake.token, botcake.flow.shipping). Dry run ang default; --apply para sumulat.
 * Hindi kailanman ipinapakita ang token.
 */
class ImportBotcakePageSettings extends Command
{
    protected $signature = "page-settings:import-botcake {spreadsheetId} {--range='BOTCAKE API'!A2:D} {--apply : Isulat sa page_settings}";
    protected $description = 'Import each page\'s Botcake page id, token and flow id from the settings sheet into page_settings';

    public function handle(BotcakePsidSheet $sheet, PageSettings $settings): int
    {
        $apply = (bool) $this->option('apply');
        $rows  = $sheet->readRange((string) $this->argument('spreadsheetId'), (string) $this->option('range'));

        $this->line($apply ? 'APPLY: isinusulat sa page_settings.' : 'DRY RUN: walang isusulat (gamitin ang --apply).');

        $trim = DB::getDriverName() === 'pgsql' ? 'BTRIM' : 'TRIM';
        $written = 0;
        $skipped = 0;

        foreach ($rows as $i => $row) {
            $rowNo  = $i + 2; // ang range ay nagsisimula sa row 2
            $page   = $settings->normPage($row[0] ?? '');
            $token  = trim((string) ($row[1] ?? ''));
            $pageId = trim((string) ($row[2] ?? ''));
            $flowId = trim((string) ($row[3] ?? ''));

            if ($page === '') {
                if ($token !== '' || $pageId !== '' || $flowId !== '') {
                    $this->line("Row {$rowNo}: walang page name, laktaw");
                    $skipped++;
                }
                continue;
            }

            $inOrders = MacroOutput::whereRaw("LOWER($trim(" . $this->col('PAGE') . ')) = ?', [$settings->pageKey($page)])->exists();

            $keys = [];
            $problems = [];
            foreach ([['page_id', $pageId, 'page id'], ['token', $token, 'token'], ['flow.shipping', $flowId, 'flow id']] as [$key, $value, $label]) {
                if ($value === '') {
                    $problems[] = "walang {$label}";
                    continue;
                }
                if ($settings->validate($page, 'botcake', $key, $value)) {
                    // Ang token ay hindi ipinapakita kahit mali.
                    $problems[] = $key === 'token' ? 'token: hindi tinanggap' : "{$label} \"{$value}\" laktaw (numero lang)";
                    continue;
                }
                $keys[$key] = $value;
            }

            $this->line(sprintf(
                'Row %d: %s | orders: %s | keys: %s%s',
                $rowNo,
                $page,
                $inOrders ? 'oo' : 'wala (walang PAGE na ganito sa orders)',
                $keys ? implode(', ', array_map(fn ($k) => 'botcake.' . $k, array_keys($keys))) : '-',
                $problems ? ' | ' . implode('; ', $problems) : ''
            ));
            $skipped += count($problems);

            if ($apply) {
                foreach ($keys as $key => $value) {
                    $res = $settings->set($page, 'botcake', $key, $value);
                    if ($res['status'] !== 'unchanged') $written++;
                }
            }
        }

        $this->line($apply ? "Naisulat: {$written} na setting. Laktaw: {$skipped}." : "Laktaw kapag --apply: {$skipped}.");

        return self::SUCCESS;
    }

    private function col(string $name): string
    {
        return DB::getDriverName() === 'pgsql' ? '"' . $name . '"' : '`' . $name . '`';
    }
}
