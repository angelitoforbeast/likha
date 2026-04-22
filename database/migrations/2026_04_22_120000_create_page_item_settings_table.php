<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_item_settings', function (Blueprint $table) {
            $table->id();
            $table->string('page_name');
            $table->string('item_name');
            $table->decimal('price', 10, 2);        // selling price / COD per order
            $table->decimal('rts_pct', 5, 2);       // RTS% e.g. 25.00 = 25%
            $table->date('effective_date');          // valid from this date onward
            $table->timestamps();

            $table->index(['page_name', 'item_name', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_item_settings');
    }
};
