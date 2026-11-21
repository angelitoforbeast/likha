<?php

namespace Tests\Feature\OrderEncode;

use App\Models\MacroOutput;
use App\Models\OrderEncode;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec 311: ang purok ng customer ay nasa ADDRESS. Ang buong encode (Runner) na may fake na OpenAI: ang address line ng
 * STEP 3 ay ang $line, ang fill_missing ay $fill, at ang finalize ng checker ay ang name_addr.
 */
class PurokInAddressTest extends LiveRowTestCase
{
    /** @var array<int, string> ang mga prompt ng chat completions (STEP 3 at fill_missing), ayon sa ayos */
    private array $extracts = [];

    private function answers(string $line = 'Blk 5 Lot 3', string $fill = '', array $nameAddr = ['full_name' => 'Ana Cruz', 'address_line1' => '']): void
    {
        Http::swap(new Factory(app('events')));
        $this->extracts = [];
        // Ang unang Http::fake ang nasusunod: ang chat completions muna, saka ang Responses API ng checker (fakeGpt).
        Http::fake(['api.openai.com/v1/chat/completions' => function (Request $r) use ($line, $fill) {
            $text = json_encode($r->data(), JSON_UNESCAPED_UNICODE);
            $this->extracts[] = str_contains($text, 'Extract Address Line 1') ? 'address_line1' : 'fill';

            return Http::response(Fixtures::chat(str_contains($text, 'Extract Address Line 1') ? $line : (str_contains($text, 'Extract only the Address Line 1') ? $fill : '')));
        }]);
        $this->fakeGpt(['name_addr' => $nameAddr]);
    }

    /** Ang extracted details ng Bungad, Quezon City, na may mga bahagi ng address (null = walang linya). */
    private function form(?string $house, ?string $purok, ?string $address, ?string $landmark = null): string
    {
        $lines = ['- Name: Ana Cruz', '- Phone Number: 09171234567'];
        if ($house !== null) $lines[] = '- House Number: ' . $house;
        if ($purok !== null) $lines[] = '- Purok/Sitio: ' . $purok;
        if ($address !== null) $lines[] = '- Address: ' . $address;
        array_push($lines, '- Brgy: Bungad', '- City: Quezon City', '- Province: Metro Manila');
        if ($landmark !== null) $lines[] = '- Landmark: ' . $landmark;

        return implode("\n", $lines);
    }

    private function address(): string
    {
        return (string) MacroOutput::sole()->ADDRESS;
    }

    public function test_S_311_PA1_H_the_form_parts_are_composed_in_code_without_the_ai(): void
    {
        $this->answers('Rizal St');

        $o = $this->liveOrder($this->form('12', 'Purok 5', 'Rizal St', 'malapit sa chapel'));

        $this->assertSame('12, Purok 5, Rizal St, near chapel', $this->address());
        $this->assertSame('12, Purok 5, Rizal St, near chapel', $o->outputs['address_line']);
        $this->assertNotContains('address_line1', $this->extracts, 'no AI call for the address line');
        $this->assertSame('FORM_PARTS', $this->step($o, 'address_line1')['status'] ?? null);
        $this->assertSame('landmark_added', $this->step($o, 'landmark')['status'] ?? null);
        $this->assertSame('SKIP:ALREADY_FILLED', $this->step($o, 'name_addr')['status'] ?? null);
        $this->assertTrue($o->outputs['proceed']);
    }

    public function test_S_311_PA1_H_a_purok_already_in_the_address_part_appears_once(): void
    {
        $this->answers();

        $this->liveOrder($this->form('12', 'Purok 5', 'Prk. 5, Rizal St'));

        $this->assertSame('12, Prk. 5, Rizal St', $this->address());
    }

    #[DataProvider('placeholderPurok')]
    public function test_S_311_PA1_N_a_placeholder_purok_adds_nothing(string $purok): void
    {
        $this->answers();

        $o = $this->liveOrder($this->form('12', $purok, 'Rizal St'));

        $this->assertSame('12, Rizal St', $this->address());
        $this->assertNull($this->step($o, 'purok')['status'] ?? null);
    }

    public static function placeholderPurok(): array
    {
        return ['N/A' => ['N/A'], 'dash' => ['-'], '(Wala pong na-mention)' => ['(Wala pong na-mention)'], 'none' => ['none']];
    }

