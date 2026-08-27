<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromptGeneratorPromptVersion extends Model
{
    protected $table = 'prompt_generator_prompt_versions';

    public $timestamps = false;

    protected $fillable = ['prompt_key', 'content', 'user_id', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];
}
