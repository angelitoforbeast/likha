<?php

namespace App\Services\Chatbot;

use App\Services\ChatbotFields;
use App\Services\PageSettings;

/**
 * Ang SHOP DETAILS ng page ay binubuo, hindi tina-type: PAGE (pangalan ng page), ITEM (ITEM NAME IN JNT DASHBOARD),
 * PRICE (₱ + ang PRICE) at QUANTITY, isang linya bawat isa. Dito kinukuha ng order ang item at COD
 * (LiveOrder::insertRaw → Parse::itemPrice), kaya ang lumang tina-type na shop_details row ay naiiwan sa history pero
 * hindi na binabasa. Ang kulang na field ay walang linya (at X sa content check).
 */
class ShopDetails
{
    public const KEY = 'shop_details';
    public const LABEL = 'SHOP DETAILS';
    public const NOTE = 'Auto: ITEM NAME IN JNT DASHBOARD + PRICE + QUANTITY';
    public const READ_ONLY = 'SHOP DETAILS is automatic (ITEM NAME IN JNT DASHBOARD + PRICE + QUANTITY): change those fields instead.';
    /** linya => botfield key */
    public const PARTS = ['ITEM' => 'item_name_in_jnt_dashboard', 'PRICE' => 'price', 'QUANTITY' => 'quantity'];

    public function __construct(private PageSettings $settings)
    {
    }

    /** Ang SHOP DETAILS ng page mula sa naka-save na mga field nito. */
    public function for(string $page): string
    {
        $fields = [];
        foreach (self::PARTS as $key) $fields[$key] = (string) $this->settings->get($page, ChatbotFields::PROVIDER, $key);

        return self::build($page, $fields);
    }

    /** @param array<string, mixed> $fields botfield key => value (ang iba ay hindi pinapansin) */
    public static function build(string $page, array $fields): string
    {
        $lines = ['PAGE: ' . $page];
        foreach (self::PARTS as $line => $key) {
            $v = self::part($key, $fields);
            if ($v !== '') $lines[] = $line . ': ' . $v;
        }

        return implode("\n", $lines);
    }

    /** @return array<int, string> ang mga key ng field na walang value */
    public static function missing(array $fields): array
    {
        return array_values(array_filter(self::PARTS, fn ($key) => self::part($key, $fields) === ''));
    }

    /** "₱1,299", "PHP 1,299", "P 1,299.50", "198" → "₱1,299", "₱1,299", "₱1,299.50", "₱198". */
    public static function price(string $value): string
    {
        $v = preg_replace('/\s+/u', '', preg_replace('/^\s*(₱|PHP|P)/iu', '', trim($value)));
        if (preg_match('/^\d[\d,]*(?:\.\d+)?/', $v, $m)) $v = $m[0];

        return $v === '' ? '' : '₱' . $v;
    }

    private static function part(string $key, array $fields): string
    {
        $v = trim((string) ($fields[$key] ?? ''));

        return $key === 'price' && $v !== '' ? self::price($v) : $v;
    }
}
