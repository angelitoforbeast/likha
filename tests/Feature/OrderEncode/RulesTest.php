<?php

namespace Tests\Feature\OrderEncode;

use App\Models\AppSetting;
use App\Models\MacroOutput;
use App\Models\OrderEncode;
use App\Models\PhoneWhitelist;
use App\Services\OrderEncode\Checker;
use App\Services\OrderEncode\Encoder;
use App\Services\OrderEncode\Input;
use App\Services\OrderEncode\Locations;
use App\Services\OrderEncode\Macro;
use App\Services\OrderEncode\Rules;
use App\Services\OrderEncode\Runner;
use App\Services\OrderEncode\Settings;
use App\Services\OrderEncode\Steps;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/** Spec 135: ang mga panuntunan R1 hanggang R5 at ang switch na improved / sheet, gamit ang mga totoong kaso ng backtest. */
class RulesTest extends EncodeRunTestCase
{
    // ---- switch ---------------------------------------------------------------------------------------------------------

    public function test_S_135_1_the_rules_are_improved_unless_the_setting_says_sheet(): void
    {
        $s = app(Settings::class);
        $this->assertSame('improved', $s->rules());

        foreach (['sheet' => 'sheet', 'SHEET ' => 'sheet', 'improved' => 'improved', 'nonsense' => 'improved'] as $value => $expected) {
            AppSetting::set(Settings::RULES, $value);
            $this->assertSame($expected, $s->rules(), $value);
        }
    }

    public function test_S_135_2_sheet_mode_adds_nothing_to_the_outputs_or_the_steps(): void
    {
        $o = $this->runOrder($this->cxd('Metro Manila', 'Quezon City', 'Bungad'), self::bungadRows(), [], 'sheet');

        $this->assertSame('done', $o->status);
        $this->assertSame(['QUEZON-CITY', 'BUNGAD', true], [$o->outputs['city'], $o->outputs['barangay'], $o->outputs['proceed']]);
        $this->assertArrayNotHasKey('rules', $o->outputs);
        $this->assertArrayNotHasKey('hold', $o->outputs);
        $this->assertNotContains('rule_hold', array_column($o->steps, 'step'));
    }

    // ---- R1 ------------------------------------------------------------------------------------------------------------

    public function test_S_135_3_the_written_barangay_bungad_is_not_replaced_by_project_7(): void
    {
        $cxd = $this->cxd('Metro Manila', '', 'Bungad'); // walang city: may remark "City", kaya tatakbo ang pag-ayos
        $answers = ['province' => ['province' => 'METRO-MANILA'], 'city' => ['city' => 'QUEZON-CITY', 'province' => 'METRO-MANILA'],
            'barangay' => ['barangay' => 'PROJECT 7', 'city' => 'QUEZON-CITY', 'province' => 'METRO-MANILA']];

        $sheet = $this->runOrder($cxd, self::bungadRows(), $answers, 'sheet');
        $this->assertSame('PROJECT 7', $sheet->outputs['barangay']); // ang sira ng script
        $this->assertContains('barangay', $this->tasks);

        $better = $this->runOrder($cxd, self::bungadRows(), $answers, 'improved');
        $this->assertSame(['METRO-MANILA', 'QUEZON-CITY', 'BUNGAD'], [$better->outputs['province'], $better->outputs['city'], $better->outputs['barangay']]);
        $this->assertNotContains('barangay', $this->tasks);
        $this->assertSame(['R1_WRITTEN_BARANGAY_KEPT', 'R1'], [$this->step($better, 'brgy_fix')['status'], $this->step($better, 'brgy_fix')['rule']]);
        $this->assertTrue($better->outputs['proceed']);
    }

