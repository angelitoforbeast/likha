<?php

namespace Tests\Feature\OrderEncode;

use App\Services\OrderEncode\Input;
use App\Services\OrderEncode\Parse;
use App\Services\OrderEncode\Text;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Ang mga maliliit na patakaran ng dalawang script: EXTRACTED DETAILS, item/presyo, normalisers, fuzzy match, mga listahan. */
class TextAndParseTest extends TestCase
{
    public static function phones(): array
    {
        return [
            'plain' => ['09171234567', '09171234567'],
            '+63' => ['+63 917 123 4567', '09171234567'],
            '63' => ['639171234567', '09171234567'],
            'dashes' => ['0917-123-4567', '09171234567'],
            'too short' => ['0917123456', ''],
            'not 09' => ['08171234567', ''],
            'letters' => ['n/a', ''],
        ];
    }

    #[DataProvider('phones')]
    public function test_S_130_40_phone_forms_in_the_extracted_details(string $given, string $expected): void
    {
        $this->assertSame($expected, Parse::addressParts("- Phone Number: {$given}")['phone']);
    }

    public function test_S_130_40_the_extracted_details_give_name_and_place_words_stripped(): void
    {
        $p = Parse::addressParts("- Name: Ana Cruz\n- Phone Number: 09171234567\n- Province: Province of Cebu.\n- City: Cebu City\n- Brgy: Barangay Lahug (Pob.)");

        $this->assertSame('Ana Cruz', $p['name']);
        $this->assertSame('OF CEBU', $p['province']);
        $this->assertSame('CEBU', $p['city']);
        $this->assertSame('LAHUG POB', $p['barangay']);
    }

    #[DataProvider('notProvided')]
    public function test_S_130_40_na_and_not_provided_leave_the_place_blank(string $value): void
    {
        $p = Parse::addressParts("Province: {$value}\nCity: {$value}\nBarangay: {$value}");

        $this->assertSame(['', '', ''], [$p['province'], $p['city'], $p['barangay']]);
    }

    public static function notProvided(): array
    {
        return [['NA'], ['n/a'], ['N/A'], ['Not Provided'], ['not provided']];
    }

    public function test_S_130_40_a_line_with_other_text_is_ignored_and_the_label_may_have_a_dash(): void
    {
        $p = Parse::addressParts("Address: somewhere\n  - name:  Ben  \nBrgy: x");

        $this->assertSame('Ben', $p['name']);
        $this->assertSame('X', $p['barangay']);
    }

    public function test_S_130_41_item_quantity_and_price_with_and_without_the_peso_sign(): void
    {
        $this->assertSame(['item' => '2 x GLOW SERUM', 'price' => '1,299.00'], Parse::itemPrice("ITEM: GLOW SERUM\nQUANTITY: 2\nPRICE: ₱1,299.00\n"));
        $this->assertSame(['item' => '1 x TEA', 'price' => '499'], Parse::itemPrice("ITEM: TEA\r\nQUANTITY: 1\r\nPRICE: 499"));
        $this->assertSame(['item' => '', 'price' => ''], Parse::itemPrice("ITEM: TEA\nPRICE: free"));
    }

    public function test_S_130_41_a_quantity_of_zero_still_counts_as_a_value(): void
    {
        $this->assertSame('0 x TEA', Parse::itemPrice("ITEM: TEA\nQUANTITY: 0")['item']);
    }

    public static function provinces(): array
    {
        return [['NCR', 'manila'], ['Metro Manila', 'manila'], ['metro-manila', 'manila'], ['National Capital Region', 'manila'], ['Cebu', 'cebu'], ['  Davao   del Sur. ', 'davao del sur'], ['Ñuble', 'nuble'], ['', '']];
    }

    #[DataProvider('provinces')]
    public function test_S_130_42_province_normalising_makes_ncr_one_key(string $given, string $expected): void
    {
        $this->assertSame($expected, Text::normProv($given));
    }

    public function test_S_130_42_place_normalising(): void
    {
        $this->assertSame('manila', Text::normPlace('City of Manila'));
        $this->assertSame('abra dolores', Text::normPlace('ABRA-DOLORES'));
        $this->assertSame('dasmarinas city', Text::normPlace('Dasmariñas City'));
        $this->assertSame('a b', Text::normPlace('a,b'));
    }

