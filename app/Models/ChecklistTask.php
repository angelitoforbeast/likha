<?php

namespace App\Models;

use App\Services\ChecklistDueToday;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChecklistTask extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title', 'description', 'type', 'is_active', 'sort_order',
        'scheduled_time', 'deadline_time', 'submission_type', 'ai_prompt', 'approval_prompt',
        'required_photos', 'frequency', 'frequency_day', 'department', 'created_by', 'extract_fields',
    ];

    protected $casts = [
        'is_active'       => 'boolean',
        'required_photos' => 'integer',
        'frequency_day'   => 'integer',
        'extract_fields'  => 'array',   // [{key, label, type, hint}] na kinukuha ng AI sa bawat submission
    ];

    /**
     * Ang AI follow-up (origin = 'ai', spec 293) ay naka-store din sa checklist_tasks pero hindi checklist task:
     * hindi ito lumalabas sa kahit anong listahan, bilang, report o route binding na dumadaan sa model na ito.
     * Ang ChecklistAiFollowup lang ang nagbabasa nito. Walang filter hangga't wala pa ang column (bago ang migration).
     */
    protected static function booted(): void
    {
        static::addGlobalScope('not_ai_followup', function (Builder $query) {
            if (ChecklistDueToday::schema()['origin']) {
                $origin = $query->qualifyColumn('origin');
                $query->where(fn ($q) => $q->whereNull($origin)->orWhere($origin, '!=', 'ai'));
            }
        });
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(ChecklistSubmission::class);
    }

    public function assignedUsers(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'checklist_task_users');
    }

    /**
     * Who created this task. Nullable for legacy rows na walang created_by
     * stamp. Used sa /checklist/manage table para makita kung sino nag-add.
     */
    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
