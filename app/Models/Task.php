<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $fillable = [
    'user_id',
    'name',
    'role_target',
    'task_name',
    'description',
    'type',
    'is_repeating',
    'priority_level',
    'due_date',
    'status',
    'is_notified',
    'completed_at',
    'remarks_assigned',
    'remarks_created_by',
    'created_by',
];

}

