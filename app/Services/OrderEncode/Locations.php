<?php

namespace App\Services\OrderEncode;

/**
 * Ang LocationData ng AFTER MACRO (B province, C city, D barangay) bilang mga mapa ng 1.0 OUTPUT (buildLocationMaps_ at ang
 * getCityListByProvince_ / getBarangayList_ / inferCityFromBarangay_).
 */
final class Locations
{
    /** @var array<string, array<string, string>> province => [cityNorm => cityLabel] */
    private array $cityMap = [];
    /** @var array<string, array<string, true>> "cityNorm|provNorm" => [brgyLabel => true] */
    private array $brgyMap = [];
    /** @var array<string, string> provNorm => provLabel */
    private array $provMap = [];
    /** @var array<string, array<string, true>> "brgyNorm|provNorm" => [cityLabel => true] */
    private array $brgyToCities = [];

    public string $provListCsv = '';

    /** @param array<int, array<int, mixed>> $rows B, C, D ng bawat row, ayon sa ayos */
    public function __construct(array $rows)
    {
        foreach ($rows as $r) {
            $r = array_pad(array_values((array) $r), 3, '');
            $provLabel = Text::jsTrim((string) $r[0]);
            $p = Text::normProv((string) $r[0]);
            $cNorm = Text::normPlace((string) $r[1]);
            $cityLabel = Text::jsTrim((string) $r[1]);
            $brgyLabel = Text::normalizeBrgyLabel((string) $r[2]);

            if ($p !== '' && $provLabel !== '' && !isset($this->provMap[$p])) $this->provMap[$p] = $provLabel;

            if ($p !== '' && $cNorm !== '' && $cityLabel !== '') {
                $this->cityMap[$p][$cNorm] ??= $cityLabel;
            }

            if ($p !== '' && $cNorm !== '' && $brgyLabel !== '') {
                $this->brgyMap[$cNorm . '|' . $p][$brgyLabel] = true;
                $brgyNorm = Text::brgyKey($brgyLabel);
                if ($brgyNorm !== '') $this->brgyToCities[$brgyNorm . '|' . $p][$cityLabel] = true;
            }
        }

        $list = array_values(array_filter($this->provMap, fn ($s) => $s !== ''));
        usort($list, fn ($a, $b) => Text::compareLocale($a, $b));
        $this->provListCsv = implode(', ', $list);
    }

    public function isEmpty(): bool
    {
        return $this->provMap === [];
    }

    public function cityList(?string $province): string
    {
        $p = Text::normProv($province);
        if ($p === '' || empty($this->cityMap[$p])) return '';
        $list = array_values($this->cityMap[$p]);
        usort($list, fn ($a, $b) => Text::compareLocale($a, $b));

        return implode(', ', $list);
    }

    public function barangayList(?string $city, ?string $province): string
    {
        $c = Text::normPlace($city);
        $p = Text::normProv($province);
        if ($c === '' || $p === '') return '';

        $set = $this->brgyMap[$c . '|' . $p] ?? null;
        if (!$set) $set = $this->brgyMap[$p . '|' . $c] ?? null; // ang swapped fallback ng script
        if (!$set) return '';
        $list = array_map('strval', array_keys($set));
        usort($list, fn ($a, $b) => Text::compareLocale($a, $b));

        return implode(', ', $list);
    }

    /** @return array<int, string> ang mga city na may ganitong barangay sa province */
    public function inferCities(?string $province, ?string $barangay): array
    {
        $p = Text::normProv($province);
        if ($p === '') return [];
        $b = Text::brgyKey($barangay);
        if ($b === '') return [];

        return array_map('strval', array_keys($this->brgyToCities[$b . '|' . $p] ?? []));
    }
}
