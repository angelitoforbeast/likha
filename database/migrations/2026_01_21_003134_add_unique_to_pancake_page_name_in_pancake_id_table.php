<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pancake_id', function (Blueprint $table) {
            // Ensure the column exists before adding unique
            if (Schema::hasColumn('pancake_id', 'pancake_page_name')) {
                $table->unique('pancake_page_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pancake_id', function (Blueprint $table) {
            $table->dropUnique(['pancake_page_name']);
        });
    }
};
