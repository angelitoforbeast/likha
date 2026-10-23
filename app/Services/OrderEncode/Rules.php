<?php

namespace App\Services\OrderEncode;

use App\Models\AddressNote;

/**
 * Ang mga mas ligtas na panuntunan ng encoder (spec 135, app_settings order_encode_rules = improved). Ang sheet mode ay walang
 * Rules object: ang encoder at checker ay kapareho ng dalawang script. Hawak ng isang Rules ang isang order: ang isinulat ng
 * customer (Province, City, Brgy ng extracted details) at kung may note na tumama.
 *
 *  R1 ang isinulat na barangay/city na nasa J&T list ng current na lugar ay hindi pinapalitan ng GPT
 *  R2 ang isinulat na province na iba sa resulta = hold (at hindi umaandar ang "Province only → ✅")
 *  R3 mas mabuting unang candidate (Macro)
 *  R4 eksaktong J&T label sa output; PROCEED lang kung ang kombinasyon ay nasa J&T file
 *  R5 doble sa parehong araw (Duplicates, sa Runner)
 */
class Rules
{
    public const NAME = 'improved';

    /** @var array{province: string, city: string, barangay: string} */
    public array $written = ['province' => '', 'city' => '', 'barangay' => ''];
    public string $key = '';
    public bool $noteHit = false;
    public ?AddressNote $note = null;
    /** @var array<int, array{rule: string, reason: string}> */
    public array $holds = [];

    public function __construct(private Locations $loc, private ?Notes $notes = null)
    {
    }

    /** @param array{province?: string, city?: string, barangay?: string, name?: string, phone?: string} $parts ang Parse::addressParts */
    public function begin(array $parts): void
    {
        $this->written = ['province' => (string) ($parts['province'] ?? ''), 'city' => (string) ($parts['city'] ?? ''), 'barangay' => (string) ($parts['barangay'] ?? '')];
        $this->key = Written::key($this->written);
        $this->noteHit = false;
        $this->note = null;
        $this->holds = [];
    }

    /** R3: ang gusto ng Macro sa pagpili ng unang candidate (barangay muna, saka city), o null kung walang isinulat. */
    public function prefer(): ?array
    {
        $b = Written::brgy($this->written['barangay']);
        $c = Written::city($this->written['city'], $this->written['province']);

        return $b === '' && $c === '' ? null : ['barangay' => $b, 'city' => $c];
    }

    /** Ang approved na note ng eksaktong isinulat; itinatakda ang noteHit. */
    public function lookupNote(): ?AddressNote
    {
        $this->note = $this->notes?->exact($this->key);
        $this->noteHit = $this->note !== null;

        return $this->note;
    }

    /** R1: ang label sa listahan ng barangay (CSV) na katumbas ng buong isinulat na barangay, o null. */
    public function writtenBarangay(string $csv): ?string
    {
        $w = Written::brgy($this->written['barangay']);
        if ($w === '') return null;
        foreach (self::items($csv) as $label) {
            if (Written::brgy($label) === $w) return $label;
        }

        return null;
    }

    /** R1: ang label sa listahan ng city (CSV) ng province na katumbas ng buong isinulat na city, o null. */
    public function writtenCity(string $csv, string $province): ?string
    {
        $w = Written::city($this->written['city'], $this->written['province'] !== '' ? $this->written['province'] : $province);
        if ($w === '') return null;
        foreach (self::items($csv) as $label) {
            if (Written::city($label, $province) === $w) return $label;
        }

        return null;
    }

    /** R4: ang label ng listahan (CSV) na katumbas ng $value, ayon sa $same; kung wala, ang $value mismo. */
    public static function canonical(string $csv, string $value, callable $same): string
    {
        foreach (self::items($csv) as $label) {
            if ($same($label, $value)) return $label;
        }

        return $value;
    }

    /** R2: bakit hold ang resultang province (ang isinulat na province ay iba ayon sa J&T), o null. */
    public function provinceContradiction(string $resultProvince): ?string
    {
        $written = $this->loc->provinceOf($this->written['province']);
        if ($written === null) return null; // "[Please confirm]", "N/A" at iba pang hindi province: hindi binibilang

        return Text::normProv($written) === Text::normProv($resultProvince) ? null : 'Province differs from what the customer wrote';
    }

    /** R4: bakit hold ang kombinasyon (wala sa J&T file), o null. */
    public function combinationProblem(string $province, string $city, string $barangay): ?string
    {
        return $this->loc->hasCombination($province, $city, $barangay) ? null : 'Not a J&T address combination';
    }

    /** @return array{rule: string, reason: string}|null ang unang hold sa R2 at R4 para sa huling E/F/G */
    public function checkFinal(string $province, string $city, string $barangay): ?array
    {
        // Tumama ang note: ang approved na sagot ay ang pagtatama sa isinulat na province, kaya R4 lang.
        if (!$this->noteHit && ($why = $this->provinceContradiction($province)) !== null) return ['rule' => 'R2', 'reason' => $why];
        if (($why = $this->combinationProblem($province, $city, $barangay)) !== null) return ['rule' => 'R4', 'reason' => $why];

        return null;
    }

    /** Ang "Past corrections" para sa mga prompt ng GPT: hanggang 5 approved na note (kapag wala, walang laman). */
    public function examplesBlock(): string
    {
        if (!$this->notes || $this->noteHit) return '';
        $this->examples ??= $this->notes->examples($this->key);
        if (!$this->examples) return '';
        $lines = array_map(fn (AddressNote $n) => '- ' . $n->written_example . ' → ' . $n->province . ' / ' . $n->city . ' / ' . $n->barangay, $this->examples);

        return "\nPast corrections (customer wrote → correct J&T address):\n" . implode("\n", $lines) . "\n";
    }

    /** @var array<int, AddressNote>|null */
    private ?array $examples = null;

    /** Ilang halimbawa ang naipasa sa GPT (para sa talaan ng hakbang). */
    public function exampleCount(): int
    {
        return count($this->examples ?? []);
    }

    /** @return array<int, string> */
    private static function items(string $csv): array
    {
        return array_values(array_filter(array_map(fn ($s) => Text::jsTrim($s), explode(',', $csv)), fn ($s) => $s !== ''));
    }
}
