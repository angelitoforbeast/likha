<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * order_encodes: isang row kada order na ine-encode ng Likha mismo (kapalit ng AUTO ENCODE at 1.0 OUTPUT ng Google Sheets).
     * Shadow: ang resulta ay itinatabi dito at inihahambing sa DATABASE row ng sheet (mismong row number). Live: bukod dito,
     * nagagawa ang macro_output row (macro_output_id). Additive lang; walang ginagalaw na umiiral na table.
     */
    public function up(): void
    {
        if (Schema::hasTable('order_encodes')) return;

        Schema::create('order_encodes', function (Blueprint $table) {
            $table->id();
            $table->string('page');
            $table->unsignedBigInteger('psid')->nullable();
            $table->unsignedBigInteger('chat_id')->nullable();
            $table->unsignedBigInteger('flow_id')->nullable();
            $table->string('run_id', 40)->nullable();
            $table->string('spreadsheet_id', 100);           // ang sheet ng step na sumulat ng row (All Orders)
            $table->string('tab', 100);
            $table->unsignedInteger('row_number')->nullable(); // ang row na isinulat ng append; walang row sa live
            $table->string('mode', 10);                       // shadow | live, noong ginawa
            $table->string('status', 10)->default('queued');  // queued | done | failed
            $table->json('input');                            // ang A..H ng row (date, page, name, phone, input, shop, cxd, price)
            $table->json('outputs')->nullable();              // name, phone, address_line, province, city, barangay, item, cod, remark, icon, r, s, t, u, v, w, final, proceed
            $table->json('steps')->nullable();                // bawat hakbang at tawag sa GPT
            $table->json('usage')->nullable();                // model => requests, tokens_in, tokens_out
            $table->json('sheet')->nullable();                // ang DATABASE row (A..W) at ang MIRRORED O/P na nabasa
            $table->json('compare')->nullable();              // field => same | different | sheet_blank | likha_blank
            $table->string('compare_state', 20)->nullable();  // waiting | done | row_mismatch | sheet_not_done
            $table->boolean('compare_equal')->nullable();
            $table->unsignedBigInteger('macro_output_id')->nullable();
            $table->timestamp('encoded_at')->nullable();
            $table->timestamp('compared_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['spreadsheet_id', 'tab', 'row_number'], 'order_encodes_source_unique');
            $table->index(['page', 'created_at'], 'order_encodes_page_created');
            $table->index('compare_state', 'order_encodes_compare_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_encodes');
    }
};
