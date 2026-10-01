<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * item_supplier_quotes.photo_path — photo ng produkto ng supplier (public disk,
 * supplier-quote-images/). Nullable; isang photo kada quote.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('item_supplier_quotes') || Schema::hasColumn('item_supplier_quotes', 'photo_path')) return;

        Schema::table('item_supplier_quotes', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('item_supplier_quotes') && Schema::hasColumn('item_supplier_quotes', 'photo_path')) {
            Schema::table('item_supplier_quotes', function (Blueprint $table) {
                $table->dropColumn('photo_path');
            });
        }
    }
};
