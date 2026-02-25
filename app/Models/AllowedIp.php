<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AllowedIp extends Model
{
    protected $fillable = ['ip_address', 'label', 'created_by'];

    public function creator()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}
