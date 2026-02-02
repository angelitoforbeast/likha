<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Important: drop children first, then parent
        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('jnt_checker_run_extras');
        Schema::dropIfExists('jnt_checker_run_items');
        Schema::dropIfExists('jnt_checker_run_files');
        Schema::dropIfExists('jnt_checker_runs');

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        // We don't recreate on down here.
        // The "create" migration will handle rebuilding.
    }
};
