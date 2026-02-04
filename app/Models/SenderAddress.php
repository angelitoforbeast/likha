<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SenderAddress extends Model
{
    protected $table = 'sender_addresses';

    protected $fillable = [
        'jnt_sender_phone',
        'jnt_sender_prov',
        'jnt_sender_city',
        'jnt_sender_area',
        'jnt_sender_address',
    ];
}
