<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('staff_events') || Schema::hasColumn('staff_events', 'source')) {
            return;
        }

        // Saang page galing ang checker.* event (checker_1, jnt); NULL sa route at login events.
        Schema::table('staff_events', function (Blueprint $table) {
            $table->string('source', 32)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('staff_events', 'source')) {
            Schema::table('staff_events', function (Blueprint $table) {
                $table->dropColumn('source');
            });
        }
    }
};
