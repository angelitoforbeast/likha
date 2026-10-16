<?php

namespace Tests\Feature\OwnerPrivate;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Base ng mga test na nagbabasa ng macro_output → daily_page_primary_item → /owner/private.
 * Mga table na gawa sa kamay kung MySQL-only ang totoong migration (mga column na ginagamit lang).
 */
abstract class MacroOutputTestCase extends OwnerPrivateTestCase
{
    protected const DAY = '2026-10-09';

    protected function setUp(): void
    {
        parent::setUp();

        // Mga table na gawa sa kamay (MySQL-only ang totoong migrations): mga column na binabasa lang.
        Schema::create('macro_output', function (Blueprint $t) {
            $t->id();
            $t->string('PAGE')->nullable();
            $t->string('ITEM_NAME')->nullable();
            $t->string('STATUS')->nullable();
            $t->string('COD')->nullable();
            $t->string('waybill')->nullable();
            $t->string('TIMESTAMP')->nullable();
            $t->string('fb_name')->nullable();
            $t->string('FULL NAME')->nullable();
            $t->string('PHONE NUMBER')->nullable();
            $t->text('all_user_input')->nullable();
            $t->text('shop_details')->nullable();
            $t->text('extracted_details')->nullable();
            $t->string('ADDRESS')->nullable();
            $t->text('status_logs')->nullable();
            $t->text('HISTORICAL LOGS')->nullable();
            $t->boolean('validate_1')->default(false);
            $t->boolean('validate_2')->default(false);
            $t->boolean('item_checker')->default(false);
            $t->boolean('edited_item_name')->default(false);
            $t->boolean('edited_cod')->default(false);
            $t->boolean('edited_address')->default(false);
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
            'database/migrations/2026_04_23_000002_create_supply_item_settings_table.php',
            'database/migrations/2026_10_16_000001_add_is_tie_to_daily_page_primary_item.php',
            'database/migrations/2026_04_13_000001_create_item_type_mappings_table.php',
        ]);
    }
}
