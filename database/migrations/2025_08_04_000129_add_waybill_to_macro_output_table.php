<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::table('macro_output', function (Blueprint $table) {
        $table->string('waybill', 100)->nullable()->after('COD')->index();
    });
}

public function down(): void
{
    Schema::table('macro_output', function (Blueprint $table) {
        $table->dropIndex(['waybill']);
        $table->dropColumn('waybill');
    });
}

};
