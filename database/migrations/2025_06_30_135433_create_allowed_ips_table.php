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
        Schema::create('allowed_ips', function (Blueprint $table) {
    $table->id();
    $table->string('ip_address')->unique();
    $table->string('label')->nullable(); // optional: e.g. "Office", "Angelito's PC"
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('allowed_ips');
    }
};
