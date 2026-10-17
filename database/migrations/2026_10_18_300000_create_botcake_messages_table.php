<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Ang send log ng /chatblast: isang row kada mensahe (ang order update na ipinapadala sa customer sa Botcake), kasama
     * ang bawat hakbang ng pagpapadala para madaling ma-debug. dedupe_key = "waybill|kind|day" para sa totoong padala
     * (unique: hindi kailanman makakatanggap ang customer ng parehong araw ng parehong uri nang dalawang beses); NULL para
     * sa test send.
     */
    public function up(): void
    {
        if (Schema::hasTable('botcake_messages')) return;

        Schema::create('botcake_messages', function (Blueprint $table) {
            $table->id();
            $table->string('page_name');
            $table->unsignedBigInteger('macro_output_id')->nullable();
            $table->string('waybill', 100);
            $table->string('kind', 30);
            $table->unsignedSmallInteger('day')->default(1); // Araw 1, Araw 2, … ng uri
            $table->string('version', 5)->nullable();      // A, B, … : ang bersyon ng text ng araw na iyon
            $table->string('fb_name')->nullable();
            $table->string('psid', 64)->nullable();
            $table->json('vars')->nullable();          // name, item, cod, waybill, page: para mabuo ulit ang text
            $table->text('text')->nullable();          // ang eksaktong text na ipapadala
            $table->string('jnt_status', 50)->nullable();
            $table->string('status', 20);              // ready|no_psid|no_config|queued|sending|sent|failed|field_mismatch|skipped|unknown
            $table->string('reason')->nullable();      // bakit no_psid / no_config / failed / skipped
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('last_http')->nullable();
            $table->json('steps')->nullable();         // bawat hakbang: request (walang token), HTTP, sagot (pinaikli)
            $table->boolean('is_test')->default(false);
            $table->unsignedBigInteger('test_of')->nullable();
            $table->string('dedupe_key', 140)->nullable()->unique();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('sent_by')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index(['status', 'created_at']);
            $table->index(['page_name', 'created_at']);
            $table->index(['waybill', 'kind']);
            $table->index(['page_name', 'sent_at']);
            $table->index(['page_name', 'status', 'sent_at']);    // daily cap: naipadala ngayong araw
            $table->index(['page_name', 'status', 'updated_at']); // daily cap: unknown ngayong araw
            $table->index('sent_at');
            $table->index('fb_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('botcake_messages');
    }
};
