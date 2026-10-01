<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quote/presyo ng isang supplier para sa isang item — "nakahanap na ako ng supplier" kahit wala pang PO.
 * Ipinapakita sa /item/photo (Suppliers column, CEO lang). Hiwalay sa supply_orders (PO).
 */
class ItemSupplierQuote extends Model
{
    protected $table = 'item_supplier_quotes';

    protected $fillable = ['item_key', 'item_name', 'supplier_id', 'price', 'moq', 'link', 'note', 'photo_path', 'updated_by'];

    protected $casts = [
        'price' => 'decimal:2',
        'moq'   => 'integer',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** "1 x HAND GRIP" → "hand grip" — same normalization ng /item supplier map (JS supKey). */
    public static function keyFor(string $name): string
    {
        $n = preg_replace('/^\s*\d+\s*[x×]\s*/iu', '', trim($name)) ?? trim($name);
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $n) ?? $n), 'UTF-8');
    }
}
