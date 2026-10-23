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
        if (Text::normProv($written) === Text::normProv($resultProvince)) return null;
        // Madalas maluwag ang isinulat na province ("Cotabato" sa GenSan, "Rizal" sa Pasig, "Quezon" sa Quezon City): hold lang kapag
        // ang isinulat na city ay nasa isinulat na province, kaya talagang ibang lugar ang sinasabi ng customer (hal. San Fernando, La Union).
        if ($this->writtenCity($this->loc->cityList($written), $written) === null) return null;

        return 'Province differs from what the customer wrote';
    }

    /**
     * R6: ibang barangay o city na nabanggit din sa chat ng customer. Ang mas buong label ng parehong barangay (NOVALICHES →
     * NOVALICHES PROPER, kapag nasa chat ang dagdag na salita) ang ibinabalik; kapag may ibang barangay ng city o ibang city ng
     * province na buong salitang nasa chat, hold, dahil madalas distrito ang napipili (Novaliches, Tugbok) imbes na ang barangay.
     *
     * @return array{barangay?: string, hold?: string}
     */
    public function ambiguity(string $province, string $city, string $barangay, string $chat): array
    {
        $text = ' ' . self::words($chat) . ' ';
        if (trim($text) === '') return [];
        $chosen = Written::brgy($barangay);
        $cityKey = Written::city($city, $province);
        // Ang mga salitang bahagi na ng napiling lugar ("davao" sa Davao del Sur, "princesa" sa Puerto Princesa) at ang mga
        // pangkaraniwang salita (poblacion, zone, purok) ay hindi binibilang na ibang lugar (2026-10-24, ikaapat na test).
        $own = ' ' . self::words($province . ' ' . str_replace('-', ' ', $city) . ' ' . $barangay) . ' ';
        $skip = fn (string $k) => $k === '' || mb_strlen($k) < 4 || preg_match('/^(\d+|[ivxlc]+)$/u', $k) || $k === $cityKey || $k === Written::prov($province)
            || str_contains($own, ' ' . $k . ' ') || preg_match('/^(poblacion|zone|purok|sitio|district|dist|proper|centro|ilaya|ibaba)\b/u', $k);

        $longer = null;
        $others = [];
        foreach (self::items($this->loc->barangayList($city, $province)) as $label) {
            $k = Written::brgy($label);
            if ($k === $chosen || $skip($k)) continue;
            if ($chosen !== '' && str_starts_with($k, $chosen . ' ')) {
                if (self::wordsIn(trim(substr($k, strlen($chosen))), $text)) $longer = $label;
                continue;
            }
            if (self::mentioned($k, $text)) $others[] = $label;
        }
        if ($longer !== null) return ['barangay' => $longer];
        if ($others) return ['hold' => 'Another barangay of the city is also in the chat: ' . implode(', ', array_slice($others, 0, 3))];

        foreach (self::items($this->loc->cityList($province)) as $label) {
            $k = Written::city($label, $province);
            if ($k === $cityKey || $k === $chosen || $skip($k) || mb_strlen($k) < 5 || preg_match('/^(san|santa|santo|sta|sto)\b/u', $k)) continue;
            if (self::mentioned($k, $text)) return ['hold' => 'Another city of the province is also in the chat: ' . $label];
        }

        return [];
    }

    private static function words(string $s): string
    {
        $s = str_replace(['ñ', 'Ñ'], 'n', mb_strtolower($s));

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/u', ' ', $s) ?? '') ?? '');
    }

    /** Buong salitang nasa text, maliban kung kalye o subdivision ang tinutukoy ("manalo extension", "rizal st"). */
    private static function mentioned(string $k, string $text): bool
    {
        $street = '(st|street|ave|avenue|rd|road|blvd|ext|extension|extention|village|vill|subd|subdivision|homes|hts|heights|drive|dr|highway)';
        if (!preg_match_all('/ ' . preg_quote($k, '/') . ' (\S+)?/u', $text, $m)) return false;
        foreach ($m[1] as $next) {
            if (!preg_match('/^' . $street . '$/u', (string) $next)) return true;
        }

        return false;
    }

    /** Bawat salita ng $needle (3+ letra) ay nasa text, eksakto o halos pareho ang baybay ("propir" = "proper"). */
    private static function wordsIn(string $needle, string $text): bool
    {
        $tokens = explode(' ', trim($text));
        foreach (explode(' ', $needle) as $w) {
            if (mb_strlen($w) < 3) continue;
            $ok = false;
            foreach ($tokens as $t) {
                if ($t === $w) { $ok = true; break; }
                if (strlen($w) >= 4 && abs(strlen($t) - strlen($w)) <= 2) {
                    similar_text($t, $w, $p);
                    if ($p >= 70) { $ok = true; break; }
                }
            }
            if (!$ok) return false;
        }

        return true;
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
