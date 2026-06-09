<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplyPaymentReceipt extends Model
{
    protected $fillable = ['supply_payment_id', 'path'];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SupplyPayment::class, 'supply_payment_id');
    }
}
