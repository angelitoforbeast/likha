<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_tracker_run_sheets', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('run_id');
            $t->unsignedBigInteger('setting_id');
            $t->string('status', 50)->default('queued'); // queued|fetching|processing|writing|done|failed
            $t->integer('processed_count')->default(0);
            $t->integer('inserted_count')->default(0);
            $t->integer('updated_count')->default(0);
            $t->integer('skipped_count')->default(0);
            $t->text('message')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->index(['run_id', 'setting_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_tracker_run_sheets');
    }
};
