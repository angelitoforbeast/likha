<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PageSenderMapping extends Model
{
    use HasFactory;

    protected $table = 'page_sender_mappings';

    protected $fillable = [
        'PAGE',
        'SENDER_NAME',
    ];
}
