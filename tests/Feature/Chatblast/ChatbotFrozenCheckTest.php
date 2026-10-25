<?php

namespace Tests\Feature\Chatblast;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Ang Check column ng /chatbot/pages ay naka-freeze katabi ng Page, Active at Flow sa desktop (1024 px pataas), hindi sa
 * phone. Habang naka-freeze: maikling cell (icon, ilang problema, ang unang linya); ang buong listahan ay sa hover o sa
 * pindot (keyboard). Ang buod ay iisang helper sa page (check-summary:start/end), pinapatakbo sa Node.
 */
class ChatbotFrozenCheckTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_20_100000_create_botcake_chats_table.php']]);
        $this->page('Page Uno', '1001');
        $this->actingAs($this->user());
    }

    public function test_S_149_27_check_is_frozen_on_desktop_only_with_a_compact_cell_that_opens_the_full_list(): void
    {
        $html = $this->get('/chatbot/pages')->assertOk()->getContent();

        foreach (['@media (min-width: 1024px)', '#cb-table .stick-4 { position: sticky; left: 28rem;', "matchMedia('(min-width: 1024px)')",
            "'stick-4 ", 'cb-chk-pop', "'aria-expanded'", '#cb-table td.stick-4:hover .cb-chk-pop', '.cb-chk:focus-within .cb-chk-pop'] as $part) {
            $this->assertStringContainsString($part, $html);
        }
        // Ang 640-1023 px: Page, Active at Flow lang ang naka-freeze (ang anino ay nasa Flow).
        $this->assertStringContainsString('#cb-table .stick-3 { position: sticky; left: 18rem; z-index: 10; box-shadow: 1px 0 0 #e2e8f0; }', $html);

        $node = (new ExecutableFinder())->find('node');
        if ($node === null) $this->markTestSkipped('Node is not installed.');
        $this->assertSame(1, preg_match('~// check-summary:start(.*?)// check-summary:end~s', $html, $m));
        $file = tempnam(sys_get_temp_dir(), 'chk') . '.mjs';
        file_put_contents($file, $m[1] . "\nconsole.log(JSON.stringify([" .
            "checkSummary({status: 'x', reasons: ['Seq 2 has no text', 'PRICE is empty', 'Bad link'], warnings: ['Maybe old price']}), " .
            "checkSummary({status: 'ok', reasons: [], warnings: ['Item name differs', 'Price differs']}), " .
            "checkSummary({status: 'ok', reasons: [], warnings: []}), checkSummary({status: 'none'}), checkSummary(null)]));");
        $p = new Process([$node, $file]);
        $p->run();
        @unlink($file);
        $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());

        $this->assertSame([
            ['icon' => 'x', 'count' => 4, 'first' => 'Seq 2 has no text'],
            ['icon' => 'warn', 'count' => 2, 'first' => 'Item name differs'],
            null, null, null,
        ], json_decode($p->getOutput(), true));
    }
}
