<?php

namespace Tests\Feature\PageSettings;

use App\Models\PageSetting;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Botcake\BotcakeApiTestCase;

/** page-settings:import-botcake: ang "BOTCAKE API" tab (A Page Name, B API KEY, C BOTCAKE ID, D FLOW ID) papunta sa page_settings. */
class ImportBotcakeSettingsTest extends BotcakeApiTestCase
{
    private const SHEET = 'TESTSETTINGS1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->order('Page Uno', 'Customer A');
        $this->sheet->ranges[self::SHEET . "|'BOTCAKE API'!A2:D"] = [
            ['Page Uno', 'tok-uno-secret-1111', '1001', '7001'],
            ['Page Dos', 'tok-dos-secret-2222', '1002', '7002/'],
        ];
    }

    public function test_dry_run_writes_nothing_and_never_prints_a_token(): void
    {
        $code = Artisan::call('page-settings:import-botcake', ['spreadsheetId' => self::SHEET]);
        $out = Artisan::output();

        $this->assertSame(0, $code);
        $this->assertSame(0, PageSetting::count());
        $this->assertStringNotContainsString('tok-', $out);
        $this->assertStringNotContainsString('1111', $out);
        $this->assertMatchesRegularExpression('/Page Uno .*orders: oo.*botcake\.page_id, botcake\.token, botcake\.flow\.shipping/', $out);
        $this->assertMatchesRegularExpression('/Page Dos .*orders: wala/', $out);
        $this->assertStringContainsString('flow id "7002/"', $out);
        $this->assertStringContainsString('DRY RUN', $out);
    }

    public function test_apply_writes_the_three_keys_and_skips_a_bad_flow_id(): void
    {
        $code = Artisan::call('page-settings:import-botcake', ['spreadsheetId' => self::SHEET, '--apply' => true]);
        $this->assertSame(0, $code);
        $this->assertStringNotContainsString('tok-', Artisan::output());

        $s = app(PageSettings::class);
        $this->assertSame('1001', $s->get('Page Uno', 'botcake', 'page_id'));
        $this->assertSame('tok-uno-secret-1111', $s->get('Page Uno', 'botcake', 'token'));
        $this->assertSame('7001', $s->get('Page Uno', 'botcake', 'flow.shipping'));

        $this->assertSame('1002', $s->get('Page Dos', 'botcake', 'page_id'));
        $this->assertSame('tok-dos-secret-2222', $s->get('Page Dos', 'botcake', 'token'));
        $this->assertNull($s->get('Page Dos', 'botcake', 'flow.shipping'));
        $this->assertSame(5, PageSetting::count());

        // Pangalawang --apply: walang bagong row.
        Artisan::call('page-settings:import-botcake', ['spreadsheetId' => self::SHEET, '--apply' => true]);
        $this->assertSame(5, PageSetting::count());
    }
}
