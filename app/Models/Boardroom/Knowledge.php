<?php

namespace App\Models\Boardroom;

use Illuminate\Database\Eloquent\Model;

class Knowledge extends Model
{
    protected $table = 'br_knowledge';

    protected $fillable = ['user_id', 'project_id', 'title', 'body', 'approved', 'approved_at'];

    protected $casts = ['approved' => 'boolean', 'approved_at' => 'datetime'];
}
