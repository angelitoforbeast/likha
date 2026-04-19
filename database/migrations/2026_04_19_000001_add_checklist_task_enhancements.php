<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_tasks', function (Blueprint $table) {
            $table->unsignedTinyInteger('required_photos')->default(0)->after('approval_prompt');
            $table->string('frequency')->default('daily')->after('required_photos'); // daily|weekly|monthly|once
            $table->unsignedTinyInteger('frequency_day')->nullable()->after('frequency'); // 0-6 weekly, 1-31 monthly
            $table->string('department')->nullable()->after('frequency_day');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()->after('department');
        });
    }

    public function down(): void
    {
        Schema::table('checklist_tasks', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropColumn(['required_photos', 'frequency', 'frequency_day', 'department', 'created_by']);
        });
    }
};
