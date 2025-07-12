<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MacroGsheetSetting extends Model
{
    protected $fillable = ['sheet_url', 'sheet_range'];
}
