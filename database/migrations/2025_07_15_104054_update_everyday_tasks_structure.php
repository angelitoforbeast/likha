<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateEverydayTasksStructure extends Migration
{
    public function up()
    {
        Schema::table('everyday_tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('everyday_tasks', 'name')) {
                $table->string('name')->after('user_id')->nullable();
            }

            if (!Schema::hasColumn('everyday_tasks', 'role_target')) {
                $table->string('role_target')->after('name')->nullable();
            }

            if (!Schema::hasColumn('everyday_tasks', 'collaborators')) {
                $table->text('collaborators')->after('role_target')->nullable();
            }

            if (!Schema::hasColumn('everyday_tasks', 'type')) {
                $table->string('type')->after('description')->nullable();
            }

            if (!Schema::hasColumn('everyday_tasks', 'is_repeating')) {
                $table->boolean('is_repeating')->after('type')->default(false);
            }

            if (!Schema::hasColumn('everyday_tasks', 'priority_score')) {
                $table->integer('priority_score')->after('is_repeating')->nullable();
            }

            if (!Schema::hasColumn('everyday_tasks', 'due_time')) {
                $table->time('due_time')->after('priority_score')->nullable();
            }

            if (!Schema::hasColumn('everyday_tasks', 'created_by')) {
                $table->unsignedBigInteger('created_by')->after('due_time')->nullable();
            }

            if (!Schema::hasColumn('everyday_tasks', 'reminder_at')) {
                $table->timestamp('reminder_at')->nullable()->after('due_time');
            }

            if (!Schema::hasColumn('everyday_tasks', 'status')) {
                $table->string('status')->nullable()->after('reminder_at');
            }

            if (!Schema::hasColumn('everyday_tasks', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('status');
            }

            if (!Schema::hasColumn('everyday_tasks', 'is_notified')) {
                $table->boolean('is_notified')->default(false)->after('completed_at');
            }

            if (!Schema::hasColumn('everyday_tasks', 'review_status')) {
                $table->string('review_status')->nullable()->after('is_notified');
            }

            if (!Schema::hasColumn('everyday_tasks', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('review_status');
            }

            if (!Schema::hasColumn('everyday_tasks', 'creator_remarks')) {
                $table->text('creator_remarks')->nullable()->after('reviewed_at');
            }

            if (!Schema::hasColumn('everyday_tasks', 'remarks_created_by')) {
                $table->unsignedBigInteger('remarks_created_by')->nullable()->after('creator_remarks');
            }

            if (!Schema::hasColumn('everyday_tasks', 'assignee_remarks')) {
                $table->text('assignee_remarks')->nullable()->after('remarks_created_by');
            }

            if (!Schema::hasColumn('everyday_tasks', 'remarks')) {
                $table->text('remarks')->nullable()->after('assignee_remarks');
            }

            if (!Schema::hasColumn('everyday_tasks', 'parent_task_id')) {
                $table->unsignedBigInteger('parent_task_id')->nullable()->after('remarks');
            }
        });
    }

    public function down()
    {
        Schema::table('everyday_tasks', function (Blueprint $table) {
            $columnsToDrop = [
                'name', 'role_target', 'collaborators', 'type', 'is_repeating',
                'priority_score', 'due_time', 'created_by', 'reminder_at',
                'status', 'completed_at', 'is_notified', 'review_status',
                'reviewed_at', 'creator_remarks', 'remarks_created_by',
                'assignee_remarks', 'remarks', 'parent_task_id',
            ];

            foreach ($columnsToDrop as $column) {
                if (Schema::hasColumn('everyday_tasks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
}
