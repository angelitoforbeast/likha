<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop if exists (as requested)
        Schema::dropIfExists('macro_import_runs');

        Schema::create('macro_import_runs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('started_by')->nullable(); // user_id
            $table->string('status')->default('queued'); // queued|running|done|failed
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->unsignedInteger('total_settings')->default(0);
            $table->unsignedInteger('processed_settings')->default(0);

            $table->unsignedBigInteger('total_processed')->default(0);
            $table->unsignedBigInteger('total_inserted')->default(0);
            $table->unsignedBigInteger('total_updated')->default(0);
            $table->unsignedBigInteger('total_skipped')->default(0);

            $table->text('message')->nullable();

            $table->timestamps();

            $table->index(['status', 'started_at']);
            $table->index('started_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('macro_import_runs');
    }
};
