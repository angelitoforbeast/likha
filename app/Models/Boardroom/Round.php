<?php

namespace App\Models\Boardroom;

use Illuminate\Database\Eloquent\Model;

/** Isang "question round": lahat ng sagot na nagmula sa ISANG tanong ng user. May sariling limit. */
class Round extends Model
{
    protected $table = 'br_rounds';

    public const MAX_CYCLES = 3;

    protected $fillable = [
        'meeting_id', 'message_id', 'mode', 'max_cycles', 'cycle', 'phase', 'status', 'agent_ids', 'calls_used',
    ];

    protected $casts = ['agent_ids' => 'array'];
}
