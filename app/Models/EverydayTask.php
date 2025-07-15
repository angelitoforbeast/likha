<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EverydayTask extends Model
{
    protected $table = 'everyday_tasks';

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
        'due_time',
        'created_by',
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
        'parent_task_id',
    ];

    protected $casts = [
        'is_repeating' => 'boolean',
        'is_notified' => 'boolean',
        'due_time' => 'datetime:H:i:s',
        'reminder_at' => 'datetime',
        'completed_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    // Relationships example: creator user
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
