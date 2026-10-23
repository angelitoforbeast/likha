<?php

namespace Tests\Feature\OrderEncode;

use App\Models\AppSetting;
use App\Models\OrderEncode;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;

/** Ang tab na "All pages (vs final order)" ng /order-encode. */
class FinalPageTest extends FinalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private array $users = [];

    private function open(string $query = '', string $role = 'CEO')
    {
        $this->users[$role] ??= $this->user($role, strtolower(str_replace(' ', '', $role)) . '@example.test');

        return $this->actingAs($this->users[$role])->get('/order-encode/final' . $query);
    }

    private function compared(): void
    {
        Artisan::call('order-encode:compare-final');
    }

    /** Page Uno: isang buo, isang may maling province na in-edit ng staff; Page Dos: isang HOLD na pareho ang desisyon, isang PROCEED na hold ang Likha. */
    private function seedOrders(): array
    {
        $ok = $this->encoded($this->macro());
        $edited = $this->encoded($this->macro(['PROVINCE' => 'BOHOL', 'edited_province' => true]));
        $hold = $this->encoded($this->macro(['PAGE' => 'Page Dos', 'STATUS' => 'HOLD', 'APP SCRIPT CHECKER' => '❌']), ['final' => '❌', 'proceed' => false], ['page' => 'Page Dos']);
        $missed = $this->encoded($this->macro(['PAGE' => 'Page Dos']), ['final' => '❌', 'proceed' => false], ['page' => 'Page Dos']);
        $this->encoded($this->macro(['STATUS' => null, 'APP SCRIPT CHECKER' => null, 'PROVINCE' => null])); // wala pang final
        $this->compared();

        return compact('ok', 'edited', 'hold', 'missed');
    }

    public function test_S_133_20_it_shows_the_tiles_the_proceed_table_and_the_per_page_table_and_sorts_it(): void
    {
        $this->seedOrders();

        $r = $this->open()->assertOk();

        $tiles = $r->viewData('tiles');
        $this->assertSame([5, 4], [$tiles['orders'], $tiles['compared']]);
        $this->assertSame([75.0, 100.0, 100.0], [$tiles['location_pct'], $tiles['address_pct'], $tiles['item_cod_pct']]); // province ng isa ay mali
        $m = $r->viewData('proceed');
        $this->assertSame(['proceed' => 1, 'hold' => 1], $m['untouched']);
        $this->assertSame(['proceed' => 1, 'hold' => 0], $m['edited']);
        $this->assertSame(['proceed' => 0, 'hold' => 1], $m['not']);
        $this->assertSame(['Page Dos', 'Page Uno'], array_column($r->viewData('pageRows'), 'page'));
        $uno = collect($r->viewData('pageRows'))->firstWhere('page', 'Page Uno');
        $this->assertSame([3, 50.0, 100.0], [$uno['orders'], $uno['location_pct'], $uno['proceed_pct']]);
        $r->assertSee('All pages (vs final order)');

        $by = fn (string $q) => array_column($this->open($q)->viewData('pageRows'), 'page');
        $this->assertSame(['Page Dos', 'Page Uno'], $by('?sort=location_pct&dir=desc'));
        $this->assertSame(['Page Uno', 'Page Dos'], $by('?sort=orders&dir=desc'));
        $this->assertSame(['Page Uno', 'Page Dos'], $by('?sort=proceed_pct&dir=desc'));
        $this->assertSame(['Page Dos', 'Page Uno'], $by('?sort=proceed_pct&dir=asc'));
    }

    public function test_S_133_21_it_filters_by_page_and_date_range(): void
    {
        $this->seedOrders();
        $old = $this->encoded($this->macro());
        $old->forceFill(['created_at' => now()->subDays(30)])->save();
        $this->compared();

        $this->assertSame(2, $this->open('?page=' . urlencode('Page Dos'))->viewData('tiles')['orders']);
        $this->assertSame(6, $this->open('?from=2026-09-01&to=2026-10-23')->viewData('tiles')['orders']);
    }

    public function test_S_133_22_the_disagreements_show_both_values_with_the_steps_and_escape_the_text(): void
    {
        $o = $this->encoded($this->macro(['BARANGAY' => '<script>alert(1)</script>']));
        $this->compared();

        $r = $this->open()->assertOk();

        $rows = $r->viewData('rows');
        $this->assertCount(1, $rows);
        $this->assertSame(['LAHUG', '<script>alert(1)</script>', 'different'], [$rows[0]['cells']['barangay']['likha'], $rows[0]['cells']['barangay']['final'], $rows[0]['cells']['barangay']['result']]);
        $r->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $r->assertSee('address_line1: WROTE_ADDRESS_LINE1 (gpt-4o-mini, 100/10 tokens, 420 ms)', false);
        $this->assertSame($o->id, $rows[0]['id']);
    }

    public function test_S_133_23_the_page_shows_the_switch_the_counts_of_today_and_the_estimated_cost(): void
    {
        AppSetting::set('order_encode_import_shadow', 'on');
        AppSetting::set('order_encode_import_daily_cap', '50');
        AppSetting::set('order_encode_import_skip_pages', 'Page Tres');
        $this->encoded($this->macro());
        $this->encoded($this->macro(), more: ['mode' => 'shadow']); // hindi bilang sa "encoded today" ng import
        app(\App\Services\OrderEncode\ImportShadow::class)->countSkipped('cap');

        $s = $this->open()->assertOk()->viewData('import');

        $this->assertSame([true, 50, 'Page Tres', 1, 1, 0], [$s['on'], $s['cap'], $s['skip_pages'], $s['encoded_today'], $s['skipped_cap'], $s['skipped_pages']]);
        $this->assertEqualsWithDelta(2 * (100 * 0.15 + 10 * 0.60) / 1e6, $s['cost'], 1e-9);
    }

    public function test_S_133_24_only_the_ceo_saves_the_three_settings_and_bad_values_are_refused(): void
    {
        $ceo = $this->user('CEO');
        $save = fn ($user, array $d) => $this->actingAs($user)->post('/order-encode/import-settings', $d);

        $save($ceo, ['switch' => 'on', 'cap' => '300', 'skip_pages' => 'Page A, Page B'])->assertRedirect();
        $this->assertSame(['on', '300', 'Page A, Page B'], [AppSetting::get('order_encode_import_shadow'), AppSetting::get('order_encode_import_daily_cap'), AppSetting::get('order_encode_import_skip_pages')]);

        $save($ceo, ['switch' => 'maybe', 'cap' => '-4', 'skip_pages' => 'x'])->assertSessionHasErrors(['switch', 'cap']);
        $this->assertSame('on', AppSetting::get('order_encode_import_shadow'));
        $this->assertSame('300', AppSetting::get('order_encode_import_daily_cap'));

        foreach (['Marketing - OIC', 'Marketing', 'Encoder'] as $i => $role) {
            $save($this->user($role, "r{$i}@example.test"), ['switch' => 'off', 'cap' => '1', 'skip_pages' => ''])->assertNotFound();
        }
        $this->assertSame('on', AppSetting::get('order_encode_import_shadow'));
    }

    public function test_S_133_25_the_marketing_roles_read_the_tab_but_cannot_edit_and_others_get_404(): void
    {
        $this->open('', 'Marketing')->assertOk()->assertDontSee('name="cap"', false);
        $this->open('', 'CEO')->assertOk()->assertSee('name="cap"', false);
        $this->open('', 'Encoder')->assertNotFound();
        auth()->logout();
        $this->get('/order-encode/final')->assertRedirect();
    }
}
