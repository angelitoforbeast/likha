<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Isang step ng sequence: pagkatapos ng delay, pinapatakbo ang published na flow mula sa Start nito. */
class ChatbotSequenceStep extends Model
{
    public const UNITS = ['minutes', 'hours', 'days'];

    protected $fillable = ['sequence_id', 'position', 'delay_amount', 'delay_unit', 'flow_id', 'active'];

    protected $casts = ['position' => 'integer', 'delay_amount' => 'integer', 'flow_id' => 'integer', 'active' => 'boolean'];

    /** Anumang sulat o bura: ang naka-cache na ayos ng bot (BotConfigCache) ay binubura. */
    protected static function booted(): void
    {
        static::saved(fn (self $m) => \App\Services\Chatbot\BotConfigCache::forgetSequence((int) $m->sequence_id));
        static::deleted(fn (self $m) => \App\Services\Chatbot\BotConfigCache::forgetSequence((int) $m->sequence_id));
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(ChatbotFlow::class, 'flow_id');
    }
}
