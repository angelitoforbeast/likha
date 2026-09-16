<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rider_name / rider_phone sa from_jnts — galing sa J&T TRACKQUERY "On Delivery"
 * scan (sprinter). Nullable — wala pa kapag hindi pa na-out-for-delivery.
 * Isinusulat ng /jnt/track-sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('from_jnts', function (Blueprint $table) {
            if (!Schema::hasColumn('from_jnts', 'rider_name')) {
                $table->string('rider_name')->nullable()->after('rts_reason');
            }
            if (!Schema::hasColumn('from_jnts', 'rider_phone')) {
                $table->string('rider_phone', 30)->nullable()->after('rider_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('from_jnts', function (Blueprint $table) {
            foreach (['rider_name', 'rider_phone'] as $c) {
                if (Schema::hasColumn('from_jnts', $c)) $table->dropColumn($c);
            }
        });
    }
};
