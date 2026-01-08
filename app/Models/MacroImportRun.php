<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MacroImportRun extends Model
{
    protected $table = 'macro_import_runs';

    protected $fillable = [
        'started_by',
        'status',
        'started_at',
        'finished_at',
        'total_settings',
        'processed_settings',
        'total_processed',
        'total_inserted',
        'total_updated',
        'total_skipped',
        'message',
    ];

    protected $casts = [
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(MacroImportRunItem::class, 'run_id');
    }
}
