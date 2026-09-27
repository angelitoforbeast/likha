<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * item_supplier_quotes — "may supplier na ba ang item na ito, at magkano kada supplier?"
 * HIWALAY sa PO (supply_orders/supply_order_items): dito naitatala ang supplier na NAKAHANAP na
 * (quote/presyo/MOQ/link) kahit hindi pa na-order. Supplier = existing `suppliers` table (name only).
 *
 *   item_key    = normalized item (tinanggal ang "1 x ", lowercase, isang space) — same key ng /item supplier map
 *   supplier_id = suppliers.id
 *   price       = presyo kada unit mula sa supplier (bago, hindi galing sa PO)
 *   moq / link / note = opsyonal
 * Unique: isang quote kada item kada supplier.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('item_supplier_quotes')) return;

        Schema::create('item_supplier_quotes', function (Blueprint $table) {
            $table->id();
            $table->string('item_key', 190)->index();
            $table->string('item_name', 255);
            $table->unsignedBigInteger('supplier_id')->index();
            $table->decimal('price', 10, 2)->nullable();
            $table->unsignedInteger('moq')->nullable();
            $table->string('link', 500)->nullable();
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['item_key', 'supplier_id'], 'item_supplier_quotes_item_supplier_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_supplier_quotes');
    }
};
