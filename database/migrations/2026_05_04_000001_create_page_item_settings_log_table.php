<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_item_settings_log', function (Blueprint $t) {
            $t->id();
            $t->string('user_email', 255)->nullable()->index();
            // Action: 'create' (no prior row), 'update' (replaced existing)
            $t->string('action', 20)->index();
            $t->string('page_name', 255)->index();
            $t->string('item_name', 255)->index();
            $t->date('effective_date')->index();
            // Captured values (nullable since either field can be empty)
            $t->decimal('old_rts_pct',     5, 2)->nullable();
            $t->decimal('new_rts_pct',     5, 2)->nullable();
            $t->decimal('old_item_value', 18, 2)->nullable();
            $t->decimal('new_item_value', 18, 2)->nullable();
            $t->string('comment', 500)->nullable();
            $t->string('item_value_comment', 500)->nullable();
            $t->timestamps();
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_item_settings_log');
    }
};
