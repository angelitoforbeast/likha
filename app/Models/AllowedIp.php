<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AllowedIp extends Model
{
    protected $fillable = ['ip_address', 'label'];
}
