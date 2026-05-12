<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChecklistTask extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title', 'description', 'type', 'is_active', 'sort_order',
        'scheduled_time', 'submission_type', 'ai_prompt', 'approval_prompt',
        'required_photos', 'frequency', 'frequency_day', 'department', 'created_by',
    ];

    protected $casts = [
        'is_active'       => 'boolean',
        'required_photos' => 'integer',
        'frequency_day'   => 'integer',
    ];

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