    public function test_S_135_4_the_written_barangay_san_isidro_is_not_replaced_by_calawis(): void
    {
        $rows = [['RIZAL', 'RIZAL-ANTIPOLO-CITY', 'SAN ISIDRO (POB.)'], ['RIZAL', 'RIZAL-ANTIPOLO-CITY', 'CALAWIS']];
        $cxd = $this->cxd('', 'Antipolo', 'San Isidro'); // walang province: may remark, kaya tatakbo ang pag-ayos
        $answers = ['province' => ['province' => 'RIZAL'], 'city' => ['city' => 'RIZAL-ANTIPOLO-CITY', 'province' => 'RIZAL'],
            'barangay' => ['barangay' => 'CALAWIS', 'city' => 'RIZAL-ANTIPOLO-CITY', 'province' => 'RIZAL']];

        $sheet = $this->runOrder($cxd, $rows, $answers, 'sheet');
        $this->assertSame('CALAWIS', $sheet->outputs['barangay']);

        $better = $this->runOrder($cxd, $rows, $answers, 'improved');
        $this->assertSame(['RIZAL', 'RIZAL-ANTIPOLO-CITY', 'SAN ISIDRO (POB.)'], [$better->outputs['province'], $better->outputs['city'], $better->outputs['barangay']]);
        $this->assertSame('R1_WRITTEN_BARANGAY_KEPT', $this->step($better, 'brgy_fix')['status']);
    }

    public function test_S_135_5_a_written_barangay_that_is_not_in_the_current_city_still_goes_to_the_fix(): void
    {
        $answers = ['province' => ['province' => 'METRO-MANILA'], 'city' => ['city' => 'QUEZON-CITY', 'province' => 'METRO-MANILA'],
            'barangay' => ['barangay' => 'PROJECT 7', 'city' => 'QUEZON-CITY', 'province' => 'METRO-MANILA']];

        $o = $this->runOrder($this->cxd('Metro Manila', 'Q.city', 'Proyek Siete'), self::bungadRows(), $answers, 'improved');

        $this->assertContains('barangay', $this->tasks);
    }

    public function test_S_135_6_the_written_city_is_not_replaced_either(): void
    {
        $rows = [['LAGUNA', 'LAGUNA-SANTA-CRUZ', 'ALIPIT'], ['LAGUNA', 'LAGUNA-LUMBAN', 'WAWA']];
        $cxd = $this->cxd('Laguna', 'Sta. Cruz', 'Wawa'); // ang barangay ay sa ibang city: ang Macro ay maaaring magkamali, ang isinulat na city ay hindi
        $answers = ['province' => ['province' => 'LAGUNA'], 'city' => ['city' => 'LAGUNA-LUMBAN', 'province' => 'LAGUNA'], 'barangay' => ['barangay' => 'ALIPIT']];

        $o = $this->runOrder($cxd, $rows, $answers, 'improved');

        $this->assertNotContains('city', $this->tasks);
        $this->assertSame('LAGUNA-SANTA-CRUZ', $o->outputs['city']);
    }

