<?php

namespace Tests\Feature\RtsProjection;

use App\Http\Controllers\OwnerColumnSettingsController;
use App\Services\ColFormatRules;
use App\Services\RtsProjection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang kulay ng Mira RTS ay galing sa Conditional Formatting (col id `mira_rts`), hindi sa code: mga bagong column,
 * seed ng dalawang default na rule, at ang feed na sumusunod sa parehong saved rules.
 */
class MiraRtsConditionsTest extends RtsProjectionTestCase
{
    private const URL = '/api/owner/rts-check?from=2026-10-02&to=2026-10-23';
    private const KEY = 'owner_private_col_format';
    private const NEW_IDS = ['rts_gap_pts', 'rts_gap_abs', 'mira_rts_error_pts', 'rts_gap_ratio', 'mira_rts_matched'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.rts_check.key' => 'k']);
        $this->rates([3 => 0.61]);
    }

    /** n order: 49% Returned, 50% Delivered, ang natira In Transit (edad 3, p .61) → projected ≈ 49.6% sa n=100; $set ang Set RTS%. */
    private function item(string $item, float $set, int $n): void
    {
        $sub = '2026-10-20 12:00:00';
        $r = (int) round($n * .49);
        $d = (int) round($n * .50);
        $this->orders($r, 'Page B', $item, $sub, 'Returned');
        $this->orders($d, 'Page B', $item, $sub, 'Delivered');
        $this->orders($n - $r - $d, 'Page B', $item, $sub, 'In Transit');
        DB::table('page_item_settings')->insert(['page_name' => 'Page B', 'item_name' => $item, 'rts_pct' => $set,
            'effective_date' => '2026-10-10', 'mode_cod_int' => 599]);
    }

    private function feed(): array
    {
        return array_column($this->getJson(self::URL, ['X-RTS-CHECK-KEY' => 'k'])->assertOk()->json('rows'), null, 'item');
    }

    private function setRuleValues(float $value): void
    {
        $saved = json_decode(DB::table('app_settings')->where('key', self::KEY)->value('value'), true);
        foreach ($saved['groups'][0]['rules'] as &$r) $r['value'] = $value;
        unset($r);
        DB::table('app_settings')->where('key', self::KEY)->update(['value' => json_encode($saved)]);
    }

    public function test_catalog_has_the_new_columns_hidden_by_default(): void
    {
        $ids = array_column(OwnerColumnSettingsController::CATALOG['owner_private'], 'id');
        foreach (self::NEW_IDS as $id) {
            $this->assertContains($id, $ids, $id);
            $this->assertNotContains($id, OwnerColumnSettingsController::DEFAULT_VISIBLE['owner_private'], $id);
        }
    }

    public function test_expected_error_grows_with_fewer_orders_and_earlier_age(): void
    {
        $e = fn (float $age, int $m) => RtsProjection::expectedError($age, 50.0, $m);

        $this->assertEqualsWithDelta(sqrt(9 + 25), $e(3, 100), 0.01);   // sqrt(3² + (100·sqrt(.25/100))²)
        $this->assertGreaterThan($e(3, 400), $e(3, 100));   // kulang sa order
        $this->assertGreaterThan($e(5, 100), $e(3, 100));   // mas bata ang edad
        $this->assertGreaterThan($e(7, 100), $e(5, 100));
    }

    public function test_seed_adds_the_default_group_once_and_never_overwrites_saved_rules(): void
    {
        $ctrl = new OwnerColumnSettingsController();
        $first = $ctrl->loadColFormat('owner_private')['byCol']['mira_rts'];
        $ctrl->loadColFormat('owner_private');

        $this->assertSame(['>', '>'], array_column($first, 'op'));
        $this->assertSame([2, 1], array_map('intval', array_column($first, 'value')));   // pula muna, saka amber
        $this->assertSame(['rts_gap_ratio', 'rts_gap_ratio'], array_column($first, 'compare_col'));
        $saved = json_decode(DB::table('app_settings')->where('key', self::KEY)->value('value'), true);
        $this->assertCount(2, $saved['groups']);   // Projected RTS at Mira RTS ng analyst

        // Binago ng owner → hindi na ginagalaw
        $this->setRuleValues(3);
        $this->assertEquals(3, $ctrl->loadColFormat('owner_private')['byCol']['mira_rts'][0]['value']);
    }

