<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AstraAttachment extends Model
{
    protected $table = 'astra_attachments';

    protected $fillable = [
        'user_id', 'conversation_id', 'message_id', 'path', 'mime', 'size', 'original_name',
    ];

    protected $casts = [
        'size' => 'integer',
    ];
}
