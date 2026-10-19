<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Isang customer (page + PSID) sa isang sequence: ang susunod na step at kailan ito ipapadala. */
class ChatbotSequenceSubscription extends Model
{
    public const STATUSES = ['active', 'paused', 'completed', 'unsubscribed', 'error'];

    protected $fillable = ['sequence_id', 'page_id', 'psid', 'status', 'current_step', 'next_send_at', 'dispatched_at', 'last_sent_at',
        'enrolled_via', 'enrolled_by', 'exit_reason'];

    protected $casts = ['page_id' => 'integer', 'psid' => 'integer', 'current_step' => 'integer', 'next_send_at' => 'datetime',
        'dispatched_at' => 'datetime', 'last_sent_at' => 'datetime'];

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(ChatbotSequence::class, 'sequence_id');
    }
}
