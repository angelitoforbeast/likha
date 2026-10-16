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

    public function test_S_043_8_new_columns_start_visible_for_every_role_of_owner_private(): void
    {
        $ctl = new OwnerColumnSettingsController();
        // naka-save bago pa ang mga bagong column: wala sila sa order
        $this->save([
            'order'           => ['on', 'name', 'spend', 'cpp'],
            'hidden'          => ['cpp'],
            'visible_by_role' => ['Marketing' => ['name', 'spend'], 'Marketing - OIC' => ['name']],
        ]);

        foreach (['CEO', 'Marketing', 'Marketing - OIC'] as $role) {
            $cfg = $ctl->loadConfig('campaigns', $role);
            $this->assertSame([], array_values(array_intersect(self::COLS, $cfg['hidden'])), $role);
            $this->assertSame(self::COLS, array_values(array_intersect($cfg['order'], self::COLS)), $role);
        }
        $this->assertContains('cpp', $ctl->loadConfig('campaigns', 'CEO')['hidden']);
        $this->assertContains('cpp', $ctl->loadConfig('campaigns', 'Marketing')['hidden']);   // lumang column: gaya pa rin ng dati
        $matrix = $ctl->loadConfigMatrix('campaigns');
        $this->assertSame(self::COLS, array_values(array_intersect($matrix['visible_by_role']['Marketing'], self::COLS)));

        // kapag na-save na kasama ang mga bagong column, ang saved list ng role ang masusunod
        $this->save([
            'order'           => array_merge(['on', 'name', 'spend', 'cpp'], self::COLS),
            'hidden'          => ['ad_rts_pct'],
            'visible_by_role' => ['Marketing' => ['name', 'ad_delivered'], 'Marketing - OIC' => self::COLS],
        ]);
        $this->assertSame(['ad_rts_pct'], array_values(array_intersect(self::COLS, $ctl->loadConfig('campaigns', 'CEO')['hidden'])));
        $this->assertSame(['ad_in_transit', 'ad_for_return', 'ad_returned', 'ad_rts_pct'], array_values(array_intersect(self::COLS, $ctl->loadConfig('campaigns', 'Marketing')['hidden'])));
        $this->assertSame([], array_values(array_intersect(self::COLS, $ctl->loadConfig('campaigns', 'Marketing - OIC')['hidden'])));
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
    }

    public function test_S_043_8_nothing_saved_shows_them_to_the_ceo(): void
    {
        $cfg = (new OwnerColumnSettingsController())->loadConfig('campaigns', 'CEO');
        $this->assertSame(self::COLS, array_values(array_intersect($cfg['order'], self::COLS)));
        $this->assertSame([], array_values(array_intersect(self::COLS, $cfg['hidden'])));
    }
}
