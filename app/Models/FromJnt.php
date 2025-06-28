<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FromJnt extends Model
{
    use HasFactory;

    protected $fillable = [
'submission_time',
'waybill_number',
'receiver',
'receiver_cellphone',
'sender',
'item_name',
'cod',
'remarks',
'status',
'signingtime',
    ];
}
