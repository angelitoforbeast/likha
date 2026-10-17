<?php

namespace Tests\Feature\OwnerPrivate;

use App\Http\Controllers\OwnerColumnSettingsController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdDeliveryColumnsTest extends OwnerPrivateTestCase
{
    private const COLS = ['ad_delivered', 'ad_in_transit', 'ad_for_return', 'ad_returned', 'ad_rts_pct'];

    private function save(array $config): void
    {
        DB::table('app_settings')->updateOrInsert(['key' => 'campaigns_cols'], ['value' => json_encode($config), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, array{0: array}> naka-save na config na hindi pa alam ang ad_rdt */
    public static function savedBeforeTheCombinedCell(): array
    {
        return [
            // bago pa ang 043: wala ang anim sa order
            'before 043'         => [['order' => ['on', 'name', 'spend', 'cpp'], 'hidden' => ['cpp'],
                                      'visible_by_role' => ['Marketing' => ['name', 'spend'], 'Marketing - OIC' => ['name']]]],
            // pagkatapos ng 043, bago ang combined cell: nasa order at visible ang lima
            'after 043, before'  => [['order' => array_merge(['on', 'name', 'spend', 'cpp'], self::COLS), 'hidden' => ['cpp'],
                                      'visible_by_role' => ['Marketing' => array_merge(['name', 'spend'], self::COLS), 'Marketing - OIC' => array_merge(['name'], self::COLS)]]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('savedBeforeTheCombinedCell')]
    public function test_S_043_16_the_combined_cell_replaces_the_five_columns_by_default_for_every_role(array $saved): void
    {
        $ctl = new OwnerColumnSettingsController();
        $this->save($saved);

        foreach (['CEO', 'Marketing', 'Marketing - OIC'] as $role) {
            $cfg = $ctl->loadConfig('campaigns', $role);
            $this->assertNotContains('ad_rdt', $cfg['hidden'], $role);
            $this->assertSame(self::COLS, array_values(array_intersect(self::COLS, $cfg['hidden'])), $role);
            // ang combined cell ay nasa puwesto ng lima (kasunod agad nito ang ad_delivered)
            $this->assertSame(array_search('ad_rdt', $cfg['order'], true) + 1, array_search('ad_delivered', $cfg['order'], true), $role);
        }
        $this->assertContains('cpp', $ctl->loadConfig('campaigns', 'Marketing')['hidden']);   // lumang column: gaya pa rin ng dati
        $matrix = $ctl->loadConfigMatrix('campaigns');
        $this->assertContains('ad_rdt', $matrix['visible_by_role']['Marketing']);
        $this->assertSame([], array_values(array_intersect(self::COLS, $matrix['visible_by_role']['Marketing'])));
        $this->assertSame(self::COLS, array_values(array_intersect(self::COLS, $matrix['hidden'])));
    }

    public function test_S_043_16_once_saved_with_the_combined_cell_the_saved_lists_decide(): void
    {
        $ctl = new OwnerColumnSettingsController();
        $this->save([
            'order'           => array_merge(['on', 'name', 'ad_rdt'], self::COLS),
            'hidden'          => ['ad_rdt'],
            'visible_by_role' => ['Marketing' => ['name', 'ad_delivered'], 'Marketing - OIC' => ['name', 'ad_rdt']],
        ]);
        $ceo = $ctl->loadConfig('campaigns', 'CEO')['hidden'];
        $this->assertContains('ad_rdt', $ceo);
        $this->assertSame([], array_values(array_intersect(self::COLS, $ceo)));
        $mkt = $ctl->loadConfig('campaigns', 'Marketing')['hidden'];
        $this->assertContains('ad_rdt', $mkt);
        $this->assertNotContains('ad_delivered', $mkt);
        $this->assertNotContains('ad_rdt', $ctl->loadConfig('campaigns', 'Marketing - OIC')['hidden']);
    }

    public function test_S_043_9_owner_private_lists_the_columns_and_loads_the_delivery_map_on_expand(): void
    {
        foreach (['tasks' => ['user_id', 'status'], 'ads_manager_reports' => ['page_name'], 'fee_settings' => ['setting_key', 'setting_value', 'effective_date', 'description', 'host_scope']] as $table => $columns) {
            if (Schema::hasTable($table)) continue;
            Schema::create($table, function (Blueprint $t) use ($columns) {
                $t->id();
                foreach ($columns as $column) $t->string($column)->nullable();
                $t->timestamps();
            });
        }
        $html = $this->actingAs($this->user('Marketing'))->get('/owner/private')->assertOk()->getContent();

        foreach (self::COLS as $id) {
            $this->assertMatchesRegularExpression("/\\{ id:'$id',/", $html, $id);
        }
        $this->assertStringContainsString(route('owner.private.ad-delivery'), $html);
        // S-043.13: POST na may CSRF (mahabang listahan ng page); S-043.12: ang dahilan ng 422 ay ipinapakita
        $this->assertMatchesRegularExpression("~fetch\('" . preg_quote(route('owner.private.ad-delivery.post'), '~') . "', \{\s*method: 'POST'~", $html);
        $this->assertStringContainsString('adDeliveryErrors', $html);
        // S-043.14: ang delivery-only na campaign row ay may tanda
        $this->assertStringContainsString('walang spend sa range', $html);
        $this->assertStringContainsString('Walang ad id', $html);
        // ang ibang screen ng campaigns table ay walang bagong column
        $this->assertStringNotContainsString('ad_delivered', file_get_contents(resource_path('views/ads_manager/campaigns.blade.php')));
        $this->assertStringNotContainsString('ad_delivered', file_get_contents(resource_path('views/item/index.blade.php')));
        // kasama ang expand partial sa /item, na walang adDelivery* sa component: bawat pasukan ay sa $data.<name> dumadaan
        $partial = file_get_contents(resource_path('views/owner/_private_expand_inline.blade.php'));
        $this->assertSame(0, preg_match('/(?<!\$data\.)adDelivery(Label|NoAdText)\(/', $partial));
        $this->assertStringContainsString('<template x-if="$data.adDeliveryNoAd && $data.adDeliveryNoAd(row.page_name)">', $partial);
        $this->assertStringContainsString('x-show="$data.adDeliveryNoAd && $data.adDeliveryNoAd(row.page_name)"', $partial);
        // S-043.10: ang "Total ng page (ayon sa order)" na linya, pagkatapos ng "Walang ad id", sa table at sa page na walang campaign row
        $this->assertStringContainsString('Total ng page (ayon sa order)', $html);
        $this->assertStringContainsString('<template x-if="$data.adDeliveryTotal && $data.adDeliveryTotal(row.page_name)">', $partial);
        $this->assertStringContainsString('x-show="$data.adDeliveryTotal && $data.adDeliveryTotal(row.page_name)"', $partial);
        $this->assertGreaterThan(strpos($partial, '<template x-if="$data.adDeliveryNoAd'), strpos($partial, '<template x-if="$data.adDeliveryTotal'));
        $this->assertSame(0, preg_match('/(?<!\$data\.)adDeliveryTotalText\(/', $partial));
        // S-043.15: ang combined cell — sa campaign, ad set, ad, "Walang ad id" at Total, iisang partial (x-text lang, walang x-html)
        $this->assertMatchesRegularExpression("/\\{ id:'ad_rdt', +label:'RTS \\/ DEL \\/ INT \\(orders\\)', .*type:'ad_rdt'/", $html);
        $this->assertSame(5, substr_count($partial, "<template x-if=\"col.type==='ad_rdt'\">"));
        foreach (['c.ad_rdt', 'aset.ad_rdt', 'ad.ad_rdt', 'adDeliveryNoAd(row.page_name).ad_rdt', 'adDeliveryTotal(row.page_name).ad_rdt'] as $expr) {
            $this->assertStringContainsString("@include('owner._ad_rdt_cell', ['rdt' => '$expr'])", $partial, $expr);
        }
        $cell = file_get_contents(resource_path('views/owner/_ad_rdt_cell.blade.php'));
        $this->assertStringNotContainsString('x-html', $cell);
        $this->assertSame(['RTS', 'DEL', 'INT'], array_values(array_filter(['RTS', 'DEL', 'INT'], fn ($l) => str_contains($cell, ">$l</td>"))));
        $this->assertStringContainsString('<template x-if="!(c.ad_rdt)"><span style="color:#cbd5e1;font-size:11px;">—</span></template>', $html);   // walang shipped = "—"
        // parehong style ng RTS / DEL / INT na cell ng page row
        $this->assertStringContainsString('padding:1px 6px;text-align:left;color:#94a3b8;font-size:9px;font-weight:700;letter-spacing:0.04em;', $html);
        // /item: walang combined cell sa sariling listahan ng column nito
        $this->assertStringNotContainsString('ad_rdt', file_get_contents(resource_path('views/item/index.blade.php')));
    }

    public function test_S_043_16_nothing_saved_shows_the_combined_cell_to_the_ceo(): void
    {
        $cfg = (new OwnerColumnSettingsController())->loadConfig('campaigns', 'CEO');
        $this->assertSame(array_merge(['ad_rdt'], self::COLS), array_values(array_intersect($cfg['order'], array_merge(['ad_rdt'], self::COLS))));
        $this->assertNotContains('ad_rdt', $cfg['hidden']);
        $this->assertSame(self::COLS, array_values(array_intersect(self::COLS, $cfg['hidden'])));
    }
}
