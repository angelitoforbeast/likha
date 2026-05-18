<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NavLink extends Model
{
    protected $fillable = [
        'key', 'label', 'route_url', 'icon', 'active_pattern', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function roleVisibility(): HasMany
    {
        return $this->hasMany(NavLinkRoleVisibility::class);
    }

    /**
     * Get the ordered list of nav links visible to a given role. Used by the
     * layout to render the top nav. Returns Eloquent Collection of NavLink models.
     */
    public static function visibleFor(?string $role)
    {
        if (!$role) return collect();

        return static::query()
            ->where('is_active', true)
            ->whereHas('roleVisibility', function ($q) use ($role) {
                $q->where('role', $role)->where('is_visible', true);
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }
}
