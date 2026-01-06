<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LikhaOrderSetting extends Model
{
    protected $fillable = ['sheet_url', 'spreadsheet_title', 'sheet_id', 'range'];

}
