<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    public function files(): HasMany
    {
        return $this->hasMany(ChecklistSubmissionFile::class)->orderBy('sort_order');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ChecklistSubmissionLog::class)->orderBy('created_at', 'desc');
    }

    public function analysisLogs(): HasMany
    {
        return $this->hasMany(ChecklistAnalysisLog::class, 'submission_id')
            ->where('log_type', 'analysis')
            ->orderBy('created_at', 'desc');
    }

    public function latestAnalysis(): HasOne
    {
        return $this->hasOne(ChecklistAnalysisLog::class, 'submission_id')
            ->ofMany(['created_at' => 'max'], fn($q) => $q->where('log_type', 'analysis'));
    }

    public function approvalLogs(): HasMany
    {
        return $this->hasMany(ChecklistAnalysisLog::class, 'submission_id')
            ->where('log_type', 'approval')
            ->orderBy('created_at', 'desc');
    }

    public function latestApproval(): HasOne
    {
        return $this->hasOne(ChecklistAnalysisLog::class, 'submission_id')
            ->ofMany(['created_at' => 'max'], fn($q) => $q->where('log_type', 'approval'));
    }

    public function isImage(): bool
    {
        return $this->file_mime && str_starts_with($this->file_mime, 'image/');
    }
}
