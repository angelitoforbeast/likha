<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Huling sync na nagbalik ng customer: ang hindi na nakita nang 30 araw sa buong basa ay nagiging inactive.
        Schema::table('botcake_psids', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable();
            $table->index(['source', 'page', 'status', 'last_seen_at']);
        });

        // Bakit huminto ang sync ng page: known_batch|end|cap|rate_limited|token_invalid|error.
        Schema::table('botcake_psid_import_runs', function (Blueprint $table) {
            $table->string('stop_reason', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('botcake_psid_import_runs', function (Blueprint $table) {
            $table->dropColumn('stop_reason');
        });

        Schema::table('botcake_psids', function (Blueprint $table) {
            $table->dropIndex(['source', 'page', 'status', 'last_seen_at']);
            $table->dropColumn('last_seen_at');
        });
    }
};
