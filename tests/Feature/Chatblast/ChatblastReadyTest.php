<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\SendChatblastMessage;
use App\Models\BotcakeMessage;
use App\Services\Chatblast;
use App\Services\ChatblastReady;
use App\Services\ChatblastSender;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Ready ang page kapag kumpleto ang setup AT may kumpirmadong padala pagkatapos ng huling pagbabago ng setup: isang
 * matagumpay na Send test (botcake.test_ok) o isang totoong naipadalang chatblast. Ang hindi Ready ay wala sa To send,
 * at hindi ipinapadala ng auto o ng sender (no_config, "Page not ready: needs a test send").
 */
class ChatblastReadyTest extends ChatblastTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->user());
        $this->travelTo('2026-10-18 10:00:00');
    }

    private function row(string $waybill = 'WB-1'): BotcakeMessage
    {
        return BotcakeMessage::where('waybill', $waybill)->where('is_test', false)->sole();
    }

    public function test_S_057_5_an_untested_page_is_not_in_to_send_until_a_test_send_or_a_real_send(): void
    {
        $this->page('Page Uno', '1001', [], false);
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-1', 'psid-a', 'In Transit');

        app(Chatblast::class)->build('in_transit', 1);
        $this->assertSame(['no_config', ChatblastReady::NOT_TESTED], [$this->row()->status, $this->row()->reason]);
        $this->assertSame([], $this->getJson('/chatblast/list')->json('rows'), 'wala sa To send');
        $this->assertSame([$this->row()->id], array_column($this->getJson('/chatblast/list?view=history&status=no_config')->json('rows'), 'id'));

        // Matagumpay na test send pagkatapos ng setup: Ready, at ang susunod na pagkuha ay ginagawang ready ang row.
        $this->travel(1)->minutes();
        app(PageSettings::class)->set('Page Uno', 'botcake', 'test_ok', now('Asia/Manila')->format('Y-m-d H:i:s'), false, null, true);
        $this->assertTrue(app(ChatblastReady::class)->tested('Page Uno'));
        app(Chatblast::class)->build('in_transit', 1);
        $this->assertSame(['ready', null], [$this->row()->status, $this->row()->reason]);

        // Ibang page: walang test send, pero may totoong naipadala pagkatapos ng setup: Ready na rin.
        $this->page('Page Dos', '2002', [], false);
        $this->travel(1)->minutes();
        DB::table('botcake_messages')->insert(['page_name' => 'Page Dos', 'waybill' => 'WB-OLD', 'kind' => 'in_transit', 'day' => 1, 'psid' => 'psid-z',
            'text' => 'x', 'status' => 'sent', 'sent_at' => now(), 'attempts' => 1, 'is_test' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertTrue(app(ChatblastReady::class)->tested('Page Dos'));
        $this->assertSame(['page uno' => true, 'page dos' => true], array_map(fn ($s) => $s['tested'], app(ChatblastReady::class)->states()));
    }

    public function test_S_057_6_changing_the_flow_field_token_or_page_id_needs_a_test_again_and_the_sender_refuses_meanwhile(): void
    {
        Queue::fake([SendChatblastMessage::class]);
        $this->page('Page Uno');
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-1', 'psid-a', 'In Transit');
        app(Chatblast::class)->build('in_transit', 1);
        $this->assertSame('ready', $this->row()->status);

        foreach (['flow.shipping' => '5599', 'field.message' => '7799', 'token' => 'tok-new-0000', 'page_id' => '1009'] as $key => $value) {
            $this->travel(1)->minutes();
            app(PageSettings::class)->set('Page Uno', 'botcake', 'test_ok', now('Asia/Manila')->format('Y-m-d H:i:s'), false, null, true);
            $this->assertTrue(app(ChatblastReady::class)->tested('Page Uno'), $key . ': bago ang pagbabago');
            $this->travel(1)->minutes();
            app(PageSettings::class)->set('Page Uno', 'botcake', $key, $value);
            $this->assertFalse(app(ChatblastReady::class)->tested('Page Uno'), $key . ': kailangan ulit ng test');
        }

        // Ang naka-queue na row ng page na hindi na Ready: hindi ipinapadala ng sender (walang tawag sa Botcake).
        Http::fake();
        BotcakeMessage::whereKey($this->row()->id)->update(['status' => 'queued']);
        app(ChatblastSender::class)->process($this->row()->id);
        $this->assertSame(['no_config', ChatblastReady::NOT_TESTED], [$this->row()->status, $this->row()->reason]);
        Http::assertNothingSent();

        // Ang ready na row ng page ay inililipat ng susunod na pagkuha sa no_config, hindi naiiwan sa To send.
        BotcakeMessage::whereKey($this->row()->id)->update(['status' => 'ready', 'reason' => null]);
        app(Chatblast::class)->build('in_transit', 1);
        $this->assertSame(['no_config', ChatblastReady::NOT_TESTED], [$this->row()->status, $this->row()->reason]);
    }

    public function test_S_057_7_send_test_sends_the_pages_latest_text_to_the_test_psid_and_a_sent_test_makes_the_page_ready(): void
    {
        $this->page('Page Uno', '1001', [], false);
        $this->shipped('Page Uno', 'Ana Cruz', 'WB-1', 'psid-a', 'In Transit');
        app(Chatblast::class)->build('in_transit', 1);

        $this->postJson('/chatblast/page-settings/send-test', ['page_name' => 'Page Uno'])->assertStatus(422)->assertJsonPath('error', 'Set a Test PSID first');

        app(PageSettings::class)->set('Page Uno', 'botcake', 'test_psid', 'psid-owner-7788');
        $written = null;
        Http::fake(['botcake.io/api/public_api/v1/pages/1001/*' => function (Request $req) use (&$written) {
            $path = parse_url($req->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/flows/send_flow')) return Http::response(['success' => true]);
            if ($req->method() === 'POST') { $written = $req->data()['data'][0]['value']; return Http::response(['success' => true]); }
            return Http::response(['success' => true, 'data' => [['custom_field_id' => 7701, 'value' => $written]]]);
        }]);

        $this->travel(1)->minutes();
        $this->postJson('/chatblast/page-settings/send-test', ['page_name' => 'Page Uno'])->assertOk()->assertJsonPath('status', 'sent');

        $test = BotcakeMessage::where('is_test', true)->sole();
        $this->assertSame(['psid-owner-7788', $this->row()->text, 'sent'], [$test->psid, $test->text, $test->status]);
        $this->assertNotNull(app(PageSettings::class)->get('Page Uno', 'botcake', 'test_ok'));
        $this->assertTrue(app(ChatblastReady::class)->tested('Page Uno'));

        // Walang row pa ang page: isang maikling sample text ang ipinapadala.
        $this->page('Page Tres', '1001', [], false);
        app(PageSettings::class)->set('Page Tres', 'botcake', 'test_psid', 'psid-owner-7788');
        $this->postJson('/chatblast/page-settings/send-test', ['page_name' => 'Page Tres'])->assertOk();
        $this->assertSame(ChatblastReady::SAMPLE_TEXT, BotcakeMessage::where('is_test', true)->where('page_name', 'Page Tres')->sole()->text);

        // Kulang ang setup: hindi pa puwede.
        $this->postJson('/chatblast/page-settings/send-test', ['page_name' => 'Page Wala'])->assertStatus(422);
    }
}
