<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GsheetGroup extends Model
{
    protected $fillable = [
        'name',
        'likha_url',
        'macro_url',
        'after_url',
        'sort_order',
    ];
}
