<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('from_jnts', function (Blueprint $table) {
            // longText para kasya kahit mahaba na history / logs
            $table->longText('status_logs')
                  ->nullable()
                  ->after('signingtime'); // ilalagay AFTER signingtime
        });
    }

    public function down(): void
    {
        Schema::table('from_jnts', function (Blueprint $table) {
            $table->dropColumn('status_logs');
        });
    }
};
