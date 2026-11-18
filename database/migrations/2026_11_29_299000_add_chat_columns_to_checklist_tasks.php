<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mira sa Likha Chat (spec 299): ang approved na AI follow-up ay ipinapadala rin bilang chat message. chat_message_id
 * = ang message na naipadala (isang beses lang: kapag may laman, hindi na ipapadala ulit); chat_failed_at = kailan
 * huling pumalya ang pagpadala (para sa "Not sent to chat" at Retry ng CEO). Bawat column ay may guard; sqlite-safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('checklist_tasks')) {
            return;
        }
        if (!Schema::hasColumn('checklist_tasks', 'chat_message_id')) {
            Schema::table('checklist_tasks', fn (Blueprint $t) => $t->unsignedBigInteger('chat_message_id')->nullable());
        }
        if (!Schema::hasColumn('checklist_tasks', 'chat_failed_at')) {
            Schema::table('checklist_tasks', fn (Blueprint $t) => $t->timestamp('chat_failed_at')->nullable());
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('checklist_tasks')) {
            return;
        }
        foreach (['chat_message_id', 'chat_failed_at'] as $column) {
            if (Schema::hasColumn('checklist_tasks', $column)) {
                Schema::table('checklist_tasks', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
