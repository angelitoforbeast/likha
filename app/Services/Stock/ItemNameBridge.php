<?php

namespace App\Services\Stock;

use App\Services\ItemAliasResolver;
use App\Support\ItemBaseKey;

/**
 * Pangalan sa ibang sistema (Nexus, walang SKU) → Likha base key (ItemBaseKey::key).
 *
 * Tatlong hakbang, ang unang tumama ang gamit:
 *   1. eksakto: ItemBaseKey::key (lowercase, isang space, walang "N x"), pagkatapos ng NFC at Unicode spaces;
 *   2. maluwag: ItemAliasResolver::normalize (wala ring space, gitling at underscore), kung iisang Likha key ang tumama;
 *   3. pamilya: ItemAliasResolver::canonicalKey (item_type_mappings), kung iisang Likha key ang tumama.
 * Kapag dalawa o higit pang Likha key ang tumama sa hakbang 2 o 3, "ambiguous": walang item na dinadagdagan.
 */
class ItemNameBridge
{
    /** @var array<string,true> eksaktong Likha key */
    private array $exact = [];

    /** @var array<string,array<int,string>> normalize(key) => Likha keys */
    private array $loose = [];

    /** @var array<string,array<int,string>> canonicalKey(key) => Likha keys */
    private array $family = [];

    /** @param iterable<string> $likhaKeys */
    public function __construct(iterable $likhaKeys, private ItemAliasResolver $aliases)
    {
        foreach ($likhaKeys as $k) {
            $k = (string) $k;
            if ($k === '' || isset($this->exact[$k])) continue;
            $this->exact[$k] = true;
            $base = $this->base($k);
            $this->loose[ItemAliasResolver::normalize($base)][] = $k;
            $this->family[$this->aliases->canonicalKey($base)][] = $k;
        }
    }

    public function likhaKey(string $name): ?string
    {
        return $this->resolve($name)['key'];
    }

    /** @return array{key: ?string, ambiguous: bool} */
    public function resolve(string $name): array
    {
        $base = $this->base($name);
        if ($base === '') return ['key' => null, 'ambiguous' => false];
        if (isset($this->exact[$base])) return ['key' => $base, 'ambiguous' => false];

        foreach ([$this->loose[ItemAliasResolver::normalize($base)] ?? [], $this->family[$this->aliases->canonicalKey($base)] ?? []] as $hits) {
            if (count($hits) === 1) return ['key' => $hits[0], 'ambiguous' => false];
            if (count($hits) > 1) return ['key' => null, 'ambiguous' => true];
        }

        return ['key' => null, 'ambiguous' => false];
    }

    /** NFC, Unicode spaces → space, saka ItemBaseKey::key. */
    private function base(string $name): string
    {
        $s = $name;
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_C);
            if (is_string($n)) $s = $n;
        }
        $s = preg_replace('/[\s\p{Z}]+/u', ' ', $s) ?? $s;

        return ItemBaseKey::key($s);
    }
}
