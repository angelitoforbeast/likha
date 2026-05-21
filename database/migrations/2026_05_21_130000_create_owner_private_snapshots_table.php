<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores frozen captures ng /owner/private's rendered state. CEO clicks the
 * "📸 Save Snapshot" button → current rows + totals + filter context get
 * persisted as JSON. Viewable later sa /owner/private/snapshots.
 *
 * Use case: lock in "as-of" view ng the page summary at a specific moment —
 * useful sa weekly reviews, anomaly investigation, before/after comparisons.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('owner_private_snapshots')) return;

        Schema::create('owner_private_snapshots', function (Blueprint $t) {
            $t->id();

            // Who saved it (best-effort — user_id may be null kung soft-deleted)
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('user_email', 255)->nullable();

            // When + what scope
            $t->timestamp('snapshot_at');
            $t->date('start_date');
            $t->date('end_date');
            $t->string('view_as', 20)->nullable();  // 'ceo' | 'marketing'

            // Quick header summary (denormalized for list view)
            $t->integer('rows_count')->default(0);
            $t->integer('skipped_count')->default(0);

            // The full payload as captured by the frontend at save-time.
            // Structure mirrors itemSummary response: rows[], totals, etc.
            // JSON column → MySQL/Postgres native, queryable kung kailangan.
            $t->json('payload');

            $t->timestamps();

            $t->index('snapshot_at');
            $t->index(['start_date', 'end_date']);
            $t->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_private_snapshots');
    }
};
