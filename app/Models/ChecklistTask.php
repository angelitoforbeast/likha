<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChecklistTask extends Model
{
    protected $fillable = ['title', 'description', 'type', 'is_active', 'sort_order'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function submissions(): HasMany
    {
        return $this->hasMany(ChecklistSubmission::class);
    }
}
