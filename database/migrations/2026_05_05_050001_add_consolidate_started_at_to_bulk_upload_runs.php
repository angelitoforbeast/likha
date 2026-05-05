<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add consolidate_started_at column para ma-track yung exact start time
 * ng consolidate phase. Used for elapsed-time + ETA computation sa UI.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('bulk_upload_runs', function (Blueprint $table) {
            $table->timestamp('consolidate_started_at')->nullable()->after('cancel_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('bulk_upload_runs', function (Blueprint $table) {
            $table->dropColumn('consolidate_started_at');
        });
    }
};
