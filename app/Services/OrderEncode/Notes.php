<?php

namespace App\Services\OrderEncode;

use App\Models\AddressNote;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Ang paghahanap sa natutunang address (address_notes) habang nag-e-encode. Index lang ang ginagamit (written_key, o status +
 * written_city / written_province): walang buong scan kada order. Approved lang ang ginagamit.
 */
class Notes
{
    public const EXAMPLES = 5;
    private const POOL = 40;

    private ?bool $ready = null;

    public function available(): bool
    {
        try {
            return $this->ready ??= Schema::hasTable('address_notes');
        } catch (Throwable) {
            return $this->ready = false;
        }
    }

    /** Ang approved na note ng eksaktong isinulat (written_key). */
    public function exact(string $key): ?AddressNote
    {
        if (!$this->available() || Written::isEmptyKey($key)) return null;

        return AddressNote::where('written_key', $key)->where('status', 'approved')->first();
    }

    /**
     * Hanggang 5 approved na note na may parehong isinulat na city o province, ang pinakamalapit ayon sa pagkakapareho ng mga salita.
     * @return array<int, AddressNote>
     */
    public function examples(string $key, int $limit = self::EXAMPLES): array
    {
        if (!$this->available() || Written::isEmptyKey($key)) return [];
        [$brgy, $city, $prov] = Written::split($key);

        $pool = collect();
        if ($city !== '') $pool = $pool->merge(AddressNote::where('status', 'approved')->where('written_city', $city)->orderByDesc('delivered')->limit(self::POOL)->get());
        if ($prov !== '') $pool = $pool->merge(AddressNote::where('status', 'approved')->where('written_province', $prov)->orderByDesc('delivered')->limit(self::POOL)->get());

        $mine = self::tokens($key);

        return $pool->unique('id')->reject(fn (AddressNote $n) => $n->written_key === $key)
            ->sortByDesc(fn (AddressNote $n) => [count(array_intersect($mine, self::tokens($n->written_key))), (int) $n->delivered])
            ->take($limit)->values()->all();
    }

    /** @return array<int, string> */
    private static function tokens(string $key): array
    {
        return array_values(array_unique(array_filter(preg_split('/[|\s]+/', $key) ?: [], fn ($t) => $t !== '')));
    }
}
