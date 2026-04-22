<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PageItemSetting extends Model
{
    protected $table = 'page_item_settings';

    protected $fillable = [
        'page_name',
        'item_name',
        'price',
        'rts_pct',
        'effective_date',
    ];

    protected $casts = [
        'price'          => 'decimal:2',
        'rts_pct'        => 'decimal:2',
        'effective_date' => 'date',
    ];

    /**
     * Get the latest setting for a page+item as of a given date.
     */
    public static function getFor(string $pageName, string $itemName, string $date): ?self
    {
        return static::where('page_name', $pageName)
            ->where('item_name', $itemName)
            ->where('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->first();
    }
}
