<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_jnt_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('page', 255)->unique();
            $table->foreignId('jnt_account_id')
                  ->nullable()
                  ->constrained('jnt_accounts')
                  ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_jnt_mappings');
    }
};
