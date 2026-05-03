<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationTracker extends Model
{
    protected $fillable = [
        'subscription_date',
        'subscription_date_raw',
        'upload_date',
        'upload_date_raw',
        'page_name',
        'name',
        'phone_number',
        'all_cx_details',
        'response_tracker',
        'imported_run_id',
    ];

    protected $casts = [
        'subscription_date' => 'datetime',
        'upload_date'       => 'datetime',
    ];
}
