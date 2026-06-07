<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supply Finance — suppliers, supply orders (PO) na may lifecycle
 * (ordered → delivered → counted), per-item lines (= stock-in kapag counted),
 * at partial payments per-supplier na may receipt upload.
 *
 * Supplier balance (utang) = opening_balance + Σ(order totals) − Σ(payments).
 * opening_balance = existing utang bago pa ang system.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('suppliers')) {
            Schema::create('suppliers', function (Blueprint $table) {
                $table->id();
                $table->string('name')->index();
                $table->string('contact')->nullable();
                $table->string('terms')->nullable();                       // e.g. "7 days", "COD"
                $table->decimal('opening_balance', 14, 2)->default(0);      // existing utang bago system
                $table->string('opening_balance_note')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('supply_orders')) {
            Schema::create('supply_orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
                $table->string('order_no')->nullable();                    // optional PO ref
                $table->date('order_date');
                $table->date('expected_delivery')->nullable();
                $table->string('status', 20)->default('ordered');          // ordered|delivered|counted
                $table->decimal('total_cost', 14, 2)->default(0);          // Σ line_total (ordered)
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('counted_at')->nullable();
                $table->timestamps();
                $table->index(['supplier_id', 'order_date']);
            });
        }

        if (!Schema::hasTable('supply_order_items')) {
            Schema::create('supply_order_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supply_order_id')->constrained('supply_orders')->cascadeOnDelete();
                $table->string('item_key')->index();                       // normalized base item (links stock-in/cogs)
                $table->string('item_name');                               // display
                $table->unsignedInteger('ordered_qty')->default(0);
                $table->decimal('unit_cost', 12, 2)->default(0);
                $table->unsignedInteger('received_qty')->nullable();       // counted = STOCK-IN (null = di pa na-count)
                $table->decimal('line_total', 14, 2)->default(0);          // ordered_qty × unit_cost
                $table->string('notes')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('supply_payments')) {
            Schema::create('supply_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
                $table->unsignedBigInteger('supply_order_id')->nullable(); // optional tag sa PO
                $table->decimal('amount', 14, 2);                          // partial OK
                $table->date('paid_date');
                $table->string('method', 30)->nullable();                  // cash|gcash|bank|...
                $table->string('reference_no')->nullable();
                $table->string('receipt_path')->nullable();                // uploaded resibo (image/pdf)
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('paid_by')->nullable();
                $table->timestamps();
                $table->index(['supplier_id', 'paid_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_payments');
        Schema::dropIfExists('supply_order_items');
        Schema::dropIfExists('supply_orders');
        Schema::dropIfExists('suppliers');
    }
};
