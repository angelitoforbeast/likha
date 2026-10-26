<?php

namespace Tests\Feature\Chatblast;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Ang "FB link" ng /chatbot/pages: ang link sa Facebook page mula sa naka-save na Page ID (botcake.page_id), sa grid, sa
 * Edit at sa Connections. Digits lang ang tinatanggap. Ang helper ay iisa sa page (fb-link:start/end), pinapatakbo sa Node.
 */
class ChatbotFbLinkTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_20_100000_create_botcake_chats_table.php']]);
        $this->page('Page Uno', '1123777717482685');
        $this->actingAs($this->user());
    }

    /** Pinapatakbo ang fbLink() ng page sa Node para sa bawat id. */
    private function links(array $ids): array
    {
        $html = $this->get('/chatbot/pages')->assertOk()->getContent();
        $this->assertSame(1, preg_match('~// fb-link:start(.*?)// fb-link:end~s', $html, $m), 'the helper is on the page');
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) $this->markTestSkipped('Node is not installed.');
        $file = tempnam(sys_get_temp_dir(), 'fbl') . '.mjs';
        file_put_contents($file, $m[1] . "\nconsole.log(JSON.stringify(" . json_encode($ids) . ".map(fbLink)));");
        $p = new Process([$node, $file]);
        $p->run();
        @unlink($file);
        $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());

        return json_decode($p->getOutput(), true);
    }

    public function test_S_157_1_a_page_id_links_to_its_facebook_page_in_a_new_tab(): void
    {
        $this->getJson('/chatbot/data')->assertOk()->assertJsonPath('rows.0.page_id', '1123777717482685');

        $this->assertSame([['href' => 'https://www.facebook.com/1123777717482685', 'target' => '_blank', 'rel' => 'noopener noreferrer', 'text' => 'Open page ↗']],
            $this->links(['1123777717482685']));
    }

    public function test_S_157_2_no_id_or_a_non_digit_id_gives_no_link(): void
    {
        $this->assertSame([null, null, null, null, null, null],
            $this->links([null, '', '12a3', ' 123', 'javascript:alert(1)', '123/../evil']));
    }

    public function test_S_157_3_the_link_is_in_the_edit_and_connections_headers(): void
    {
        $html = $this->get('/chatbot/pages')->assertOk()->getContent();

        foreach (['id="pe-fb"', 'id="conn-fb"', "\$('pe-fb').replaceChildren(fbAnchor(r.page_id))", "\$('conn-fb').replaceChildren("] as $part) {
            $this->assertStringContainsString($part, $html);
        }
    }

    public function test_S_157_4_the_fb_link_column_sits_in_the_grid_with_the_other_fixed_columns(): void
    {
        $html = $this->get('/chatbot/pages')->assertOk()->getContent();

        // Header pagkatapos ng Last chat, at ang cell sa parehong puwesto ng row.
        $this->assertSame(1, preg_match("~text: 'Last chat'\}\),\s*el\('th', \{[^}]*text: 'FB link'\}\)~", $html), 'FB link header after Last chat');
        $this->assertSame(1, preg_match("~text: r\.last_chat \|\| '—'\}\),\s*el\('td', \{[^}]*\}, fbAnchor\(r\.page_id\)\)~", $html), 'FB link cell after Last chat');
    }
}
