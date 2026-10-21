<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Draft at Publish ng flow:
     * - chatbot_flows.graph = ang published (ito lang ang binabasa ng live na takbo); draft_graph = ang ini-edit (null =
     *   walang hindi pa naipa-publish); draft_version = tumataas sa bawat sulat, para mahuli ang dalawang nag-e-edit.
     * - chatbot_flow_versions: isang snapshot kada Publish (ang huling 50 kada flow).
     * Ang mga flow na mayroon na ay published na: published_at = ang huling save nila.
     */
    public function up(): void
    {
        if (!Schema::hasTable('chatbot_flows')) return;

        Schema::table('chatbot_flows', function (Blueprint $table) {
            if (!Schema::hasColumn('chatbot_flows', 'draft_graph')) $table->longText('draft_graph')->nullable();
            if (!Schema::hasColumn('chatbot_flows', 'draft_version')) $table->unsignedInteger('draft_version')->default(0);
            if (!Schema::hasColumn('chatbot_flows', 'published_at')) $table->timestamp('published_at')->nullable();
            if (!Schema::hasColumn('chatbot_flows', 'published_by')) $table->unsignedBigInteger('published_by')->nullable();
        });
        DB::table('chatbot_flows')->whereNull('published_at')->whereNotNull('graph')->update(['published_at' => DB::raw('COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)')]);

        if (!Schema::hasTable('chatbot_flow_versions')) {
            Schema::create('chatbot_flow_versions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('flow_id');
                $table->longText('graph');
                $table->string('note', 200)->nullable();
                $table->unsignedBigInteger('published_by')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['flow_id', 'id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_flow_versions');
        if (!Schema::hasTable('chatbot_flows')) return;
        foreach (['draft_graph', 'draft_version', 'published_at', 'published_by'] as $column) {
            if (Schema::hasColumn('chatbot_flows', $column)) Schema::table('chatbot_flows', fn (Blueprint $table) => $table->dropColumn($column));
        }
    }
};
