<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::table('macro_gsheet_settings', function (Blueprint $table) {
        $table->string('gsheet_name')->nullable();
    });
}


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('macro_gsheet_settings', function (Blueprint $table) {
            //
        });
    }
};
