<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('macro_output', function (Blueprint $table) {
            // ✅ Add as NULLABLE (existing rows will be NULL, not 0)
            if (!Schema::hasColumn('macro_output', 'validate_1')) {
                $table->boolean('validate_1')->nullable();
            }
            if (!Schema::hasColumn('macro_output', 'validate_2')) {
                $table->boolean('validate_2')->nullable();
            }
            if (!Schema::hasColumn('macro_output', 'item_checker')) {
                $table->boolean('item_checker')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('macro_output', function (Blueprint $table) {
            if (Schema::hasColumn('macro_output', 'item_checker')) {
                $table->dropColumn('item_checker');
            }
            if (Schema::hasColumn('macro_output', 'validate_2')) {
                $table->dropColumn('validate_2');
            }
            if (Schema::hasColumn('macro_output', 'validate_1')) {
                $table->dropColumn('validate_1');
            }
        });
    }
};