    public static function written(): array
    {
        return [
            'brgy folded' => ['Brgy. Bungad', 'bungad'],
            'poblacion folded' => ['Poblacion 4', '4'],
            'pob folded' => ['Pob. 4', '4'],
            'label of the list' => ['POBLACION BARANGAY 4', '4'],
            'roman numeral' => ['Barangay IV (POB.)', '4'],
            'enye' => ['Parañaque', 'paranaque'],
            'poblacion alone stays' => ['Poblacion', 'poblacion'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('written')]
    public function test_S_135_7_written_barangays_are_compared_in_one_form(string $raw, string $expected): void
    {
        $this->assertSame($expected, \App\Services\OrderEncode\Written::brgy($raw));
    }

    // ---- R2 ------------------------------------------------------------------------------------------------------------

    private function checkTanqui(string $writtenProvince, ?Rules $rules): Checker
    {
        $rows = [['LA-UNION', 'LA-UNION-SAN-FERNANDO-CITY', 'TANQUI'], ['PAMPANGA', 'PAMPANGA-SAN-FERNANDO-CITY', 'TANQUI']];
        $loc = new Locations($rows, $rules !== null);
        $rules?->begin(['province' => $writtenProvince, 'city' => 'SAN FERNANDO', 'barangay' => 'TANQU']);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/v1/responses' => Http::response(Fixtures::responses(json_encode(['province_ok' => false, 'city_ok' => true, 'barangay_ok' => true])))]);
        $steps = new Steps(Fixtures::KEY);
        $c = new Checker(['A' => '10:00 23-10-2026', 'B' => 'Ana Cruz', 'C' => '9171234567', 'D' => 'Blk 1', 'E' => 'PAMPANGA', 'F' => 'PAMPANGA-SAN-FERNANDO-CITY', 'G' => 'TANQUI',
            'H' => '2 x TEA', 'I' => '499', 'J' => 'Page Uno', 'K' => 'chat of the customer', 'L' => 'shop', 'M' => 'Tanqui, San Fernando City, La Union', 'N' => '',
            'R' => '', 'S' => '', 'T' => '', 'U' => '', 'V' => '', 'W' => ''], $loc, Fixtures::gpt($steps), $steps, $rules);
        $c->run();

        return $c;
    }

    public function test_S_135_8_province_only_does_not_turn_into_a_check_when_the_customer_wrote_another_province(): void
    {
        $sheet = $this->checkTanqui('La Union', null);
        $this->assertSame(['Province', '✅'], [$sheet->cells['S'], $sheet->cells['W']]); // ang sira ng script: Pampanga ang naging ✅

        $loc = new Locations([['LA-UNION', 'LA-UNION-SAN-FERNANDO-CITY', 'TANQUI'], ['PAMPANGA', 'PAMPANGA-SAN-FERNANDO-CITY', 'TANQUI']], true);
        $better = $this->checkTanqui('La Union', new Rules($loc));
        $this->assertSame('Province', $better->cells['W']);
    }

    public function test_S_135_9_a_written_province_that_resolves_to_nothing_does_not_count(): void
    {
        $loc = new Locations([['LA-UNION', 'LA-UNION-SAN-FERNANDO-CITY', 'TANQUI'], ['PAMPANGA', 'PAMPANGA-SAN-FERNANDO-CITY', 'TANQUI']], true);

        foreach (['[Please confirm]', 'N/A', 'PLEASE CONFIRM', ''] as $junk) {
            $this->assertSame('✅', $this->checkTanqui($junk, new Rules($loc))->cells['W'], $junk);
        }
    }

    public function test_S_135_10_the_order_is_held_with_the_reason_when_the_province_differs(): void
    {
        $rows = [['LA-UNION', 'LA-UNION-SAN-FERNANDO-CITY', 'TANQUI'], ['PAMPANGA', 'PAMPANGA-SAN-FERNANDO-CITY', 'TANQUI']];
        // Ang customer ay nagsulat ng La Union, pero ang resulta (Macro) ay Pampanga: ang listahan ng Macro ay luma (walang La Union).
        $encoder = new Encoder(new Macro([$rows[1]]), new Locations($rows, true), Fixtures::gpt($steps = new Steps(Fixtures::KEY)), $steps, new Rules(new Locations($rows, true)));
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response(Fixtures::chat('Blk 5')),
            'api.openai.com/v1/responses' => Http::response(Fixtures::responses(json_encode(['province_ok' => false, 'city_ok' => true, 'barangay_ok' => true]))),
        ]);

        $r = $encoder->run(Input::fromRow($this->withCxd($this->cxd('La Union', 'San Fernando', 'Tanqui'))));

        $this->assertSame('PAMPANGA', $r['outputs']['province']);
        $this->assertFalse($r['outputs']['proceed']);
        $this->assertSame(['rule' => 'R2', 'reason' => 'Province differs from what the customer wrote'], $r['outputs']['hold']);
        $this->assertSame('HOLD:R2', collect($steps->log)->firstWhere('step', 'rule_hold')['status']);
    }

    // ---- R3 ------------------------------------------------------------------------------------------------------------

