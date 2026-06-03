<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `pages` (total PDF pages generated) sa jnt_waybill_print_runs para
 * makompute ang pages-per-minute metric sa /jnt/waybills/files. started_at /
 * finished_at ay existing na — duration galing doon.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('jnt_waybill_print_runs')
            && !Schema::hasColumn('jnt_waybill_print_runs', 'pages')) {
            Schema::table('jnt_waybill_print_runs', function (Blueprint $table) {
                $table->unsignedInteger('pages')->default(0)->after('fail_count');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('jnt_waybill_print_runs')
            && Schema::hasColumn('jnt_waybill_print_runs', 'pages')) {
            Schema::table('jnt_waybill_print_runs', function (Blueprint $table) {
                $table->dropColumn('pages');
            });
        }
    }
};
