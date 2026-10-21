<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('checklist_tasks', 'deadline_time')) {
            return;
        }

        // Oras na late na ang task kapag wala pang submission; blangko = scheduled_time + 15 minuto
        Schema::table('checklist_tasks', function (Blueprint $table) {
            $table->string('deadline_time')->nullable()->after('scheduled_time');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('checklist_tasks', 'deadline_time')) {
            return;
        }

        Schema::table('checklist_tasks', function (Blueprint $table) {
            $table->dropColumn('deadline_time');
        });
    }
};
