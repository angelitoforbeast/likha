<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('botcake_psid_import_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('running'); // running|done|failed
            $table->string('cutoff_datetime')->nullable();

            // high-level progress (for UI)
            $table->unsignedBigInteger('current_setting_id')->nullable();
            $table->string('current_gsheet_name')->nullable();
            $table->string('current_sheet_name')->nullable();

            $table->unsignedInteger('k1_value')->nullable();
            $table->unsignedInteger('seed_row')->nullable();
            $table->unsignedInteger('selected_start_row')->nullable();

            $table->unsignedInteger('batch_no')->default(0);
            $table->unsignedInteger('batch_start_row')->nullable();
            $table->unsignedInteger('batch_end_row')->nullable();
            $table->unsignedInteger('next_scan_from')->nullable();

            $table->unsignedInteger('total_imported')->default(0);
            $table->unsignedInteger('total_not_existing')->default(0);
            $table->unsignedInteger('total_skipped')->default(0); // skipped due to date/invalid/etc.

            $table->text('last_message')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('botcake_psid_import_runs');
    }
};
