<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Isang sequence ng chatbot (drip): mga step na may delay, bawat isa ay nagpapatakbo ng published na flow. */
class ChatbotSequence extends Model
{
    public const MAX_STEPS = 50;
    public const START_ORDER = 'order_uploaded'; // starts_on: kusang nagsisimula kapag na-upload ang order ng customer

    /**
     * Ang send_from, send_to at outside_window ay nasa table pa pero hindi na ginagamit: ang step ay sa delay nito, anumang
     * oras, at tinatapos ang subscription (outside 24h) kapag lampas 24 oras mula sa huling mensahe ng customer.
     */
    protected $fillable = ['name', 'description', 'active', 'exit_on_reply', 'starts_on'];

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
