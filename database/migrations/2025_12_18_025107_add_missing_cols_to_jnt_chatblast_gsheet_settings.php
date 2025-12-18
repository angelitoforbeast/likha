<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('jnt_chatblast_gsheet_settings', function (Blueprint $table) {
            // add only if missing (safe)
            if (!Schema::hasColumn('jnt_chatblast_gsheet_settings', 'gsheet_name')) {
                $table->string('gsheet_name')->nullable()->after('id');
            }

            if (!Schema::hasColumn('jnt_chatblast_gsheet_settings', 'sheet_url')) {
                $table->text('sheet_url')->nullable()->after('gsheet_name');
            }

            if (!Schema::hasColumn('jnt_chatblast_gsheet_settings', 'sheet_range')) {
                $table->string('sheet_range')->nullable()->after('sheet_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('jnt_chatblast_gsheet_settings', function (Blueprint $table) {
            if (Schema::hasColumn('jnt_chatblast_gsheet_settings', 'sheet_range')) {
                $table->dropColumn('sheet_range');
            }
            if (Schema::hasColumn('jnt_chatblast_gsheet_settings', 'sheet_url')) {
                $table->dropColumn('sheet_url');
            }
            if (Schema::hasColumn('jnt_chatblast_gsheet_settings', 'gsheet_name')) {
                $table->dropColumn('gsheet_name');
            }
        });
    }
};
