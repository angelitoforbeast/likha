<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromptGeneration extends Model
{
    protected $fillable = [
        'mode',
        'model',
        'store_name',
        'product_name',
        'inputs',
        'output',
        'user_id',
        'user_name',
    ];

    protected $casts = [
        'inputs' => 'array',
    ];
}
