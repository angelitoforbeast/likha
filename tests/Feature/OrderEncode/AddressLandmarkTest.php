<?php

namespace Tests\Feature\OrderEncode;

use App\Services\AddressLandmark;
use App\Services\AstraAddressRules;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Spec 187: ang iisang panuntunan ng landmark sa address (encode order at Astra). */
class AddressLandmarkTest extends TestCase
{
    #[DataProvider('placeholders')]
    public function test_S_187_3_placeholders_are_no_landmark(string $landmark): void
    {
        $this->assertSame(['address' => 'Pk. 17, Zone 7,', 'result' => 'none'], AddressLandmark::apply('Pk. 17, Zone 7,', $landmark));
    }

    public static function placeholders(): array
    {
        $list = ['', '  ', '-', '--', '.', 'N/A', 'n/a', 'NA', 'N.A.', 'none', 'None po', 'wala', 'Wala po', 'Wala pong nabanggit', 'wala pong na-mention',
            'Wala pong nakalagay', 'walang landmark', 'Not provided', 'not mentioned', 'No landmark', 'unknown', '[Not provided]', '(Wala pong na-mention)',
            '[customer did not give one]', 'Hindi pa naibigay', 'hindi po nabanggit', 'malapit sa N/A'];

        return array_combine($list, array_map(fn ($p) => [$p], $list));
    }

    #[DataProvider('realLandmarks')]
    public function test_S_187_3_real_landmarks_are_kept_even_when_they_start_like_a_placeholder(string $landmark, string $expected): void
    {
        $this->assertSame(['address' => $expected, 'result' => 'added'], AddressLandmark::apply('Purok 3', $landmark));
    }

    public static function realLandmarks(): array
    {
        return [
            'hindi kalayuan'  => ['Hindi kalayuan sa simbahan', 'Purok 3, near Hindi kalayuan sa simbahan'],
            'tapat ng'        => ['tapat ng 7-Eleven', 'Purok 3, near 7-Eleven'],
            'near'            => ['Near Brgy. Hall', 'Purok 3, near Brgy. Hall'],
            'NA in a word'    => ['Nayon chapel', 'Purok 3, near Nayon chapel'],
        ];
    }

    public function test_S_187_1_the_landmark_is_appended_after_the_address_without_a_double_comma(): void
    {
        $this->assertSame(['address' => 'Pk. 17, Zone 7, near Brgy. Hall', 'result' => 'added'], AddressLandmark::apply('Pk. 17, Zone 7,', 'Malapit sa Brgy. Hall'));
    }

    public function test_S_187_2_a_landmark_already_inside_the_address_any_case_or_spacing_is_kept_as_is(): void
    {
        $this->assertSame(['address' => 'Pk 17  NEAR brgy.   hall,', 'result' => 'kept'], AddressLandmark::apply('Pk 17  NEAR brgy.   hall,', 'Brgy. Hall'));
    }

    public function test_S_187_4_a_long_address_with_a_landmark_is_capped_at_a_word_and_never_over_the_limit(): void
    {
        $address = trim(str_repeat('Sitio Malaking Bato ', 13)); // 259 chars
        $out = AddressLandmark::apply($address, 'Brgy. Hall');

        $this->assertSame(250, AddressLandmark::MAX);
        $this->assertSame('added', $out['result']);
        $this->assertLessThanOrEqual(250, mb_strlen($out['address']));
        $this->assertStringStartsWith('Sitio Malaking Bato Sitio', $out['address']);
        $this->assertMatchesRegularExpression('/(Sitio|Malaking|Bato)$/', $out['address'], 'cut at a word boundary, no trailing comma or space');

        // Isang mahabang salita: walang hangganan na malapit, kaya putol sa mismong limit.
        $one = AddressLandmark::apply(str_repeat('Z', 300), 'Brgy. Hall');
        $this->assertSame(str_repeat('Z', 250), $one['address']);

        // Kasya: buo.
        $fits = str_repeat('a', 230);
        $this->assertSame($fits . ', near Brgy. Hall', AddressLandmark::apply($fits, 'Brgy. Hall')['address']);
    }

    #[DataProvider('astraForms')]
    public function test_S_187_5_astra_composes_the_address_with_the_shared_rule(array $form, string $expected): void
    {
        $this->assertSame($expected, AstraAddressRules::composeAddress($form));
        $this->assertSame($expected, AddressLandmark::compose([$form['house_number'] ?? '', $form['purok_sitio'] ?? '', $form['address'] ?? ''], (string) ($form['landmark'] ?? '')));
    }

    public static function astraForms(): array
    {
        return [
            'parts and a landmark'          => [['house_number' => '12', 'purok_sitio' => 'Purok 3', 'address' => 'Sampaguita St', 'landmark' => 'malapit sa chapel'], '12, Purok 3, Sampaguita St, near chapel'],
            'a part inside another'         => [['house_number' => '8543', 'address' => '8543 OB Junction', 'landmark' => '-'], '8543 OB Junction'],
            'landmark inside the address'   => [['address' => 'Rizal St near Jollibee', 'landmark' => 'Jollibee'], 'Rizal St near Jollibee'],
            'a part inside the landmark'    => [['address' => 'Naic Public Market', 'landmark' => "Naic Public Market, near Jekep's"], "near Naic Public Market, near Jekep's"],
            'only a landmark'               => [['landmark' => 'tapat ng plaza'], 'near plaza'],
            'a placeholder landmark'        => [['address' => 'Rizal St', 'landmark' => 'N/A'], 'Rizal St'],
        ];
    }
}
