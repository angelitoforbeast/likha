<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplyExcludedPage extends Model
{
    protected $table = 'supply_excluded_pages';

    protected $fillable = [
        'page_name',
        'reason',
    ];

    /**
     * Returns an array of excluded page_names (lowercased for case-insensitive match).
     */
    public static function excludedSet(): array
    {
        return static::query()
            ->pluck('page_name')
            ->map(fn($p) => mb_strtolower(trim((string) $p)))
            ->all();
    }
}
