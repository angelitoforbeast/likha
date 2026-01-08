<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MacroImportRun extends Model
{
    protected $fillable = [
        'status','processed','inserted','updated','marked',
        'current_setting','message','last_error','started_at','finished_at'
    ];
}
