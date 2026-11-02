<?php

namespace App\Services\Stock;

use App\Services\ItemAliasResolver;
use App\Support\ItemBaseKey;

/**
 * Pangalan sa ibang sistema (Nexus, walang SKU) → Likha base key (ItemBaseKey::key).
 *
 * Parehong panig: NFC, tanggal ang "N x", saka ItemAliasResolver::canonicalKey (lowercase, walang space/gitling/
 * underscore, at ang item_type_mappings). Kapag dalawang Likha key ang may parehong canonical, ang key na mismong
 * canonical ang nananalo, kung wala, ang pinakauna ayon sa ayos (laging pareho ang resulta).
 */
class ItemNameBridge
{
    /** canonical => Likha base key */
    private array $map = [];

    /** @param iterable<string> $likhaKeys */
    public function __construct(iterable $likhaKeys, private ItemAliasResolver $aliases)
    {
        $keys = [];
        foreach ($likhaKeys as $k) {
            $k = (string) $k;
            if ($k !== '') $keys[$k] = true;
        }
        $keys = array_keys($keys);
        sort($keys, SORT_STRING);

        foreach ($keys as $k) {
            $c = $this->canon($k);
            if ($c === '') continue;
            if (!isset($this->map[$c])) {
                $this->map[$c] = $k;
            } elseif (ItemAliasResolver::normalize($this->map[$c]) !== $c && ItemAliasResolver::normalize($k) === $c) {
                $this->map[$c] = $k;
            }
        }
    }

    public function likhaKey(string $name): ?string
    {
        $c = $this->canon($name);

        return $c === '' ? null : ($this->map[$c] ?? null);
    }

    private function canon(string $name): string
    {
        $s = trim($name);
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_C);
            if (is_string($n)) $s = $n;
        }

        return $this->aliases->canonicalKey(ItemBaseKey::key($s));
    }
}
