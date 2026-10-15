<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('checklist_task_changes')) {
            return;
        }

        // Kasaysayan ng bawat pagbago sa checklist task: sino, kailan, ano ang dati at ang bago.
        // Walang cascade: dapat manatili ang kasaysayan kahit mabura ang task o ang user.
        Schema::create('checklist_task_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_task_id')->nullable()->constrained('checklist_tasks')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->json('changes')->nullable();   // {field: [before, after]}
            $table->timestamp('created_at')->nullable();

            $table->index(['checklist_task_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_task_changes');
    }
};
