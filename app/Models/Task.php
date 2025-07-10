<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'role_target',
        'collaborators',
        'task_name',
        'description',
        'type',
        'is_repeating',
        'priority_score',
        'due_date',
        'due_time',
        'reminder_at',
        'status',
        'completed_at',
        'is_notified',
        'review_status',
        'reviewed_at',
        'creator_remarks',
        'remarks_created_by',
        'assignee_remarks',
        'remarks',
        'created_by',
        'parent_task_id',
    ];

    public function creator()
{
    return $this->belongsTo(User::class, 'created_by');
}

}
