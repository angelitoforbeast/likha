<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Category ng item sa /item (hal. "Bahay at Paglilinis"). CEO ang nagtatakda.
 */
class ItemCategory extends Model
{
    protected $table = 'item_categories';

    protected $fillable = ['name', 'sort_order'];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function assignments(): HasMany
    {
        return $this->hasMany(ItemCategoryAssignment::class, 'category_id');
    }
}
