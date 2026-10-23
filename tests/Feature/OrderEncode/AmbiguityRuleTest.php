<?php

namespace Tests\Feature\OrderEncode;

use App\Services\OrderEncode\Locations;
use App\Services\OrderEncode\Rules;
use Tests\TestCase;

/**
 * R6 at ang mas maluwag na R2, mula sa isa-isang pagsuri ng 110 order na magkaiba ang sheet at ang Likha (2026-10-24):
 * ang Likha ay pumili ng distrito (Novaliches, Tugbok, Jolo) kahit nakasulat sa chat ang tiyak na barangay o city.
 */
class AmbiguityRuleTest extends TestCase
{
    private function rules(): Rules
    {
        return new Rules(new Locations([
            ['METRO-MANILA', 'QUEZON-CITY', 'NOVALICHES'], ['METRO-MANILA', 'QUEZON-CITY', 'NOVALICHES PROPER'],
            ['METRO-MANILA', 'QUEZON-CITY', 'TALIPAPA'], ['METRO-MANILA', 'QUEZON-CITY', 'GULOD'], ['METRO-MANILA', 'QUEZON-CITY', 'BUNGAD'],
            ['PALAWAN', 'PUERTO-PRINCESA-CITY', 'MILAGROSA'], ['PALAWAN', 'PUERTO-PRINCESA-CITY', 'MANALO'],
            ['SULU', 'JOLO', 'ALAT'], ['SULU', 'INDANAN', 'KAJATIAN'],
            ['SOUTH-COTABATO', 'GENERAL-SANTOS-CITY', 'TAMBLER'], ['COTABATO', 'KIDAPAWAN-CITY', 'POBLACION'],
            ['LA-UNION', 'LA-UNION-SAN-FERNANDO-CITY', 'TANQUI'], ['PAMPANGA', 'PAMPANGA-SAN-FERNANDO-CITY', 'TANQUI'],
        ], true));
    }

    public function test_another_barangay_of_the_city_in_the_chat_holds_the_order(): void
    {
        $r = $this->rules()->ambiguity('METRO-MANILA', 'QUEZON-CITY', 'NOVALICHES', 'Barangay talipapa novaliches Quizon city , malapit sa Walter mart');
        $this->assertSame(['hold' => 'Another barangay of the city is also in the chat: TALIPAPA'], $r);

        $r = $this->rules()->ambiguity('METRO-MANILA', 'QUEZON-CITY', 'NOVALICHES', 'ca. no. president st sta.Monica village gulod novalitches Quezon city');
        $this->assertSame(['hold' => 'Another barangay of the city is also in the chat: GULOD'], $r);
    }

    public function test_the_longer_label_of_the_same_barangay_is_used_when_its_extra_word_is_in_the_chat(): void
    {
        $r = $this->rules()->ambiguity('METRO-MANILA', 'QUEZON-CITY', 'NOVALICHES', 'brgy novalichis propir Qison cty');
        $this->assertSame(['barangay' => 'NOVALICHES PROPER'], $r);
    }

    public function test_a_street_named_like_another_barangay_does_not_hold(): void
    {
        $this->assertSame([], $this->rules()->ambiguity('PALAWAN', 'PUERTO-PRINCESA-CITY', 'MILAGROSA', 'cherry pention house manalo extention barangay milagrosa pueryoprinsessa city palawan'));
        $this->assertSame([], $this->rules()->ambiguity('METRO-MANILA', 'QUEZON-CITY', 'BUNGAD', '#20.miller st. Brgy. Bungad Q.city'));
    }

    public function test_another_city_of_the_province_in_the_chat_holds_the_order(): void
    {
        $r = $this->rules()->ambiguity('SULU', 'JOLO', 'ALAT', 'km2 smart kajatian indanan jolo sulu');
        $this->assertSame(['hold' => 'Another city of the province is also in the chat: INDANAN'], $r);
    }

    public function test_words_that_are_part_of_the_chosen_place_or_common_words_do_not_hold(): void
    {
        $rules = new Rules(new Locations([
            ['DAVAO-DEL-SUR', 'DIGOS-CITY', 'ZONE 1 (POB.)'], ['DAVAO-DEL-SUR', 'DIGOS-CITY', 'SAN JOSE (BALUTAKAY)'], ['DAVAO-DEL-SUR', 'DAVAO-CITY', 'AGDAO'],
            ['PALAWAN', 'PUERTO-PRINCESA-CITY', 'PRINCESA'], ['PALAWAN', 'PUERTO-PRINCESA-CITY', 'SAN PEDRO'],
        ], true));

        // "Davao del Sur" ay hindi ang Davao City; ang "Zone 1" ay purok/zone, hindi barangay na tinutukoy.
        $this->assertSame([], $rules->ambiguity('DAVAO-DEL-SUR', 'DIGOS-CITY', 'SAN JOSE (BALUTAKAY)', 'zone 1 san jose digos city davao del sur'));
        // "Princesa" ay bahagi ng Puerto Princesa City.
        $this->assertSame([], $rules->ambiguity('PALAWAN', 'PUERTO-PRINCESA-CITY', 'SAN PEDRO', 'brgy san pedro puerto princesa city palawan'));
    }

    public function test_a_loose_written_province_does_not_hold_but_a_real_contradiction_does(): void
    {
        $rules = $this->rules();
        $rules->begin(['province' => 'Cotabato', 'city' => 'General Santos', 'barangay' => 'Tambler']);
        $this->assertNull($rules->provinceContradiction('SOUTH-COTABATO'));

        $rules->begin(['province' => 'La Union', 'city' => 'San Fernando', 'barangay' => 'Tanqui']);
        $this->assertSame('Province differs from what the customer wrote', $rules->provinceContradiction('PAMPANGA'));
    }
}
