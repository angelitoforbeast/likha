<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Playbook — "Problem & Solution" knowledge base. Magre-register ng problema
 * (base sa experience) + solution/fix + fix checklist. Reusable reference kapag
 * nangyari ulit. May recurrence tracking + screenshot attachments + search.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('playbook_problems')) {
            Schema::create('playbook_problems', function (Blueprint $table) {
                $table->id();
                $table->string('title')->index();
                $table->string('category')->nullable()->index();   // Ads/CPP, RTS, Item, etc.
                $table->string('severity', 20)->default('medium');  // low|medium|high|critical
                $table->string('status', 20)->default('open');      // open|resolved|recurring
                $table->text('description')->nullable();            // ang problema
                $table->text('root_cause')->nullable();             // bakit nangyari
                $table->text('solution')->nullable();               // ang fix
                $table->text('prevention')->nullable();             // paano maiwasan
                $table->unsignedInteger('times_seen')->default(1);  // recurrence counter
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('resolved_by')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('playbook_checklist_items')) {
            Schema::create('playbook_checklist_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('playbook_problem_id')->constrained('playbook_problems')->cascadeOnDelete();
                $table->string('label');
                $table->boolean('is_done')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('playbook_attachments')) {
            Schema::create('playbook_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('playbook_problem_id')->constrained('playbook_problems')->cascadeOnDelete();
                $table->string('path');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('playbook_recurrences')) {
            Schema::create('playbook_recurrences', function (Blueprint $table) {
                $table->id();
                $table->foreignId('playbook_problem_id')->constrained('playbook_problems')->cascadeOnDelete();
                $table->date('occurred_at');
                $table->text('note')->nullable();
                $table->unsignedBigInteger('logged_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('playbook_recurrences');
        Schema::dropIfExists('playbook_attachments');
        Schema::dropIfExists('playbook_checklist_items');
        Schema::dropIfExists('playbook_problems');
    }
};
