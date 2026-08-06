<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_manager_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action');                       // restart_workers/clear_pending/clear_failed/nuclear_reset
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name')->nullable();        // snapshot
            $table->string('user_email')->nullable();       // snapshot
            $table->string('user_role')->nullable();        // snapshot (CEO / Marketing - OIC)
            $table->string('ip', 64)->nullable();
            $table->json('details')->nullable();            // {cleared, runs_cancelled, pending_cleared, ...}
            $table->timestamps();

            $table->index('action');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_manager_logs');
    }
};
