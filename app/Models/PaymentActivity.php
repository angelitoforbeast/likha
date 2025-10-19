<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentActivity extends Model
{
    protected $table = 'payment_activity_ads_manager';

    protected $fillable = [
        'date',
        'transaction_id',
        'amount',
        'ad_account',
        'payment_method',
        'source_filename',
        'import_batch_id',
        'uploaded_by',
        'uploaded_at',
    ];

    protected $casts = [
        'date' => 'date',
        'uploaded_at' => 'datetime',
    ];
}
