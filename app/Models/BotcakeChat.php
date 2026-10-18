<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/** Isang mensahe ng customer mula sa JSON API block ng Botcake (/api/botcake/chat). */
class BotcakeChat extends Model
{
    public const KEEP_DAYS = 90;

    public $timestamps = false;

    protected $fillable = ['page_id', 'page_name', 'psid', 'name', 'message', 'message_id', 'payload', 'received_at'];

    protected $casts = [
        'payload'     => 'array',
        'received_at' => 'datetime',
    ];

    /** Binubura ang mas luma sa 90 araw; ilang row ang nabura (0 kapag wala pang table). */
    public static function prune(): int
    {
        if (!Schema::hasTable('botcake_chats')) return 0;

        return static::where('received_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
    }

    /** Ang prune nang isang beses kada araw (Manila), para sa server na chatblast:auto lang ang tumatakbo sa cron. */
    public static function pruneDaily(): void
    {
        if (Cache::add('botcake-chats:pruned:' . now('Asia/Manila')->toDateString(), true, now()->addDays(2))) static::prune();
    }
}
