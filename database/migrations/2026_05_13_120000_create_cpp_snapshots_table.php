<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshots of /ads_manager/cpp per page per bucket per date.
 *
 * Created when user clicks the "Copy Table" button sa /cpp summary view.
 * Bucket auto-derived from PH-time of click:
 *   - 06:00 – 12:30  → '10AM'
 *   - 12:30 – 17:30  → '3PM'
 *   - 17:30 – 06:00  → '7PM'   (anything after 17:30 falls here, including late-night)
 *   - (future) cron at 11:59 PM → '11:59PM'  — schema supports it na
 *
 * Unique key (snapshot_date, snapshot_bucket, page_name) lets repeated clicks
 * sa same bucket overwrite cleanly (latest-wins semantics that match the team's
 * "re-copy when something updates" workflow).
 *
 * Size estimate: ~40 pages × 3 buckets × 365 days = ~44k rows/year. Tiny.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('cpp_snapshots', function (Blueprint $table) {
            $table->id();

            // What date the snapshot is FOR (the data's day, not necessarily save day).
            $table->date('snapshot_date');

            // Which bucket within that day.
            $table->string('snapshot_bucket', 16); // '10AM' | '3PM' | '7PM' | '11:59PM'

            // Exact click time — useful for audit.
            $table->dateTime('snapshot_at');

            // Page identifiers — page_name is what the user sees; page_key is
            // lowercased+trimmed for safe joining against macro_output / cpp queries.
            $table->string('page_name');
            $table->string('page_key')->index();

            // Item names for that page on that date (comma-separated, can be multi).
            $table->text('item_names')->nullable();

            // Snapshot metrics (mirror what /cpp summary shows).
            $table->decimal('amount_spent', 12, 2)->default(0);
            $table->unsignedInteger('orders')->default(0);
            $table->unsignedInteger('proceed_orders')->default(0);
            $table->decimal('cpp',      12, 2)->nullable();
            $table->decimal('cpi',      12, 2)->nullable();
            $table->decimal('cpm',      12, 2)->nullable();
            $table->decimal('tcpr_pct',  5, 2)->nullable();

            // Audit — who triggered the save (Copy click). Nullable for cron-saved.
            $table->unsignedBigInteger('saved_by_user_id')->nullable();
            $table->string('saved_by_user_email')->nullable();

            $table->timestamps();

            // Unique lock per (date, bucket, page) — re-clicks overwrite cleanly.
            $table->unique(
                ['snapshot_date', 'snapshot_bucket', 'page_name'],
                'cpp_snap_date_bucket_page_unique'
            );

            // Common query: list snapshots in a date range, ordered by date+bucket.
            $table->index(['snapshot_date', 'snapshot_bucket'], 'cpp_snap_date_bucket_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cpp_snapshots');
    }
};
