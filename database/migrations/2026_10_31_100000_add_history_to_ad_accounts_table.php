<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * History ng ad_accounts sa parehong table: ang lumang row ay naiiwan (active = false) kapag pinalitan o binura.
 * Ang unique sa ad_account_id ay tinanggal kasi may lumang rows na ang parehong ID;
 * ang "isang active row bawat ad_account_id" ay binabantayan ng AdAccountController (sa loob ng transaction).
 * Ang mga dating row ay active = true, created_by = NULL ("unknown").
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('ad_accounts')) return;

        Schema::table('ad_accounts', function (Blueprint $table) {
            $table->dropUnique(['ad_account_id']);
        });

        Schema::table('ad_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->after('name');
            $table->boolean('active')->default(true)->after('created_by');
            $table->timestamp('replaced_at')->nullable()->after('active');
            $table->unsignedBigInteger('replaced_by')->nullable()->after('replaced_at');
            $table->string('action', 16)->nullable()->after('replaced_by'); // added | changed | deleted

            $table->index(['ad_account_id', 'active']);
            $table->index('active');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('ad_accounts')) return;

        Schema::table('ad_accounts', function (Blueprint $table) {
            $table->dropIndex(['ad_account_id', 'active']);
            $table->dropIndex(['active']);
            $table->dropColumn(['created_by', 'active', 'replaced_at', 'replaced_by', 'action']);
        });
        // Ang unique ay hindi ibinabalik: babagsak ito kapag may lumang rows na. Burahin muna ang inactive rows kung kailangan.
    }
};
