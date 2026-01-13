<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pancake_conversations', function (Blueprint $table) {
            $table->id();

            $table->string('pancake_page_id', 191);
            $table->string('full_name', 191);

            // compiled chat (multi rows concatenated with linebreak)
            $table->longText('customers_chat')->nullable();

            $table->timestamps();

            $table->unique(['pancake_page_id', 'full_name'], 'pancake_page_fullname_unique');
            $table->index(['pancake_page_id'], 'pancake_page_id_idx');
            $table->index(['full_name'], 'pancake_full_name_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pancake_conversations');
    }
};
