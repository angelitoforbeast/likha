<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaybookRecurrence extends Model
{
    protected $fillable = ['playbook_problem_id', 'occurred_at', 'note', 'logged_by'];

    protected $casts = ['occurred_at' => 'date'];

    public function problem(): BelongsTo
    {
        return $this->belongsTo(PlaybookProblem::class, 'playbook_problem_id');
    }
}
