<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CardStatementLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'sale_date' => 'date:Y-m-d',
        'post_date' => 'date:Y-m-d',
        'is_credit' => 'boolean',
        'is_ads'    => 'boolean',
        'amount'    => 'decimal:2',
    ];
}
