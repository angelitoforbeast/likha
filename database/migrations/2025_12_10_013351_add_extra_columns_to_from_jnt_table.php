<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('from_jnts', function (Blueprint $table) {
            $table->string('province')->nullable()->after('remarks');
            $table->string('city')->nullable()->after('province');
            $table->string('barangay')->nullable()->after('city');

            $table->decimal('total_shipping_cost', 10, 2)->nullable()->after('barangay');
            $table->string('rts_reason')->nullable()->after('total_shipping_cost');
        });
    }

    public function down(): void
    {
        Schema::table('from_jnts', function (Blueprint $table) {
            $table->dropColumn([
                'province',
                'city',
                'barangay',
                'total_shipping_cost',
                'rts_reason',
            ]);
        });
    }
};