    public static function fuzzy(): array
    {
        return [
            'under 3 chars is false' => ['ab', 'ab ab ab', false],
            'exact inside' => ['lahug', 'ako po si ana, lahug cebu', true],
            'one letter off (0.8)' => ['lahug', 'taga lahuq po', true],
            'too far (0.6)' => ['lahug', 'taga lahxx po', false],
            '0 is o' => ['montalban', 'm0ntalban rizal', true],
            '1 is l and i' => ['pilar', 'pi1ar', true],
            'brgy dots dropped' => ['b-r.g y', 'brgy', true],
            'empty message' => ['lahug', '', false],
        ];
    }

    #[DataProvider('fuzzy')]
    public function test_S_130_43_fuzzy_match(string $word, string $message, bool $expected): void
    {
        $this->assertSame($expected, Text::isFuzzyMatch($word, $message));
    }

    public static function cities(): array
    {
        return [
            'city word dropped' => ['Cebu City', 'Samar', 'cebu'],
            'province inside the city once' => ['Cebu City', 'Cebu', 'cebu'],
            'province twice, only the first goes' => ['Cebu Cebu City', 'Cebu', 'cebu'],
            'ncr drops metro and ncr' => ['Metro Manila Makati', 'NCR', 'makati'],
            'manila alone stays' => ['Manila', 'Metro Manila', 'manila'],
            'blank' => ['  ', 'Cebu', ''],
        ];
    }

    #[DataProvider('cities')]
    public function test_S_130_42_city_normalising_for_the_check(string $city, string $province, string $expected): void
    {
        $this->assertSame($expected, Text::normalizeCityForCheck($city, $province));
    }

    public function test_S_130_42_list_membership_and_canonical_province(): void
    {
        $this->assertTrue(Text::cityInList('Cebu-City', 'Mandaue City, CEBU CITY'));
        $this->assertFalse(Text::cityInList('Lapu-Lapu', 'Mandaue City, CEBU CITY'));
        $this->assertTrue(Text::provInList('NCR', 'Cebu, METRO MANILA'));
        $this->assertSame('METRO MANILA', Text::canonicalizeProvince('ncr', 'Cebu, METRO MANILA'));
        $this->assertSame('Wakanda', Text::canonicalizeProvince(' Wakanda ', 'Cebu'));
        $this->assertTrue(Text::brgyInList('Brgy. Lahug', 'BANILAD, LAHUG'));
        $this->assertTrue(Text::brgyInList('barangay 5', 'BARANGAY 5, BARANGAY 6'));
        $this->assertFalse(Text::brgyInList('', 'BANILAD'));
    }

    public function test_S_130_42_the_sheet_turns_numbers_written_as_text_into_numbers(): void
    {
        $this->assertSame('9171234567', Text::sheetValue('09171234567'));
        $this->assertSame('1299', Text::sheetValue('1,299.00'));
        $this->assertSame('1299.5', Text::sheetValue('1299.50'));
        $this->assertSame('0', Text::sheetValue('0'));
        $this->assertSame('2 x TEA', Text::sheetValue('2 x TEA'));
    }

    public function test_S_130_44_the_row_is_read_by_header_names_and_otherwise_by_position(): void
    {
        $row = ['10:00 23-10-2026', 'Page Uno', 'Ana', '0917', 'input', 'shop', 'cxd', '499', 'DONE'];

        $this->assertSame('cxd', Input::fromRow($row)->get('cxd'));
        $this->assertSame('499', Input::fromRow($row)->get('price'));

        $shifted = ['row tag', '10:00', 'Page Uno', 'Ana', '0917', 'input', 'shop', 'cxd', '499'];
        $headers = ['', 'Date', 'Page', 'Name', 'Phone Number', 'All User Input', 'Shop Details', 'Extracted Details', 'Price'];
        $in = Input::fromRow($shifted, $headers);
        $this->assertSame(['10:00', 'Page Uno', 'cxd', '499'], [$in->get('date'), $in->get('page'), $in->get('cxd'), $in->get('price')]);

        $this->assertSame('cxd', Input::fromRow($row, ['x', 'y'])->get('cxd'));
    }
}
