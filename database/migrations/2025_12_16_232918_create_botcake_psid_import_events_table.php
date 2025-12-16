<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('botcake_psid_import_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('botcake_psid_import_runs')->cascadeOnDelete();

            $table->string('type'); // setting_start|batch_done|setting_done|run_done|error
            $table->unsignedBigInteger('setting_id')->nullable();
            $table->string('gsheet_name')->nullable();
            $table->string('sheet_name')->nullable();

            $table->unsignedInteger('batch_no')->nullable();
            $table->unsignedInteger('start_row')->nullable();
            $table->unsignedInteger('end_row')->nullable();
            $table->unsignedInteger('rows_in_batch')->nullable();

            $table->unsignedInteger('imported')->nullable();
            $table->unsignedInteger('not_existing')->nullable();
            $table->unsignedInteger('skipped')->nullable();

            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('botcake_psid_import_events');
    }
};
