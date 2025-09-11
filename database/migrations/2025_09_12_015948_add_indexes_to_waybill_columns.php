<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('from_jnts', function (Blueprint $table) {
            $table->index('waybill_number', 'from_jnts_waybill_number_index');
        });
    }

    public function down(): void
    {
        Schema::table('from_jnts', function (Blueprint $table) {
            $table->dropIndex('from_jnts_waybill_number_index');
        });
    }
};
