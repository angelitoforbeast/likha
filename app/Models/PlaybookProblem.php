<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlaybookProblem extends Model
{
    protected $fillable = [
        'title', 'category', 'severity', 'status',
        'description', 'root_cause', 'solution', 'prevention',
        'times_seen', 'created_by', 'resolved_by', 'resolved_at',
    ];

    protected $casts = [
        'times_seen'  => 'integer',
        'resolved_at' => 'datetime',
    ];

    public function checklist(): HasMany
    {
        return $this->hasMany(PlaybookChecklistItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PlaybookAttachment::class);
    }

    public function recurrences(): HasMany
    {
        return $this->hasMany(PlaybookRecurrence::class)->orderByDesc('occurred_at')->orderByDesc('id');
    }
}
