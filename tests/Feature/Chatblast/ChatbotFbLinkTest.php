<?php

namespace Tests\Feature\Chatblast;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Ang FB icon ng /chatbot/pages: katabi ng pangalan ng page, bukas ang chat ng page sa facebook.com (messages/t) mula sa naka-save na
 * Page ID (botcake.page_id), sa grid, sa Edit at sa Connections. Digits lang. Ang helper ay iisa sa page (fb-link:start/end), sa Node.
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

    /** Pinapatakbo ang fbLink() ng page sa Node para sa bawat [id, page]. */
    private function links(array $pairs): array
    {
        $html = $this->get('/chatbot/pages')->assertOk()->getContent();
        $this->assertSame(1, preg_match('~// fb-link:start(.*?)// fb-link:end~s', $html, $m), 'the helper is on the page');
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) $this->markTestSkipped('Node is not installed.');
        $base = tempnam(sys_get_temp_dir(), 'fbl');
        $file = $base . '.mjs';
        file_put_contents($file, $m[1] . "\nconsole.log(JSON.stringify(" . json_encode($pairs) . ".map(([id, page]) => fbLink(id, page))));");
        $p = new Process([$node, $file]);
        $p->run();
        @unlink($file);
        @unlink($base);
        $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());

        return json_decode($p->getOutput(), true);
    }

    public function test_S_157_1_a_page_id_opens_the_pages_facebook_chat_in_a_new_tab(): void
    {
        $this->getJson('/chatbot/data')->assertOk()->assertJsonPath('rows.0.page_id', '1123777717482685');

        $this->assertSame([['href' => 'https://www.facebook.com/messages/t/1123777717482685', 'target' => '_blank', 'rel' => 'noopener noreferrer',
            'title' => 'Chat with Page Uno on Facebook', 'aria-label' => 'Chat with Page Uno on Facebook']],
            $this->links([['1123777717482685', 'Page Uno']]));
    }

    public function test_S_157_2_no_id_or_a_non_digit_id_gives_no_icon(): void
    {
        $this->assertSame([null, null, null, null, null, null],
            $this->links(array_map(fn ($id) => [$id, 'Page Uno'], [null, '', '12a3', ' 123', 'javascript:alert(1)', '123/../evil'])));
    }

    public function test_S_157_3_the_icon_is_beside_the_name_in_the_edit_and_connections_headers(): void
    {
        $html = $this->get('/chatbot/pages')->assertOk()->getContent();

        $this->assertSame(1, preg_match('~<span id="pe-name"></span>.*?</div><span id="pe-fb"~', $html), 'Edit: icon right after the name');
        $this->assertSame(1, preg_match('~<div id="conn-title"[^>]*>Connections</div><span id="conn-fb"~', $html), 'Connections: icon right after the name');
        foreach (["\$('pe-fb').replaceChildren(fbAnchor(r))", "\$('conn-fb').replaceChildren(fbAnchor(rows.find(r => r.page === page)))"] as $part) {
            $this->assertStringContainsString($part, $html);
        }
    }

    public function test_S_157_4_the_icon_sits_beside_the_page_name_in_the_grid_with_no_separate_column(): void
    {
        $html = $this->get('/chatbot/pages')->assertOk()->getContent();

        $this->assertSame(1, preg_match("~\[pickBox\(r\), r\.page, fbAnchor\(r\),~", $html), 'icon right after the page name');
        $this->assertStringNotContainsString("text: 'FB link'", $html);
        // Ang icon: maliit na Facebook "f", kulay Facebook blue.
        $this->assertStringContainsString("fill: '#1877F2'", $html);
    }
}
