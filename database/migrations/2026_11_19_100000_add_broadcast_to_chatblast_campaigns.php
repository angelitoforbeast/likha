<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Spec 263 (broadcast). No new table: a broadcast is a chatblast_campaigns row with mode "broadcast", and its filters,
     * flow, hourly cap, state and per-page facts go in one nullable JSON column (the small campaigns table: instant).
     * Its sends are botcake_messages rows (kind broadcast, campaign_id); one index for the progress GROUP BY, the
     * feeder's waiting pick and its "handed out in the last hour" count. On MySQL InnoDB adding a secondary index is an
     * online operation (ALGORITHM=INPLACE, LOCK=NONE): the table stays readable and writable while it builds.
     */
    public function up(): void
    {
        if (Schema::hasTable('chatblast_campaigns') && !Schema::hasColumn('chatblast_campaigns', 'broadcast')) {
            Schema::table('chatblast_campaigns', function (Blueprint $table) {
                $table->json('broadcast')->nullable();
            });
        }
        if (Schema::hasTable('botcake_messages') && Schema::hasColumn('botcake_messages', 'campaign_id') && !Schema::hasIndex('botcake_messages', 'botcake_messages_broadcast_index')) {
            Schema::table('botcake_messages', function (Blueprint $table) {
                $table->index(['campaign_id', 'page_name', 'status', 'queued_at'], 'botcake_messages_broadcast_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('botcake_messages') && Schema::hasIndex('botcake_messages', 'botcake_messages_broadcast_index')) {
            Schema::table('botcake_messages', function (Blueprint $table) {
                $table->dropIndex('botcake_messages_broadcast_index');
            });
        }
        if (Schema::hasTable('chatblast_campaigns') && Schema::hasColumn('chatblast_campaigns', 'broadcast')) {
            Schema::table('chatblast_campaigns', function (Blueprint $table) {
                $table->dropColumn('broadcast');
            });
        }
    }
};
