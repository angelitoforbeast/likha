<?php

namespace App\Models\Boardroom;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Turn extends Model
{
    protected $table = 'br_turns';

    public const PENDING = ['queued', 'generating', 'paused'];

    protected $fillable = [
        'uuid', 'meeting_id', 'round_id', 'agent_id', 'purpose', 'phase', 'cycle', 'seq', 'dedupe_key', 'status', 'attempts',
        'requests', 'reserved', 'parent_turn_id', 'issue_id', 'reply_to_message_id', 'context_hash', 'provider', 'model',
        'request_meta', 'outcome', 'provider_response_id', 'finish', 'tokens_in', 'tokens_out', 'tokens_reasoning',
        'est_cost_usd', 'error_code', 'error_message', 'retryable', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'request_meta' => 'array',
        'outcome'      => 'array',
        'retryable'    => 'boolean',
        'reserved'     => 'boolean',
        'started_at'   => 'datetime',
        'finished_at'  => 'datetime',
    ];

    /** Turn ba ito ng tanong ng user? (hiwalay ang limit nito sa limit ng meeting) */
    public function isQuestion(): bool
    {
        return $this->round_id !== null || $this->purpose === 'direct';
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class, 'meeting_id');
    }

    public function message(): HasOne
    {
        return $this->hasOne(Message::class, 'turn_id');
    }
}
