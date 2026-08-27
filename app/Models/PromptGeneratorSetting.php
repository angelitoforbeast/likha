<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromptGeneratorSetting extends Model
{
    protected $table = 'prompt_generator_settings';

    protected $fillable = ['key', 'value'];
}
