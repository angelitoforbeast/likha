<?php

namespace Tests\Feature\NightRun;

use App\Models\AppSetting;
use App\Support\NightRunSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Illuminate\Support\Facades\Schema;

/**
 * Night run settings (spec 007 §3): iisang reader na may validation at defaults.
 */
class NightSettingsTest extends NightRunTestCase
{
    private const DEFAULTS = [
        'night_macro_import_enabled' => false,
        'night_likha_import_enabled' => false,
        'night_astra_enabled'        => false,
        'night_import_time_1'        => '01:00',
        'night_import_time_2'        => '02:00',
        'night_astra_time'           => '03:00',
        'night_astra_stop_time'      => '07:00',
        'night_astra_max_rows'       => 1500,
    ];

    private function set(array $values): void
    {
        foreach ($values as $key => $value) {
            AppSetting::set($key, $value);
        }
    }

    public function test_nothing_saved_means_everything_off_with_the_defaults(): void
    {
        $this->assertSame(self::DEFAULTS, NightRunSettings::read());
    }

    public function test_valid_values_are_read_and_only_1_turns_a_switch_on(): void
    {
        $this->set([
            'night_macro_import_enabled' => '1',
            'night_likha_import_enabled' => 'true',
            'night_astra_enabled'        => '0',
            'night_import_time_1'        => '00:30',
            'night_import_time_2'        => '23:59',
            'night_astra_time'           => '04:15',
            'night_astra_stop_time'      => '06:00',
            'night_astra_max_rows'       => '20000',
        ]);

        $this->assertSame([
            'night_macro_import_enabled' => true,
            'night_likha_import_enabled' => false,
            'night_astra_enabled'        => false,
            'night_import_time_1'        => '00:30',
            'night_import_time_2'        => '23:59',
            'night_astra_time'           => '04:15',
            'night_astra_stop_time'      => '06:00',
            'night_astra_max_rows'       => 20000,
        ], NightRunSettings::read());
    }

    public function test_invalid_time_falls_back_to_the_default(): void
    {
        foreach (['', '25:00', '1:00', 'abc', '03:60', "04:00\n"] as $bad) {
            $this->set([
                'night_import_time_1'   => $bad,
                'night_import_time_2'   => $bad,
                'night_astra_time'      => $bad,
                'night_astra_stop_time' => $bad,
            ]);

            $this->assertSame(self::DEFAULTS, NightRunSettings::read(), "value '{$bad}'");
        }
    }

    public function test_max_rows_out_of_range_falls_back_to_the_default(): void
    {
        // [naka-save, inaasahan]
        $cases = [['0', 1500], ['20001', 1500], ['-5', 1500], ['1.5', 1500], ['abc', 1500], ['', 1500], ['1', 1], ['20000', 20000]];

        foreach ($cases as [$saved, $expected]) {
            $this->set(['night_astra_max_rows' => $saved]);

            $this->assertSame($expected, NightRunSettings::read()['night_astra_max_rows'], "value '{$saved}'");
        }
    }

    public function test_astra_time_not_earlier_than_the_stop_time_falls_back_to_both_defaults(): void
    {
        foreach ([['07:30', '07:30'], ['08:00', '05:00']] as [$astra, $stop]) {
            $this->set(['night_astra_time' => $astra, 'night_astra_stop_time' => $stop]);

            $read = NightRunSettings::read();

            $this->assertSame(['03:00', '07:00'], [$read['night_astra_time'], $read['night_astra_stop_time']], "{$astra} / {$stop}");
        }
    }

    public function test_no_app_settings_table_means_everything_off(): void
    {
        $this->set(['night_macro_import_enabled' => '1', 'night_import_time_1' => '00:30']);
        Schema::drop('app_settings');

        $this->assertSame(self::DEFAULTS, NightRunSettings::read());
    }

