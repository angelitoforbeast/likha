<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdAccount extends Model
{
    protected $table = 'ad_accounts';

    protected $fillable = [
        'ad_account_id',
        'name',
        'created_by',
        'active',
        'replaced_at',
        'replaced_by',
        'action',
    ];

    protected $casts = [
        'active'      => 'boolean',
        'created_by'  => 'integer',
        'replaced_by' => 'integer',
        'replaced_at' => 'datetime',
    ];
}
