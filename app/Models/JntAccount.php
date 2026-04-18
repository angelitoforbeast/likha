<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JntAccount extends Model
{
    protected $table = 'jnt_accounts';
    protected $guarded = [];

    protected $casts = [
        'force_uppercase'        => 'boolean',
        'use_item_sender_mapping'=> 'boolean',
        'pickup_days_offset'     => 'integer',
    ];

    public function pageMappings(): HasMany
    {
        return $this->hasMany(PageJntMapping::class, 'jnt_account_id');
    }
}
