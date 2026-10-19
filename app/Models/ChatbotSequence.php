<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Isang sequence ng chatbot (drip): mga step na may delay, bawat isa ay nagpapatakbo ng published na flow. */
class ChatbotSequence extends Model
{
    public const MAX_STEPS = 50;

    protected $fillable = ['name', 'description', 'active', 'exit_on_reply', 'send_from', 'send_to', 'outside_window'];

    protected $casts = ['active' => 'boolean', 'exit_on_reply' => 'boolean'];

    public function steps(): HasMany
    {
        return $this->hasMany(ChatbotSequenceStep::class, 'sequence_id')->orderBy('position');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(ChatbotSequenceSubscription::class, 'sequence_id');
    }
}
