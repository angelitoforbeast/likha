<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotcakePsidImportRun extends Model
{
    protected $fillable = [
        'status',
        'cutoff_datetime',
        'current_setting_id',
        'current_gsheet_name',
        'current_sheet_name',
        'k1_value',
        'seed_row',
        'selected_start_row',
        'batch_no',
        'batch_start_row',
        'batch_end_row',
        'next_scan_from',
        'total_imported',
        'total_not_existing',
        'total_skipped',
        'last_message',
        'last_error',
    ];

    public function events()
    {
        return $this->hasMany(BotcakePsidImportEvent::class, 'run_id');
    }
}
