<?php

namespace App\Services\OrderEncode;

use App\Models\MacroOutput;
use App\Models\OrderEncode;

/**
 * Ang paghahambing ng encode ng Likha sa FINAL na order sa macro_output (ang naging resulta ng mga sheet at ng staff).
 * Bawat field ay same | different | final_blank | likha_blank. Ang PROCEED ay ang final O ng Likha (✅) laban sa STATUS = PROCEED.
 */
class FinalComparer
{
    /** field => [susi sa outputs ng Likha, column ng macro_output, uri] */
    public const FIELDS = [
        'province' => ['province', 'PROVINCE', 'place'], 'city' => ['city', 'CITY', 'place'], 'barangay' => ['barangay', 'BARANGAY', 'place'],
        'address_line' => ['address_line', 'ADDRESS', 'address'], 'item' => ['item', 'ITEM_NAME', 'place'], 'cod' => ['cod', 'COD', 'number'],
        'checker' => ['final', 'APP SCRIPT CHECKER', 'exact'],
    ];

    /** Ang mga tanda na na-edit ng staff ang order. */
    public const EDITED = ['edited_full_name', 'edited_phone_number', 'edited_address', 'edited_province', 'edited_city', 'edited_barangay', 'edited_cod', 'edited_item_name'];

    /** Napunan na ba ng Macro import ang order? (STATUS, APP SCRIPT CHECKER o PROVINCE) */
    public static function filled(MacroOutput $m): bool
    {
        foreach (['STATUS', 'APP SCRIPT CHECKER', 'PROVINCE'] as $c) {
            if (trim((string) $m->getAttribute($c)) !== '') return true;
        }

        return false;
    }

    /** @return array<string, mixed> ang ilalagay sa order_encodes.final_compare */
    public static function compare(OrderEncode $o, MacroOutput $m): array
    {
        $out = (array) $o->outputs;
        $fields = [];
        $final = [];
        foreach (self::FIELDS as $field => [$key, $column, $type]) {
            $theirs = (string) $m->getAttribute($column);
            $fields[$field] = self::one(self::norm((string) ($out[$key] ?? ''), $type), self::norm($theirs, $type));
            $final[$field] = trim($theirs);
        }
        $status = trim((string) $m->getAttribute('STATUS'));
        $likha = (bool) ($out['proceed'] ?? false);
        $theirsProceed = strtoupper($status) === 'PROCEED';
        $fields['proceed'] = $likha === $theirsProceed ? 'same' : 'different';
        $flags = array_values(array_filter(self::EDITED, fn ($f) => filter_var($m->getAttribute($f), FILTER_VALIDATE_BOOLEAN)));

        return [
            'state' => 'done', 'at' => now()->toIso8601String(), 'fields' => $fields, 'likha_proceed' => $likha, 'final_proceed' => $theirsProceed,
            'status' => $status, 'edited' => $flags !== [], 'edited_flags' => $flags, 'final' => $final + ['status' => $status],
        ];
    }

    private static function one(string $ours, string $theirs): string
    {
        if ($ours === '' && $theirs === '') return 'same';
        if ($theirs === '') return 'final_blank';
        if ($ours === '') return 'likha_blank';

        return $ours === $theirs ? 'same' : 'different';
    }

    private static function norm(string $v, string $type): string
    {
        $v = Text::jsTrim($v);
        switch ($type) {
            case 'number':
                $n = str_replace([',', '₱', ' '], '', $v);

                return is_numeric($n) ? (string) (float) $n : $v;
            case 'address':
                // Maliit na titik, Ñ = N; ang bantas at ang paulit-ulit na espasyo ay iisang espasyo.
                return Text::jsTrim(Text::collapse(preg_replace('/[^\p{L}\p{N}]+/u', ' ', Text::lower($v)) ?? $v));
            case 'place':
                return Text::lower(Text::collapse($v));
            default:
                return $v;
        }
    }
}
