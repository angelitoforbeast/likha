<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ang record ng bawat AI request (spec 150): sino ang sumagot (provider, model), gaano katagal, tokens, kung lumipat sa
     * susunod na AI at bakit, ang bawat subok (walang text), at kung naghihintay (sync) o pool (async). Walang text ng
     * customer dito: ang messages, answer at context ay binubura pagkatapos ng 2 araw (kaya nullable), ang record ay 90 araw.
     */
    public function up(): void
    {
        if (!Schema::hasTable('chatbot_ai_requests') || Schema::hasColumn('chatbot_ai_requests', 'provider')) return;

        Schema::table('chatbot_ai_requests', function (Blueprint $table) {
            $table->longText('messages')->nullable()->change();
            $table->text('context')->nullable()->change();
            $table->string('provider', 20)->nullable();
            $table->string('model_used', 100)->nullable();
            $table->boolean('fell_back')->default(false);
            $table->string('fallback_reason', 255)->nullable();
            $table->text('ai_attempts')->nullable(); // JSON: [{provider, model, ms, ok, why}]
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->string('path', 5)->nullable(); // async | sync
            $table->index(['page_name', 'created_at']);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('chatbot_ai_requests') || !Schema::hasColumn('chatbot_ai_requests', 'provider')) return;

        // Ang mga row na wala nang text (o sync) ay binibigyan ng laman bago ibalik ang NOT NULL.
        DB::table('chatbot_ai_requests')->whereNull('messages')->update(['messages' => '[]']);
        DB::table('chatbot_ai_requests')->whereNull('context')->update(['context' => '{}']);
        Schema::table('chatbot_ai_requests', function (Blueprint $table) {
            $table->dropIndex(['page_name', 'created_at']);
            $table->dropColumn(['provider', 'model_used', 'fell_back', 'fallback_reason', 'ai_attempts', 'latency_ms', 'prompt_tokens', 'completion_tokens', 'path']);
        });
        Schema::table('chatbot_ai_requests', function (Blueprint $table) {
            $table->longText('messages')->nullable(false)->change();
            $table->text('context')->nullable(false)->change();
        });
    }
};
