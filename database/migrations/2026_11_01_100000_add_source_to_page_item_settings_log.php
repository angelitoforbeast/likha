<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saan galing ang edit: owner_private | item_cogs | cogs_sync.
 * NULL (lahat ng lumang row) = /owner/private, doon lang dati nagsusulat.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('page_item_settings_log')) return;

        Schema::table('page_item_settings_log', function (Blueprint $t) {
            if (!Schema::hasColumn('page_item_settings_log', 'source')) {
                $t->string('source', 30)->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('page_item_settings_log')) return;

        Schema::table('page_item_settings_log', function (Blueprint $t) {
            if (Schema::hasColumn('page_item_settings_log', 'source')) {
                $t->dropIndex(['source']);
                $t->dropColumn('source');
            }
        });
    }
};
