<?php

namespace App\Models\Boardroom;

use Illuminate\Database\Eloquent\Model;

class Decision extends Model
{
    protected $table = 'br_decisions';

    protected $fillable = ['project_id', 'meeting_id', 'title', 'detail', 'status', 'decided_by', 'decided_at'];

    protected $casts = ['decided_at' => 'datetime'];
}
