<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multiple receipts per supply payment. Dating isang receipt_path lang sa
 * supply_payments — ngayon N receipts via supply_payment_receipts. Mini-na ang
 * existing single receipts papunta sa bagong table para unified ang display.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('supply_payment_receipts')) {
            Schema::create('supply_payment_receipts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supply_payment_id')->constrained('supply_payments')->cascadeOnDelete();
                $table->string('path');
                $table->timestamps();
            });

            // Migrate existing single receipts → receipts table.
            if (Schema::hasColumn('supply_payments', 'receipt_path')) {
                foreach (DB::table('supply_payments')->whereNotNull('receipt_path')->get(['id', 'receipt_path']) as $p) {
                    if (!empty($p->receipt_path)) {
                        DB::table('supply_payment_receipts')->insert([
                            'supply_payment_id' => $p->id,
                            'path'              => $p->receipt_path,
                            'created_at'        => now(),
                            'updated_at'        => now(),
                        ]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_payment_receipts');
    }
};
