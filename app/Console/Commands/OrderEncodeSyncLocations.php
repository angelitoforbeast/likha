<?php

namespace App\Console\Commands;

use App\Models\PageSetting;
use App\Services\OrderEncode\LocationStore;
use App\Services\OrderEncode\Settings;
use App\Services\OrderEncode\Sheets;
use Illuminate\Console\Command;
use Throwable;

/**
 * Spec 130: ang LocationData ng macro sheet (A2:D) at ng AFTER MACRO (B2:D) ng mga page na naka-shadow o live, bilang file sa
 * storage/app/order-encode, ayon sa ayos ng sheet (mahalaga ito sa "unang candidate"). Ang walang laman o pumalyang basa ay hindi
 * nag-o-overwrite ng umiiral na file. Sinasabi kung magkapareho ang dalawang listahan.
 */
class OrderEncodeSyncLocations extends Command
{
    protected $signature = 'order-encode:sync-locations';
    protected $description = 'Store the LocationData of the macro and AFTER MACRO sheets for the order encode (spec 130)';

    public function handle(Settings $settings, Sheets $sheets, LocationStore $store): int
    {
        $pages = PageSetting::where('provider', Settings::PROVIDER)->where('active', true)->pluck('page_name')->unique()
            ->filter(fn ($p) => $settings->mode($p) !== 'off');

        $pairs = [];
        foreach ($pages as $page) {
            $pairs[(string) $settings->sheetId($page, 'macro_sheet') . '|' . (string) $settings->sheetId($page, 'compare_sheet')] = true;
        }

        $failed = false;
        $cache = []; // "kind|id" => rows o null
        $load = function (string $kind, string $id, string $range) use (&$cache, &$failed, $sheets, $store) {
            if ($id === '') {
                $this->warn("A page has no {$kind} sheet set, skipped.");
                $failed = true;

                return null;
            }

            return $cache[$kind . '|' . $id] ??= $this->read($sheets, $id, $range, $kind, $store, $failed);
        };
        foreach (array_keys($pairs) as $pair) {
            [$macroId, $afterId] = explode('|', $pair);
            $macro = $load(LocationStore::MACRO, $macroId, 'A2:D');
            $after = $load(LocationStore::AFTER, $afterId, 'B2:D');
            if ($macro !== null && $after !== null) {
                $same = array_map(fn ($r) => array_slice(array_pad($r, 4, ''), 1, 3), $macro) === array_map(fn ($r) => array_pad($r, 3, ''), $after);
                $this->line($same ? 'The macro list and the AFTER MACRO list are identical.' : 'The macro list and the AFTER MACRO list are NOT identical.');
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<int, array<int, string>>|null null kapag pumalya o walang laman (hindi ino-overwrite ang file) */
    private function read(Sheets $sheets, string $id, string $range, string $kind, LocationStore $store, bool &$failed): ?array
    {
        try {
            $rows = $sheets->values($id, 'LocationData!' . $range);
        } catch (Throwable $e) {
            report($e);
            $this->error("Could not read the {$kind} LocationData; the old file stays.");
            $failed = true;

            return null;
        }
        if (!$rows) {
            $this->error("The {$kind} LocationData came back empty; the old file stays.");
            $failed = true;

            return null;
        }
        $store->write($kind, $id, $rows);
        $this->info(sprintf('%s list stored: %d rows.', $kind, count($rows)));

        return $rows;
    }
}