    public function test_S_135_11_the_first_candidate_prefers_the_written_barangay_then_the_written_city(): void
    {
        $rows = [['AGUSAN-DEL-SUR', 'AGUSAN-DEL-SUR-ESPERANZA', 'CEBULAN'], ['CEBU', 'CEBU-CITY', 'PASIL'], ['CEBU', 'CEBU-CITY', 'LAHUG']];
        $macro = new Macro($rows);

        $sheet = $macro->run('CEBU', 'CEBU', 'PASOLOD');
        $this->assertSame('CEBULAN', $sheet['barangay']); // pagkakasunod-sunod lang ng listahan

        $prefer = (new Rules(new Locations($rows, true)));
        $prefer->begin(['province' => 'CEBU', 'city' => 'CEBU', 'barangay' => 'PASOLOD']);
        $better = $macro->run('CEBU', 'CEBU', 'PASOLOD', $prefer->prefer());
        $this->assertSame(['CEBU', 'CEBU-CITY'], [$better['province'], $better['city']]);

        // ang barangay na isinulat ang nauuna sa city
        $rows2 = [['CEBU', 'CEBU-CITY', 'LAHUG'], ['CEBU', 'CEBU-MANDAUE-CITY', 'LAHUG']];
        $p = new Rules(new Locations($rows2, true));
        $p->begin(['province' => 'CEBU', 'city' => 'MANDAUE CITY', 'barangay' => 'LAHUG']);
        $this->assertSame('CEBU-MANDAUE-CITY', (new Macro($rows2))->run('CEBU', '', 'LAHUG', $p->prefer())['city']);
    }

    // ---- R4 ------------------------------------------------------------------------------------------------------------

    public function test_S_135_12_the_label_is_written_as_the_jnt_list_writes_it(): void
    {
        $rows = [['BATANGAS', 'BATANGAS-TALISAY', 'POBLACION BARANGAY 4'], ['BATANGAS', 'BATANGAS-TALISAY', 'POBLACION BARANGAY 3']];
        $cells = fn () => ['A' => '10:00 23-10-2026', 'B' => 'Ana Cruz', 'C' => '9171234567', 'D' => 'Blk 1', 'E' => 'BATANGAS', 'F' => 'BATANGAS-TALISAY', 'G' => '',
            'H' => '2 x TEA', 'I' => '499', 'J' => 'Page Uno', 'K' => 'chat of the customer', 'L' => 'shop', 'M' => 'Poblacion 4 Talisay Batangas', 'N' => '',
            'R' => '', 'S' => '', 'T' => '', 'U' => '', 'V' => '', 'W' => ''];
        Http::fake(['api.openai.com/v1/responses' => Http::response(Fixtures::responses(json_encode(['barangay' => 'BARANGAY POBLACION 4', 'city' => 'BATANGAS-TALISAY', 'province' => 'BATANGAS'])))]);

        $sheet = new Checker($cells(), new Locations($rows), Fixtures::gpt($s1 = new Steps(Fixtures::KEY)), $s1);
        $sheet->run();
        $this->assertSame('BARANGAY POBLACION 4', $sheet->cells['G']); // hindi label ng J&T

        $loc = new Locations($rows, true);
        $rules = new Rules($loc);
        $rules->begin(['province' => 'BATANGAS', 'city' => 'TALISAY', 'barangay' => 'POBLACION 5']); // hindi tumutugma: GPT ang sasagot
        Http::fake(['api.openai.com/v1/responses' => Http::response(Fixtures::responses(json_encode(['barangay' => 'barangay poblacion 4', 'city' => 'BATANGAS-TALISAY', 'province' => 'BATANGAS'])))]);
        $better = new Checker($cells(), $loc, Fixtures::gpt($s2 = new Steps(Fixtures::KEY)), $s2, $rules);
        $better->run();
        $this->assertSame('POBLACION BARANGAY 4', $better->cells['G']);
        $this->assertTrue($loc->hasCombination('BATANGAS', 'BATANGAS-TALISAY', $better->cells['G']));
    }

