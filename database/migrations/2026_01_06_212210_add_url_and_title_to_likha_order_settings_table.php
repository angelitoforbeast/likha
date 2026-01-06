<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('likha_order_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('likha_order_settings', 'sheet_url')) {
                $table->text('sheet_url')->nullable()->after('id');
            }
            if (!Schema::hasColumn('likha_order_settings', 'spreadsheet_title')) {
                $table->string('spreadsheet_title')->nullable()->after('sheet_url');
            }
            // keep sheet_id + range existing
        });
    }

    public function down(): void
    {
        Schema::table('likha_order_settings', function (Blueprint $table) {
            if (Schema::hasColumn('likha_order_settings', 'sheet_url')) {
                $table->dropColumn('sheet_url');
            }
            if (Schema::hasColumn('likha_order_settings', 'spreadsheet_title')) {
                $table->dropColumn('spreadsheet_title');
            }
        });
    }
};
