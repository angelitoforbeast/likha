<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotcakePsidImportEvent extends Model
{
    protected $fillable = [
        'run_id',
        'type',
        'setting_id',
        'gsheet_name',
        'sheet_name',
        'batch_no',
        'start_row',
        'end_row',
        'rows_in_batch',
        'imported',
        'not_existing',
        'skipped',
        'message',
    ];

    public function run()
    {
        return $this->belongsTo(BotcakePsidImportRun::class, 'run_id');
    }
}
