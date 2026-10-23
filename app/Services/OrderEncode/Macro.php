<?php

namespace App\Services\OrderEncode;

/**
 * STEP 6 ng auto-encode.gs (Macro()) at ang mga formula ng ENCODER (encoder-formulas.md): ang LocationData ng macro sheet
 * (A = "PROVINCE CITY BARANGAY", B province, C city, D barangay, ayon sa ayos ng sheet) at ang pagbuo ng P1 salita por salita.
 */
final class Macro
{
    /** @var array<int, array{0: string, 1: string, 2: string, 3: string}> */
    private array $rows;
    /** @var array<int, string> ang A ng bawat row, lowercase (para sa FILTER) */
    private array $hay;

    /** @param array<int, array<int, mixed>> $rows A, B, C, D ng LocationData (A2:D) */
    public function __construct(array $rows)
    {
        $this->rows = [];
        $this->hay = [];
        foreach ($rows as $r) {
            $r = array_pad(array_values((array) $r), 4, '');
            $a = (string) $r[0] !== '' ? (string) $r[0] : trim($r[1] . ' ' . $r[2] . ' ' . $r[3]);
            if ($a === '') continue;
            $this->rows[] = [$a, (string) $r[1], (string) $r[2], (string) $r[3]];
            $this->hay[] = Text::lower($a);
        }
    }

    /**
     * Q1 at U1: ang FILTER ng sheet. Bawat salita ng P1 (hinati sa espasyo, hanggang 6) ay dapat substring (walang pakialam sa
     * laki ng letra) ng A. Ang una sa ayos ng listahan ang Q1; ang bilang ang U1.
     * @return array{first: ?array{0: string, 1: string, 2: string, 3: string}, count: int}
     */
    public function filter(string $p1): array
    {
        if ($p1 === '') return ['first' => null, 'count' => 0];
        $words = array_slice(array_values(array_filter(explode(' ', Text::lower($p1)), fn ($w) => $w !== '')), 0, 6);
        if (!$words) return ['first' => null, 'count' => 0];

        $first = null;
        $count = 0;
        foreach ($this->hay as $i => $h) {
            foreach ($words as $w) {
                if (!str_contains($h, $w)) continue 2;
            }
            $first ??= $this->rows[$i];
            $count++;
        }

        return ['first' => $first, 'count' => $count];
    }

    /** Ang Q1 gaya ng nababasa ng script: lowercase, "-" = espasyo; walang tugma = "#N/A". */
    private function q1(string $p1): string
    {
        $f = $this->filter($p1)['first'];
        if ($p1 === '') return '';

        return str_replace('-', ' ', Text::lower($f === null ? '#N/A' : $f[0]));
    }

    /**
     * Macro() para sa row 2. Walang province, city at barangay = walang sinusulat (null).
     * @return array{points: int, remark: string, icon: string, province: string, city: string, barangay: string, p1: string, candidates: int}|null
     */
    public function run(string $provinceRaw, string $cityRaw, string $barangayRaw): ?array
    {
        [$province, $city, $barangay] = array_map(fn ($v) => Text::jsTrim(str_replace('-', ' ', Text::lower($v))), [$provinceRaw, $cityRaw, $barangayRaw]);
        if ($province === '' && $city === '' && $barangay === '') return null;

        $k1 = '';
        if ($province !== '') {
            $k1 .= $province;
            if (!str_contains($this->q1($k1), $province)) $k1 = Text::jsTrim(Text::replaceFirst($k1, $province, ''));
        }
        if ($city !== '') {
            $k1 .= ($k1 !== '' ? ' ' : '') . $city;
            if (!str_contains($this->q1($k1), $city)) $k1 = Text::jsTrim(Text::collapse(Text::removeWord($k1, $city)));
        }
        if ($barangay !== '') {
            $k1 .= ($k1 !== '' ? ' ' : '') . $barangay;
            // Ang script ay binabasa ang column 24 (X, hindi V) bilang "points now"; blangko ito sa bagong row, kaya hindi kailanman 7.
            if (!str_contains($this->q1($k1), $barangay)) $k1 = Text::jsTrim(Text::collapse(Text::removeWord($k1, $barangay)));
        }

        $q1 = $this->q1($k1);
        $points = 0;
        if ($province !== '' && str_contains($q1, $province)) $points += 1;
        if ($city !== '' && str_contains($q1, $city)) $points += 2;
        if ($barangay !== '' && str_contains($q1, $barangay)) $points += 4;

        $f = $this->filter($k1);
        $suggestions = $f['count'];
        $remark = '';
        $icon = '';
        if ($points === 7 && $suggestions > 1) {
            $remark = 'MULTIPLE SUGGESTED ADDRESS';
        } elseif ($points === 7 && $suggestions === 1) {
            $icon = '✅';
        } else {
            $remark = [6 => 'Province', 5 => 'City', 4 => 'Province and City', 3 => 'Barangay', 2 => 'Province and Barangay', 1 => 'City and Barangay'][$points] ?? '❌';
            $icon = '❌';
        }

        // R1/S1/T1 = VLOOKUP ng Q1: walang tugma = #N/A.
        $hit = $f['first'];

        return [
            'points' => $points, 'remark' => $remark, 'icon' => $icon,
            'province' => $hit[1] ?? '#N/A', 'city' => $hit[2] ?? '#N/A', 'barangay' => $hit[3] ?? '#N/A',
            'p1' => $k1, 'candidates' => $suggestions,
        ];
    }
}
