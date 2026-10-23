<?php

namespace App\Services\OrderEncode;

use App\Models\MacroOutput;
use App\Models\OrderEncode;
use App\Models\PhoneWhitelist;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * R5 (spec 135): ang doble sa parehong araw. Ang order ay may kaparehong phone, o kaparehong pangalan + barangay + city, na order
 * sa parehong ts_date: sa macro_output o sa mga encode na wala pa roon. Ang phone sa whitelist ng Validate ay hindi binibilang.
 * Gaya ng Validate, hindi kasama ang CANNOT PROCEED at ang order mismo.
 */
class Duplicates
{
    private const LIMIT = 5;

    /**
     * @param array<string, mixed> $outputs ang outputs ng encode (name, phone, city, barangay)
     * @return array<int, string> ang mga order id ("12" sa macro_output, "e34" sa encode na wala pa roon); walang laman = walang doble
     */
    public function find(OrderEncode $o, Input $in, array $outputs): array
    {
        try {
            $date = self::date($in->get('date'));
            $phone = self::tenDigits((string) ($outputs['phone'] ?? ''));
            $name = self::name((string) ($outputs['name'] ?? ''));
            if ($phone !== '' && $this->whitelisted($phone)) $phone = '';
            if ($phone === '' && $name === '') return [];

            $found = [];
            foreach ($this->macroRows($o, $date, $phone, $name) as $r) {
                $samePhone = $phone !== '' && self::tenDigits((string) $r->{'PHONE NUMBER'}) === $phone;
                if ($samePhone || $this->sameNameAddress($r, $name, $outputs)) $found[] = (string) $r->id;
            }
            foreach ($this->encodeRows($o, $date) as $e) {
                $out = (array) $e->outputs;
                $samePhone = $phone !== '' && self::tenDigits((string) ($out['phone'] ?? '')) === $phone;
                $sameName = $name !== '' && self::name((string) ($out['name'] ?? '')) === $name && self::sameAddress($out['barangay'] ?? '', $out['city'] ?? '', $outputs);
                if ($samePhone || $sameName) $found[] = 'e' . $e->id;
            }

            return array_slice(array_values(array_unique($found)), 0, self::LIMIT);
        } catch (Throwable $e) {
            report($e);

            return []; // ang kulang na pagsusuri ay hindi dapat magpabagsak ng encode
        }
    }

    /** @return iterable<int, object> */
    private function macroRows(OrderEncode $o, string $date, string $phone, string $name): iterable
    {
        if (!Schema::hasTable('macro_output') || !Schema::hasColumn('macro_output', 'ts_date')) return [];

        $wrap = fn (string $col) => DB::getQueryGrammar()->wrap($col);
        $q = MacroOutput::query()->where(function ($s) { $s->whereNull('STATUS')->orWhere('STATUS', '<>', 'CANNOT PROCEED'); });
        if ($o->macro_output_id) $q->where('id', '<>', $o->macro_output_id);
        if (Schema::getColumnType('macro_output', 'ts_date') === 'date') $q->where('ts_date', '=', $date);
        else $q->whereBetween('ts_date', [$date . ' 00:00:00', $date . ' 23:59:59']);

        $q->where(function ($s) use ($phone, $name, $wrap) {
            if ($phone !== '') $s->orWhereIn('PHONE NUMBER', [$phone, '0' . $phone]);
            if ($name !== '') $s->orWhereRaw('LOWER(TRIM(' . $wrap('FULL NAME') . ')) = ?', [$name]);
        });

        return $q->orderBy('id')->limit(50)->get(['id', 'FULL NAME', 'PHONE NUMBER', 'CITY', 'BARANGAY']);
    }

    /** Ang mga encode ng araw na wala pa sa macro_output (kung nasa macro_output na, doon na sila nabibilang). */
    private function encodeRows(OrderEncode $o, string $date): iterable
    {
        return OrderEncode::whereNull('macro_output_id')->where('id', '<>', $o->id)->where('status', 'done')->whereNotNull('outputs')
            ->where('created_at', '>=', $date . ' 00:00:00')->where('created_at', '<=', $date . ' 23:59:59')->limit(500)->get(['id', 'outputs']);
    }

    private function sameNameAddress(object $r, string $name, array $outputs): bool
    {
        return $name !== '' && self::name((string) $r->{'FULL NAME'}) === $name && self::sameAddress((string) $r->BARANGAY, (string) $r->CITY, $outputs);
    }

    /** Pareho ang barangay at city (hindi blangko ang barangay). */
    private static function sameAddress(mixed $barangay, mixed $city, array $outputs): bool
    {
        $b = Written::brgy((string) $barangay);

        return $b !== '' && $b === Written::brgy((string) ($outputs['barangay'] ?? '')) && Text::normPlace((string) $city) === Text::normPlace((string) ($outputs['city'] ?? ''));
    }

    private function whitelisted(string $phone): bool
    {
        try {
            return in_array($phone, PhoneWhitelist::phonesForHost((string) parse_url((string) config('app.url'), PHP_URL_HOST)), true);
        } catch (Throwable) {
            return false;
        }
    }

    /** Ang petsa ng order (Y-m-d) mula sa A ng row; kung hindi mabasa, ngayon (Manila). */
    private static function date(string $raw): string
    {
        $ts = LiveOrder::timestamp($raw);

        return preg_match('/(\d{2})-(\d{2})-(\d{4})$/', $ts, $m) ? $m[3] . '-' . $m[2] . '-' . $m[1] : now('Asia/Manila')->toDateString();
    }

    private static function tenDigits(string $phone): string
    {
        $d = preg_replace('/\D/', '', $phone) ?? '';

        return strlen($d) >= 10 ? substr($d, -10) : '';
    }

    private static function name(string $name): string
    {
        $n = Text::lower(Text::collapse(Text::jsTrim($name)));

        return $n === 'unknown' ? '' : $n;
    }
}
