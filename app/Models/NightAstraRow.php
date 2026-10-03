<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Isang order sa Astra night run. Unique (step_id, macro_output_id).
 */
class NightAstraRow extends Model
{
    protected $table = 'night_astra_rows';

    protected $fillable = [
        'step_id',
        'macro_output_id',
        'state',
        'attempts',
        'code',
        'proceed',
        'reason',
        'log_id',
        'cost_usd',
        'duration_ms',
        'dispatched_at',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'proceed'       => 'boolean',
        'dispatched_at' => 'datetime',
        'started_at'    => 'datetime',
        'finished_at'   => 'datetime',
    ];

    public function step()
    {
        return $this->belongsTo(NightRunStep::class, 'step_id');
    }
}
