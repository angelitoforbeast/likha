<?php

namespace App\Services\CardStatement;

use Carbon\Carbon;

/**
 * RCBC: SALE DATE  POST DATE  DESCRIPTION  AMOUNT, dates MM/DD/YY, credit ay may "-" sa dulo.
 * Linyang may amount pero walang petsa (PREVIOUS STATEMENT BALANCE atbp.) ay kasama rin, para makita bilang "not ads".
 */
class RcbcLayout implements StatementLayout
{
    private const AMOUNT = '([\d,]+\.\d{2})\s*(-)?\s*$';

    public function key(): string
    {
        return 'rcbc';
    }

    public function detects(string $text): bool
    {
        return (bool) preg_match('/\bRCBC\b/i', $text);
    }

    public function parse(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) as $raw) {
            $raw = trim($raw);
            if (preg_match('#^(\d{2}/\d{2}/\d{2,4})\s+(\d{2}/\d{2}/\d{2,4})\s+(.+?)\s+' . self::AMOUNT . '#u', $raw, $m)) {
                $sale = self::date($m[1]);
                $post = self::date($m[2]);
                if ($sale === null) continue;
                $lines[] = ['sale_date' => $sale, 'post_date' => $post, 'description' => trim($m[3]), 'amount' => self::amount($m[4]), 'is_credit' => ($m[5] ?? '') === '-'];
            } elseif (preg_match('#^([^\d].*?)\s+' . self::AMOUNT . '#u', $raw, $m)) {
                $lines[] = ['sale_date' => null, 'post_date' => null, 'description' => trim($m[1]), 'amount' => self::amount($m[2]), 'is_credit' => ($m[3] ?? '') === '-'];
            }
        }

        return $lines;
    }

    /** MM/DD/YY o MM/DD/YYYY → Y-m-d; maling petsa → null. */
    public static function date(string $v): ?string
    {
        if (!preg_match('#^(\d{2})/(\d{2})/(\d{2}|\d{4})$#', $v, $m)) return null;
        $year = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];
        if (!checkdate((int) $m[1], (int) $m[2], $year)) return null;

        return Carbon::create($year, (int) $m[1], (int) $m[2])->toDateString();
    }

    public static function amount(string $v): string
    {
        return number_format((float) str_replace(',', '', $v), 2, '.', '');
    }
}
