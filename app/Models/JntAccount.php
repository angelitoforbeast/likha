<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JntAccount extends Model
{
    protected $table = 'jnt_accounts';
    protected $guarded = [];

    public function pageMappings(): HasMany
    {
        return $this->hasMany(PageJntMapping::class, 'jnt_account_id');
    }
}
