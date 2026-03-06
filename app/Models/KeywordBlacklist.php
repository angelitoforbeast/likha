<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KeywordBlacklist extends Model
{
    protected $table = 'keyword_blacklist';

    protected $fillable = [
        'keyword',
        'reason',
        'host_scope',
        'created_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
