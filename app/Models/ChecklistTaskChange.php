<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Isang pagbago sa checklist task; changes = {field: [before, after]}. */
class ChecklistTaskChange extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['checklist_task_id', 'user_id', 'action', 'changes'];

    protected $casts = [
        'changes' => 'array',
    ];

    public const ACTION_LABELS = [
        'created'              => 'Created',
        'updated'              => 'Edited',
        'deleted'              => 'Deleted',
        'duplicated'           => 'Created as a copy',
        'activated'            => 'Enabled',
        'deactivated'          => 'Disabled',
        'reordered'            => 'Reordered',
        'bulk_assigned'        => 'Bulk assign',
        'bulk_type'            => 'Bulk type',
        'bulk_submission_type' => 'Bulk submission type',
        'fields_changed'       => 'Fields to extract',
    ];

    public const FIELD_LABELS = [
        'title'           => 'Title',
        'description'     => 'Description',
        'type'            => 'Type',
        'is_active'       => 'Active',
        'scheduled_time'  => 'Time',
        'submission_type' => 'Submission',
        'ai_prompt'       => 'AI prompt',
        'approval_prompt' => 'Approval prompt',
        'required_photos' => 'Required photos',
        'frequency'       => 'Frequency',
        'frequency_day'   => 'Frequency day',
        'department'      => 'Department',
        'assigned_users'  => 'Assigned to',
        'sort_order'      => 'Order',
        'deleted'         => 'Deleted',
        'copy_of'         => 'Copy of',
        'extract_fields'  => 'Fields to extract',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(ChecklistTask::class, 'checklist_task_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
