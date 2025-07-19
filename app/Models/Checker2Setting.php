<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Checker2Setting extends Model
{
    protected $table = 'checker_2_settings';

    protected $fillable = [
        'sheet_name',
        'sheet_url',
        'sheet_range',
    ];
}
