<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Boardroom — resources registry: saan nakalagay ang impormasyon at sino ang mga contact.
 * Ang AI ang nagdadagdag / nag-e-edit / nag-a-archive mula sa sinabi ng user sa chat.
 * Mga bagong br_* table lang; walang ginagalaw na table ng website (suppliers, PO, orders).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('br_resources', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('project_id')->nullable();      // null = lahat ng project
            $t->string('type', 20);                                // channel|group_chat|website|page|contact|link|other
            $t->string('name', 200);
            $t->string('name_key', 200);                           // normalized na pangalan, para sa paghahanap ng doble
            $t->string('purpose', 500)->nullable();                // para saan
            $t->json('tags')->nullable();
            $t->string('location', 1000)->nullable();              // link, username, o paglalarawan kung nasaan
            $t->unsignedBigInteger('parent_id')->nullable();       // saan ito kabilang (hal. contact → Messenger)
            $t->text('details')->nullable();
            $t->string('holder', 200)->nullable();                 // sino sa team ang may access
            $t->string('status', 12)->default('active');           // active | archived
            $t->string('source', 12)->default('chat');             // chat = itinala ng AI | manual = tinype ng user
            $t->unsignedBigInteger('agent_id')->nullable();        // aling role ang nagtala
            $t->unsignedBigInteger('meeting_id')->nullable();
            $t->unsignedBigInteger('message_id')->nullable();      // ang message ng user na pinagmulan
            $t->timestamps();
            $t->index(['user_id', 'status']);
            $t->index(['user_id', 'type', 'name_key']);
        });

        Schema::create('br_payment_accounts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('resource_id')->index();        // kaninong contact
            $t->string('method', 60);                              // GCash, BDO, atbp.
            $t->string('account_name', 200)->nullable();
            $t->text('account_number_encrypted');                  // naka-encrypt; huling 4 lang ang ipinapadala sa AI
            $t->string('last4', 8)->nullable();
            $t->string('notes', 500)->nullable();
            $t->string('status', 12)->default('active');
            $t->string('source', 12)->default('chat');
            $t->unsignedBigInteger('agent_id')->nullable();
            $t->unsignedBigInteger('meeting_id')->nullable();
            $t->unsignedBigInteger('message_id')->nullable();
            $t->timestamps();
        });

        // Tala ng BAWAT pagbabago — ng AI man o ng user. Ito ang batayan ng I-undo at ng kasaysayan.
        Schema::create('br_changes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('target_type', 12);                         // resource | account
            $t->unsignedBigInteger('target_id');
            $t->string('action', 12);                              // add | edit | archive | restore | delete
            $t->string('label', 300);                              // maikling paglalarawan para sa chat at listahan
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->string('actor', 8);                                // ai | user
            $t->unsignedBigInteger('agent_id')->nullable();
            $t->unsignedBigInteger('meeting_id')->nullable();
            $t->unsignedBigInteger('message_id')->nullable();
            $t->timestamp('undone_at')->nullable();
            $t->timestamps();
            $t->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('br_changes');
        Schema::dropIfExists('br_payment_accounts');
        Schema::dropIfExists('br_resources');
    }
};
