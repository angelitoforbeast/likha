<?php

namespace App\Services\CardStatement;

/**
 * Statement text → mga linya: pinipili ang bank layout, mina-mask ang card numbers at minamarkahan kung ads (FACEBK).
 */
class StatementParser
{
    /** @return list<StatementLayout> unang tumugma sa "auto" ang gagamitin; generic ang huli. */
    public function layouts(): array
    {
        return [new RcbcLayout(), new GenericLayout()];
    }

    public function bankKeys(): array
    {
        return array_map(fn (StatementLayout $l) => $l->key(), $this->layouts());
    }

    /** @return array{bank: string, lines: list<array>} */
    public function parseText(string $text, string $bank = 'auto'): array
    {
        $layout = null;
        foreach ($this->layouts() as $l) {
            if ($bank === 'auto' ? $l->detects($text) : $l->key() === $bank) { $layout = $l; break; }
        }
        $layout ??= new GenericLayout();

        return ['bank' => $layout->key(), 'lines' => $this->finish($layout->parse($text))];
    }

    /** Mask + ads flag sa bawat linya (pati sa nabasa ng vision). */
    public function finish(array $lines): array
    {
        return array_map(function (array $l) {
            $l['description'] = CardNumberMask::mask((string) $l['description']);
            $l['is_ads'] = self::isAds($l['description']);

            return $l;
        }, $lines);
    }

    public static function isAds(string $description): bool
    {
        return (bool) preg_match('/FACEBK|FACEBOOK|META\s+PLATFORMS/i', $description);
    }
}
