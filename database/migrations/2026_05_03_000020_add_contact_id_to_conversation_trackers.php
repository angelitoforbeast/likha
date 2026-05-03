<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_trackers', function (Blueprint $t) {
            // Platform-generated unique identifier per contact, sourced from
            // col H ("CONTACT ID") in the upload sheet. Primary upsert key
            // when present; falls back to (page_name, name) when blank.
            $t->string('contact_id', 100)->nullable()->after('phone_number')->index();
            // Composite index for fast (page_name, contact_id) upsert lookup.
            $t->index(['page_name', 'contact_id'], 'ct_page_contactid_idx');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_trackers', function (Blueprint $t) {
            $t->dropIndex('ct_page_contactid_idx');
            $t->dropIndex(['contact_id']); // single-col index
            $t->dropColumn('contact_id');
        });
    }
};
