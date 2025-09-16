<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('jnt_return_scanned', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('waybill_number', 50)->unique()->index();
            $table->timestamp('scanned_at')->nullable();
            $table->string('scanned_by', 100)->nullable();
            $table->string('status_at_scan', 50)->nullable(); // optional, like "Returned to Shipper"
            $table->text('remarks')->nullable();
            $table->timestamps(); // created_at, updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jnt_return_scanned');
    }
};
