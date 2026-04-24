<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_page_primary_item', function (Blueprint $table) {
            $table->id();
            $table->date('ts_date');
            $table->string('page_label', 255);
            $table->string('page_key', 255);              // LOWER(TRIM(page_label))
            $table->string('primary_item', 255);
            $table->string('primary_item_key', 255);      // normalized for joins
            $table->integer('primary_orders');            // raw row count
            $table->integer('total_orders_all');          // all items on this (date, page)
            $table->decimal('primary_mode_cod', 18, 2)->nullable();
            $table->string('second_item', 255)->nullable();
            $table->integer('second_orders')->nullable();
            $table->timestamp('computed_at')->useCurrent();
            $table->timestamps();

            $table->unique(['ts_date', 'page_key'], 'dppi_date_page_unique');
            $table->index(['page_key', 'ts_date'], 'dppi_page_date_idx');
            $table->index('ts_date', 'dppi_date_idx');
            $table->index('primary_item_key', 'dppi_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_page_primary_item');
    }
};
