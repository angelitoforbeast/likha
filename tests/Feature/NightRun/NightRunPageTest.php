<?php

namespace Tests\Feature\NightRun;

use App\Models\AppSetting;
use App\Models\NightAstraRow;
use App\Models\NightRunStep;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang markup ng "Night run" sa logs page at ng night block sa settings page (spec 007 §9, §10), sa pamamagitan ng
 * totoong routes. Ang bilang at CEO-only na data ay sinubok na sa NightRunSummaryTest: dito ang ipinapakita.
 */
class NightRunPageTest extends NightAstraTestCase
{
    private const LOGS_URL     = '/encoder/checker_1/ai-checker/logs';
    private const SETTINGS_URL = '/encoder/checker_1/settings';
    private const HOSTILE      = '<script>alert(1)</script>';

    protected function setUp(): void
    {
        parent::setUp();

        // Bawat view ay dumadaan sa composer ng AppServiceProvider na bumibilang ng tasks ng user.
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
    }

    /** Isang gabi: macro import na may 1 pumalyang sheet, at Astra na tapos (PROCEED, TO FIX, failed). Ibinabalik ang Astra step. */
    private function seedNight(string $sheet = 'Sheet A', ?string $astraReason = null): NightRunStep
    {
        $run = $this->macroImport('done', ['done', 'failed']);
        DB::table('macro_import_run_items')->where('run_id', $run->id)->where('status', 'failed')
            ->update(['gsheet_name' => $sheet, 'message' => 'Boom: quota exceeded']);
        NightRunStep::create([
            'night_date' => self::NIGHT, 'kind' => 'macro_import_1', 'state' => 'started', 'trigger' => 'schedule',
            'ref_id' => $run->id, 'started_at' => self::NIGHT . ' 01:00:00',
        ]);

        $step = $this->runningStep([], ['state' => 'finished', 'finished_at' => now(), 'rows_found' => 3, 'reason' => $astraReason]);
        foreach ([['done', true, 'PROCEED'], ['done', false, 'TO FIX'], ['failed', false, null]] as [$state, $proceed, $code]) {
            NightAstraRow::create([
                'step_id' => $step->id, 'macro_output_id' => $this->order()->id, 'state' => $state, 'proceed' => $proceed, 'code' => $code,
                'attempts' => 1, 'dispatched_at' => now(), 'started_at' => now(), 'finished_at' => now(),
            ]);
        }

        return $step;
    }

    private function logs(string $role = 'CEO')
    {
        $email = strtolower($role) . '@example.test';

        return $this->actingAs(User::where('email', $email)->first() ?? $this->user($role, $email))->get(self::LOGS_URL);
    }

    public function test_the_ceo_sees_the_night_with_every_action(): void
    {
        $step = $this->seedNight();

        $response = $this->logs();

        $response->assertOk()->assertSeeInOrder(['Night run', 'Imports', 'Astra', 'Finished', '3 rows', '1 PROCEED', '1 for a person', '1 failed']);
        $response->assertSee('Mon, Oct 5')->assertSee('orders of Oct 4')->assertSee('Done with 1 failed sheet')->assertSee('estimated');
        $response->assertSee('Retry failed')->assertSee(route('night_run.retry_failed', ['step' => $step->id]), false);
        $response->assertSee(route('night_run.run_now'), false)->assertSee('Run now')->assertSee('Show rows')->assertSee('TO FIX 1');
    }

    public function test_a_marketing_user_sees_the_counts_but_nothing_for_the_ceo(): void
    {
        $this->seedNight();

        $response = $this->logs('Marketing');

        $response->assertOk()->assertSeeInOrder(['Night run', 'Imports', 'Astra', 'Finished', '3 rows', '1 PROCEED']);
        foreach (['estimated', 'Retry failed', 'Run now', 'Show rows', 'Boom: quota exceeded'] as $needle) {
            $this->assertFalse(str_contains($response->getContent(), $needle), "found: {$needle}");
        }
        $response->assertSee('Sheet A');
    }

    public function test_the_ceo_sees_the_sheet_message_and_the_banner_only_when_there_are_lines(): void
    {
        $this->seedNight();
        $this->logs()->assertSee('Boom: quota exceeded');
        $this->assertFalse(str_contains($this->logs()->getContent(), 'data-night-banner'));

        // Astra tumigil: may banner na pula.
        NightRunStep::where('kind', 'astra')->update(['state' => 'stopped', 'reason' => 'Stopped: no API key set']);
        $response = $this->logs();
        $response->assertSee('data-night-banner', false)->assertSee('Night Astra 03:00: Stopped: no API key set');
    }

    public function test_outside_text_is_escaped(): void
    {
        $this->seedNight(self::HOSTILE, self::HOSTILE);

        $html = $this->logs()->getContent();

        $this->assertFalse(str_contains($html, self::HOSTILE));
        $this->assertTrue(str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'));
    }

    public function test_the_partial_never_prints_raw_html(): void
    {
        $source = file_get_contents(resource_path('views/encoder/_night_run.blade.php'));

        $this->assertStringNotContainsString('{!!', $source);
        $this->assertSame(0, preg_match('/\sx-html\s*=/', $source));
    }

    public function test_without_nights_it_says_so_and_the_ceo_is_pointed_to_the_settings(): void
    {
        $this->logs('Marketing')->assertSee('No night run yet.')->assertDontSee(route('encoder.checker1.settings'), false);
        $this->logs()->assertSee('No night run yet.')->assertSee(route('encoder.checker1.settings'), false);
    }

    public function test_the_ceo_sees_the_night_block_in_settings_and_others_do_not(): void
    {
        AppSetting::set('night_astra_max_rows', '900');
        AppSetting::set('night_astra_enabled', '1');
        AppSetting::set('night_likha_import_enabled', '0');

        $response = $this->actingAs($this->user())->get(self::SETTINGS_URL);

        $response->assertOk()->assertSee('Night run')->assertSee('action="' . route('night_run.settings') . '"', false);
        $response->assertSee('name="night_astra_max_rows"', false)->assertSee('value="900"', false)->assertSee('value="03:00"', false);
        $this->assertSame(1, preg_match('/<input[^>]*type="checkbox"[^>]*name="night_astra_enabled"[^>]*value="1"[^>]*checked/s', $response->getContent()));
        $this->assertSame(0, preg_match('/name="night_likha_import_enabled"[^>]*checked/s', $response->getContent()));

        auth()->forgetGuards();
        $this->actingAs($this->user('Marketing', 'm@example.test'))->get(self::SETTINGS_URL)->assertOk()
            ->assertDontSee(route('night_run.settings'), false);
    }
}
