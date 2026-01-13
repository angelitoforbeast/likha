<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PancakeConversation extends Model
{
    protected $table = 'pancake_conversations';

    protected $fillable = [
        'pancake_page_id',
        'full_name',
        'customers_chat',
    ];
}
