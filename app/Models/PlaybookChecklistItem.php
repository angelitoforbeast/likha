<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaybookChecklistItem extends Model
{
    protected $fillable = ['playbook_problem_id', 'label', 'is_done', 'sort_order'];

    protected $casts = ['is_done' => 'boolean', 'sort_order' => 'integer'];

    public function problem(): BelongsTo
    {
        return $this->belongsTo(PlaybookProblem::class, 'playbook_problem_id');
    }
}
