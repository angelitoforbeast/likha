<?php

namespace Tests\Feature\Chatblast;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Ang filter ng Connected column ng /chatbot/pages: isang row kada bot (Lahat | May | Wala), "Walang kahit anong bot" at
 * I-reset. Ang lohika ay iisang helper sa page (sa pagitan ng connected-filter:start at :end); dito ay pinapatakbo ito
 * sa Node gamit ang mga row na alam ang sagot. Walang JS runner ang repo, kaya ang helper lang ang sinusubok, hindi ang DOM.
 */
class ChatbotConnectedFilterTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_20_100000_create_botcake_chats_table.php']]);
        $this->page('Page Uno', '1001');
        $this->actingAs($this->user());
    }

    /** Ang helper mula sa page, pinatakbo sa Node kasama ang $script; ang ibinalik ay ang JSON na isinulat ng script. */
    private function runHelper(string $script): mixed
    {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) $this->markTestSkipped('Node is not installed.');
        $html = $this->get('/chatbot/pages')->assertOk()->getContent();
        $this->assertSame(1, preg_match('~// connected-filter:start(.*?)// connected-filter:end~s', $html, $m), 'the helper is on the page');
        $file = tempnam(sys_get_temp_dir(), 'cf') . '.mjs';
        file_put_contents($file, $m[1] . "\n" . $script);
        try {
            $p = new Process([$node, $file]);
            $p->run();
            $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());

            return json_decode($p->getOutput(), true);
        } finally {
            @unlink($file);
        }
    }

    public function test_S_149_12_the_connected_filter_keeps_pages_by_bot_with_may_wala_none_and_reset(): void
    {
        $out = $this->runHelper(<<<'JS'
            const rows = [{page: 'A', connected: ['Botcake']}, {page: 'B', connected: ['Forbeast']}, {page: 'C', connected: ['Botcake', 'Forbeast']}, {page: 'D', connected: []}, {page: 'E'}];
            const keep = cf => rows.filter(r => CF.match(r.connected || [], cf)).map(r => r.page);
            console.log(JSON.stringify({
                mayBotcake: keep({bots: {Botcake: 'has'}, none: false}),
                walaForbeast: keep({bots: {Forbeast: 'not'}, none: false}),
                both: keep({bots: {Botcake: 'has', Forbeast: 'not'}, none: false}),
                none: keep({bots: {Botcake: 'has'}, none: true}),
                reset: keep(CF.reset()),
                activeAfterReset: CF.active(CF.reset()),
                active: CF.active({bots: {Forbeast: 'not'}, none: false}),
            }));
            JS);

        $this->assertSame([
            'mayBotcake' => ['A', 'C'], 'walaForbeast' => ['A', 'D', 'E'], 'both' => ['A'], 'none' => ['D', 'E'],
            'reset' => ['A', 'B', 'C', 'D', 'E'], 'activeAfterReset' => false, 'active' => true,
        ], $out);
    }

    public function test_S_149_13_each_provider_the_data_knows_gets_a_row_with_no_filter_code_change(): void
    {
        $this->assertSame(['Botcake', 'Forbeast'], $this->getJson('/chatbot/data')->assertOk()->json('bots'));
        $out = $this->runHelper(<<<'JS'
            console.log(JSON.stringify([CF.bots(['Botcake', 'Forbeast'], []), CF.bots(['Botcake', 'Forbeast', 'Zeta'], [{connected: ['Zeta']}]), CF.bots(['Botcake'], [{connected: ['Forbeast']}])]));
            JS);

        $this->assertSame([['Botcake', 'Forbeast'], ['Botcake', 'Forbeast', 'Zeta'], ['Botcake', 'Forbeast']], $out);

        $html = $this->get('/chatbot/pages')->getContent();
        // Ang buong script ng page ay buo pa rin (isang syntax error ay sisira sa buong page).
        $script = collect(explode('<script>', $html))->first(fn ($part) => str_contains($part, 'connected-filter:start'));
        $this->assertNotNull($script);
        $file = tempnam(sys_get_temp_dir(), 'cfp') . '.js';
        file_put_contents($file, explode('</script>', $script)[0]);
        $check = new Process([(new ExecutableFinder())->find('node'), '--check', $file]);
        $check->run();
        @unlink($file);
        $this->assertTrue($check->isSuccessful(), $check->getErrorOutput());
        foreach (["'Walang kahit anong bot'", "'I-reset'", "['any', 'Lahat'], ['has', 'May'], ['not', 'Wala']", "'aria-pressed'"] as $part) {
            $this->assertStringContainsString($part, $html);
        }
    }
}
