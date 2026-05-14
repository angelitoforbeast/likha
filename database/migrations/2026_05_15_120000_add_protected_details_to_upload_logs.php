<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track which fields got "protected" sa /ads_manager/report uploads.
 *
 * "Protected" = the upload's value for a monotonically-increasing column
 * (amount_spent_php, impressions, reach, etc.) was LOWER than the existing
 * row's value, so the update was rejected for that column and the existing
 * value was preserved.
 *
 * Stored as JSON like:
 *   { "amount_spent_php": 12, "impressions": 5, "purchases": 1 }
 *
 * 12 means 12 rows had amount_spent_php rejected because new < old.
 * Lets admin see what kind of data integrity issues a particular upload had.
 *
 * Nullable — only populated for ads_manager_reports uploads na may protections.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('upload_logs')) return;

        Schema::table('upload_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('upload_logs', 'protected_details')) {
                $table->json('protected_details')->nullable()->after('error_rows');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('upload_logs')) return;

        Schema::table('upload_logs', function (Blueprint $table) {
            if (Schema::hasColumn('upload_logs', 'protected_details')) {
                $table->dropColumn('protected_details');
            }
        });
    }
};
