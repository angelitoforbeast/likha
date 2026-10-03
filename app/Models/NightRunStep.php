<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Isang hakbang ng night run (import o Astra) sa isang gabi. Unique (night_date, kind).
 * night_date = 'Y-m-d' string (walang cast) para pareho ang laman sa mysql, pgsql at sqlite.
 */
class NightRunStep extends Model
{
    protected $table = 'night_run_steps';

    protected $fillable = [
        'night_date',
        'kind',
        'state',
        'reason',
        'ref_id',
        'trigger',
        'started_at',
        'finished_at',
        'stop_at',
        'rows_found',
        'rows_over_max',
        'consecutive_failures',
    ];

    protected $casts = [
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
        'stop_at'     => 'datetime',
    ];

    public function rows()
    {
        return $this->hasMany(NightAstraRow::class, 'step_id');
    }
}
