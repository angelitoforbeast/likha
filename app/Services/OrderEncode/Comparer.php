<?php

namespace App\Services\OrderEncode;

use App\Models\OrderEncode;

/**
 * Ang paghahambing ng resulta ng Likha sa DATABASE row ng sheet (A..W) at sa DATABASE - MIRRORED (O, P) ng mismong row number.
 * Bawat field ay same | different | sheet_blank | likha_blank, pagkatapos ng trim at ng sariling patakaran ng field.
 */
class Comparer
{
    /** field => [saan sa outputs ng Likha, saan sa DATABASE (index ng A..W) o 'O'/'P' ng MIRRORED, uri] */
    public const FIELDS = [
        'name' => ['name', 1, 'text'], 'phone' => ['phone', 2, 'phone'], 'address_line' => ['address_line', 3, 'text'],
        'province' => ['province', 4, 'text'], 'city' => ['city', 5, 'text'], 'barangay' => ['barangay', 6, 'text'],
        'item' => ['item', 7, 'text'], 'cod' => ['cod', 8, 'number'],
        'r' => ['r', 17, 'exact'], 's' => ['s', 18, 'exact'], 'w' => ['w', 22, 'exact'],
        'final' => ['final', 'O', 'exact'], 'proceed' => ['proceed', 'P', 'exact'],
    ];

    /** Ang oras ng order sa input ng Likha at sa A ng DATABASE ay iisa? (ibang anyo ay tinatanggap) */
    public static function sameStamp(string $a, string $b): bool
    {
        $a = trim($a);
        $b = trim($b);
        if ($a === $b) return true;
        $pa = self::parse($a);
        $pb = self::parse($b);

        return $pa !== null && $pb !== null && $pa === $pb;
    }

    private static function parse(string $s): ?string
    {
        foreach (['H:i d-m-Y', 'G:i d-m-Y', 'H:i:s d-m-Y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $f) {
            $d = \DateTime::createFromFormat('!' . $f, $s);
            if ($d && $d->format($f) === $s) return $f === 'Y-m-d' ? $d->format('Y-m-d') : $d->format('Y-m-d H:i');
        }

        return null;
    }

    /** Ang parehong order? ang A (oras) at J (page) ng DATABASE laban sa input ng Likha. */
    public static function sameOrder(OrderEncode $o, array $db): bool
    {
        $f = (array) ($o->input['fields'] ?? []);
        // Ang petsa na formula (hal. =TODAY()) ay hindi mababasa ng Likha; ang page na lang ang titingnan.
        $date = trim((string) ($f['date'] ?? ''));

        return ($date === '' || str_starts_with($date, '=') || self::sameStamp($date, (string) ($db[0] ?? '')))
            && mb_strtolower(trim((string) ($f['page'] ?? $o->page))) === mb_strtolower(trim((string) ($db[9] ?? '')));
    }

    /**
     * @param array<string, mixed>      $outputs ang outputs ng Likha
     * @param array<int, string>        $db      A..W (index 0..22)
     * @param array<int, string>        $mirror  [O, P]
     * @return array<string, string>
     */
    public static function fields(array $outputs, array $db, array $mirror): array
    {
        $out = [];
        foreach (self::FIELDS as $field => [$key, $at, $type]) {
            $ours = $key === 'proceed' ? (($outputs['proceed'] ?? false) ? 'PROCEED' : '') : trim((string) ($outputs[$key] ?? ''));
            $sheet = trim((string) ($at === 'O' ? ($mirror[0] ?? '') : ($at === 'P' ? ($mirror[1] ?? '') : ($db[$at] ?? ''))));
            $out[$field] = self::one($ours, $sheet, $type);
        }

        return $out;
    }

    private static function one(string $ours, string $sheet, string $type): string
    {
        [$a, $b] = [self::norm($ours, $type), self::norm($sheet, $type)];
        if ($a === '' && $b === '') return 'same';
        if ($b === '') return 'sheet_blank';
        if ($a === '') return 'likha_blank';

        return $a === $b ? 'same' : 'different';
    }

    private static function norm(string $v, string $type): string
    {
        $v = Text::jsTrim($v);
        switch ($type) {
            case 'phone':
                return ltrim(preg_replace('/\D/', '', $v), '0'); // ang numero sa sheet ay nawawalan ng 0 sa harap
            case 'number':
                $n = str_replace([',', '₱', ' '], '', $v);

                return is_numeric($n) ? Text::sheetValue((string) $n) : $v;
            case 'text':
                return mb_strtolower(Text::collapse($v));
            default:
                return $v;
        }
    }
}
