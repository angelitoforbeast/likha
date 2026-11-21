<?php

namespace Tests\Feature\OrderEncode;

use App\Services\AddressLandmark;
use App\Services\OrderEncode\FormAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Spec 311: ang address ng order form ay binubuo sa code (kasama ang purok), at ang placeholder ay hindi address. */
class FormAddressTest extends TestCase
{
    /** Ang form ng extracted details, may "- " sa unahan gaya ng flow; ang null ay walang linya. */
    private static function form(?string $house, ?string $purok, ?string $address, ?string $landmark = null, string $brgy = 'Lahug', string $city = 'Cebu City', string $prov = 'Cebu'): string
    {
        $lines = ['- Name: Ana Cruz', '- Phone Number: 09171234567'];
        if ($house !== null) $lines[] = '- House Number: ' . $house;
        if ($purok !== null) $lines[] = '- Purok/Sitio: ' . $purok;
        if ($address !== null) $lines[] = '- Address: ' . $address;
        $lines[] = '- Brgy: ' . $brgy;
        $lines[] = '- City: ' . $city;
        $lines[] = '- Province: ' . $prov;
        if ($landmark !== null) $lines[] = '- Landmark: ' . $landmark;

        return implode("\n", $lines);
    }

    public function test_S_311_PA1_H_the_parts_are_composed_with_the_landmark(): void
    {
        $form = FormAddress::read(self::form('12', 'Purok 5', 'Rizal St', 'malapit sa chapel'));

        $this->assertSame('parts', $form['shape']);
        $this->assertSame('12, Purok 5, Rizal St', FormAddress::compose($form));
        $this->assertSame('12, Purok 5, Rizal St, near chapel', FormAddress::compose($form, 'malapit sa chapel'));
        $this->assertSame(AddressLandmark::compose(['12', 'Purok 5', 'Rizal St'], 'malapit sa chapel'), FormAddress::compose($form, 'malapit sa chapel'));
    }

    #[DataProvider('samePurok')]
    public function test_S_311_PA1_H_a_purok_already_in_the_line_appears_once(string $line, string $purok): void
    {
        $this->assertTrue(FormAddress::hasPurok($line, $purok), "{$purok} in {$line}");
        $this->assertSame(['address' => $line, 'result' => 'kept'], FormAddress::keepPurok($line, $purok));
    }

    public static function samePurok(): array
    {
        return [
            'Prk. 5'        => ['Prk. 5, Rizal St', 'Purok 5'],
            'Prk 5'         => ['Prk 5 Rizal St', 'Purok 5'],
            'Pk. 5'         => ['Pk. 5 Rizal St', 'Purok 5'],
            'P-5'           => ['P-5 Rizal St', 'Purok 5'],
            'P5'            => ['P5, Rizal St', 'Purok 5'],
            'Purok No. 5'   => ['Purok No. 5, Rizal St', 'Purok 5'],
            'Purok 05'      => ['Purok 05 Rizal St', 'Purok 5'],
            'Purok V'       => ['Purok V, Rizal St', 'Purok 5'],
            'lowercase'     => ['purok 5 rizal st', 'PUROK 5'],
            'form is Prk.'  => ['Purok 5, Rizal St', 'Prk. 5'],
            'named purok'   => ['Prk. Sampaguita, Rizal St', 'Purok Sampaguita'],
            'sitio So.'     => ['So. Malipayon, Rizal St', 'Sitio Malipayon'],
            'plain value'   => ['Zone 3, Rizal St', 'Zone 3'],
            '(P7)'          => ['(P7) Pido St.', 'Purok 7'],
        ];
    }

    #[DataProvider('otherPurok')]
    public function test_S_311_PA1_H_a_different_or_missing_purok_is_added_in_front(string $line, string $purok, string $expected): void
    {
        $this->assertFalse(FormAddress::hasPurok($line, $purok));
        $this->assertSame(['address' => $expected, 'result' => 'added'], FormAddress::keepPurok($line, $purok));
    }

    public static function otherPurok(): array
    {
        return [
            'no purok'          => ['Rizal St', 'Purok 5', 'Purok 5, Rizal St'],
            'another number'    => ['Prk. 15, Rizal St', 'Purok 5', 'Purok 5, Prk. 15, Rizal St'],
            'Phase 5 is not P5' => ['Phase 5, Rizal St', 'Purok 5', 'Purok 5, Phase 5, Rizal St'],
            'trailing comma'    => ['Rizal St,', 'Sitio Malipayon', 'Sitio Malipayon, Rizal St'],
        ];
    }

    #[DataProvider('placeholderPurok')]
    public function test_S_311_PA1_N_a_placeholder_purok_adds_nothing(string $purok): void
    {
        $form = FormAddress::read(self::form('12', $purok, 'Rizal St'));

        $this->assertSame('', FormAddress::purok($form));
        $this->assertSame('12, Rizal St', FormAddress::compose($form));
        $this->assertSame(['address' => 'Rizal St', 'result' => 'none'], FormAddress::keepPurok('Rizal St', FormAddress::purok($form)));
    }

    public static function placeholderPurok(): array
    {
        $list = ['N/A', '-', '(Wala pong na-mention)', 'none', 'Wala', '', 'n/a', 'None provided', '[not provided]'];

        return array_combine(array_map(fn ($p) => $p === '' ? 'blank' : $p, $list), array_map(fn ($p) => [$p], $list));
    }

