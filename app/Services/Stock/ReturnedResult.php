<?php

namespace App\Services\Stock;

/**
 * Sagot ng isang ReturnedSource.
 *   available: false = hindi makuha (walang numero; hindi ito 0)
 *   pieces:    likhaKeys = true → Likha base key (ItemBaseKey::key) => pieces;
 *              likhaKeys = false → pangalan sa ibang sistema (Nexus) => pieces, itinutugma sa /item
 *   flags:     mga hindi binilang, para makita (hal. unsigned, future)
 */
final class ReturnedResult
{
    /**
     * @param array<string,int> $pieces
     * @param array<string,int> $flags
     */
    public function __construct(
        public readonly bool $available,
        public readonly array $pieces = [],
        public readonly bool $likhaKeys = true,
        public readonly array $flags = [],
        public readonly ?string $note = null,
    ) {}

    public static function unavailable(string $note): self
    {
        return new self(false, [], true, [], $note);
    }

    /** Para sa cache (array lang ang iniimbak). */
    public function toArray(): array
    {
        return ['available' => $this->available, 'pieces' => $this->pieces, 'likha_keys' => $this->likhaKeys,
                'flags' => $this->flags, 'note' => $this->note];
    }

    public static function fromArray(array $a): self
    {
        return new self((bool) $a['available'], (array) $a['pieces'], (bool) $a['likha_keys'], (array) $a['flags'], $a['note'] ?? null);
    }
}
