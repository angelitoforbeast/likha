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

    /** @var array<string, true> "province|city|barangay" ng file, maliit na titik at Ñ = N: ang buong kombinasyon gaya ng isinulat ng J&T */
    private array $combos = [];

    public string $provListCsv = '';

    /**
     * @param array<int, array<int, mixed>> $rows B, C, D ng bawat row, ayon sa ayos
     * @param bool $exactLabels true: ang barangay sa mga listahan ay eksaktong gaya ng sa J&T file (walang "BARANGAY x" na pagbabago); ang pagtutugma ay ganoon pa rin
     */
    public function __construct(array $rows, private bool $exactLabels = false)
    {
        foreach ($rows as $r) {
            $r = array_pad(array_values((array) $r), 3, '');
            $provLabel = Text::jsTrim((string) $r[0]);
            $p = Text::normProv((string) $r[0]);
            $cNorm = Text::normPlace((string) $r[1]);
            $cityLabel = Text::jsTrim((string) $r[1]);
            $brgyLabel = $this->exactLabels ? Text::jsTrim((string) $r[2]) : Text::normalizeBrgyLabel((string) $r[2]);
            if ($provLabel !== '' && $cityLabel !== '' && $brgyLabel !== '') $this->combos[Text::lower($provLabel . '|' . $cityLabel . '|' . Text::jsTrim((string) $r[2]))] = true;

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

    /** Ang J&T province na tinutukoy ng isinulat ng customer (Metro Manila = NCR = manila), o null kapag wala sa listahan. */
    public function provinceOf(?string $written): ?string
    {
        $p = Text::normProv($written);

        return $p === '' ? null : ($this->provMap[$p] ?? null);
    }

    /** Nasa J&T file ba ang eksaktong PROVINCE | CITY | BARANGAY na ito (walang pakialam sa laki ng letra at Ñ)? */
    public function hasCombination(?string $province, ?string $city, ?string $barangay): bool
    {
        return isset($this->combos[Text::lower(Text::jsTrim($province) . '|' . Text::jsTrim($city) . '|' . Text::jsTrim($barangay))]);
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
