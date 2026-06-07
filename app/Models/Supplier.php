<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = [
        'name', 'contact', 'terms',
        'opening_balance', 'opening_balance_note',
        'notes', 'created_by',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(SupplyOrder::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplyPayment::class);
    }
}
