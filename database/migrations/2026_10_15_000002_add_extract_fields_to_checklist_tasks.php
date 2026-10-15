<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('checklist_tasks', 'extract_fields')) {
            return;
        }

        // Mga value na kinukuha ng AI sa bawat submission: [{key, label, type, hint}]
        Schema::table('checklist_tasks', function (Blueprint $table) {
            $table->json('extract_fields')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('checklist_tasks', function (Blueprint $table) {
            $table->dropColumn('extract_fields');
        });
    }
};
