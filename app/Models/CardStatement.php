<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CardStatement extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'period_start' => 'date',
        'period_end'   => 'date',
        'matched_at'   => 'datetime',
    ];

    public function lines()
    {
        return $this->hasMany(CardStatementLine::class)->orderBy('line_no');
    }
}
