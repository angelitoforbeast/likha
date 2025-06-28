<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LikhaOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'date',
        'page_name',
        'name',
        'phone_number',
        'all_user_input',
        'shop_details',
        'extracted_details',
        'price',
    ];
}