    public function test_save_writes_the_values_the_reader_returns(): void
    {
        $values = [
            'night_macro_import_enabled' => true,
            'night_likha_import_enabled' => false,
            'night_astra_enabled'        => true,
            'night_import_time_1'        => '01:30',
            'night_import_time_2'        => '02:30',
            'night_astra_time'           => '03:30',
            'night_astra_stop_time'      => '06:30',
            'night_astra_max_rows'       => 900,
        ];

        NightRunSettings::save($values + ['hold_snapshot_time' => '09:00']);

        $this->assertSame($values, NightRunSettings::read());
        $this->assertSame('1', AppSetting::get('night_macro_import_enabled'));
        $this->assertSame('0', AppSetting::get('night_likha_import_enabled'));
        $this->assertNull(AppSetting::get('hold_snapshot_time'), 'hindi night key → hindi sinusulat');
    }

    /**
     * Binabasa ulit ang routes/console.php sa bagong Schedule (gaya ng ginagawa ng bawat `schedule:run`),
     * tapos ang totoong `schedule:list`. Para makita ang listing bilang ebidensya:
     * `php artisan test --filter=test_schedule_list_has_the_night_imports_only_when_their_switch_is_on`.
     */
    public function test_schedule_list_has_the_night_imports_only_when_their_switch_is_on(): void
    {
        $this->set(['night_import_time_1' => '01:15', 'night_import_time_2' => '02:45']);

        // [macro switch, likha switch, inaasahang entries: command => cron]
        $cases = [
            'switches on' => ['1', '1', [
                'night:import macro 1' => '15 1 * * *',
                'night:import likha 1' => '15 1 * * *',
                'night:import macro 2' => '45 2 * * *',
                'night:import likha 2' => '45 2 * * *',
                'night:astra-tick'     => '* * * * *',
            ], '1'],
            'macro only' => ['1', '0', [
                'night:import macro 1' => '15 1 * * *',
                'night:import macro 2' => '45 2 * * *',
            ], '0'],
            'switches off' => ['0', '0', [], '0'],
        ];

        foreach ($cases as $name => [$macro, $likha, $expected, $astra]) {
            $this->set(['night_macro_import_enabled' => $macro, 'night_likha_import_enabled' => $likha, 'night_astra_enabled' => $astra]);

            $this->app->forgetInstance(Schedule::class);
            ScheduleFacade::clearResolvedInstance(Schedule::class);
            require base_path('routes/console.php');

            $night = [];
            foreach ($this->app->make(Schedule::class)->events() as $event) {
                if (preg_match('/night:\S+.*$/', (string) $event->command, $m)) {
                    $night[$m[0]] = $event->expression;
                    $this->assertSame('Asia/Manila', (string) $event->timezone, $name);
                    $this->assertTrue($event->withoutOverlapping, $name);
                }
            }
            $this->assertSame($expected, $night, $name);

            Artisan::call('schedule:list');
            $listing = Artisan::output();
            $this->assertStringContainsString('holds:snapshot', $listing, $name);
            $this->assertSame(count($expected), substr_count($listing, 'night:import') + substr_count($listing, 'night:astra-tick'), $name);

            if (in_array('--filter=' . __FUNCTION__, $_SERVER['argv'] ?? [], true)) {
                fwrite(STDERR, "\n--- schedule:list, {$name} ---\n{$listing}");
            }
        }
    }

    public function test_night_tables_are_created_by_their_migrations(): void
    {
        $this->assertTrue(Schema::hasColumns('night_run_steps', [
            'id', 'night_date', 'kind', 'state', 'reason', 'ref_id', 'trigger', 'started_at', 'finished_at', 'stop_at',
            'rows_found', 'rows_over_max', 'consecutive_failures', 'failure_streak_started_at', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('night_astra_rows', [
            'id', 'step_id', 'macro_output_id', 'state', 'attempts', 'code', 'proceed', 'reason', 'log_id', 'cost_usd',
            'duration_ms', 'dispatched_at', 'started_at', 'finished_at', 'created_at', 'updated_at',
        ]));
    }
}
