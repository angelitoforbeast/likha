<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdsManager extends Model
{
    use HasFactory;

    protected $table = 'ads_managers';

    protected $fillable = [
        'reporting_starts',
        'page',
        'amount_spent',
        'cpm',
        'cpi',
    ];
}
