<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('macro_gsheet_settings', function (Blueprint $t) {
            if (!Schema::hasColumn('macro_gsheet_settings', 'is_archived')) {
                // Mirrors the same archive workflow we added to
                // likha_order_settings — archived sheets stay visible sa UI
                // but are skipped sa import job (managed at /macro/gsheet/settings).
                $t->boolean('is_archived')->default(false)->after('sheet_range')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('macro_gsheet_settings', function (Blueprint $t) {
            if (Schema::hasColumn('macro_gsheet_settings', 'is_archived')) {
                $t->dropColumn('is_archived');
            }
        });
    }
};
