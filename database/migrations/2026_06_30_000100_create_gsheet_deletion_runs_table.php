<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gsheet_deletion_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->string('group_name')->nullable();      // snapshot
            $table->unsignedInteger('end_row');
            $table->string('status')->default('queued');   // queued/running/done/done_with_errors/failed
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name')->nullable();
            $table->json('before')->nullable();            // {likha_l1, macro_e2, after_lastrow}
            $table->json('after')->nullable();
            $table->json('result')->nullable();            // per-tab entries
            $table->unsignedInteger('deleted_total')->default(0);
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsheet_deletion_runs');
    }
};