    public function test_S_311_PA1_E_the_old_one_line_form_and_no_form_are_left_to_the_ai(): void
    {
        $this->assertSame('one_line', FormAddress::read("- Name: Ana Cruz\n- Address: Blk 5 Lot 3 Lahug\n- Brgy: Lahug")['shape']);
        $this->assertSame('none', FormAddress::read("- Name: Ana Cruz\n- Brgy: Lahug\n- City: Cebu City")['shape']);
        $this->assertSame('none', FormAddress::read('Hi po, order ko 2 pcs')['shape']);
    }

    public function test_S_311_PA1_the_form_places_are_taken_off_and_the_cap_holds(): void
    {
        $this->assertSame('12, Purok 5, Rizal St', FormAddress::compose(FormAddress::read(self::form('12', 'Purok 5', 'Rizal St, Brgy. Lahug, Cebu City, Cebu'))));
        $this->assertSame('Purok 5 Rizal St', FormAddress::compose(FormAddress::read(self::form('-', '-', 'Purok 5 Rizal St Lahug Cebu City'))));
        // Sa dulo lang at buong salita: ang kalye na kapangalan ng lugar ay hindi ginagalaw.
        $this->assertSame('Rizal St', FormAddress::compose(FormAddress::read(self::form('-', '-', 'Rizal St', null, 'San Isidro', 'Tanay', 'Rizal'))));
        $this->assertSame('Purok 5, Malahug', FormAddress::compose(FormAddress::read(self::form('-', 'Purok 5', 'Malahug'))));
        // Ang barangay na numero ("Barangay 5") ay hindi tinatanggal sa "Purok 5"; ang "Brgy. 5" lang.
        $this->assertSame('Purok 5', FormAddress::compose(FormAddress::read(self::form('-', 'Purok 5', 'Brgy. 5', null, 'Barangay 5', 'Mandaue City', 'Cebu'))));
        // Ang bahagi na lugar lang ay walang laman.
        $this->assertSame('', FormAddress::compose(FormAddress::read(self::form('-', '-', 'Lahug, Cebu City'))));
        // Ang cap ng 250 character.
        $long = FormAddress::compose(FormAddress::read(self::form('12', 'Purok 5', str_repeat('Sampaguita ', 40))), 'chapel');
        $this->assertLessThanOrEqual(AddressLandmark::MAX, mb_strlen($long));
        $this->assertStringStartsWith('12, Purok 5, Sampaguita', $long);
    }

    public function test_S_311_PA1_no_duplicate_parts(): void
    {
        $this->assertSame('12 Purok 5 Rizal St', FormAddress::compose(FormAddress::read(self::form('12', 'Purok 5', '12 Purok 5 Rizal St'))));
        $this->assertSame('12, Prk. 5, Rizal St', FormAddress::compose(FormAddress::read(self::form('12', 'Purok 5', 'Prk. 5, Rizal St'))));
    }

    #[DataProvider('addressPlaceholders')]
    public function test_S_311_PA2_every_listed_placeholder_is_not_an_address(string $value): void
    {
        $this->assertTrue(AddressLandmark::isPlaceholderAddress($value), $value);
    }

    public static function addressPlaceholders(): array
    {
        $list = [
            // ang listahan ng spec 311
            'n/a', 'N/A', 'no address line 1 or landmark provided.', 'no address line provided.', 'no address line found.', '---',
            'not provided', '[not provided]', '[missing]', '(Wala pong namention)', '(Wala pong na-mention)',
            // mga kahawig
            'No Address Line 1 provided', 'No address provided', 'No address', 'no specific address provided', 'Address not provided',
            'Address: N/A', 'Address line 1 not found', 'missing', 'Not found', 'not specified', 'none', 'None po', 'Unknown', 'unknown.',
            'null', 'NA', 'N.A.', '-', '--', '.', '', '  ', 'Wala', 'wala pong nabanggit', 'walang address', 'Hindi po nabanggit',
            '[customer did not give one]', '(none)', '[N/A]', 'No house number or street', 'no address line 1 / landmark',
        ];

        return array_combine(array_map(fn ($p) => trim($p) === '' ? 'blank ' . strlen($p) : $p, $list), array_map(fn ($p) => [$p], $list));
    }

    #[DataProvider('realAddresses')]
    public function test_S_311_PA2_N_a_real_address_is_not_a_placeholder(string $value): void
    {
        $this->assertFalse(AddressLandmark::isPlaceholderAddress($value), $value);
    }

    public static function realAddresses(): array
    {
        $list = ['(P7) Pido St.', '(Purok 7)', '[Blk 5 Lot 3]', 'Purok 5, Rizal St', 'No. 5 Rizal St', 'No 12 Mabini St', 'Blk 5 Lot 3',
            'Sitio Malipayon', 'Zone 3', 'Near chapel', 'Narra St', 'Nasipit Road', 'Unit 4 Nova Bldg', '(near the school) Purok 2'];

        return array_combine($list, array_map(fn ($p) => [$p], $list));
    }

    public function test_S_311_PA2_the_landmark_rule_is_unchanged(): void
    {
        // Ang landmark ay may sariling listahan pa rin: ang "(Purok 7)" bilang landmark ay tala, at ang address-only na tala ay landmark pa.
        $this->assertSame('', AddressLandmark::clean('(Purok 7)'));
        $this->assertSame('missing', AddressLandmark::clean('missing'));
    }
}
