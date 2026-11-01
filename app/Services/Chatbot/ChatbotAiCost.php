<?php

namespace App\Services\Chatbot;

/**
 * Ang gastos ng isang sagot ng chatbot AI sa integer micro-USD, mula sa price list (services.chatbot_ai.prices: USD kada 1M
 * tokens, input at output). Ang USD kada 1M tokens ay micro-USD kada token, kaya tokens × presyo, at pataas sa buong micro
 * (ang maliit na tawag ay hindi kailanman 0). Walang usage, o model na wala sa listahan: null at ang dahilan, hindi 0.
 */
class ChatbotAiCost
{
    public const NO_USAGE = 'no_usage';
    public const NO_PRICE = 'no_price';

    /** Ang presyo ng model [input, output], o null; ang petsa sa dulo (-2024-07-18 o -20240718) ay inaalis. */
    public static function price(string $model): ?array
    {
        $prices = (array) config('services.chatbot_ai.prices', []);
        $model = strtolower(trim($model));
        foreach ([$model, (string) preg_replace('/-(\d{4}-\d{2}-\d{2}|\d{8})$/', '', $model)] as $m) {
            $p = $prices[$m] ?? null;
            if (is_array($p) && count($p) === 2 && is_numeric($p[0]) && is_numeric($p[1])) return [(float) $p[0], (float) $p[1]];
        }

        return null;
    }

    /** @return array{0: ?int, 1: ?string} [micro-USD, dahilan kapag null] */
    public static function of(string $model, ?int $in, ?int $out): array
    {
        if ($in === null || $out === null) return [null, self::NO_USAGE];
        $price = self::price($model);
        if ($price === null) return [null, self::NO_PRICE];

        // Ang round sa 6 na decimal bago ang ceil: ang 100 × 0.15 ng float ay 15.000000000000002, na magiging 1 micro na sobra.
        $micros = $in * $price[0] + $out * $price[1];

        return [(int) ceil(round($micros, 6)), null];
    }
}
