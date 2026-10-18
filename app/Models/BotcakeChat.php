<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/** Isang mensahe ng customer mula sa JSON API block ng Botcake (/api/botcake/chat). */
class BotcakeChat extends Model
{
    public const KEEP_DAYS = 90;
    public const PRUNE_BATCH = 5000;      // row kada delete
    public const PRUNE_MAX_BATCHES = 40;  // hanggang 200k row kada takbo; ang sobra ay sa susunod na takbo
    public const PAGE_MAP_CACHE = 'botcake-chats:page-map';
    public const PAGE_IDS_CACHE = 'botcake-chats:page-ids';
    public const KEY_HASHES_CACHE = 'botcake-chats:key-hashes';
    public const CACHE_SECONDS = 600;

    public $timestamps = false;

    protected $fillable = ['page_id', 'page_name', 'psid', 'name', 'message', 'message_id', 'payload', 'received_at'];

    protected $casts = [
        'payload'     => 'array',
        'received_at' => 'datetime',
    ];

    /**
     * botcake.page_id => pangalan ng page, mula sa active na page_settings (naka-cache 10 minuto; binubura ng
     * PageSettings kapag may nagbago na botcake.page_id).
     * @return array<string, string>
     */
    public static function pageMap(): array
    {
        return Cache::remember(self::PAGE_MAP_CACHE, self::CACHE_SECONDS, function () {
            $map = [];
            foreach (PageSetting::where('provider', 'botcake')->where('key', 'page_id')->where('active', true)->orderBy('id')->get() as $s) {
                $id = trim((string) $s->plainValue());
                if ($id !== '') $map[$id] = $s->page_name;
            }
            return $map;
        });
    }

    /**
     * sha256 ng chat.key => pangalan ng page, para sa mabilis na pagtanggi bago basahin ang body (naka-cache 10 minuto;
     * hash lang ang nasa cache, hindi ang key).
     * @return array<string, string>
     */
    public static function keyHashes(): array
    {
        return Cache::remember(self::KEY_HASHES_CACHE, self::CACHE_SECONDS, function () {
            $map = [];
            foreach (PageSetting::where('provider', 'botcake')->where('key', 'chat.key')->where('active', true)->orderBy('id')->get() as $s) {
                $key = (string) $s->plainValue();
                if ($key !== '') $map[hash('sha256', $key)] = $s->page_name;
            }
            return $map;
        });
    }

    /** Binubura ang naka-cache na mapa ng page at ng mga hash ng chat.key (kapag may nagbagong botcake.page_id o chat.key). */
    public static function forgetPageMap(): void
    {
        Cache::forget(self::PAGE_MAP_CACHE);
        Cache::forget(self::KEY_HASHES_CACHE);
    }

    /** Ang mga page_id na nasa chats (naka-cache 10 minuto): para sa page na wala sa page_settings. */
    public static function pageIds(): array
    {
        return Cache::remember(self::PAGE_IDS_CACHE, self::CACHE_SECONDS, fn () =>
            static::query()->whereNotNull('page_id')->distinct()->pluck('page_id')->map(fn ($id) => (string) $id)->all());
    }

    /**
     * Binubura ang mas luma sa 90 araw, 5000 row kada delete hanggang maubos (hanggang 40 batch kada takbo, para hindi
     * mahaba ang lock sa table); ilang row ang nabura (0 kapag wala pang table).
     */
    public static function prune(): int
    {
        if (!Schema::hasTable('botcake_chats')) return 0;

        $cutoff = now()->subDays(self::KEEP_DAYS);
        $deleted = 0;
        for ($i = 0; $i < self::PRUNE_MAX_BATCHES; $i++) {
            $ids = static::where('received_at', '<', $cutoff)->orderBy('id')->limit(self::PRUNE_BATCH)->pluck('id');
            if ($ids->isEmpty()) break;
            $deleted += static::whereIn('id', $ids)->delete();
            if ($ids->count() < self::PRUNE_BATCH) break;
        }

        return $deleted;
    }

    /** Ang prune nang isang beses kada araw (Manila), para sa server na chatblast:auto lang ang tumatakbo sa cron. */
    public static function pruneDaily(): void
    {
        if (Cache::add('botcake-chats:pruned:' . now('Asia/Manila')->toDateString(), true, now()->addDays(2))) static::prune();
    }
}