    public function test_S_135_13_a_combination_that_is_not_in_the_jnt_list_is_held(): void
    {
        // Burauen / Bayog: ang Bayog ay sa ibang city. Ang listahan ng Macro ay may sirang linya na wala sa J&T file.
        $jnt = [['LEYTE', 'LEYTE-BURAUEN', 'POBLACION'], ['LEYTE', 'LEYTE-DULAG', 'BAYOG'], ['LAGUNA', 'LAGUNA-SANTA-CRUZ', 'ALIPIT'], ['LAGUNA', 'LAGUNA-PILA', 'INALMASINAN']];
        $stale = [['LEYTE', 'LEYTE-BURAUEN', 'BAYOG'], ['LAGUNA', 'LAGUNA-SANTA-CRUZ', 'INALMASINAN']];

        foreach ([['Leyte', 'Burauen', 'Bayog'], ['Laguna', 'Santa Cruz', 'Inalmasinan']] as [$p, $c, $b]) {
            Http::fake(['*' => Http::response(Fixtures::chat('Blk 5'))]);
            $steps = new Steps(Fixtures::KEY);
            $rules = new Rules(new Locations($jnt, true));
            $in = Input::fromRow($this->withCxd($this->cxd($p, $c, $b)));

            $sheet = (new Encoder(new Macro($stale), new Locations($jnt), Fixtures::gpt($steps), $steps))->run($in);
            $this->assertTrue($sheet['outputs']['proceed'], "{$c}/{$b} was PROCEEDed by the script");

            $r = (new Encoder(new Macro($stale), new Locations($jnt, true), Fixtures::gpt($steps = new Steps(Fixtures::KEY)), $steps, $rules))->run($in);
            $this->assertFalse($r['outputs']['proceed'], "{$c}/{$b}");
            $this->assertSame(['rule' => 'R4', 'reason' => 'Not a J&T address combination'], $r['outputs']['hold']);
        }
    }

    public function test_S_135_14_a_real_combination_is_not_held(): void
    {
        $o = $this->runOrder($this->cxd('Metro Manila', 'Quezon City', 'Bungad'), self::bungadRows());

        $this->assertTrue($o->outputs['proceed']);
        $this->assertNull($o->outputs['hold']);
        $this->assertSame('improved', $o->outputs['rules']);
    }

    // ---- R5 ------------------------------------------------------------------------------------------------------------

    private function earlier(array $over = []): MacroOutput
    {
        return MacroOutput::create($over + ['PAGE' => 'Page Uno', 'FULL NAME' => 'Maria Santos', 'PHONE NUMBER' => '9171234567', 'ts_date' => '2026-10-23', 'STATUS' => 'PROCEED',
            'CITY' => 'QUEZON-CITY', 'BARANGAY' => 'BUNGAD']);
    }

    public function test_S_135_15_three_same_day_orders_of_one_phone_hold_the_fourth(): void
    {
        $ids = [$this->earlier()->id, $this->earlier()->id, $this->earlier()->id];

        $o = $this->runOrder($this->cxd('Metro Manila', 'Quezon City', 'Bungad'), self::bungadRows());

        $this->assertFalse($o->outputs['proceed']);
        $this->assertSame(['rule' => 'R5', 'reason' => 'Possible duplicate: ' . implode(', ', $ids)], $o->outputs['hold']);
        $this->assertSame('HOLD:R5', $this->step($o, 'rule_hold')['status']);
    }

    public function test_S_135_16_the_same_name_barangay_and_city_is_a_duplicate_with_another_phone(): void
    {
        $same = $this->earlier(['FULL NAME' => 'ana cruz', 'PHONE NUMBER' => '9189999999']);

        $o = $this->runOrder($this->cxd('Metro Manila', 'Quezon City', 'Bungad'), self::bungadRows());

        $this->assertSame('Possible duplicate: ' . $same->id, $o->outputs['hold']['reason']);
    }

