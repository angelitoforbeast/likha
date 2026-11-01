<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ang key at gastos ng bawat AI request (spec 210): aling key ng pool ang sumagot (id, pangalan at huling 4 lang, walang
     * key), ang gastos sa micro-USD (null kapag walang usage o walang presyo, may dahilan sa cost_flag), at ang padala ng usage
     * sa Forbeast ng page (usage_status, ang gateway, kailan, at ang sagot). Lahat nullable: ang lumang row ay walang laman.
     */
    public function up(): void
    {
        if (!Schema::hasTable('chatbot_ai_requests') || Schema::hasColumn('chatbot_ai_requests', 'key_id')) return;
        Schema::table('chatbot_ai_requests', function (Blueprint $table) {
            $table->string('key_id', 12)->nullable();
            $table->string('key_label', 100)->nullable();
            $table->string('key_last4', 4)->nullable();
            $table->unsignedBigInteger('cost_micros')->nullable();
            $table->string('cost_flag', 20)->nullable(); // no_usage | no_price
            $table->string('usage_status', 16)->nullable(); // pending | sent | needs_price | unbilled | page_not_found | invalid | no_gateway
            $table->string('usage_gateway', 20)->nullable();
            $table->timestamp('usage_sent_at')->nullable();
            $table->string('usage_result', 20)->nullable();
            $table->index(['usage_status', 'usage_gateway', 'id']);
            $table->index(['key_id', 'created_at']);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('chatbot_ai_requests') || !Schema::hasColumn('chatbot_ai_requests', 'key_id')) return;
        Schema::table('chatbot_ai_requests', function (Blueprint $table) {
            $table->dropIndex(['usage_status', 'usage_gateway', 'id']);
            $table->dropIndex(['key_id', 'created_at']);
            $table->dropColumn(['key_id', 'key_label', 'key_last4', 'cost_micros', 'cost_flag', 'usage_status', 'usage_gateway', 'usage_sent_at', 'usage_result']);
        });
    }
};
