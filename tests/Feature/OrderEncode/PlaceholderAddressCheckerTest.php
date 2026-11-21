<?php

namespace Tests\Feature\OrderEncode;

use App\Services\OrderEncode\Checker;
use App\Services\OrderEncode\Steps;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Spec 311 PA-2: ang placeholder sa ADDRESS ay hindi "filled" sa finalize ng checker. Fake ang OpenAI (gaya ng CheckerTest). */
class PlaceholderAddressCheckerTest extends TestCase
{
    private Steps $steps;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->steps = new Steps(Fixtures::KEY);
    }

    /** Isang row na ✅ ang lugar (walang ibang tawag sa GPT), at ang K ay ang usapan na may purok at kalye. */
    private function check(string $address, array $answers = []): Checker
    {
        $seq = Http::sequence();
        foreach ($answers as $a) $seq->push(Fixtures::responses(json_encode($a)));
        Http::fake(['api.openai.com/v1/responses' => $seq]);
        $row = ['A' => '10:00 23-10-2026', 'B' => 'Ana Cruz', 'C' => '9171234567', 'D' => $address, 'E' => 'CEBU', 'F' => 'CEBU CITY', 'G' => 'LAHUG',
            'H' => '2 x TEA', 'I' => '499', 'J' => 'Page Uno', 'K' => "FB NAME: Ana Cruz\nPurok 3, Mabini St, Lahug, Cebu City", 'L' => 'shop',
            'M' => 'Lahug, Cebu City, Cebu', 'N' => '', 'R' => '', 'S' => '', 'T' => '', 'U' => '', 'V' => '', 'W' => ''];
        $c = new Checker($row, Fixtures::locations(), Fixtures::gpt($this->steps), $this->steps);
        $c->run();

        return $c;
    }

    private function nameAddrStatus(): ?string
    {
        return array_column($this->steps->log, 'status', 'step')['name_addr'] ?? null;
    }

    #[DataProvider('placeholders')]
    public function test_S_311_PA2_H_a_placeholder_address_is_rebuilt_from_the_chat(string $placeholder): void
    {
        $c = $this->check($placeholder, [['full_name' => 'Ana Cruz', 'address_line1' => 'Purok 3, Mabini St']]);

        $this->assertSame('Purok 3, Mabini St', $c->cells['D']);
        $this->assertSame('WROTE_NAMEADDR', $this->nameAddrStatus());
        $this->assertSame(1, $this->steps->requests());
    }

    public static function placeholders(): array
    {
        $list = ['n/a', 'N/A', 'no address line 1 or landmark provided.', 'no address line provided.', 'no address line found.', '---', 'not provided',
            '[not provided]', '[missing]', '(Wala pong namention)', 'Unknown'];

        return array_combine($list, array_map(fn ($p) => [$p], $list));
    }

    public function test_S_311_PA2_a_placeholder_answer_is_never_written(): void
    {
        $c = $this->check('', [['full_name' => 'Ana Cruz', 'address_line1' => 'No address line provided.']]);

        $this->assertSame('', $c->cells['D']);
        $this->assertSame('NO_CHANGE', $this->nameAddrStatus());
    }

    #[DataProvider('realAddresses')]
    public function test_S_311_PA2_N_a_real_address_and_a_name_skip_with_no_ai_call(string $address): void
    {
        $c = $this->check($address);

        $this->assertSame($address, $c->cells['D']);
        $this->assertSame('SKIP:ALREADY_FILLED', $this->nameAddrStatus());
        $this->assertSame(0, $this->steps->requests());
    }

    public static function realAddresses(): array
    {
        return ['(P7) Pido St.' => ['(P7) Pido St.'], 'Blk 1' => ['Blk 1'], 'No. 5 Rizal St' => ['No. 5 Rizal St']];
    }
}
