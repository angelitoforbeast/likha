<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Collapses raw macro_output item_names into canonical "aliases" via the
 * item_type_mappings table — so renamed variants (e.g. "Alagang Pamilya II"
 * → "Alagang Pamilya VII") are treated as one family for analytics.
 *
 * Hosts with zero mappings (e.g. likha) get a pass-through: canonicalKey()
 * returns the item's own normalized key, identical to pre-aliasing behavior.
 */
class ItemAliasResolver
{
    /** normalized_item_name => normalized_item_type */
    private array $keyMap = [];

    /** normalized_item_name => raw item_type label (for display) */
    private array $labelMap = [];

    private bool $loaded = false;

    public function __construct()
    {
        $this->load();
    }

    /**
     * Normalize an item label the same way DailyPrimaryItemService does:
     * lowercase + strip spaces, dashes, underscores.
     */
    public static function normalize(string $itemName): string
    {
        $s = mb_strtolower(trim($itemName));
        return str_replace([' ', '-', '_'], '', $s);
    }

    /**
     * Returns the canonical key to group orders under. If the raw item_name has
     * a mapping in item_type_mappings, returns the alias's normalized form.
     * Else returns the raw item's own normalized form (no behavioral change).
     */
    public function canonicalKey(string $itemName): string
    {
        $n = self::normalize($itemName);
        return $this->keyMap[$n] ?? $n;
    }

    /**
     * Returns the canonical display label. If mapped, returns the item_type
     * string (the family name); otherwise returns the raw item_name unchanged.
     * Only used when the alias-family label should win over per-variant labels;
     * the DailyPrimaryItemService keeps per-day per-variant labels instead so
     * the breakdown matrix still shows variant evolution.
     */
    public function canonicalLabel(string $itemName): string
    {
        $n = self::normalize($itemName);
        return $this->labelMap[$n] ?? $itemName;
    }

    /** True if this raw item_name has an alias entry. */
    public function isAliased(string $itemName): bool
    {
        return isset($this->keyMap[self::normalize($itemName)]);
    }

    /** Total number of mappings loaded. Zero = aliasing inactive. */
    public function mappingCount(): int
    {
        return count($this->keyMap);
    }

    private function load(): void
    {
        if ($this->loaded) return;
        $this->loaded = true;

        if (!Schema::hasTable('item_type_mappings')) return;

        $rows = DB::table('item_type_mappings')
            ->select('item_name', 'item_type')
            ->get();

        foreach ($rows as $r) {
            $name = (string) ($r->item_name ?? '');
            $type = (string) ($r->item_type ?? '');
            if ($name === '' || $type === '') continue;
            $nKey = self::normalize($name);
            $this->keyMap[$nKey]   = self::normalize($type);
            $this->labelMap[$nKey] = trim($type);
        }
    }
}
