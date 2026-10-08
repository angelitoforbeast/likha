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
 * Ang markup ng "Night run" sa logs page at ng night block sa settings page, sa pamamagitan ng
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

        // Ang isang linya ay nagdadagdag: skipped, not run (kasama ang sobra sa maximum) at queued/running.
        AppSetting::set('night_astra_max_rows', '2500');
        foreach (['skipped', 'not_run', 'queued', 'running'] as $state) {
            NightAstraRow::create(['step_id' => $step->id, 'macro_output_id' => $this->order()->id, 'state' => $state, 'attempts' => 0]);
        }
        $step->update(['rows_found' => 9, 'rows_over_max' => 2]);
        $html = $this->logs()->getContent();
        // Ang text ng linya, walang tags: link na ang "for a person" pero pareho pa rin ang mga salita at ang pagkakasunod.
        $text = preg_replace('/\s+/', ' ', strip_tags($html));
        $this->assertTrue(str_contains($text, '9 rows · 1 PROCEED · 1 for a person · 1 failed · 1 skipped · 3 not run · 2 queued/running'), 'one-line summary');
        $this->assertTrue(str_contains($html, '2 not run: over the safety maximum of 2,500'), 'over max line');

        // Mahabang text mula sa labas: nababali, hindi nag-s-scroll pahalang. At bawat click sa Show rows ay kumukuha ulit.
        $this->assertSame(1, preg_match('/class="[^"]*break-words[^"]*">Failed sheet:/', $html), 'sheet line wraps');
        $this->assertSame(1, preg_match('/class="[^"]*break-words[^"]*" x-text="r\.reason"/', $html), 'row reason wraps');
        $this->assertTrue(str_contains($html, 'if (this.loading) return;'), 'refetch on every click');
        $this->assertFalse(str_contains($html, 'this.rows !== null || this.loading'), 'no cached rows');
    }

    /** Ang address ng link na ang text ay eksaktong `$words`, bilang [path, query]; null kapag walang ganoong link. */
    private function linkTo(string $html, string $words): ?array
    {
        if (preg_match('/<a href="([^"]*)" class="[^"]*\bwhitespace-nowrap\b[^"]*">' . preg_quote($words, '/') . '<\/a>/', $html, $m) !== 1) {
            return null;
        }
        $url = html_entity_decode($m[1], ENT_QUOTES);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return [parse_url($url, PHP_URL_PATH), $query];
    }

    public function test_S_09_1_the_collapsed_count_is_a_link(): void
    {
        $step = $this->seedNight();

        $html = $this->logs()->getContent();

        // Ang mga order ng gabing 2026-10-05 ay ang sa 2026-10-04. Buo ang "1 for a person" sa loob ng link (hindi napuputol).
        $this->assertSame(
            ['/encoder/checker_1', ['date' => '2026-10-04', 'night_step' => (string) $step->id]],
            $this->linkTo($html, '1 for a person')
        );
        $this->assertStringNotContainsString('target=', $html);
        // Walang nadagdag na space o putol sa pagitan ng link at ng mga katabi nito sa linya.
        $this->assertSame(1, preg_match('/1 PROCEED · <a href="[^"]*" class="[^"]*">1 for a person<\/a> · 1 failed/', $html));
    }

    public function test_S_09_2_the_expanded_count_is_the_same_link(): void
    {
        $this->seedNight();

        $html = $this->logs()->getContent();

        $this->assertNotNull($this->linkTo($html, '1 left for a person'));
        $this->assertSame($this->linkTo($html, '1 for a person'), $this->linkTo($html, '1 left for a person'));
        // Ang hati kada code ay plain text pa rin, sa labas ng link.
        $this->assertSame(1, preg_match('/1 left for a person<\/a>\s*\(TO FIX 1\)/', $html));
    }

    public function test_S_09_3_a_count_of_zero_is_plain_text(): void
    {
        $this->seedNight();
        NightAstraRow::where('state', 'done')->where('proceed', false)->delete();

        $html = $this->logs()->getContent();

        $this->assertStringContainsString('1 PROCEED · 0 for a person · 1 failed', preg_replace('/\s+/', ' ', strip_tags($html)));
        $this->assertStringContainsString('0 left for a person', $html);
        $this->assertStringNotContainsString('night_step=', $html);
    }

    public function test_S_09_4_no_astra_step_no_link(): void
    {
        $this->seedNight();
        NightAstraRow::query()->delete();
        NightRunStep::where('kind', 'astra')->delete();

        $response = $this->logs();

        $response->assertOk()->assertSee('Mon, Oct 5');
        $this->assertStringNotContainsString('night_step=', $response->getContent());
        $this->assertStringNotContainsString('for a person', $response->getContent());
    }

    public function test_S_09_5_a_non_ceo_sees_the_link_too(): void
    {
        $step = $this->seedNight();

        $html = $this->logs('Marketing')->getContent();

        $this->assertSame(
            ['/encoder/checker_1', ['date' => '2026-10-04', 'night_step' => (string) $step->id]],
            $this->linkTo($html, '1 for a person')
        );
        $this->assertNotNull($this->linkTo($html, '1 left for a person'));
        // Ang para sa CEO lang ay para sa CEO pa rin.
        foreach (['Show rows', 'estimated', 'Retry failed', '/rows'] as $ceoOnly) {
            $this->assertStringNotContainsString($ceoOnly, $html);
        }
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

        // Tinanggihang save na naka-uncheck ang Night Astra: nananatiling unchecked, ang error ay nasa block lang (night bag).
        $this->from(self::SETTINGS_URL)->post(route('night_run.settings'), [
            'night_macro_import_enabled' => '1', 'night_import_time_1' => '02:00', 'night_import_time_2' => '01:00',
            'night_astra_time' => '03:00', 'night_astra_stop_time' => '06:00', 'night_astra_max_rows' => '900',
        ])->assertRedirect(self::SETTINGS_URL);
        $html = $this->get(self::SETTINGS_URL)->getContent();
        $this->assertSame(0, preg_match('/name="night_astra_enabled"[^>]*checked/s', $html), 'astra stays off');
        $this->assertSame(1, preg_match('/name="night_macro_import_enabled"[^>]*checked/s', $html), 'macro stays on');
        $this->assertSame(1, substr_count($html, 'The first import time must be earlier'), 'error shown once, in the block');

        // Matagumpay na night save: sariling mensahe, hindi ang banner ng Idle Summary.
        $html = $this->withSession(['night_settings_saved' => true])->get(self::SETTINGS_URL)->getContent();
        $this->assertTrue(str_contains($html, 'Night run settings saved.'));
        $this->assertFalse(str_contains($html, 'Refresh the Idle Summary page'));

        auth()->forgetGuards();
        $this->actingAs($this->user('Marketing', 'm@example.test'))->get(self::SETTINGS_URL)->assertOk()
            ->assertDontSee(route('night_run.settings'), false);
    }
}
