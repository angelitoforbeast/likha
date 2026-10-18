<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Isang mensahe ng chatblast (gaya ng flow sa Botcake): pangalan at mga bersyon ng text na ginagamit ng mga sequence. */
class ChatblastMessage extends Model
{
    protected $fillable = ['name', 'versions', 'log'];

    protected $casts = [
        'versions' => 'array',
        'log'      => 'array',
    ];
}
