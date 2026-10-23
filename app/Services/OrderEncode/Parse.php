<?php

namespace App\Services\OrderEncode;

/**
 * STEP 2 at STEP 4 ng auto-encode.gs: ang EXTRACTED DETAILS (ENCODER!H2) at ang SHOP DETAILS (ENCODER!G2), walang AI.
 */
final class Parse
{
    /**
     * extractAddressPartsRow2(). Ang isang linya ay isang label lang; ang una na tumugma sa ayos ng script ang nasusunod.
     * @return array{name: string, phone: string, province: string, city: string, barangay: string}
     */
    public static function addressParts(string $cxd): array
    {
        $name = $phone = $province = $city = $barangay = '';

        foreach (explode("\n", $cxd) as $line) {
            $line = Text::jsTrim($line);
            if (preg_match('/^-?\s*Name:/i', $line)) {
                $name = Text::jsTrim(preg_replace('/^-?\s*Name:\s*/i', '', $line));
            } elseif (preg_match('/^-?\s*Phone Number:/i', $line)) {
                $phone = preg_replace('/\s+/', '', Text::jsTrim(preg_replace('/^-?\s*Phone Number:\s*/i', '', $line)));
                $phone = preg_replace('/[^0-9]/', '', preg_replace('/^(\+63|63)/', '0', $phone));
                if (strlen($phone) !== 11 || !preg_match('/^09\d{9}$/', $phone)) $phone = '';
            } elseif (preg_match('/^-?\s*Province:/i', $line)) {
                $province = self::place(preg_replace('/^-?\s*Province:\s*/i', '', $line)) ?? $province;
            } elseif (preg_match('/^-?\s*City:/i', $line)) {
                $city = self::place(preg_replace('/^-?\s*City:\s*/i', '', $line)) ?? $city;
            } elseif (preg_match('/^-?\s*(Brgy|Barangay):/i', $line)) {
                $barangay = self::place(preg_replace('/^-?\s*(Brgy|Barangay):\s*/i', '', $line)) ?? $barangay;
            }
        }

        return compact('name', 'phone', 'province', 'city', 'barangay');
    }

    /** NA / N/A / NOT PROVIDED = null (hindi ginagalaw ang value); kung hindi, tinatanggal ang "province/brgy/barangay/city" at ang simbolo. */
    private static function place(string $raw): ?string
    {
        $raw = Text::jsTrim(Text::foldN($raw)); // Ñ = N: hindi tinatanggal ng strip ang Ñ
        if (preg_match('/^(NA|N\/A|NOT PROVIDED)$/i', $raw)) return null;
        $s = preg_replace('/\b(province|brgy|barangay|city)[\.\s]*/i', '', $raw);
        $s = preg_replace('/[^A-Za-z0-9 ]/', '', $s);

        return strtoupper(Text::jsTrim($s));
    }

    /**
     * extractDetails(): "qty x ITEM" at ang presyo (₱ muna, saka walang ₱).
     * @return array{item: string, price: string}
     */
    public static function itemPrice(string $shop): array
    {
        $item = preg_match('/ITEM:\s*([^\r\n]+?)(?:\r?\n|$)/i', $shop, $m) ? Text::jsTrim($m[1]) : '';
        $qty = preg_match('/QUANTITY:\s*(\d+)/i', $shop, $m) ? Text::jsTrim($m[1]) : '';
        $out = $qty !== '' && $item !== '' ? "{$qty} x {$item}" : '';

        $price = '';
        if (preg_match('/PRICE:\s*₱\s*([\d,\.]+)/i', $shop, $m)) $price = Text::jsTrim($m[1]);
        elseif (preg_match('/PRICE:\s*([\d,\.]+)/i', $shop, $m)) $price = Text::jsTrim($m[1]);

        return ['item' => $out, 'price' => $price];
    }
}
