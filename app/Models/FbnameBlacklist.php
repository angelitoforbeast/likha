<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FbnameBlacklist extends Model
{
    protected $table = 'fbname_blacklist';

    protected $fillable = [
        'fb_name',
        'reason',
        'host_scope',
        'created_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
