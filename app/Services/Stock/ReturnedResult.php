<?php

namespace App\Services\Stock;

/**
 * Sagot ng isang ReturnedSource.
 *   available: false = hindi makuha (walang numero; hindi ito 0)
 *   pieces:    likhaKeys = true → Likha base key (ItemBaseKey::key) => pieces;
 *              likhaKeys = false → pangalan sa ibang sistema (Nexus) => pieces, itinutugma sa /item
 *   byDay:     parehong susi => [Y-m-d (Manila) => pieces]; ang /item ay nagbibilang mula sa simula ng bawat item.
 *              Walang byDay ang source = lahat ng pieces ay bilang.
 *   flags:     mga hindi binilang, para makita (hal. unsigned, future)
 */
final class ReturnedResult
{
    /**
     * @param array<string,int> $pieces
     * @param array<string,int> $flags
     * @param array<string,array<string,int>> $byDay
     */
    public function __construct(
        public readonly bool $available,
        public readonly array $pieces = [],
        public readonly bool $likhaKeys = true,
        public readonly array $flags = [],
        public readonly ?string $note = null,
        public readonly array $byDay = [],
    ) {}

    public static function unavailable(string $note): self
    {
        return new self(false, [], true, [], $note);
    }

    /** Pieces ng isang susi mula sa $start (kasama), o lahat kapag walang byDay ang source. */
    public function since(string $name, string $start): int
    {
        if (!$this->byDay) return (int) ($this->pieces[$name] ?? 0);
        $n = 0;
        foreach ($this->byDay[$name] ?? [] as $day => $pieces) {
            if ($day >= $start) $n += (int) $pieces;
        }

        return $n;
    }

    /** Para sa cache (array lang ang iniimbak). */
    public function toArray(): array
    {
        return ['available' => $this->available, 'pieces' => $this->pieces, 'likha_keys' => $this->likhaKeys,
                'flags' => $this->flags, 'note' => $this->note, 'by_day' => $this->byDay];
    }

    public static function fromArray(array $a): self
    {
        return new self((bool) $a['available'], (array) $a['pieces'], (bool) $a['likha_keys'], (array) $a['flags'],
                        $a['note'] ?? null, (array) ($a['by_day'] ?? []));
    }
}
