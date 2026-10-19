<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * chatbot_waits: ang deadline ng "wait for reply" na may skip_after, isa kada customer (page + PSID). Ang tick
     * (chatbot:resume-skipped) ay naghahanap ayon sa due_at (index), kaya hindi nito sinusuyod ang customer values.
     * Kasabay ng __wait: nabubura kapag nabura ang __wait (sagot, "clear all fields", o wait na walang skip_after).
     */
    public function up(): void
    {
        if (Schema::hasTable('chatbot_waits')) return;

        Schema::create('chatbot_waits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('page_id');
            $table->unsignedBigInteger('psid');
            $table->unsignedBigInteger('flow_id');
            $table->string('node_id', 20);
            $table->timestamp('due_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['page_id', 'psid']);
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_waits');
    }
};
