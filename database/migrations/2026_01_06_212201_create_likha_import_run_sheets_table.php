<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('likha_import_run_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('likha_import_runs')->cascadeOnDelete();
            $table->foreignId('setting_id')->constrained('likha_order_settings')->cascadeOnDelete();

            $table->string('status')->default('queued'); // queued|fetching|processing|writing|done|failed
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('inserted_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->text('message')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index(['run_id', 'setting_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('likha_import_run_sheets');
    }
};
