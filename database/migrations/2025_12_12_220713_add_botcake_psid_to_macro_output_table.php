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
            // PSID from Botcake (stored as string, usually long ID)
            $table->string('botcake_psid', 64)
                  ->nullable()
                  ->after('fb_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('macro_output', function (Blueprint $table) {
            $table->dropColumn('botcake_psid');
        });
    }
};
