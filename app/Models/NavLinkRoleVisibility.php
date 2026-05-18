<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NavLinkRoleVisibility extends Model
{
    protected $table = 'nav_link_role_visibility';

    protected $fillable = [
        'nav_link_id', 'role', 'is_visible',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
    ];

    public function navLink(): BelongsTo
    {
        return $this->belongsTo(NavLink::class);
    }
}
