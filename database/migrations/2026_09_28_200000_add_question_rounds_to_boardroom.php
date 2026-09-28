<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Boardroom — "question rounds": ang mga tanong ng user (@mention) ay may SARILING limit kada tanong
 * at hindi na ibinabawas sa limit ng meeting. Mga br_* table lang ang ginagalaw (maliliit na table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('br_rounds', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('meeting_id')->index();
            $t->unsignedBigInteger('message_id');            // ang message ng user na nagbukas ng round
            $t->string('mode', 12)->default('answer');       // answer = sagot lang | discuss = pag-usapan
            $t->unsignedInteger('max_cycles')->default(1);   // 1..3
            $t->unsignedInteger('cycle')->default(1);
            $t->string('phase', 12)->default('answers');     // answers | review | summary | done
            $t->string('status', 12)->default('running');    // running | completed | failed | stopped
            $t->json('agent_ids');                           // mga role na sasagot
            $t->unsignedInteger('calls_used')->default(0);
            $t->timestamps();
        });

        Schema::table('br_turns', function (Blueprint $t) {
            $t->unsignedBigInteger('round_id')->nullable()->index();   // null = turn ng meeting mismo
        });

        // Hiwalay na bilang para sa mga tanong ng user — hindi kasama sa mga limit ng meeting.
        Schema::table('br_meetings', function (Blueprint $t) {
            $t->unsignedInteger('question_calls')->default(0);
            $t->unsignedBigInteger('question_tokens_in')->default(0);
            $t->unsignedBigInteger('question_tokens_out')->default(0);
            $t->decimal('question_cost_usd', 14, 6)->default(0);
            $t->unsignedInteger('question_unpriced_calls')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('br_meetings', function (Blueprint $t) {
            $t->dropColumn(['question_calls', 'question_tokens_in', 'question_tokens_out', 'question_cost_usd', 'question_unpriced_calls']);
        });
        Schema::table('br_turns', function (Blueprint $t) {
            $t->dropIndex(['round_id']);
            $t->dropColumn('round_id');
        });
        Schema::dropIfExists('br_rounds');
    }
};
