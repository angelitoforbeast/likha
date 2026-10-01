<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * item_supplier_quote_history — LUMANG values ng isang supplier quote bago ito nabago
 * (presyo / MOQ / link) o na-delete. Para sa "dati ₱X (date)" sa /item (CEO lang).
 *
 *   action     = 'update' | 'delete'
 *   quoted_at  = kailan na-set ang lumang values (lumang updated_at ng quote)
 *   updated_by = sino ang nagbago / nag-delete
 *   created_at = kailan nabago
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('item_supplier_quote_history')) return;

        Schema::create('item_supplier_quote_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('quote_id')->nullable();
            $table->string('item_key', 190);
            $table->string('item_name', 255);
            $table->unsignedBigInteger('supplier_id');
            $table->decimal('price', 10, 2)->nullable();
            $table->unsignedInteger('moq')->nullable();
            $table->string('link', 500)->nullable();
            $table->string('action', 10);
            $table->timestamp('quoted_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['item_key', 'supplier_id'], 'isq_history_item_supplier_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_supplier_quote_history');
    }
};
