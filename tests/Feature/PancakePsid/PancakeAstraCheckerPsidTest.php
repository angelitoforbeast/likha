<?php

namespace Tests\Feature\PancakePsid;

use App\Models\MacroOutput;
use App\Services\AstraEncoder;
use App\Services\MacroChecker;

/** Ang Astra at MacroChecker ay kumukuha ng Pancake chat sa iisang lookup: PSID muna; walang PSID = gaya ng dati. */
class PancakeAstraCheckerPsidTest extends PancakePsidTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->mapPage('Page Uno', '5001');
    }

    private function order(array $extra = []): MacroOutput
    {
        return MacroOutput::create($extra + ['PAGE' => 'Page Uno', 'fb_name' => 'Ana Cruz', 'ts_date' => '2026-10-20']);
    }

    public function test_a_psid_row_wins_over_a_newer_same_name_row_of_another_customer(): void
    {
        $this->conv('5001', 'Ana Cruz', 'tamang Ana', '12345678901234567');
        $this->conv('5001', 'Ana Cruz', 'ibang Ana, mas bago', '76543210987654321');
        $o = $this->order(['botcake_psid' => '12345678901234567']);

        $this->assertSame('tamang Ana', AstraEncoder::pancakeHistory((string) $o->fb_name, $o->PAGE, $o->botcake_psid));
        $this->assertSame('tamang Ana', (new MacroChecker())->fetchPancakeChat((string) $o->fb_name, $o->PAGE, $o->botcake_psid));
    }

    public function test_without_a_psid_the_newest_row_with_that_name_is_returned_as_before(): void
    {
        $this->conv('5001', 'Ana Cruz', 'luma');
        $this->conv('5001', 'Ana Cruz', 'pinakabago');
        $o = $this->order();

        $this->assertSame('pinakabago', AstraEncoder::pancakeHistory('Ana Cruz'));
        $this->assertSame('pinakabago', AstraEncoder::pancakeHistory((string) $o->fb_name, $o->PAGE, $o->botcake_psid));
        $this->assertSame('pinakabago', (new MacroChecker())->fetchPancakeChat('Ana Cruz'));
        $this->assertSame('', AstraEncoder::pancakeHistory('Walang Ganito'));
    }

    public function test_the_checker_still_caps_the_chat_at_8000_chars(): void
    {
        $this->conv('5001', 'Ana Cruz', str_repeat('a', 8100), '12345678901234567');

        $chat = (new MacroChecker())->fetchPancakeChat('Ana Cruz', 'Page Uno', '12345678901234567');

        $this->assertSame(8000 + mb_strlen("\n\n[TRUNCATED]"), mb_strlen($chat));
    }
}
