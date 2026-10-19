<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mga sequence ng chatbot (drip): ang sequence, ang mga step nito (bawat step ay nagpapatakbo ng published na flow) at
 * ang mga naka-subscribe na customer (page + PSID). Ang (status, next_send_at) ay para sa tick bawat minuto.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('chatbot_sequences')) {
            Schema::create('chatbot_sequences', function (Blueprint $t) {
                $t->id();
                $t->string('name', 120)->unique();
                $t->text('description')->nullable();
                $t->boolean('active')->default(true);
                $t->boolean('exit_on_reply')->default(true);
                $t->string('send_from', 5)->default('08:00'); // HH:MM, Manila
                $t->string('send_to', 5)->default('21:00');
                $t->string('outside_window', 10)->default('skip'); // skip | stop
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('chatbot_sequence_steps')) {
            Schema::create('chatbot_sequence_steps', function (Blueprint $t) {
                $t->id();
                $t->foreignId('sequence_id')->constrained('chatbot_sequences')->cascadeOnDelete();
                $t->unsignedSmallInteger('position');
                $t->unsignedInteger('delay_amount')->default(0);
                $t->string('delay_unit', 10)->default('hours'); // minutes | hours | days
                $t->foreignId('flow_id')->nullable()->constrained('chatbot_flows')->nullOnDelete();
                $t->boolean('active')->default(true);
                $t->timestamps();
                $t->index(['sequence_id', 'position']);
            });
        }

        if (!Schema::hasTable('chatbot_sequence_subscriptions')) {
            Schema::create('chatbot_sequence_subscriptions', function (Blueprint $t) {
                $t->id();
                $t->foreignId('sequence_id')->constrained('chatbot_sequences')->cascadeOnDelete();
                $t->unsignedBigInteger('page_id');
                $t->unsignedBigInteger('psid');
                $t->string('status', 20)->default('active'); // active | paused | completed | unsubscribed | error
                $t->unsignedSmallInteger('current_step')->default(1); // position ng susunod na step
                $t->timestamp('next_send_at')->nullable();
                $t->timestamp('dispatched_at')->nullable();
                $t->timestamp('last_sent_at')->nullable();
                $t->string('enrolled_via', 10)->default('other'); // flow | inbox | other
                $t->unsignedBigInteger('enrolled_by')->nullable(); // user (inbox) o flow (flow)
                $t->string('exit_reason', 60)->nullable(); // bakit tapos, naka-pause o error
                $t->timestamps();
                $t->unique(['sequence_id', 'page_id', 'psid']);
                $t->index(['status', 'next_send_at']);
                $t->index(['page_id', 'psid']);
                $t->index(['sequence_id', 'status']); // para sa bilang sa listahan
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_sequence_subscriptions');
        Schema::dropIfExists('chatbot_sequence_steps');
        Schema::dropIfExists('chatbot_sequences');
    }
};
