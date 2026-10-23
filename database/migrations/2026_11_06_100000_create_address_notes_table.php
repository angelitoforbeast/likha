<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * address_notes: ang natutunan ng encoder mula sa mga itinamang order. Isang row kada isinulat ng customer (written_key =
     * "barangay|city|province", normalised); ang sagot ay ang J&T address na napunta ang order. Walang pangalan o phone dito:
     * mga bahagi ng address at order id lang. Additive; walang ginagalaw na umiiral na table.
     */
    public function up(): void
    {
        if (Schema::hasTable('address_notes')) return;

        Schema::create('address_notes', function (Blueprint $table) {
            $table->id();
            $table->string('written_key', 255);
            $table->string('written_brgy', 100)->default('');
            $table->string('written_city', 100)->default('');
            $table->string('written_province', 100)->default('');
            $table->string('written_example', 255)->default('');
            $table->string('province', 120);
            $table->string('city', 160);
            $table->string('barangay', 200);
            $table->string('source', 60)->default('');          // staff_fix, astra_fix, delivered, manual (listahan, pinaghiwalay ng kuwit)
            $table->unsignedInteger('seen')->default(0);
            $table->unsignedInteger('delivered')->default(0);
            $table->boolean('conflict')->default(false);
            $table->longText('conflicts')->nullable();          // JSON: ang ibang sagot at ang bilang nila
            $table->string('status', 10)->default('pending');   // pending | approved | rejected
            $table->string('reviewed_by', 100)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->text('order_ids')->nullable();              // JSON: hanggang 20 halimbawa
            $table->longText('ledger')->nullable();             // JSON: order id => sagot:delivered, para hindi mabilang nang dalawang beses
            $table->timestamps();

            $table->unique('written_key', 'address_notes_key_unique');
            $table->index(['status', 'written_city'], 'address_notes_status_city');
            $table->index(['status', 'written_province'], 'address_notes_status_province');
            $table->index(['status', 'delivered'], 'address_notes_status_delivered');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('address_notes');
    }
};
