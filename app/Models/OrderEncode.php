<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Ang isang order na ine-encode ng Likha (spec 130): input, resulta, talaan ng mga hakbang at ang paghahambing sa sheet. */
class OrderEncode extends Model
{
    protected $table = 'order_encodes';

    protected $guarded = [];

    protected $casts = [
        'input' => 'array', 'outputs' => 'array', 'steps' => 'array', 'usage' => 'array', 'sheet' => 'array', 'compare' => 'array', 'final_compare' => 'array',
        'compare_equal' => 'boolean', 'encoded_at' => 'datetime', 'compared_at' => 'datetime',
    ];
}
