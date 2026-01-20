<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PancakeId extends Model
{
    protected $table = 'pancake_id';

    protected $fillable = [
        'pancake_page_id',
        'pancake_page_name',
    ];
}
