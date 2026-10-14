<?php

use App\Http\Controllers\OwnerPrivateController;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SF_M (`shipping_fee_per_order_m`) = ₱40 para sa PROF(1D)-M ng /owner/private, sa dalawang scope.
 * Effective 2000-01-01 para may value ang bawat page date (walang back-fill warning).
 * Idempotent: kapag nandiyan na ang row (key + scope + petsa), walang idadagdag.
 * Bumps ang cache version: ang mga payload na na-cache bago nito ay walang -M field.
 */
return new class extends Migration
{
    private const KEY  = 'shipping_fee_per_order_m';
    private const DATE = '2000-01-01';

    public function up(): void
    {
        if (!Schema::hasTable('fee_settings')) return;

        foreach (['likha', 'incepxion'] as $scope) {
            $exists = DB::table('fee_settings')
                ->where('setting_key', self::KEY)
                ->where('host_scope', $scope)
                ->where('effective_date', self::DATE)
                ->exists();
            if ($exists) continue;

            DB::table('fee_settings')->insert([
                'setting_key'    => self::KEY,
                'setting_value'  => 40,
                'effective_date' => self::DATE,
                'description'    => 'Shipping Fee per Order (M) para sa PROF(1D)-M',
                'host_scope'     => $scope,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        OwnerPrivateController::bumpCacheVersion();
    }

    /** Ang seed rows lang (2000-01-01) ang inaalis; ang mga idinagdag sa Fee Settings ay naiiwan. */
    public function down(): void
    {
        if (!Schema::hasTable('fee_settings')) return;

        DB::table('fee_settings')
            ->where('setting_key', self::KEY)
            ->where('effective_date', self::DATE)
            ->delete();

        OwnerPrivateController::bumpCacheVersion();
    }
};