    public function test_seed_skips_when_the_owner_already_has_a_rule_for_the_column(): void
    {
        DB::table('app_settings')->insert(['key' => self::KEY, 'value' => json_encode(['groups' => [
            ['cols' => ['mira_rts'], 'rules' => [['op' => '>', 'value' => 9, 'bg' => '#00ff00', 'color' => '', 'bold' => false,
                'label' => 'mine', 'compare_col' => 'rts_gap_abs', 'active_state' => 'any']]],
        ]])]);

        $by = (new OwnerColumnSettingsController())->loadColFormat('owner_private')['byCol']['mira_rts'];

        $this->assertCount(1, $by);
        $this->assertSame('mine', $by[0]['label']);
    }

    public function test_feed_level_follows_the_default_rules(): void
    {
        $this->item('Item Red', 20, 100);     // gap ≈ −29.6, error ≈ 5.8 → ratio ≈ 5
        $this->item('Item Yellow', 40, 100);  // gap ≈ −9.6 → ratio ≈ 1.65
        $this->item('Item None', 49.5, 100);
        $this->item('Item Few', 20, 20);      // 20 order lang → wala sa feed

        $rows = $this->feed();

        $this->assertSame(['Item Red', 'Item Yellow', 'Item None'], array_keys($rows));   // pula, dilaw, saka ang gap
        $this->assertSame(['red', 'yellow', 'none'], array_values(array_column($rows, 'level')));
        $this->assertSame([true, false, false], array_values(array_column($rows, 'flag')));
        $red = $rows['Item Red'];
        $this->assertEqualsWithDelta(5.83, $red['error_pts'], 0.02);    // sqrt(3² + 5.0²)
        $this->assertEqualsWithDelta(5.08, $red['gap_ratio'], 0.05);    // 29.61 ÷ 5.83
        $this->assertGreaterThan(1, $rows['Item Yellow']['gap_ratio']);
        $this->assertLessThan(2, $rows['Item Yellow']['gap_ratio']);
    }

    public function test_changing_a_saved_rule_changes_the_page_data_and_the_feed_level(): void
    {
        $this->item('Item Red', 20, 100);
        $this->assertSame('red', $this->feed()['Item Red']['level']);

        $this->setRuleValues(99);   // itinaas ng owner ang dalawang threshold → wala nang tatamaan

        $this->assertSame('none', $this->feed()['Item Red']['level']);
        $this->withoutVite();
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        $html = $this->actingAs($this->user())->get('/owner/private')->assertOk()->getContent();
        $this->assertStringContainsString('"value":99', $html);   // ang CF rules na binabasa ng cell
    }

    public function test_evaluator_supports_numeric_thresholds_refs_and_null_ops_and_skips_formulas(): void
    {
        $row = ['mira_rts' => 40.0, 'rts_gap_ratio' => 1.5, 'rts_gap_abs' => 8.0];
        $rule = fn (array $r) => $r + ['bg' => '#fecaca', 'compare_col' => '', 'active_state' => 'any'];
        $ref = ['type' => 'ref', 'table' => 'owner_private', 'col' => 'mira_rts'];

        $hit = ColFormatRules::firstHit([
            $rule(['op' => '>', 'value' => 2, 'compare_col' => 'rts_gap_ratio']),
            $rule(['op' => '>', 'value' => $ref, 'compare_col' => 'rts_gap_abs']),   // 8 > 40 hindi
            $rule(['op' => '>=', 'value' => 1.5, 'compare_col' => 'rts_gap_ratio', 'bg' => '#fde68a']),
        ], 'mira_rts', $row);
        $this->assertSame('#fde68a', $hit['bg']);

        $formula = $rule(['op' => '>', 'value' => ['type' => 'formula', 'expr' => '[[rts_gap_abs]]-1'], 'compare_col' => 'rts_gap_ratio']);
        $this->assertNull(ColFormatRules::firstHit([$formula], 'mira_rts', $row));   // hindi kaya ng PHP → skip
        $empty = ['mira_rts' => 1, 'rts_gap_ratio' => null];
        $this->assertNull(ColFormatRules::firstHit([$rule(['op' => '>', 'value' => 0, 'compare_col' => 'rts_gap_ratio'])], 'mira_rts', $empty));
        $this->assertNotNull(ColFormatRules::firstHit([$rule(['op' => 'is_null', 'value' => null, 'compare_col' => 'rts_gap_ratio'])], 'mira_rts', $empty));

        $this->assertSame('red', ColFormatRules::level('#fecaca'));
        $this->assertSame('yellow', ColFormatRules::level('#fde68a'));
        $this->assertSame('none', ColFormatRules::level(null));
    }
}
