<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add user tracking to upload_logs (JNT V1 uploads).
 *
 * Why: V1 walang user info per upload — yung history table mo sa /jnt_upload
 * ay magpapakita ng "Uploaded by" column. Going forward, every new upload ay
 * mata-tag sa user na nag-upload. Existing rows ay null (pre-tracking) — they
 * display as "—".
 *
 * Mirrors yung user tracking pattern sa V2 (bulk_upload_runs.user_id +
 * user_email).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('upload_logs')) return;

        Schema::table('upload_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('upload_logs', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('size');
                $table->index('user_id', 'upload_logs_user_id_idx');
            }
            if (! Schema::hasColumn('upload_logs', 'user_email')) {
                // Fallback display value kung yung user ay na-delete na later —
                // email persists kahit user gone.
                $table->string('user_email')->nullable()->after('user_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('upload_logs')) return;

        Schema::table('upload_logs', function (Blueprint $table) {
            if (Schema::hasColumn('upload_logs', 'user_id')) {
                try { $table->dropIndex('upload_logs_user_id_idx'); } catch (\Throwable $e) {}
                $table->dropColumn('user_id');
            }
            if (Schema::hasColumn('upload_logs', 'user_email')) {
                $table->dropColumn('user_email');
            }
        });
    }
};
