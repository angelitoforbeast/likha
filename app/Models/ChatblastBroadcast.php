<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A /chatblast broadcast (spec 263): one message to a chosen audience of PSIDs. It is a chatblast_campaigns row with
 * mode "broadcast" (hidden from every sequence query by ChatblastCampaign's scope); the filters, flow, cap, state and
 * per-page facts are in the `broadcast` JSON column. The sends are botcake_messages rows with kind "broadcast" and
 * campaign_id = this id.
 */
class ChatblastBroadcast extends Model
{
    public const MODE = 'broadcast';

    protected $table = 'chatblast_campaigns';

    protected $fillable = ['kind', 'name', 'statuses', 'pages', 'window_days', 'days', 'mode', 'active', 'log', 'broadcast'];

    protected $casts = [
        'statuses'  => 'array',
        'pages'     => 'array',
        'days'      => 'array',
        'active'    => 'boolean',
        'log'       => 'array',
        'broadcast' => 'array',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('broadcast', fn (Builder $q) => $q->where('mode', self::MODE));
        static::creating(function (ChatblastBroadcast $b) {
            $b->mode = self::MODE;
            $b->active = false; // never picked up by the sequence build, auto or sending pages
            $b->statuses ??= [];
            $b->window_days ??= 0;
        });
    }

    /** The content: the one text that goes into field.message. */
    public function text(): string
    {
        return (string) (($this->days ?? [])[0][0] ?? '');
    }

    public function state(): string
    {
        return (string) ($this->broadcast['state'] ?? 'draft');
    }
}
