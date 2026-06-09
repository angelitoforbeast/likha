<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaybookAttachment extends Model
{
    protected $fillable = ['playbook_problem_id', 'path'];

    public function problem(): BelongsTo
    {
        return $this->belongsTo(PlaybookProblem::class, 'playbook_problem_id');
    }
}
