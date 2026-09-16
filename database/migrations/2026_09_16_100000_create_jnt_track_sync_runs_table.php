<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runs para sa /jnt/track-sync — API-based status refresh ng from_jnts (TRACKQUERY).
 * Progress + Updated/Skipped/Failed counts + preview sample.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jnt_track_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->boolean('dry_run')->default(true);   // true = preview (walang isusulat)
            $table->string('status', 20)->default('queued'); // queued|running|done|failed

            $table->unsignedInteger('total')->default(0);     // waybills sa saklaw (non-final)
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('updated')->default(0);   // nagbago ang status
            $table->unsignedInteger('unchanged')->default(0); // pareho pa rin
            $table->unsignedInteger('skipped')->default(0);   // walang track / success:false
            $table->unsignedInteger('unmapped')->default(0);  // Problematic/di-kilalang scantype
            $table->unsignedInteger('failed')->default(0);    // error habang nagpo-proseso

            $table->json('result_sample')->nullable();        // sample rows + unmapped scantypes
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jnt_track_sync_runs');
    }
};
