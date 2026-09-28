<?php

namespace App\Models\Boardroom;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Isang record sa resources registry: channel, group chat, website, page, contact, o link. */
class ResourceEntry extends Model
{
    protected $table = 'br_resources';

    public const TYPES  = ['channel', 'group_chat', 'website', 'page', 'contact', 'link', 'other'];
    public const FIELDS = ['type', 'name', 'purpose', 'tags', 'location', 'parent_id', 'details', 'holder', 'project_id'];

    protected $fillable = [
        'user_id', 'project_id', 'type', 'name', 'name_key', 'purpose', 'tags', 'location', 'parent_id',
        'details', 'holder', 'status', 'source', 'agent_id', 'meeting_id', 'message_id',
    ];

    protected $casts = ['tags' => 'array'];

    protected static function booted(): void
    {
        static::saving(function (ResourceEntry $r) {
            $r->name_key = static::key((string) $r->name);
        });
    }

    /** Normalized na pangalan: maliit na titik, walang bantas, iisang espasyo. */
    public static function key(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $name) ?? $name;

        return mb_substr(trim(preg_replace('/\s+/', ' ', $name) ?? $name), 0, 200);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(PaymentAccount::class, 'resource_id');
    }

    /** Ang mga field na itinatala sa kasaysayan at ibinabalik ng undo. */
    public function snapshot(): array
    {
        return $this->only(array_merge(self::FIELDS, ['status']));
    }
}
