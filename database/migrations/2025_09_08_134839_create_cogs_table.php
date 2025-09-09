<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('cogs', function (Blueprint $table) {
            $table->id();
            $table->date('date');                        // araw ng presyo (only if may entry sa macro_output)
            $table->string('item_name');                 // direktang name (pareho sa macro_output.ITEM_NAME)
            $table->decimal('unit_cost', 12, 2)->nullable(); // carry-forward or edited; nullable for first-ever day
            $table->json('history_logs')->nullable();    // audit (optional)
            $table->timestamps();

            $table->unique(['item_name','date']);       // 1 row per item per day (kapag present sa macro_output)
            $table->index(['date','item_name']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('cogs');
    }
};
