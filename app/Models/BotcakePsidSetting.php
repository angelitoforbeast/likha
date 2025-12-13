<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotcakePsidSetting extends Model
{
    protected $table = 'botcake_psid_settings';

    protected $fillable = [
        'gsheet_name',
        'sheet_url',
        'sheet_range',
    ];
}
