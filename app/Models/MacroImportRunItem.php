<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MacroImportRunItem extends Model
{
    protected $table = 'macro_import_run_items';

    protected $fillable = [
        'run_id',
        'setting_id',
        'gsheet_name',
        'sheet_url',
        'sheet_range',
        'status',
        'processed',
        'inserted',
        'updated',
        'skipped',
        'message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function run()
    {
        return $this->belongsTo(MacroImportRun::class, 'run_id');
    }
}
