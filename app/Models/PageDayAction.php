<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PageDayAction extends Model
{
    protected $table = 'page_day_actions';

    protected $fillable = [
        'page_key', 'ts_date', 'comment', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'ts_date' => 'date',
    ];
}
