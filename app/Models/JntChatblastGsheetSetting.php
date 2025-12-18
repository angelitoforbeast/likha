<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JntChatblastGsheetSetting extends Model
{
    protected $table = 'jnt_chatblast_gsheet_settings';

    protected $fillable = [
        'gsheet_name',
        'sheet_url',
        'sheet_range',
    ];
}