    public function test_S_311_PA1_N_all_parts_placeholders_go_to_the_ai_as_today(): void
    {
        $this->answers('Blk 5 Lot 3');

        $o = $this->liveOrder($this->form('-', 'N/A', 'N/A'));

        $this->assertSame('Blk 5 Lot 3', $this->address());
        $this->assertContains('address_line1', $this->extracts);
        $this->assertSame('WROTE_ADDRESS_LINE1', $this->step($o, 'address_line1')['status'] ?? null);
    }

    #[DataProvider('oldForms')]
    public function test_S_311_PA1_E_a_form_without_a_purok_line_is_the_same_as_today(string $cxd, string $today): void
    {
        $this->answers('Blk 5 Lot 3');

        $o = $this->liveOrder($cxd);

        $this->assertSame($today, $this->address());
        $this->assertSame(['address_line1'], $this->extracts, 'one AI call, as today');
        $this->assertSame('WROTE_ADDRESS_LINE1', $this->step($o, 'address_line1')['status'] ?? null);
        $this->assertNull($this->step($o, 'purok')['status'] ?? null);
    }

    public static function oldForms(): array
    {
        $base = "- Name: Ana Cruz\n- Phone Number: 09171234567\n- Province: Metro Manila\n- City: Quezon City\n- Brgy: Bungad";

        return [
            'one-line Address'                => [$base . "\n- Address: Blk 5 Lot 3", 'Blk 5 Lot 3'],
            'one-line Address and a landmark' => [$base . "\n- Address: Blk 5 Lot 3\n- Landmark: malapit sa chapel", 'Blk 5 Lot 3, near chapel'],
            'no address line at all'          => [$base, 'Blk 5 Lot 3'],
        ];
    }

    public function test_S_311_PA2_H_a_placeholder_from_the_ai_lets_the_checker_rebuild_the_address_from_the_chat(): void
    {
        $this->answers('n/a', '', ['full_name' => 'Ana Cruz', 'address_line1' => 'Purok 3, Mabini St']);

        $o = $this->liveOrder($this->details(), [4 => "Hi po, order ko 2 pcs\nPurok 3, Mabini St, Bungad, Quezon City"]);

        $this->assertSame('Purok 3, Mabini St', $this->address());
        $this->assertSame('PLACEHOLDER', $this->step($o, 'address_line1')['status'] ?? null);
        $this->assertSame('WROTE_NAMEADDR', $this->step($o, 'name_addr')['status'] ?? null);
    }

    #[DataProvider('placeholders')]
    public function test_S_311_PA2_the_encoder_never_writes_a_placeholder_into_address(string $placeholder): void
    {
        // STEP 3, fill_missing at ang checker ay pawang placeholder ang sagot.
        $this->answers($placeholder, $placeholder, ['full_name' => 'Ana Cruz', 'address_line1' => $placeholder]);

        $o = $this->liveOrder($this->details());

        $this->assertSame('', $this->address());
        $this->assertSame('', $o->outputs['address_line']);
    }

    public static function placeholders(): array
    {
        $list = ['n/a', 'no address line 1 or landmark provided.', 'no address line provided.', 'no address line found.', '---', 'not provided',
            '[not provided]', '[missing]', '(Wala pong namention)', 'Unknown'];

        return array_combine($list, array_map(fn ($p) => [$p], $list));
    }

    public function test_S_311_PA2_N_a_real_address_starting_with_a_bracket_is_kept(): void
    {
        $this->answers('(P7) Pido St.');

        $o = $this->liveOrder($this->details());

        $this->assertSame('(P7) Pido St.', $this->address());
        $this->assertSame('SKIP:ALREADY_FILLED', $this->step($o, 'name_addr')['status'] ?? null);
    }

    public function test_S_311_PA3_fill_missing_still_fills_an_empty_address_line(): void
    {
        $this->answers('', 'Purok 2, Narra St');
        $this->liveOrder($this->details());
        $this->assertSame('Purok 2, Narra St', $this->address());
    }

    /**
     * Ang talaan ng resulta: ang anyo ng form, ang isinasagot ng AI (na tinatanggal ang purok), ang ADDRESS ngayon (bago) at
     * ang ADDRESS pagkatapos ng spec 311.
     */
    #[DataProvider('table')]
    public function test_S_311_before_and_after(string $cxd, string $ai, string $before, string $after): void
    {
        $this->answers($ai);

        $this->liveOrder($cxd);

        $this->assertSame($after, $this->address());
    }

