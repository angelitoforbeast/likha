<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * AI follow-up ng Marketing head (spec 293): row ng checklist_tasks na origin = 'ai'. Hindi checklist task: ang
 * ChecklistTask model at ang ChecklistDueToday ay hindi ito nakikita, at ito lang ang model na nagbabasa nito.
 *
 * description = ang huling text na makikita ng tao, deadline_time = "HH:MM" ngayong araw, iisang assignee sa
 * checklist_task_users. ai_draft = ang orihinal na isinulat ng AI (message, deadline, model, tokens), hindi binabago.
 * follows_date ay "Y-m-d" na string (walang date cast: sa sqlite ay may oras ang naka-store kapag naka-cast).
 */
class ChecklistAiFollowup extends Model
{
    protected $table = 'checklist_tasks';

    protected $fillable = [
        'title', 'description', 'deadline_time', 'sort_order', 'department',
        'follows_task_id', 'follows_date', 'follows_reason', 'review_status',
        'reviewed_by', 'reviewed_at', 'review_note', 'ai_draft', 'ai_cost_usd', 'is_active',
    ];

    protected $attributes = [
        'origin'          => 'ai',
        'type'            => 'any',
        'frequency'       => 'once',
        'submission_type' => 'individual',
        'is_active'       => false,   // aktibo lang kapag na-approve ng CEO
        'review_status'   => 'draft',
        'sort_order'      => 0,
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'reviewed_at' => 'datetime',
        'ai_draft'    => 'array',
        'ai_cost_usd' => 'float',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('ai_followup', fn (Builder $query) => $query->where($query->qualifyColumn('origin'), 'ai'));
        static::creating(function (self $followup) {
            $followup->origin = 'ai';
        });
    }

    /** Ang iisang tao na makakakita nito. */
    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'checklist_task_users', 'checklist_task_id', 'user_id')->withTimestamps();
    }

    /** Ang task na sinusundan (kahit na-delete na). */
    public function followsTask(): BelongsTo
    {
        return $this->belongsTo(ChecklistTask::class, 'follows_task_id')->withTrashed();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function person(): ?User
    {
        return $this->assignedUsers->first();
    }

    /** Binago ng CEO ang text ng AI bago i-approve. */
    public function wasEdited(): bool
    {
        return $this->review_status === 'approved'
            && trim((string) $this->description) !== trim((string) ($this->ai_draft['message'] ?? ''));
    }
}
