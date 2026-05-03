<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_tracker_runs', function (Blueprint $t) {
            $t->id();
            $t->string('status', 50)->default('queued'); // queued|running|done|failed
            $t->integer('total_settings')->default(0);
            $t->integer('total_processed')->default(0);
            $t->integer('total_inserted')->default(0);
            $t->integer('total_updated')->default(0);
            $t->integer('total_skipped')->default(0);
            $t->integer('total_failed')->default(0);
            $t->text('message')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_tracker_runs');
    }
};
