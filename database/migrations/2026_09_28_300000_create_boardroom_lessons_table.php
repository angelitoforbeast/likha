<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Boardroom — playbook kada role: mga aral na itinuro ng user sa chat ("next time, ganito dapat...").
 * Bagong table lang ito; walang ginagalaw na existing table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('br_lessons', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agent_id');                   // ang role na natuto
            $t->unsignedBigInteger('user_id');                    // ang nagturo
            $t->unsignedBigInteger('project_id')->nullable();     // null = lahat ng project
            $t->unsignedBigInteger('meeting_id')->nullable();     // saan itinuro
            $t->unsignedBigInteger('message_id')->nullable();     // ang message ng user na nagturo
            $t->string('applies_when', 300)->nullable();          // sa anong sitwasyon
            $t->text('rule');                                     // ano ang dapat gawin
            $t->string('status', 12)->default('active');          // active | disabled | replaced
            $t->unsignedBigInteger('replaced_by_id')->nullable();
            $t->string('source', 12)->default('chat');            // chat = kusang natutunan | manual = tinype sa Agents page
            $t->timestamps();
            $t->index(['agent_id', 'user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('br_lessons');
    }
};
