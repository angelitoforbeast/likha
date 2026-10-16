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

        // Ang huling dahilan kung bakit walang PSID ang isang order (ambiguous / no_match), isinusulat ng matching.
        // Hiwalay sa macro_output para hindi galawin ang orders table; ang walang row dito = hindi pa nasusuri.
        if (!Schema::hasTable('botcake_psid_misses')) {
            Schema::create('botcake_psid_misses', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id')->unique(); // macro_output.id
                $table->string('page_key'); // LOWER(TRIM(PAGE))
                $table->date('ts_date')->nullable();
                $table->string('reason', 20); // ambiguous|no_match
                $table->timestamp('checked_at')->nullable();

                $table->index(['page_key', 'ts_date', 'reason']);
                $table->index('ts_date');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('botcake_psid_misses');

        Schema::table('botcake_psid_import_runs', function (Blueprint $table) {
            $table->dropColumn('stop_reason');
        });

        Schema::table('botcake_psids', function (Blueprint $table) {
            $table->dropIndex(['source', 'page', 'status', 'last_seen_at']);
            $table->dropColumn('last_seen_at');
        });
    }
};
