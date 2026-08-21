<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('barcode_print_logs', function (Blueprint $table) {
            $table->id();
            $table->date('target_date');                     // anong date ng bundles na na-print
            $table->unsignedInteger('bundle_count')->default(0);
            $table->unsignedInteger('waybill_count')->default(0);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name')->nullable();          // snapshot
            $table->string('user_email')->nullable();
            $table->string('user_role')->nullable();
            $table->string('ip', 64)->nullable();
            $table->timestamps();

            $table->index('target_date');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barcode_print_logs');
    }
};
