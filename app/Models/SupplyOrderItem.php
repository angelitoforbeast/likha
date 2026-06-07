<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplyOrderItem extends Model
{
    protected $fillable = [
        'supply_order_id', 'item_key', 'item_name',
        'ordered_qty', 'unit_cost', 'received_qty', 'line_total', 'notes',
    ];

    protected $casts = [
        'ordered_qty'  => 'integer',
        'received_qty' => 'integer',
        'unit_cost'    => 'decimal:2',
        'line_total'   => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(SupplyOrder::class, 'supply_order_id');
    }
}
