<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageJntMapping extends Model
{
    protected $table = 'page_jnt_mappings';
    protected $guarded = [];

    public function account(): BelongsTo
    {
        return $this->belongsTo(JntAccount::class, 'jnt_account_id');
    }
}