    public function test_S_135_17_another_day_another_name_a_cannot_proceed_row_or_a_whitelisted_phone_is_not_a_duplicate(): void
    {
        $this->earlier(['ts_date' => '2026-10-22']);                          // ibang araw
        $this->earlier(['PHONE NUMBER' => '9180000000', 'FULL NAME' => 'Ana Cruz', 'BARANGAY' => 'PROJECT 7']); // parehong pangalan, ibang barangay
        $this->earlier(['STATUS' => 'CANNOT PROCEED']);                       // hindi binibilang

        $this->assertTrue($this->runOrder($this->cxd('Metro Manila', 'Quezon City', 'Bungad'), self::bungadRows())->outputs['proceed']);

        $this->earlier();
        if (Schema::hasTable('phone_whitelist')) {
            PhoneWhitelist::create(['phone_number' => '9171234567', 'host_scope' => 'likha', 'reason' => 'test']);
            $this->assertTrue($this->runOrder($this->cxd('Metro Manila', 'Quezon City', 'Bungad'), self::bungadRows())->outputs['proceed']);
        }
    }

    public function test_S_135_18_an_encode_that_is_not_in_macro_output_yet_counts_for_the_same_day(): void
    {
        $first = $this->runOrder($this->cxd('Metro Manila', 'Quezon City', 'Bungad'), self::bungadRows());
        $this->assertTrue($first->outputs['proceed']);
        $first->update(['created_at' => '2026-10-23 10:00:00']);

        $second = $this->runOrder($this->cxd('Metro Manila', 'Quezon City', 'Bungad'), self::bungadRows());

        $this->assertSame('Possible duplicate: e' . $first->id, $second->outputs['hold']['reason']);
    }

    public function test_S_135_60_a_line_repeated_in_the_jnt_file_counts_once_so_san_isidro_in_antipolo_is_not_multiple_suggested_address(): void
    {
        $rows = [['RIZAL', 'RIZAL-ANTIPOLO-CITY', 'SAN ISIDRO (POB.)'], ['RIZAL', 'RIZAL-ANTIPOLO-CITY', 'SAN ISIDRO (POB.)'], ['RIZAL', 'RIZAL-ANTIPOLO-CITY', 'CALAWIS']];
        $cxd = $this->cxd('Rizal', 'Antipolo', 'San Isidro');

        foreach (['sheet', 'improved'] as $mode) {
            $o = $this->runOrder($cxd, $rows, ['barangay' => ['barangay' => 'CALAWIS']], $mode);

            $this->assertSame('', $o->outputs['remark'], $mode);
            $this->assertSame(1, $o->outputs['candidates'], $mode);
            $this->assertSame('SAN ISIDRO (POB.)', $o->outputs['barangay'], $mode);
            $this->assertNotContains('barangay', $this->tasks, $mode);
            $this->assertTrue($o->outputs['proceed'], $mode);
        }
        $this->assertCount(2, (new \App\Services\OrderEncode\LocationList($this->addressFile))->rows()); // ang 3 linya ay 2 ang magkaiba
    }

    public function test_S_135_36_a_held_live_order_is_written_to_macro_output_as_to_fix_so_validate_cannot_make_it_proceed(): void
    {
        $earlier = $this->earlier();
        $this->writeList(self::bungadRows());
        AppSetting::set(Settings::RULES, 'improved');
        $this->fakeGpt();
        $this->configurePage('live');
        $o = OrderEncode::create(['page' => self::PAGE, 'spreadsheet_id' => self::ORDERS, 'tab' => 'All Orders', 'row_number' => null, 'mode' => 'live', 'status' => 'queued',
            'input' => ['row' => array_map(fn ($v) => "'" . $v, $this->withCxd($this->cxd('Metro Manila', 'Quezon City', 'Bungad')))]]);

        app(Runner::class)->run($o);

        $m = MacroOutput::where('id', '<>', $earlier->id)->sole();
        $this->assertSame(['TO FIX', null, 'HOLD R5: Possible duplicate: ' . $earlier->id], [$m->{'APP SCRIPT CHECKER'}, $m->STATUS, $m->{'AI ANALYZE'}]);
        $this->assertSame('✅', $o->fresh()->outputs['final']); // ang encode mismo ay hindi binabago
    }

    public function test_S_135_19_sheet_mode_does_not_check_duplicates(): void
    {
        $this->earlier();

        $o = $this->runOrder($this->cxd('Metro Manila', 'Quezon City', 'Bungad'), self::bungadRows(), [], 'sheet');

        $this->assertTrue($o->outputs['proceed']);
    }
}
