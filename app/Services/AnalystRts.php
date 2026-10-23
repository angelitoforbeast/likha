<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mira RTS = ang RTS% na tinype ng analyst (may dahilan); hindi ito ang formula (RtsProjection) at hindi ang Set RTS
 * ng staff (rts_pct). Nasa page_settings: provider "analyst", key "rts_pct:<canonical item>" at "rts_reason:<canonical item>".
 * Ang history (sino/kailan, dating value) ay ang mga pinalitang row ng page_settings.
 */
class AnalystRts
{
    public const PROVIDER = 'analyst';
    public const PCT = 'rts_pct:';
    public const REASON = 'rts_reason:';
    private const TZ = 'Asia/Manila';

    /** Ang key ng analyst na tinatanggap ng PageSettings (may item name sa dulo, kaya hindi pasok sa karaniwang key rule). */
    public static function isKey(string $provider, string $key): bool
    {
        return $provider === self::PROVIDER && (str_starts_with($key, self::PCT) || str_starts_with($key, self::REASON)) && strlen($key) > strlen(self::PCT);
    }

    /** Pinakahuling tinype bawat page+item: itemKey => [pct, reason, at, prev]. */
    public function all(): array
    {
        if (!Schema::hasTable('page_settings')) return [];

        $out = [];
        $reasons = [];
        $rows = DB::table('page_settings')->where('provider', self::PROVIDER)->where('active', true)
            ->orderBy('id')->get(['page_name', 'key', 'value', 'created_at']);
        foreach ($rows as $r) {
            $pk = strtolower(trim((string) $r->page_name));
            if (str_starts_with($r->key, self::PCT)) {
                $out[$pk . '||' . substr($r->key, strlen(self::PCT))] = [
                    'pct' => (float) $r->value, 'reason' => null, 'at' => $r->created_at ? (string) $r->created_at : null, 'prev' => null,
                ];
            } elseif (str_starts_with($r->key, self::REASON)) {
                $reasons[$pk . '||' . substr($r->key, strlen(self::REASON))] = (string) $r->value;
            }
        }
        foreach ($out as $k => &$o) $o['reason'] = $reasons[$k] ?? null;
        unset($o);
        if ($out === []) return $out;

        // Dating value = pinakabagong pinalitang row ng parehong key.
        $old = DB::table('page_settings')->where('provider', self::PROVIDER)->where('active', false)->where('key', 'like', self::PCT . '%')
            ->orderBy('id')->get(['page_name', 'key', 'value']);
        foreach ($old as $r) {
            $k = strtolower(trim((string) $r->page_name)) . '||' . substr($r->key, strlen(self::PCT));
            if (isset($out[$k])) $out[$k]['prev'] = (float) $r->value;
        }

        return $out;
    }

    /** Isang page+item. Ibinabalik ang error text kapag walang tugmang setting row o hindi pumasa, kung hindi null. */
    public function save(string $page, string $item, float $pct, string $reason): ?string
    {
        if (!Schema::hasTable('page_settings')) return 'Wala pang page_settings table.';
        $aliases = new ItemAliasResolver();
        $want = RtsProjection::itemKey($page, $item, $aliases);
        $today = Carbon::now(self::TZ)->toDateString();

        $rows = DB::table('page_item_settings')
            ->whereRaw('LOWER(TRIM(page_name)) = ?', [strtolower(trim($page))])
            ->where('effective_date', '<=', $today)
            ->orderByDesc('effective_date')->orderByDesc('id')
            ->get(['page_name', 'item_name']);
        $target = $rows->first(fn ($r) => RtsProjection::itemKey((string) $r->page_name, (string) $r->item_name, $aliases) === $want);
        if (!$target) return 'Walang page+item na ganito sa settings.';

        $canon = $aliases->canonicalKey($item);
        $value = rtrim(rtrim(number_format(round($pct, 2), 2, '.', ''), '0'), '.');
        $settings = new PageSettings();
        try {
            DB::transaction(function () use ($settings, $target, $canon, $value, $reason) {
                $settings->set((string) $target->page_name, self::PROVIDER, self::PCT . $canon, $value, system: true);
                $settings->set((string) $target->page_name, self::PROVIDER, self::REASON . $canon, $reason, system: true);
            });
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }
}
