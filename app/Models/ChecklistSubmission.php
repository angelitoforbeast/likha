<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecklistSubmission extends Model
{
    protected $fillable = [
        'checklist_task_id', 'user_id', 'date',
        'notes', 'file_path', 'file_original_name', 'file_mime',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(ChecklistTask::class, 'checklist_task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isImage(): bool
    {
        return $this->file_mime && str_starts_with($this->file_mime, 'image/');
    }
}