    public static function table(): array
    {
        $f = function (?string $house, ?string $purok, ?string $address, ?string $landmark = null, bool $dash = true): string {
            $p = $dash ? '- ' : '';
            $lines = [$p . 'Name: Ana Cruz', $p . 'Phone Number: 09171234567'];
            if ($house !== null) $lines[] = $p . 'House Number: ' . $house;
            if ($purok !== null) $lines[] = $p . 'Purok/Sitio: ' . $purok;
            if ($address !== null) $lines[] = $p . 'Address: ' . $address;
            array_push($lines, $p . 'Brgy: Bungad', $p . 'City: Quezon City', $p . 'Province: Metro Manila');
            if ($landmark !== null) $lines[] = $p . 'Landmark: ' . $landmark;

            return implode("\n", $lines);
        };

        return [
            '1 Astra block, all parts'           => [$f('12', 'Purok 5', 'Rizal St', 'malapit sa chapel', false), 'Rizal St', 'Rizal St, near chapel', '12, Purok 5, Rizal St, near chapel'],
            '2 dashed parts form'                => [$f('12', 'Purok 5', 'Rizal St'), '12 Rizal St', '12 Rizal St', '12, Purok 5, Rizal St'],
            '3 purok only'                       => [$f('-', 'Purok 3', '-'), '(Wala pong namention)', '(Wala pong namention)', 'Purok 3'],
            '4 sitio and street'                 => [$f('N/A', 'Sitio Malipayon', 'Mabini St'), 'Mabini St', 'Mabini St', 'Sitio Malipayon, Mabini St'],
            '5 purok already in Address'         => [$f('12', 'Purok 5', 'Prk. 5, Rizal St'), 'Prk. 5, Rizal St', 'Prk. 5, Rizal St', '12, Prk. 5, Rizal St'],
            '6 Address with brgy and city'       => [$f('-', 'Purok 2', 'Narra St, Brgy. Bungad, Quezon City'), 'Narra St', 'Narra St', 'Purok 2, Narra St'],
            '7 placeholder purok'                => [$f('12', 'N/A', 'Rizal St'), '12 Rizal St', '12 Rizal St', '12, Rizal St'],
            '8 placeholder purok, Tagalog'       => [$f('12', '(Wala pong na-mention)', 'Rizal St', 'tapat ng plaza'), '12 Rizal St', '12 Rizal St, near plaza', '12, Rizal St, near plaza'],
            '9 house number inside Address'      => [$f('8543', '-', '8543 OB Junction'), '8543 OB Junction', '8543 OB Junction', '8543 OB Junction'],
            '10 Purok V and Purok 5'             => [$f('-', 'Purok 5', 'Purok V, Rizal St'), 'Rizal St', 'Rizal St', 'Purok V, Rizal St'],
            '11 old one-line form'               => [$f(null, null, 'Blk 5 Lot 3 Bungad QC'), 'Blk 5 Lot 3', 'Blk 5 Lot 3', 'Blk 5 Lot 3'],
            '12 old one-line form, landmark'     => [$f(null, null, 'Blk 5 Lot 3', 'near Jollibee'), 'Blk 5 Lot 3', 'Blk 5 Lot 3, near Jollibee', 'Blk 5 Lot 3, near Jollibee'],
            '13 old form, AI placeholder'        => [$f(null, null, 'Bungad QC'), 'n/a', 'n/a', ''],
            '14 old form, AI note'               => [$f(null, null, 'Bungad QC'), 'no address line provided.', 'no address line provided.', ''],
            '15 no form at all'                  => ["Hi po\nBungad Quezon City Metro Manila", 'Blk 9', 'Blk 9', 'Blk 9'],
            '16 all parts placeholders'          => [$f('-', '-', 'N/A'), '[missing]', '[missing]', ''],
            '17 bracketed real address (old)'    => [$f(null, null, '(P7) Pido St.'), '(P7) Pido St.', '(P7) Pido St.', '(P7) Pido St.'],
            '18 named purok, landmark'           => [$f('Blk 2 Lot 4', 'Purok Sampaguita', 'Camia St', 'Brgy. Hall'), 'Blk 2 Lot 4 Camia St', 'Blk 2 Lot 4 Camia St, near Brgy. Hall', 'Blk 2 Lot 4, Purok Sampaguita, Camia St, near Brgy. Hall'],
        ];
    }
}
