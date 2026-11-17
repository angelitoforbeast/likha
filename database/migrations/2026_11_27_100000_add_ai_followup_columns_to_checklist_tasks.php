<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI follow-ups ng Marketing head (spec 293): row ng checklist_tasks na origin = 'ai', isang assignee, frequency once,
 * hindi aktibo hanggang ma-approve ng CEO. Hindi checklist item: ang ChecklistTask model at ang ChecklistDueToday ay
 * hindi kailanman nagbabasa ng origin = 'ai'. Bawat column ay may sariling guard, kaya ligtas ulitin; sqlite-safe.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'origin', 'follows_task_id', 'follows_date', 'follows_reason', 'review_status',
        'reviewed_by', 'reviewed_at', 'review_note', 'ai_draft', 'ai_cost_usd',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('checklist_tasks')) {
            return;
        }

        $add = [
            'origin'          => fn (Blueprint $t) => $t->string('origin', 16)->nullable(),           // null = karaniwang task; 'ai' = follow-up
            'follows_task_id' => fn (Blueprint $t) => $t->unsignedBigInteger('follows_task_id')->nullable(), // ang task na sinusundan
            'follows_date'    => fn (Blueprint $t) => $t->date('follows_date')->nullable(),            // araw ng task na sinusundan (Manila)
            'follows_reason'  => fn (Blueprint $t) => $t->string('follows_reason', 16)->nullable(),    // late / not_approved
            'review_status'   => fn (Blueprint $t) => $t->string('review_status', 16)->nullable(),     // draft / approved / rejected / expired
            'reviewed_by'     => fn (Blueprint $t) => $t->unsignedBigInteger('reviewed_by')->nullable(),
            'reviewed_at'     => fn (Blueprint $t) => $t->timestamp('reviewed_at')->nullable(),
            'review_note'     => fn (Blueprint $t) => $t->text('review_note')->nullable(),             // dahilan ng reject
            'ai_draft'        => fn (Blueprint $t) => $t->json('ai_draft')->nullable(),                 // orihinal na draft, model, tokens
            'ai_cost_usd'     => fn (Blueprint $t) => $t->decimal('ai_cost_usd', 12, 6)->nullable(),
        ];
        foreach ($add as $column => $define) {
            if (!Schema::hasColumn('checklist_tasks', $column)) {
                Schema::table('checklist_tasks', fn (Blueprint $t) => $define($t));
            }
        }

        // Ang command at ang page ay laging naghahanap ayon sa origin at araw
        if (!Schema::hasIndex('checklist_tasks', 'checklist_tasks_origin_follows_date_index')) {
            Schema::table('checklist_tasks', fn (Blueprint $t) => $t->index(['origin', 'follows_date']));
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('checklist_tasks')) {
            return;
        }
        if (Schema::hasIndex('checklist_tasks', 'checklist_tasks_origin_follows_date_index')) {
            Schema::table('checklist_tasks', fn (Blueprint $t) => $t->dropIndex(['origin', 'follows_date']));
        }
        foreach (self::COLUMNS as $column) {
            if (Schema::hasColumn('checklist_tasks', $column)) {
                Schema::table('checklist_tasks', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
