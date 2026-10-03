<?php

namespace Tests\Feature\NightRun;

use App\Jobs\RunNightAstraRow;
use App\Models\AppSetting;
use App\Models\NightAstraRow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * Aling mga order ang kinukuha ng Astra night run (spec 007 §6.1): "lahat ng blangko" ng kahapon (Manila),
 * pinakaluma muna, hanggang sa safety maximum.
 */
class NightAstraSelectionTest extends NightAstraTestCase
{
    public function test_it_takes_every_blank_status_row_of_the_orders_date_oldest_first(): void
    {
        $late        = $this->order(['TIMESTAMP' => '23:50 04-10-2026', 'STATUS' => null]);
        $emptyStatus = $this->order(['TIMESTAMP' => '08:05 04-10-2026', 'STATUS' => '']);
        $spaces      = $this->order(['TIMESTAMP' => '08:05 04-10-2026', 'STATUS' => '   ']);
        $filled      = $this->order(['TIMESTAMP' => '10:00 04-10-2026', 'FULL NAME' => 'Ana Cruz', 'PHONE NUMBER' => '9171234567',
            'ADDRESS' => '1 Mabini St', 'PROVINCE' => 'METRO-MANILA', 'CITY' => 'QUEZON-CITY', 'BARANGAY' => 'HOLY SPIRIT']);
        $triedByAi   = $this->order(['TIMESTAMP' => '00:01 04-10-2026', 'APP SCRIPT CHECKER' => 'TO FIX', 'AI ANALYZE' => '⚠ TO FIX']);

        $this->order(['STATUS' => 'PROCEED']);
        $this->order(['STATUS' => 'CANNOT PROCEED']);
        $this->order(['ts_date' => '2026-10-03', 'TIMESTAMP' => '21:14 03-10-2026']);
        $this->order(['ts_date' => '2026-10-05', 'TIMESTAMP' => '00:10 05-10-2026']);
        $this->order(['ts_date' => null]);

        $this->assertSame(
            [$triedByAi->id, $emptyStatus->id, $spaces->id, $filled->id, $late->id],
            $this->night()->selection(self::ORDERS)->pluck('id')->all()
        );
    }

    public function test_the_tick_takes_yesterday_by_the_manila_date_not_the_servers(): void
    {
        $appTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC'); // patunay na Manila ang "kahapon", hindi ang timezone ng server

        try {
            // 00:30 ng Oct 5 sa Manila = 16:30 ng Oct 4 sa UTC: kahapon sa Manila = Oct 4, sa UTC = Oct 3.
            Carbon::setTestNow(Carbon::parse('2026-10-04 16:30:00', 'UTC'));
            AppSetting::set('night_astra_time', '00:15');
            $this->macroImport('done', ['done'], '2026-10-04 16:05:00'); // 00:05 sa Manila
            $manilaYesterday = $this->order(['ts_date' => '2026-10-04']);
            $this->order(['ts_date' => '2026-10-03']);

            $this->night()->tick();

            $step = $this->step();
            $this->assertSame([self::NIGHT, 'running'], [substr((string) $step->night_date, 0, 10), $step->state]);
            $this->assertSame([$manilaYesterday->id], NightAstraRow::pluck('macro_output_id')->all());
            // Stop time 07:00 sa Manila = 23:00 UTC ng Oct 4.
            $this->assertSame('2026-10-04 23:00:00', $step->stop_at->toDateTimeString());
        } finally {
            date_default_timezone_set($appTimezone);
        }
    }

    public function test_over_the_safety_maximum_only_the_oldest_rows_get_a_record(): void
    {
        AppSetting::set('night_astra_max_rows', '2');
        $this->macroImport();
        $third  = $this->order(['TIMESTAMP' => '12:00 04-10-2026']);
        $first  = $this->order(['TIMESTAMP' => '06:00 04-10-2026']);
        $second = $this->order(['TIMESTAMP' => '09:00 04-10-2026']);
        $this->order(['TIMESTAMP' => '15:00 04-10-2026']);
        $this->order(['TIMESTAMP' => '18:00 04-10-2026']);

        $this->night()->tick();

        $step = $this->step();
        $this->assertSame(['running', 5, 3], [$step->state, (int) $step->rows_found, (int) $step->rows_over_max]);
        $this->assertSame([$first->id, $second->id], NightAstraRow::orderBy('id')->pluck('macro_output_id')->all());
        $this->assertNotContains($third->id, NightAstraRow::pluck('macro_output_id')->all());
        Queue::assertPushed(RunNightAstraRow::class, 2);
    }
}
