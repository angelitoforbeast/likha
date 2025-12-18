<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('jnt_chatblast_gsheet_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('jnt_chatblast_gsheet_settings', 'gsheet_name')) {
                $table->string('gsheet_name')->nullable()->after('id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('jnt_chatblast_gsheet_settings', function (Blueprint $table) {
            if (Schema::hasColumn('jnt_chatblast_gsheet_settings', 'gsheet_name')) {
                $table->dropColumn('gsheet_name');
            }
        });
    }
};
