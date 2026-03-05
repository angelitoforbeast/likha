<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key', 100);       // e.g. cod_fee_rate, cod_fee_vat_rate, shipping_fee_per_item
            $table->decimal('setting_value', 18, 6);   // the rate or amount
            $table->date('effective_date');             // when this rate takes effect
            $table->string('description', 255)->nullable();
            $table->string('host_scope', 100)->default('likha'); // likha, incepxion, or * for all
            $table->timestamps();

            $table->index(['setting_key', 'host_scope', 'effective_date'], 'fee_settings_lookup_idx');
        });

        // Seed default values based on current hardcoded rates
        DB::table('fee_settings')->insert([
            [
                'setting_key'    => 'cod_fee_rate',
                'setting_value'  => 0.015000,
                'effective_date' => '2025-01-01',
                'description'    => 'COD Fee Rate (1.5%)',
                'host_scope'     => 'likha',
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
            [
                'setting_key'    => 'cod_fee_vat_rate',
                'setting_value'  => 0.120000,
                'effective_date' => '2025-01-01',
                'description'    => 'COD Fee VAT Rate (12% of COD Fee)',
                'host_scope'     => 'likha',
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
            [
                'setting_key'    => 'cod_fee_rate',
                'setting_value'  => 0.010000,
                'effective_date' => '2025-01-01',
                'description'    => 'COD Fee Rate (1%)',
                'host_scope'     => 'incepxion',
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
            [
                'setting_key'    => 'cod_fee_vat_rate',
                'setting_value'  => 0.120000,
                'effective_date' => '2025-01-01',
                'description'    => 'COD Fee VAT Rate (12% of COD Fee)',
                'host_scope'     => 'incepxion',
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_settings');
    }
};
