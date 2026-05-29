<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CancelDetectorSetting extends Model
{
    protected $fillable = [
        'sheet_url',
        'sheet_id',
        'spreadsheet_title',
        'selected_sheet_name',
        'range',
        'is_archived',
    ];

    protected $casts = [
        'is_archived' => 'boolean',
    ];
}
