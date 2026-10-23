<?php

namespace App\Services\OrderEncode;

/**
 * Ang row na isinusulat ng sheet step ng flow, bilang mga cell ng ENCODER (B..I ng script: DATE, PAGE, NAME, PHONE NUMBER,
 * ALL USER INPUT, SHOP DETAILS, CXD, PRICE). Ang ayos ng All Orders ay A date, B page, C name, D phone, E all user input,
 * F shop details, G extracted details, H price (I = status, hindi ginagamit). Kapag may header ang tab, ang pangalan ng
 * header ang masusunod; kapag walang makilala, ang ayos ng column.
 */
final class Input
{
    public const FIELDS = ['date', 'page', 'name', 'phone', 'input', 'shop', 'cxd', 'price'];

    /** Mga pangalan ng header (lowercase, walang sobrang espasyo) na tinatanggap kada field. */
    private const HEADERS = [
        'date'  => ['date', 'timestamp', 'time', 'date time', 'datetime'],
        'page'  => ['page', 'page name'],
        'name'  => ['name', 'fb name', 'full name', 'customer name'],
        'phone' => ['phone', 'phone number', 'contact number', 'mobile number'],
        'input' => ['all user input', 'user input', 'all input'],
        'shop'  => ['shop details', 'shop detail', 'shop'],
        'cxd'   => ['extracted details', 'cxd', 'customer details', 'extracted'],
        'price' => ['price', 'cod', 'amount'],
    ];

    /** @param array<string, string> $v field => value */
    public function __construct(public array $v)
    {
    }

    /**
     * @param array<int, mixed>       $row     ang mga cell na isinulat, A pataas
     * @param array<int, string>|null $headers ang row 1 ng tab, kung nabasa
     */
    public static function fromRow(array $row, ?array $headers = null): self
    {
        $row = array_values($row);
        $at = array_fill_keys(self::FIELDS, null);

        foreach ((array) $headers as $i => $h) {
            $key = trim(preg_replace('/\s+/', ' ', mb_strtolower((string) $h)));
            foreach (self::HEADERS as $field => $names) {
                if ($at[$field] === null && in_array($key, $names, true)) {
                    $at[$field] = (int) $i;
                    break;
                }
            }
        }
        // Wala o kulang ang makilalang header (wala pang 3): ang ayos ng column.
        $mapped = count(array_filter($at, fn ($i) => $i !== null));
        $v = [];
        foreach (self::FIELDS as $pos => $field) {
            $i = $mapped >= 3 ? ($at[$field] ?? null) : $pos;
            $v[$field] = $i === null ? '' : self::cell($row[$i] ?? '');
        }

        return new self($v);
    }

    /** Ang apostrophe sa unahan (USER_ENTERED ng sheet step) ay kinakain ng Google; wala ito sa nababasa ng script. */
    private static function cell(mixed $v): string
    {
        $s = (string) $v;

        return str_starts_with($s, "'") ? substr($s, 1) : $s;
    }

    public function get(string $field): string
    {
        return (string) ($this->v[$field] ?? '');
    }
}
