<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('pancake_conversations') || Schema::hasColumn('pancake_conversations', 'psid')) {
            return;
        }

        Schema::table('pancake_conversations', function (Blueprint $table) {
            // PSID ng customer sa page (Sender ID ng Pancake export). Null = lumang row na name lang ang alam.
            $table->string('psid', 32)->nullable()->after('full_name');

            // Dalawang customer na magkapareho ang pangalan sa iisang page ay magkahiwalay na row na ngayon,
            // kaya hindi na unique ang (page, name). Index pa rin para mabilis ang name lookup.
            $table->dropUnique('pancake_page_fullname_unique');
            $table->index(['pancake_page_id', 'full_name'], 'pancake_page_fullname_idx');

            // Leading psid: ang list ng orders ay naghahanap ng psid kahit hindi pa alam ang page.
            $table->index(['psid', 'pancake_page_id'], 'pancake_psid_page_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('pancake_conversations') || !Schema::hasColumn('pancake_conversations', 'psid')) {
            return;
        }

        Schema::table('pancake_conversations', function (Blueprint $table) {
            $table->dropIndex('pancake_psid_page_idx');
            $table->dropIndex('pancake_page_fullname_idx');
            $table->unique(['pancake_page_id', 'full_name'], 'pancake_page_fullname_unique');
            $table->dropColumn('psid');
        });
    }
};
