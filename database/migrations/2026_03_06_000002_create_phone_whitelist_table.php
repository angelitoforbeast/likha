<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_whitelist', function (Blueprint $table) {
            $table->id();
            $table->string('phone_number', 20);
            $table->string('reason', 255)->nullable();
            $table->string('host_scope', 20)->default('likha'); // likha | incepxion
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['phone_number', 'host_scope']);
            $table->index('host_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_whitelist');
    }
};
