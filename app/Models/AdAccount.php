<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdAccount extends Model
{
    protected $table = 'ad_accounts';

    protected $fillable = [
        'ad_account_id',
        'name',
    ];
}
