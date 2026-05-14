<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add the source-file's INTERNAL created/modified dates sa upload_logs.
 *
 * Why: XLSX files (Meta Ads Manager exports, JNT reports) have embedded
 * dcterms:created at dcterms:modified metadata sa `docProps/core.xml`. Tells
 * the marketing team kung kelan talaga in-export/in-edit yung file BEFORE
 * upload — useful to spot stale data (e.g. file from yesterday uploaded today).
 *
 * Existing `created_at` column already tracks WHEN the user uploaded sa
 * system. These new columns track WHEN the source file was originally made.
 *
 * Nullable for non-XLSX uploads (CSV/ZIP) at for legacy rows.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('upload_logs')) return;

        Schema::table('upload_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('upload_logs', 'file_created_at')) {
                $table->dateTime('file_created_at')->nullable()->after('size');
            }
            if (! Schema::hasColumn('upload_logs', 'file_modified_at')) {
                $table->dateTime('file_modified_at')->nullable()->after('file_created_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('upload_logs')) return;

        Schema::table('upload_logs', function (Blueprint $table) {
            if (Schema::hasColumn('upload_logs', 'file_created_at')) {
                $table->dropColumn('file_created_at');
            }
            if (Schema::hasColumn('upload_logs', 'file_modified_at')) {
                $table->dropColumn('file_modified_at');
            }
        });
    }
};
