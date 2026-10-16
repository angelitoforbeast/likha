<?php

namespace Tests\Feature\Botcake;

use App\Models\BotcakePsidSetting;
use App\Models\MacroOutput;
use App\Services\BotcakePsidSheet;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Base ng Botcake PSID tests (sqlite in-memory). macro_output = gawa sa kamay (MySQL-only ang legacy migrations nito),
 * ang settings / runs / events / botcake_psids = totoong migrations. Ang sheet ay FakeBotcakePsidSheet.
 */
abstract class BotcakePsidTestCase extends TestCase
{
    protected const SHEET_ID = 'TESTSHEET1';
    protected const TAB = 'PSID';

    protected FakeBotcakePsidSheet $sheet;
    protected BotcakePsidSetting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('macro_output', function (Blueprint $t) {
            $t->id();
            $t->string('PAGE')->nullable();
            $t->string('fb_name')->nullable();
            $t->string('botcake_psid', 64)->nullable();
            $t->date('ts_date')->nullable();
            $t->timestamps();
        });

        Artisan::call('migrate', [
            '--path' => [
                'database/migrations/2025_12_12_223020_create_botcake_psid_settings_table.php',
                'database/migrations/2025_12_16_232916_create_botcake_psid_import_runs_table.php',
                'database/migrations/2025_12_16_232918_create_botcake_psid_import_events_table.php',
                'database/migrations/2026_10_16_400000_create_botcake_psids_table.php',
                'database/migrations/2026_10_16_400100_add_pending_counts_to_botcake_psid_import_runs.php',
                'database/migrations/2026_10_17_100100_add_api_columns_to_botcake_psids.php',
                'database/migrations/2026_10_17_100200_add_last_seen_and_misses_for_botcake_sync.php',
            ],
        ]);

        $this->sheet = new FakeBotcakePsidSheet();
        $this->app->instance(BotcakePsidSheet::class, $this->sheet);

        $this->setting = BotcakePsidSetting::create([
            'gsheet_name' => 'Test PSID sheet',
            'sheet_url'   => 'https://docs.google.com/spreadsheets/d/' . self::SHEET_ID . '/edit',
            'sheet_range' => self::TAB . '!A:J',
        ]);
    }

    /** @param array<int, array<int, string>> $rows A..J ng bawat row (walang header) */
    protected function sheetRows(array $rows): void
    {
        $this->sheet->put(self::SHEET_ID, self::TAB, $rows);
    }

    /** @return array<int, array<int, string>> */
    protected function currentSheet(): array
    {
        return $this->sheet->rows(self::SHEET_ID, self::TAB);
    }

    protected function statusJ(int $rowNo): string
    {
        return $this->currentSheet()[$rowNo][9] ?? '';
    }

    protected function order(string $page, string $name, ?string $psid = null, ?string $tsDate = null): MacroOutput
    {
        return MacroOutput::create([
            'PAGE'         => $page,
            'fb_name'      => $name,
            'botcake_psid' => $psid,
            'ts_date'      => $tsDate ?? now('Asia/Manila')->toDateString(),
        ]);
    }
}
