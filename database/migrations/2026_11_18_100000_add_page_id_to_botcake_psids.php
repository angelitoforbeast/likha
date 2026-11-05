<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('botcake_psids', function (Blueprint $table) {
            // Ang Facebook page id: hindi nagbabago kahit palitan ang pangalan ng page sa page settings. Null sa lumang
            // row hanggang punan ng botcake:psid-page-id-backfill, at sa page na walang botcake.page_id.
            $table->string('page_id', 64)->nullable();

            $table->index(['page_id', 'psid']);
            $table->index(['source', 'page_id', 'status', 'name_key', 'psid']);
            $table->index(['source', 'page_id', 'status', 'phone_key', 'psid']);
        });
    }

    public function down(): void
    {
        Schema::table('botcake_psids', function (Blueprint $table) {
            $table->dropIndex(['page_id', 'psid']);
            $table->dropIndex(['source', 'page_id', 'status', 'name_key', 'psid']);
            $table->dropIndex(['source', 'page_id', 'status', 'phone_key', 'psid']);
            $table->dropColumn('page_id');
        });
    }
};
