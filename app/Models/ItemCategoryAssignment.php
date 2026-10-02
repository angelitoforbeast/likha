<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Isang category kada item — item_key = base key (tingnan ang App\Support\ItemBaseKey).
 */
class ItemCategoryAssignment extends Model
{
    protected $table = 'item_category_assignments';

    protected $fillable = ['item_key', 'category_id', 'updated_by'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }
}
