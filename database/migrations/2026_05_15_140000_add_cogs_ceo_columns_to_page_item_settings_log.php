<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_item_settings_log', function (Blueprint $t) {
            if (!Schema::hasColumn('page_item_settings_log', 'old_item_value_ceo')) {
                $t->decimal('old_item_value_ceo', 18, 2)->nullable()->after('new_item_value');
            }
            if (!Schema::hasColumn('page_item_settings_log', 'new_item_value_ceo')) {
                $t->decimal('new_item_value_ceo', 18, 2)->nullable()->after('old_item_value_ceo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('page_item_settings_log', function (Blueprint $t) {
            if (Schema::hasColumn('page_item_settings_log', 'new_item_value_ceo')) {
                $t->dropColumn('new_item_value_ceo');
            }
            if (Schema::hasColumn('page_item_settings_log', 'old_item_value_ceo')) {
                $t->dropColumn('old_item_value_ceo');
            }
        });
    }
};
