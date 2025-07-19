<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('checker_2_settings', function (Blueprint $table) {
            $table->renameColumn('sheet_name', 'gsheet_name');
        });
    }

    public function down(): void
    {
        Schema::table('checker_2_settings', function (Blueprint $table) {
            $table->renameColumn('gsheet_name', 'sheet_name');
        });
    }
};
