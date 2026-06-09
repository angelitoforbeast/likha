<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplyPayment extends Model
{
    protected $fillable = [
        'supplier_id', 'supply_order_id', 'amount', 'paid_date',
        'method', 'reference_no', 'receipt_path', 'notes', 'paid_by',
    ];

    protected $casts = [
        'paid_date' => 'date',
        'amount'    => 'decimal:2',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function receipts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SupplyPaymentReceipt::class);
    }
}
