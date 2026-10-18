<?php

namespace Tests\Feature\Chatblast;

use App\Services\BotcakeFlowNames;
use App\Services\PageSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Ang nakita ng customer sa Messenger: ang body ng shipping template ng page na ang {{1}} ay ang text ng row, mula sa
 * nakatala lang (walang tawag sa Botcake), at ang link na "Open in Botcake".
 */
class ChatblastCustomerSawTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->user());
        $this->travelTo('2026-10-18 10:00:00');
        config(['services.botcake.inbox_url' => 'https://botcake.example/pages/{page_id}/live_chat']);
    }

    private function msg(string $status, array $extra = []): int
    {
        return DB::table('botcake_messages')->insertGetId($extra + [
            'page_name' => 'Page Uno', 'waybill' => 'WB-' . uniqid(), 'kind' => 'delivering', 'fb_name' => 'Buyer', 'psid' => 'psid-123456',
            'text' => "Hi Buyer\nYour parcel is on the way.", 'status' => $status, 'attempts' => 0, 'is_test' => false,
            'created_at' => now(), 'updated_at' => now(), 'sent_at' => $status === 'sent' ? now() : null,
        ]);
    }

    private function template(string $page, string $body): void
    {
        app(PageSettings::class)->set($page, 'botcake', 'template.shipping.text', $body, false, null, true);
    }

    public static function bodies(): array
    {
        return [
            'may body'        => ["Good day!\n{{1}}\nThank you.", "Good day!\nHi Buyer\nYour parcel is on the way.\nThank you.", null],
            'hindi nasuri'    => [BotcakeFlowNames::UNCHECKED . 'HTTP 500', "Hi Buyer\nYour parcel is on the way.", 'Template text unknown: run Test token on the page'],
            'hindi approved'  => [BotcakeFlowNames::NOT_APPROVED, "Hi Buyer\nYour parcel is on the way.", 'Template text unknown: run Test token on the page'],
            'walang template' => [null, "Hi Buyer\nYour parcel is on the way.", 'Template text unknown: run Test token on the page'],
        ];
    }

    /** @dataProvider bodies */
    public function test_S_056_22_the_panel_shows_what_the_customer_saw_from_stored_data_only(?string $body, string $saw, ?string $note): void
    {
        Http::fake();
        $this->page('Page Uno');
        if ($body !== null) $this->template('Page Uno', $body);
        $id = $this->msg('sent');

        $panel = $this->getJson('/chatblast/messages/' . $id)->assertOk();

        $this->assertSame(['text' => $saw, 'note' => $note], $panel->json('customer_saw'));
        Http::assertNothingSent();
    }

    public function test_S_056_23_open_in_botcake_needs_a_psid_and_the_page_id(): void
    {
        $this->page('Page Uno', '4455667788');

        $this->assertSame('https://botcake.example/pages/4455667788/live_chat', $this->getJson('/chatblast/messages/' . $this->msg('ready'))->json('botcake_url'));
        $this->assertNull($this->getJson('/chatblast/messages/' . $this->msg('no_psid', ['psid' => null]))->json('botcake_url'));
        $this->assertNull($this->getJson('/chatblast/messages/' . $this->msg('ready', ['page_name' => 'Page Dos']))->json('botcake_url'));
    }

    public function test_S_056_24_history_lists_sent_rows_with_the_first_line_the_customer_saw(): void
    {
        $this->page('Page Uno');
        $this->template('Page Uno', "Good day!\n{{1}}");
        $sent = $this->msg('sent');
        $failed = $this->msg('failed');

        $rows = fn (string $chip) => collect($this->getJson('/chatblast/list?view=history&status=' . $chip)->assertOk()->json('rows'))->keyBy('id');

        $this->assertSame('Good day!', $rows('sent')[$sent]['first_line']);
        $this->assertSame('Hi Buyer', $rows('failed')[$failed]['first_line'], 'ang hindi naipadala: ang text ng row');
    }

    public function test_S_056_25_the_panel_has_the_chat_bubble_and_the_botcake_link_in_a_new_tab(): void
    {
        foreach (['/chatblast', '/chatblast/history'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('What the customer saw', $html);
            $this->assertStringContainsString('Open in Botcake', $html);
            $this->assertStringContainsString("target: '_blank'", $html);
        }
    }
}
