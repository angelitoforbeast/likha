<?php

namespace Tests\Feature\OrderEncode;

use App\Models\MacroOutput;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/** Spec 187: ang landmark ng customer (Landmark: ng extracted details) ay laging nasa ADDRESS ng encode, ayon sa panuntunan. */
class LandmarkTest extends LiveRowTestCase
{
    /** Ang address line na isasagot ng GPT sa STEP 3 (ang ibang sagot ay gaya ng fakeGpt). */
    private function addressLine(string $line): void
    {
        Http::swap(new Factory(app('events')));
        // Ang unang Http::fake ang nasusunod: ang address line muna, saka ang iba.
        Http::fake(['api.openai.com/v1/chat/completions' => fn ($r) => Http::response(Fixtures::chat(str_contains(json_encode($r->data()), 'Extract Address Line 1') ? $line : ''))]);
        $this->fakeGpt();
    }

    private function withLandmark(string $landmark): string
    {
        return $this->details() . "\n- Landmark: " . $landmark;
    }

    public function test_S_187_1_the_landmark_is_added_to_the_ai_address_line(): void
    {
        $this->addressLine('Pk. 17, Zone 7,');

        $o = $this->liveOrder($this->withLandmark('Malapit sa Brgy. Hall'));

        $this->assertSame('Pk. 17, Zone 7, near Brgy. Hall', MacroOutput::sole()->ADDRESS);
        $this->assertSame('Pk. 17, Zone 7, near Brgy. Hall', $o->outputs['address_line']);
        $this->assertSame('landmark_added', $this->step($o, 'landmark')['status'] ?? null);
    }

    public function test_S_187_2_a_landmark_already_in_the_address_is_not_repeated(): void
    {
        $this->addressLine('Pk. 17, Zone 7 NEAR  brgy.   HALL');

        $o = $this->liveOrder($this->withLandmark('malapit sa Brgy. Hall'));

        $address = (string) MacroOutput::sole()->ADDRESS;
        $this->assertSame(1, substr_count(mb_strtolower($address), 'hall'), $address);
        $this->assertStringNotContainsString('near Brgy. Hall', $address);
        $this->assertSame('landmark_kept', $this->step($o, 'landmark')['status'] ?? null);
    }

    #[DataProvider('placeholders')]
    public function test_S_187_3_a_placeholder_landmark_adds_nothing(string $landmark): void
    {
        $this->addressLine('Pk. 17, Zone 7,');

        $o = $this->liveOrder($this->withLandmark($landmark));

        $this->assertSame('Pk. 17, Zone 7,', MacroOutput::sole()->ADDRESS);
        $this->assertSame('no_landmark', $this->step($o, 'landmark')['status'] ?? null);
    }

    public static function placeholders(): array
    {
        return array_map(fn ($p) => [$p], [
            'N/A' => 'N/A', 'NA' => 'NA', '[Not provided]' => '[Not provided]', '(Wala pong na-mention)' => '(Wala pong na-mention)',
            'Wala pong nabanggit' => 'Wala pong nabanggit', 'dash' => '-', 'none' => 'none', 'wala' => 'Wala', 'not provided' => 'Not provided',
            'wala pong nakalagay' => 'Wala pong nakalagay', 'hindi pa naibigay' => 'Hindi pa naibigay', 'none po' => 'None po',
            'None provided' => 'None provided',
        ]);
    }

    public function test_S_187_3_no_landmark_line_adds_nothing(): void
    {
        $this->addressLine('Pk. 17, Zone 7,');

        $o = $this->liveOrder($this->details());

        $this->assertSame('Pk. 17, Zone 7,', MacroOutput::sole()->ADDRESS);
        $this->assertSame('no_landmark', $this->step($o, 'landmark')['status'] ?? null);
    }

    #[DataProvider('noAddressLine')]
    public function test_S_187_3_no_address_line_is_not_made_into_a_landmark_only_address(string $line): void
    {
        // Walang address line ang STEP 3 at ang finalize ng checker.
        Http::swap(new Factory(app('events')));
        Http::fake(['api.openai.com/v1/chat/completions' => Http::response(Fixtures::chat($line))]);
        $this->fakeGpt(['name_addr' => ['full_name' => 'Ana Cruz', 'address_line1' => $line]]);

        $o = $this->liveOrder($this->withLandmark('Malapit sa Brgy. Hall'));

        $this->assertStringNotContainsString('Brgy. Hall', (string) MacroOutput::sole()->ADDRESS);
        $this->assertSame('no_address', $this->step($o, 'landmark')['status'] ?? null);
    }

    public static function noAddressLine(): array
    {
        return ['blank' => [''], 'unknown' => ['Unknown']];
    }

    public function test_S_187_1_the_landmark_is_added_to_an_address_line_written_by_the_checker(): void
    {
        Http::swap(new Factory(app('events')));
        Http::fake(['api.openai.com/v1/chat/completions' => Http::response(Fixtures::chat(''))]);
        $this->fakeGpt(['name_addr' => ['full_name' => 'Ana Cruz', 'address_line1' => 'Pk. 17, Zone 7']]);

        $o = $this->liveOrder($this->withLandmark('Malapit sa Brgy. Hall'));

        $this->assertSame('Pk. 17, Zone 7, near Brgy. Hall', MacroOutput::sole()->ADDRESS);
        $this->assertSame('landmark_added', $this->step($o, 'landmark')['status'] ?? null);
    }

    public function test_S_187_6_a_staff_edited_address_is_not_changed_by_a_re_encode(): void
    {
        $row = $this->existing(['ADDRESS' => 'Blk 2 Lot 4 inayos ng staff', 'edited_address' => true]);
        $this->addressLine('Pk. 17, Zone 7,');

        $o = $this->liveOrder($this->withLandmark('Malapit sa Brgy. Hall'), [0 => '11:00 23-10-2026']);

        $this->assertSame($row->id, $o->macro_output_id);
        $this->assertSame('Blk 2 Lot 4 inayos ng staff', $row->fresh()->ADDRESS);
        $this->assertSame('landmark_added', $this->step($o, 'landmark')['status'] ?? null, 'the encode result has it; the row keeps the staff edit');
    }
}
