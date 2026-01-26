<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JntShipment extends Model
{
    protected $table = 'jnt_shipments';

    // Allow mass assignment for all columns (simple + reliable for internal tool tables)
    protected $guarded = [];

    protected $casts = [
        'success' => 'boolean',
        'request_payload' => 'array',
        'response_payload' => 'array',
    ];
}
