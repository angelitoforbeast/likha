<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop if exists (as requested)
        Schema::dropIfExists('macro_import_run_items');

        Schema::create('macro_import_run_items', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('run_id');
            $table->unsignedBigInteger('setting_id')->nullable();

            // snapshot fields for UI stability
            $table->string('gsheet_name')->nullable();
            $table->text('sheet_url')->nullable();
            $table->string('sheet_range')->nullable();

            $table->string('status')->default('queued'); // queued|running|done|failed|skipped

            $table->unsignedBigInteger('processed')->default(0);
            $table->unsignedBigInteger('inserted')->default(0);
            $table->unsignedBigInteger('updated')->default(0);
            $table->unsignedBigInteger('skipped')->default(0);

            $table->text('message')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index(['run_id', 'status']);
            $table->index('setting_id');

            // FK (optional). If you want strict FK, uncomment below.
            // $table->foreign('run_id')->references('id')->on('macro_import_runs')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('macro_import_run_items');
    }
};
