<?php

namespace App\Services\CardStatement;

use Carbon\Carbon;

/**
 * Fallback sa ibang bangko: linyang may petsa, FACEBK/FACEBOOK/META na description at amount sa dulo
 * ("-" o "CR" = credit). Unang petsa = sale date, pangalawa (kung meron) = post date. Ibang linya ay hindi kinukuha.
 */
class GenericLayout implements StatementLayout
{
    private const DATE = '\d{4}-\d{2}-\d{2}|\d{2}/\d{2}/\d{4}|\d{2}/\d{2}/\d{2}|[A-Za-z]{3}\s+\d{1,2},?\s+\d{4}|\d{1,2}\s+[A-Za-z]{3},?\s+\d{4}';

    public function key(): string
    {
        return 'generic';
    }

    public function detects(string $text): bool
    {
        return true;
    }

    public function parse(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) as $raw) {
            $raw = trim($raw);
            if (!StatementParser::isAds($raw)) continue;
            if (!preg_match('/^(.*?)\s+([\d,]+\.\d{2})\s*(-|CR)?\s*$/iu', $raw, $m)) continue;

            $rest  = $m[1];
            $dates = [];
            while (count($dates) < 2 && preg_match('#^(' . self::DATE . ')\s+#u', $rest, $d)) {
                $parsed = self::date($d[1]);
                if ($parsed === null) break;
                $dates[] = $parsed;
                $rest = substr($rest, strlen($d[0]));
            }
            if ($dates === []) continue;

            $lines[] = [
                'sale_date'   => $dates[0],
                'post_date'   => $dates[1] ?? null,
                'description' => trim($rest),
                'amount'      => RcbcLayout::amount($m[2]),
                'is_credit'   => ($m[3] ?? '') !== '',
            ];
        }

        return $lines;
    }

    private static function date(string $v): ?string
    {
        if (str_contains($v, '/')) return RcbcLayout::date($v);
        try {
            $c = Carbon::parse(str_replace(',', '', $v));
        } catch (\Throwable $e) {
            return null;
        }

        return $c->toDateString();
    }
}
