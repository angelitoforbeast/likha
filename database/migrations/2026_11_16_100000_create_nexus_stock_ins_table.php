<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * nexus_stock_ins — ang stock-in ng warehouse (Nexus app) kada araw at item, galing sa nexus:sync-stock.
 * name_key = sha1 ng normalized na pangalan (NFC, trim, isang space, lowercase): pareho sa lahat ng collation,
 * kaya ang "Café" at "Cafe" ay hindi nagsasama sa MySQL. Ang raw na pangalan (item_name) ang itinutugma sa /item.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nexus_stock_ins')) return;

        Schema::create('nexus_stock_ins', function (Blueprint $table) {
            $table->id();
            $table->date('stock_date');
            $table->char('name_key', 40);
            $table->string('item_name', 255);
            $table->unsignedInteger('stock_in_lots')->default(0);
            $table->unsignedInteger('stock_in_pieces')->default(0);
            $table->integer('remaining')->nullable();
            $table->dateTime('as_of')->nullable();
            $table->timestamps();
            $table->unique(['stock_date', 'name_key'], 'nexus_stock_ins_day_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nexus_stock_ins');
    }
};
