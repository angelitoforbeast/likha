<?php

namespace Tests\Feature\OwnerPrivate;

use App\Services\ItemAliasResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * /owner/private/item-summary sa sqlite: ang PROF(1D)-M (`projected_profit_last_day_m`) ng mga halimbawa
 * ng brief (end date 2026-10-09, scope likha), at ang Prof.Profit(1D) na hindi nagbabago.
 * Mga value: kinuwenta sa kamay mula sa brief, hindi gaya ng code.
 */
class ItemSummaryProfitMTest extends OwnerPrivateTestCase
{
    private const END = '2026-10-09';

    protected function setUp(): void
    {
        parent::setUp();

        // Mga table na gawa sa kamay (ang totoong migrations ng mga ito ay may MySQL-only na bahagi):
        // ang mga column lang na binabasa ng itemSummary.
        Schema::create('macro_output', function (Blueprint $t) {
            $t->id();
            $t->string('PAGE')->nullable();
            $t->string('ITEM_NAME')->nullable();
            $t->string('STATUS')->nullable();
            $t->string('COD')->nullable();
            $t->date('ts_date')->nullable();
            $t->timestamps();
        });
        Schema::create('ads_manager_reports', function (Blueprint $t) {
            $t->id();
            $t->date('day')->nullable();
            $t->string('page_name')->nullable();
            $t->string('amount_spent_php')->nullable();
        });
        Schema::create('page_item_settings', function (Blueprint $t) {
            $t->id();
            $t->string('page_name');
            $t->string('item_name');
            $t->decimal('rts_pct', 6, 2)->nullable();
            $t->date('effective_date');
            $t->string('comment')->nullable();
            $t->string('item_value_comment')->nullable();
            $t->string('promo')->nullable();
            $t->integer('mode_cod_int')->nullable();
            $t->timestamps();
        });
        Schema::create('cogs_ceo', function (Blueprint $t) {
            $t->id();
            $t->date('date');
            $t->string('item_name');
            $t->decimal('unit_cost', 12, 2)->nullable();
            $t->timestamps();
        });
        Schema::create('supply_excluded_pages', function (Blueprint $t) {
            $t->id();
            $t->string('page_name');
            $t->timestamps();
        });

        DB::table('fee_settings')->insert([
            'setting_key' => 'shipping_fee_per_order', 'setting_value' => 33, 'effective_date' => '2025-01-01',
            'host_scope' => 'likha', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function migrationPaths(): array
    {
        return array_merge(parent::migrationPaths(), [
            'database/migrations/2025_09_08_134839_create_cogs_table.php',
            'database/migrations/2026_04_24_000009_create_daily_page_primary_item_table.php',
            'database/migrations/2026_03_05_000001_create_fee_settings_table.php',
            'database/migrations/2026_10_14_100000_seed_shipping_fee_per_order_m.php',
        ]);
    }

    /**
     * Isang page na may iisang item. $days: date => [orders, proceed, price, ads].
     * RTS% at cogs ay effective mula 2026-10-01.
     */
    private function page(string $label, string $item, array $days, ?float $rts, float $cogsCeo, float $cogs): void
    {
        foreach ($days as $d => [$orders, $proceed, $price, $ads]) {
            DB::table('daily_page_primary_item')->insert([
                'ts_date' => $d, 'page_label' => $label, 'page_key' => strtolower($label),
                'primary_item' => $item, 'primary_item_key' => ItemAliasResolver::normalize($item),
                'primary_orders' => $orders, 'total_orders_all' => $orders, 'primary_mode_cod' => $price,
            ]);
            DB::table('macro_output')->insert(array_map(fn ($i) => [
                'PAGE' => $label, 'ITEM_NAME' => $item, 'STATUS' => $i < $proceed ? 'Proceed' : 'Pending',
                'COD' => (string) $price, 'ts_date' => $d,
            ], range(0, $orders - 1)));
            DB::table('ads_manager_reports')->insert(['day' => $d, 'page_name' => $label, 'amount_spent_php' => (string) $ads]);
        }
        if ($rts !== null) {
            DB::table('page_item_settings')->insert([
                'page_name' => $label, 'item_name' => $item, 'rts_pct' => $rts, 'effective_date' => '2026-10-01',
                'comment' => '', 'item_value_comment' => '', 'promo' => '', 'mode_cod_int' => (int) round(reset($days)[2]),
            ]);
        }
        DB::table('cogs_ceo')->insert(['item_name' => $item, 'date' => '2026-10-01', 'unit_cost' => $cogsCeo]);
        DB::table('cogs')->insert(['item_name' => $item, 'date' => '2026-10-01', 'unit_cost' => $cogs]);
    }

    /** Page A ng brief: 50 proceed × ₱599, RTS 30%, cogs_ceo 180 / cogs 170, ads 4,000. */
    private function pageA(string $date = self::END): void
    {
        $this->page('Pahina Alpha', '1 x Zorblat Lamp', [$date => [50, 50, 599, 4000]], 30, 180, 170);
    }

    /** Page C ng brief: 20 proceed × ₱799, RTS 0%, cogs_ceo 250 / cogs 240, ads 1,500. */
    private function pageC(): void
    {
        $this->page('Pahina Gamma', '1 x Quillex Fan', [self::END => [20, 20, 799, 1500]], 0, 250, 240);
    }

    /** Ang mga row ng payload, naka-key sa page_key. */
    private function rows(string $role = 'CEO', array $query = []): array
    {
        $this->actingAs($this->user($role, strtolower(str_replace([' ', '-'], '', $role)) . '@example.test'));
        $res = $this->getJson('/owner/private/item-summary?' . http_build_query($query + ['start_date' => self::END, 'end_date' => self::END]))->assertOk();

        return array_column($res->json('rows'), null, 'page_key');
    }

    public function test_pages_a_and_c_get_the_brief_values_and_prof_1d_stays_as_it_was(): void
    {
        $this->pageA();
        $this->pageC();

        $rows = $this->rows();

        $this->assertEqualsWithDelta(8662.788, $rows['pahina alpha']['projected_profit_last_day'], 0.001);
        $this->assertEqualsWithDelta(5132.788, $rows['pahina alpha']['projected_profit_last_day_m'], 0.001);
        $this->assertEqualsWithDelta(8551.536, $rows['pahina gamma']['projected_profit_last_day'], 0.001);
        $this->assertEqualsWithDelta(8231.536, $rows['pahina gamma']['projected_profit_last_day_m'], 0.001);
    }

    public function test_page_b_with_its_own_cod_fee_vat_and_shipping(): void
    {
        DB::table('fee_settings')->insert(array_map(fn ($kv) => [
            'setting_key' => $kv[0], 'setting_value' => $kv[1], 'effective_date' => '2026-10-01',
            'host_scope' => 'likha', 'created_at' => now(), 'updated_at' => now(),
        ], [['cod_fee_rate', 0.02], ['cod_fee_vat_rate', 0.10], ['shipping_fee_per_order', 35]]));
        $this->page('Pahina Beta', '1 x Quillex Fan', [self::END => [20, 20, 799, 1500]], 0, 250, 240);

        $ceo = $this->rows()['pahina beta'];
        $this->assertEqualsWithDelta(8428.44, $ceo['projected_profit_last_day'], 0.001);
        $this->assertEqualsWithDelta(8148.44, $ceo['projected_profit_last_day_m'], 0.001);

        // Marketing viewer: cogs (240), gaya ng Prof.Profit(1D).
        $this->assertEqualsWithDelta(8348.44, $this->rows('Marketing')['pahina beta']['projected_profit_last_day_m'], 0.001);
    }

    public function test_a_marketing_viewer_gets_the_value_from_cogs(): void
    {
        $this->pageA();

        $this->assertEqualsWithDelta(5632.788, $this->rows('Marketing')['pahina alpha']['projected_profit_last_day_m'], 0.001);
    }

    public function test_page_d_on_a_partial_date_projects_48_proceed(): void
    {
        // 2026-10-09: 50 orders / 40 proceed; 2026-10-10: 60 / 48 → historical proceed rate 88/110 = 0.8.
        $this->page('Pahina Delta', '1 x Zorblat Lamp', [
            self::END    => [50, 40, 599, 2000],
            '2026-10-10' => [60, 48, 599, 3000],
        ], 30, 180, 170);

        $d = $this->rows('CEO', ['partial_date' => '2026-10-10'])['pahina delta'];

        $this->assertEqualsWithDelta(9156.27648, $d['projected_profit_last_day'], 0.001);
        $this->assertEqualsWithDelta(5868.27648, $d['projected_profit_last_day_m'], 0.001);
    }

    public function test_sf_m_follows_its_effective_date(): void
    {
        DB::table('fee_settings')->insert([
            'setting_key' => 'shipping_fee_per_order_m', 'setting_value' => 45, 'effective_date' => '2026-10-10',
            'host_scope' => 'likha', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->page('Pahina Alpha', '1 x Zorblat Lamp', [
            self::END    => [50, 50, 599, 4000],
            '2026-10-10' => [50, 50, 599, 4000],
        ], 30, 180, 170);

        $on9 = $this->rows()['pahina alpha'];
        $this->assertEqualsWithDelta(5132.788, $on9['projected_profit_last_day_m'], 0.001);
        $this->assertEqualsWithDelta(8662.788, $on9['projected_profit_last_day'], 0.001);

        $res = $this->getJson('/owner/private/item-summary?start_date=2026-10-10&end_date=2026-10-10')->assertOk();
        $on10 = array_column($res->json('rows'), null, 'page_key')['pahina alpha'];
        $this->assertEqualsWithDelta(4882.788, $on10['projected_profit_last_day_m'], 0.001);
        $this->assertEqualsWithDelta(8662.788, $on10['projected_profit_last_day'], 0.001);
    }

    public function test_without_sf_m_rows_the_page_loads_and_only_m_is_null(): void
    {
        DB::table('fee_settings')->where('setting_key', 'shipping_fee_per_order_m')->delete();
        $this->pageA();

        $a = $this->rows()['pahina alpha'];
        $this->assertNull($a['projected_profit_last_day_m']);
        $this->assertEqualsWithDelta(8662.788, $a['projected_profit_last_day'], 0.001);
    }

    /** @return array<string, array{0: ?float, 1: int}> RTS%, price */
    public static function notComputable(): array
    {
        return [
            'no RTS history'     => [null, 599],
            'no end-day price'   => [30, 0],
        ];
    }

    #[DataProvider('notComputable')]
    public function test_m_is_null_where_prof_1d_is_null(?float $rts, int $price): void
    {
        $this->page('Pahina Alpha', '1 x Zorblat Lamp', [self::END => [50, 50, $price, 4000]], $rts, 180, 170);

        $a = $this->rows()['pahina alpha'];
        $this->assertNull($a['projected_profit_last_day']);
        $this->assertNull($a['projected_profit_last_day_m']);
    }
}
