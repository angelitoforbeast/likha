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
            'text' => 'x', 'status' => 'sent', 'queued_at' => now(), 'sent_at' => now(), 'attempts' => 1, 'is_test' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertTrue(app(ChatblastReady::class)->tested('Page Dos'));
        $this->assertSame(['page uno' => true, 'page dos' => true], array_map(fn ($s) => $s['tested'], app(ChatblastReady::class)->states()));
        // Iba ang baybay ng PAGE sa order (hal. "PAGE  DOS"): iisang page pa rin, gaya ng states() at ng build.
        $this->assertTrue(app(ChatblastReady::class)->tested('PAGE  DOS'));
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

    /** Ang pekeng Botcake ng test send: $read = ano ang ibinabalik ng read-back; $flow = sagot ng send_flow. */
    private function botcake(?string $read = null, int $flowHttp = 200, array $flowBody = ['success' => true], ?\Closure $onWrite = null): void
    {
        $written = null;
        Http::fake(['botcake.io/api/public_api/v1/pages/1001/*' => function (Request $req) use (&$written, $read, $flowHttp, $flowBody, $onWrite) {
            $path = parse_url($req->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/flows/send_flow')) return Http::response($flowBody, $flowHttp);
            if ($req->method() === 'POST') { $written = $req->data()['data'][0]['value']; if ($onWrite) $onWrite(); return Http::response(['success' => true]); }
            return Http::response(['success' => true, 'data' => [['custom_field_id' => 7701, 'value' => $read ?? $written]]]);
        }]);
    }

    public static function failedTests(): array
    {
        return [
            'failed (flow refused)' => [null, 200, ['success' => false], 'failed'],
            'field mismatch'        => ['ibang text', 200, ['success' => true], 'field_mismatch'],
            'unknown (HTTP 503)'    => [null, 503, ['message' => 'down'], 'unknown'],
        ];
    }

    /** @dataProvider failedTests */
    public function test_S_057_7_a_test_that_does_not_end_sent_never_marks_the_page_tested(?string $read, int $http, array $body, string $status): void
    {
        $this->page('Page Uno', '1001', [], false);
        app(PageSettings::class)->set('Page Uno', 'botcake', 'test_psid', 'psid-owner-7788');
        $this->botcake($read, $http, $body);

        $this->travel(1)->minutes();
        $this->postJson('/chatblast/page-settings/send-test', ['page_name' => 'Page Uno'])->assertOk()->assertJsonPath('status', $status);

        $this->assertNull(app(PageSettings::class)->get('Page Uno', 'botcake', 'test_ok'));
        $this->assertFalse(app(ChatblastReady::class)->tested('Page Uno'));
    }

    public function test_S_057_6_a_send_in_flight_while_the_setup_changes_never_counts_for_the_new_setup(): void
    {
        $this->page('Page Uno', '1001', [], false);
        app(PageSettings::class)->set('Page Uno', 'botcake', 'test_psid', 'psid-owner-7788');

        // Test send: habang isinusulat ang field, may nagpalit ng message field (hal. mula sa dropdown).
        $this->travel(1)->minutes();
        $this->botcake(null, 200, ['success' => true], function () {
            $this->travel(1)->seconds();
            app(PageSettings::class)->set('Page Uno', 'botcake', 'field.message', '7799', false, 1);
            $this->travel(1)->seconds();
        });
        $this->postJson('/chatblast/page-settings/send-test', ['page_name' => 'Page Uno'])->assertOk()->assertJsonPath('status', 'sent');
        $this->assertNull(app(PageSettings::class)->get('Page Uno', 'botcake', 'test_ok'), 'nasubukan ang lumang field, hindi ang bago');
        $this->assertFalse(app(ChatblastReady::class)->tested('Page Uno'));

        // Totoong padala na na-queue bago ang pagbabago ng setup, naipadala pagkatapos: hindi rin bilang.
        $this->travel(1)->minutes();
        DB::table('botcake_messages')->insert(['page_name' => 'Page Uno', 'waybill' => 'WB-OLD', 'kind' => 'in_transit', 'day' => 1, 'psid' => 'psid-z',
            'text' => 'x', 'status' => 'sent', 'queued_at' => now()->subMinutes(5), 'sent_at' => now(), 'attempts' => 1, 'is_test' => false, 'created_at' => now()->subMinutes(5), 'updated_at' => now()]);
        $this->assertFalse(app(ChatblastReady::class)->tested('Page Uno'), 'na-queue bago ang huling pagbabago');
        $this->assertFalse(app(ChatblastReady::class)->states()['page uno']['tested']);

        // Totoong padala na mas luma sa huling pagbabago (hal. pinalitan ang token pagkatapos): hindi bilang.
        $this->travel(1)->minutes();
        app(PageSettings::class)->set('Page Uno', 'botcake', 'token', 'tok-rotated-0000');
        DB::table('botcake_messages')->where('waybill', 'WB-OLD')->update(['queued_at' => now()->subMinutes(10)]);
        $this->assertFalse(app(ChatblastReady::class)->tested('Page Uno'));
    }

    public function test_S_057_7_send_test_needs_page_settings_access(): void
    {
        $this->page('Page Uno');
        app(PageSettings::class)->set('Page Uno', 'botcake', 'test_psid', 'psid-owner-7788');
        $this->actingAs($this->user('Data Encoder', 'enc@example.test'));

        $this->postJson('/chatblast/page-settings/send-test', ['page_name' => 'Page Uno'])->assertNotFound();
        $this->assertSame(0, BotcakeMessage::where('is_test', true)->count());
    }
}
