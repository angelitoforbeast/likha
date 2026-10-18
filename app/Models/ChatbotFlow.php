<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Isang flow ng sariling chatbot: ang graph (nodes at edges) ay iisang JSON (FlowGraph). */
class ChatbotFlow extends Model
{
    protected $fillable = ['name', 'graph', 'updated_by'];

    protected $casts = ['graph' => 'array'];
}
