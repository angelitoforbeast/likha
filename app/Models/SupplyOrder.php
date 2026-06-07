<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplyOrder extends Model
{
    protected $fillable = [
        'supplier_id', 'order_no', 'order_date', 'expected_delivery',
        'status', 'total_cost', 'notes', 'created_by',
        'delivered_at', 'counted_at',
    ];

    protected $casts = [
        'order_date'        => 'date',
        'expected_delivery' => 'date',
        'delivered_at'      => 'datetime',
        'counted_at'        => 'datetime',
        'total_cost'        => 'decimal:2',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplyOrderItem::class);
    }
}
