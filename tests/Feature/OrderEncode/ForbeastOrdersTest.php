<?php

namespace Tests\Feature\OrderEncode;

use App\Jobs\EncodeOrder;
use App\Jobs\ResumeChatbotAi;
use App\Jobs\RunEncodeAstra;
use App\Jobs\RunSpotAstra;
use App\Jobs\SendOrderToForbeast;
use App\Models\MacroOutput;
use App\Models\OrderEncode;
use App\Services\OrderEncode\ForbeastOrders;
use App\Services\OrderEncode\Runner;
use App\Services\PageSettings;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

/**
 * Spec 211: ang order na naisulat sa macro_output (Upload to macro, Encode order) ay ipinapadala rin sa Forbeast
 * (PUT /api/v1/site/orders/{ref}) sa sariling job. Fake na Forbeast (Http::fake), tunay na FlowEngine at Runner.
 */
class ForbeastOrdersTest extends UploadEncodeTestCase
{
    private const FB = 'https://fb.example.test/api';
    private const IX = 'https://ix.example.test/api';
    private const FB_KEY = 'fbs_test_likha_key_000000000000';
    private const IX_KEY = 'fbs_test_ix_key_00000000000000';

    /** @var array<int, array{0: int, 1: array}> ang mga sagot ng fake na Forbeast, sunod-sunod; ang huli ay inuulit */
    private array $answers = [];
    /** @var array<int, int> ang mga job na napatakbo na */
    private array $ran = [];
    /** @var array<int, Request> ang lahat ng tawag sa fake na Forbeast, pati ang walang sagot (timeout) */
    private array $seen = [];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_11_15_100000_add_forbeast_to_order_encodes_table.php']]);
        Queue::fake([EncodeOrder::class, ResumeChatbotAi::class, RunSpotAstra::class, RunEncodeAstra::class, SendOrderToForbeast::class]);
        config([
            'app.url' => 'https://likha.example.test',
            'services.gateway.url' => self::FB, 'services.forbeast_site.key' => self::FB_KEY, 'services.forbeast_site.secret' => 'fb-site-secret',
            'services.incepxionbot.url' => self::IX, 'services.incepxionbot.site_key' => self::IX_KEY, 'services.incepxionbot.site_secret' => 'ix-site-secret',
            'order_encode.forbeast.since' => '2026-01-01T00:00:00+08:00',
        ]);
        app(PageSettings::class)->set(self::PAGE, 'chatbot', 'provider', 'gateway');
        app(PageSettings::class)->set(self::PAGE, 'chatbot', 'gateway', 'forbeast', false, null, true);
        $this->fields()->set('PHONE', '0918 111 2222');
        $this->fields()->set('CXD', $this->details());
        $this->answers = [[201, ['order_id' => 812]]];
        Http::fake([
            'fb.example.test/*' => fn (Request $r) => $this->answer($r),
            'ix.example.test/*' => fn (Request $r) => $this->answer($r),
        ]);
    }

    private function answer(Request $r)
    {
        $this->seen[] = $r;
        $a = count($this->answers) > 1 ? array_shift($this->answers) : $this->answers[0];
        if ($a[0] === 0) throw new ConnectionException('timed out');

        return Http::response($a[1], $a[0]);
    }

    /** Ang mga SendOrderToForbeast na naka-queue na hindi pa napapatakbo. @return array<int, SendOrderToForbeast> ang mga ibinalik sa queue (retry) */
    private function sendJobs(): array
    {
        $released = [];
        foreach (Queue::pushed(SendOrderToForbeast::class) as $job) {
            if (in_array(spl_object_id($job), $this->ran, true)) continue;
            $this->ran[] = spl_object_id($job);
            $job->withFakeQueueInteractions()->handle(app(ForbeastOrders::class));
            if ($job->job->isReleased()) $released[] = $job;
        }

        return $released;
    }

    /** @return array<int, Request> ang mga PUT sa Forbeast */
    private function puts(): array
    {
        return array_values(array_filter($this->seen, fn (Request $r) => $r->method() === 'PUT'));
    }

    public function test_S_211_1_1_an_upload_insert_puts_the_order_to_the_pages_gateway_with_the_contract_mapping(): void
    {
        $run = $this->go($this->uploadGraph(), 'order po, isa');

        $row = MacroOutput::sole();
        $o = OrderEncode::sole();
        $this->assertSame('next', $this->step($run, 'n2')['port']);
        Queue::assertPushed(SendOrderToForbeast::class, 1);
        Queue::assertPushedOn('default', SendOrderToForbeast::class); // ang database queue na default, hindi ang encode
        $this->assertSame([], $this->puts()); // ang step ay hindi naghintay ng Forbeast
        $this->assertSame('queued', $o->forbeast_state);

        $this->sendJobs();

        $puts = $this->puts();
        $this->assertCount(1, $puts);
        $this->assertSame(self::FB . '/v1/site/orders/likha-mo-' . $row->id, $puts[0]->url());
        $this->assertSame(['Bearer ' . self::FB_KEY], $puts[0]->header('Authorization'));
        $f = $o->fresh()->input['fields'];
        $body = $puts[0]->data();
        $this->assertSame([
            'page_id' => self::PAGE_ID, 'version' => (int) $o->fresh()->forbeast_version, 'ordered_at' => '2026-10-23T10:15:00+08:00',
            'name' => 'Ana Cruz', 'phone' => '0918 111 2222', 'details' => $f['input'], 'item' => "PAGE: Page Uno\nPRICE: ₱998", 'shop' => "PAGE: Page Uno\nPRICE: ₱998",
            'cxd' => $this->details(), 'price' => '998', 'status' => 'uploaded',
            'fields' => ['A' => $f['date'], 'B' => self::PAGE, 'C' => 'Ana Cruz', 'D' => '0918 111 2222', 'E' => $f['input'], 'F' => "PAGE: Page Uno\nPRICE: ₱998", 'G' => $this->details(), 'H' => '998'],
        ], $body);
        $this->assertIsInt($body['version']);
        $o->refresh();
        $this->assertSame(['sent', 812, 'likha-mo-' . $row->id, 'forbeast'], [$o->forbeast_state, $o->forbeast['order_id'], $o->forbeast['ref'], $o->forbeast['gateway']]);
        $this->assertSame('uploaded', $o->status); // ang upload mismo ay hindi ginalaw
    }

    public function test_S_211_1_2_an_encode_order_run_is_sent_when_the_row_is_written_and_a_failed_encode_never(): void
    {
        $o = OrderEncode::create([
            'page' => self::PAGE, 'psid' => self::PSID, 'chat_id' => 13, 'flow_id' => 5, 'run_id' => 'a1b2c3d4',
            'spreadsheet_id' => 'flow-node', 'tab' => 'All Orders', 'row_number' => null, 'mode' => 'live', 'status' => 'queued',
            'input' => ['row' => $this->withCxd($this->details(), [1 => self::PAGE]), 'node' => 'n2', 'sheet_written' => false, 'page_id' => self::PAGE_ID],
        ]);
        Queue::assertNotPushed(SendOrderToForbeast::class); // naka-queue pa lang ang encode: walang padala

        app(Runner::class)->run($o);

        $o->refresh();
        $this->assertNotNull($o->macro_output_id);
        Queue::assertPushed(SendOrderToForbeast::class, 1);
        $this->sendJobs();
        $put = $this->puts()[0];
        $this->assertSame(self::FB . '/v1/site/orders/likha-mo-' . $o->macro_output_id, $put->url());
        $this->assertSame((string) MacroOutput::find($o->macro_output_id)->STATUS ?: 'encoded', $put->data()['status']);
        $this->assertSame('sent', $o->fresh()->forbeast_state);

        // Ang encode na pumalya (walang address list): walang row, walang padala.
        @unlink($this->addressFile);
        $this->app->instance(\App\Services\OrderEncode\LocationList::class, new \App\Services\OrderEncode\LocationList($this->addressFile));
        $failed = OrderEncode::create([
            'page' => self::PAGE, 'psid' => self::PSID + 1, 'chat_id' => 14, 'flow_id' => 5, 'run_id' => 'e5f6a7b8',
            'spreadsheet_id' => 'flow-node', 'tab' => 'All Orders', 'row_number' => null, 'mode' => 'live', 'status' => 'queued',
            'input' => ['row' => $this->withCxd($this->details(), [1 => self::PAGE]), 'node' => 'n2', 'sheet_written' => false, 'page_id' => self::PAGE_ID],
        ]);
        app(Runner::class)->run($failed);
        $this->assertSame(['failed', null, null], [$failed->fresh()->status, $failed->fresh()->macro_output_id, $failed->fresh()->forbeast_state]);
        Queue::assertPushed(SendOrderToForbeast::class, 1);
    }

    public function test_S_211_1_2_the_encode_of_an_uploaded_row_sends_the_same_ref_again_after_the_encode_wrote(): void
    {
        $this->go($this->uploadGraph(), 'order po');
        $this->sendJobs();
        $o = OrderEncode::sole();
        $first = (int) $o->forbeast_version;
        $o->update(['status' => 'queued', 'input' => array_merge((array) $o->input, ['encode' => ['node' => 'n5', 'astra' => false]])]);
        $this->travel(2)->seconds();

        app(Runner::class)->run($o->fresh());

        $this->assertSame('done', $o->fresh()->status);
        Queue::assertPushed(SendOrderToForbeast::class, 2);
        $this->sendJobs();
        $puts = $this->puts();
        $this->assertCount(2, $puts);
        $this->assertSame($puts[0]->url(), $puts[1]->url());
        $this->assertGreaterThan($first, $puts[1]->data()['version']);
        $row = MacroOutput::sole();
        $this->assertSame(trim((string) $row->STATUS) !== '' ? trim((string) $row->STATUS) : 'encoded', $puts[1]->data()['status']);
    }

    public function test_S_211_1_3_an_upload_update_of_the_same_row_sends_the_same_ref_with_a_higher_version(): void
    {
        $this->go($this->uploadGraph(), "hello\nBlk 5 Bungad QC", '2026-10-23 10:15');
        $this->travel(2)->seconds();
        $this->go($this->uploadGraph(['mode' => 'update']), "hello\nBlk 5 Bungad QC\nsa may simbahan po", '2026-10-23 10:20');

        $this->assertSame(1, MacroOutput::count());
        Queue::assertPushed(SendOrderToForbeast::class, 2);
        $this->sendJobs();
        $puts = $this->puts();
        $this->assertCount(2, $puts);
        $this->assertSame($puts[0]->url(), $puts[1]->url());
        $this->assertSame(self::FB . '/v1/site/orders/likha-mo-' . MacroOutput::sole()->id, $puts[1]->url());
        $this->assertGreaterThan($puts[0]->data()['version'], $puts[1]->data()['version']);
        $this->assertStringContainsString('sa may simbahan po', $puts[1]->data()['details']);
    }

    public function test_S_211_1_4_pages_on_the_incepxionbot_gateway_send_to_the_incepxionbot_gateway(): void
    {
        app(PageSettings::class)->set(self::PAGE, 'chatbot', 'gateway', 'incepxionbot', false, null, true);

        $this->go($this->uploadGraph());
        $this->sendJobs();

        $put = $this->puts()[0];
        $this->assertStringStartsWith(self::IX . '/v1/site/orders/likha-mo-', $put->url());
        $this->assertSame(['Bearer ' . self::IX_KEY], $put->header('Authorization'));
        $this->assertSame('incepxionbot', OrderEncode::sole()->forbeast['gateway']);
    }

    public function test_S_211_1_5_a_test_run_sends_nothing(): void
    {
        $run = $this->go($this->uploadGraph(), 'order po', test: true);

        $this->assertSame('Test: would insert', $this->step($run, 'n2')['note']);
        $this->assertSame(0, MacroOutput::count());
        Queue::assertNotPushed(SendOrderToForbeast::class);
        $this->assertSame([], $this->puts());
    }

    public function test_S_211_1_6_a_page_with_no_gateway_set_up_sends_nothing_and_nothing_fails(): void
    {
        // Botcake ang page at walang chatbot.gateway: hindi page ng gateway.
        DB::table('page_settings')->where('provider', 'chatbot')->whereIn('key', ['provider', 'gateway'])->delete();
        PageSettings::forget(self::PAGE);
        $run = $this->go($this->uploadGraph(), 'order po');
        $this->assertSame(['next', 1], [$this->step($run, 'n2')['port'], MacroOutput::count()]);

        // Naka-gateway ang page pero walang site key ang gateway: hindi naka-link.
        app(PageSettings::class)->set(self::PAGE, 'chatbot', 'gateway', 'forbeast', false, null, true);
        config(['services.forbeast_site.key' => '']);
        $run = $this->go($this->uploadGraph(), 'isa pa po', '2026-10-23 11:00', psid: self::PSID + 1);
        $this->assertSame(['next', 2], [$this->step($run, 'n2')['port'], MacroOutput::count()]);

        Queue::assertNotPushed(SendOrderToForbeast::class);
        $this->assertSame([null, null], OrderEncode::orderBy('id')->pluck('forbeast_state')->all());
        $this->assertSame([], $this->puts());
    }

    /** @return array<string, array{0: int, 1: array, 2: string}> */
    public static function refusals(): array
    {
        return [
            'page_not_found' => [404, ['error' => 'page_not_found', 'message' => 'Forbeast has no page with that id for this site.'], 'Forbeast has no page with that id for this site.'],
            '422' => [422, ['error' => 'invalid_request', 'message' => 'Send phone as text of at most 20 characters.', 'fields' => ['phone' => 'Send phone as text of at most 20 characters.']], 'phone'],
        ];
    }

    /** @dataProvider refusals */
    public function test_S_211_1_7_a_refusal_marks_the_send_failed_with_the_reason_without_retrying(int $status, array $body, string $reason): void
    {
        $this->answers = [[$status, $body]];
        $this->go($this->uploadGraph(), 'order po');
        $before = $this->columns(MacroOutput::sole());

        $this->assertSame([], $this->sendJobs()); // walang throw: hindi inuulit ng queue

        $o = OrderEncode::sole();
        $this->assertSame('failed', $o->forbeast_state);
        $this->assertStringContainsString($reason, $o->forbeast['reason']);
        $this->assertStringContainsString((string) $status, $o->forbeast['reason']);
        $this->assertSame(['uploaded', null], [$o->status, $o->error]); // ang upload at ang encode ay hindi apektado
        $this->assertSame($before, $this->columns(MacroOutput::sole()));
        $this->assertCount(1, $this->puts());
    }

    public function test_S_211_1_8_a_redelivered_send_gets_duplicate_and_is_marked_sent(): void
    {
        // Ang unang PUT ay naitabi ng Forbeast pero nawala ang sagot (timeout); ang ulit ay "duplicate".
        $this->answers = [[0, []], [200, ['order_id' => 812, 'duplicate' => true]]];
        $this->go($this->uploadGraph(), 'order po');
        $job = Queue::pushed(SendOrderToForbeast::class)->first();

        $job->withFakeQueueInteractions()->handle(app(ForbeastOrders::class));
        $job->assertReleased();
        $job->withFakeQueueInteractions()->handle(app(ForbeastOrders::class)); // ang ulit ng queue (parehong version)

        $puts = $this->puts();
        $this->assertCount(2, $puts);
        $this->assertSame($puts[0]->data()['version'], $puts[1]->data()['version']);
        $o = OrderEncode::sole();
        $this->assertSame(['sent', 812, true], [$o->forbeast_state, $o->forbeast['order_id'], $o->forbeast['duplicate']]);

        // Ang redelivery ng job na naipadala na: walang bagong tawag.
        $job->withFakeQueueInteractions()->handle(app(ForbeastOrders::class));
        $this->assertCount(2, $this->puts());
        $this->assertSame('sent', OrderEncode::sole()->forbeast_state);
    }

    /** @return array<string, array{0: int, 1: array}> */
    public static function outages(): array
    {
        return [
            'timeout' => [0, []],
            '503' => [503, ['error' => 'down']],
            '500' => [500, []],
            '429' => [429, ['error' => 'too_many_requests', 'message' => 'Too many requests. Wait a minute and try again.']],
        ];
    }

    /** @dataProvider outages */
    public function test_S_211_1_9_an_outage_retries_with_backoff_and_lands_later(int $status, array $body): void
    {
        $this->answers = [[$status, $body], [201, ['order_id' => 9]]];
        $run = $this->go($this->uploadGraph(), 'order po');
        $this->assertSame('next', $this->step($run, 'n2')['port']); // ang encode ay hindi naghintay

        $job = Queue::pushed(SendOrderToForbeast::class)->first();
        $job->withFakeQueueInteractions()->handle(app(ForbeastOrders::class));

        $backoff = $job->backoff();
        $job->assertReleased($backoff[0]); // ibinalik sa queue nang may pagitan
        $o = OrderEncode::sole();
        $this->assertSame(['queued', 1], [$o->forbeast_state, $o->forbeast['tries']]);
        $this->assertNotSame('', (string) $o->forbeast['reason']);
        $this->assertSame($backoff, array_values(array_unique($backoff)));
        $this->assertGreaterThan($backoff[0], end($backoff));
        $this->assertTrue(\Illuminate\Support\Carbon::instance($job->retryUntil())->gt(now()->addHours(23)));

        $job->withFakeQueueInteractions()->handle(app(ForbeastOrders::class)); // ang retry

        $this->assertSame(['sent', 9], [OrderEncode::sole()->forbeast_state, OrderEncode::sole()->forbeast['order_id']]);
    }

    public function test_S_211_1_9_a_send_that_never_lands_ends_failed_and_visible(): void
    {
        $this->answers = [[503, []]];
        $this->go($this->uploadGraph(), 'order po');
        $job = Queue::pushed(SendOrderToForbeast::class)->first();

        $job->failed(new RuntimeException('Forbeast answered 503.'));

        $o = OrderEncode::sole();
        $this->assertSame('failed', $o->forbeast_state);
        $this->assertStringContainsString('503', $o->forbeast['reason']);
    }

    public function test_S_211_1_10_a_401_pauses_sending_visibly_and_nothing_is_dropped(): void
    {
        $this->answers = [[401, ['error' => 'unauthorized', 'message' => 'Missing, wrong or inactive key.']]];
        $this->go($this->uploadGraph(), 'order po');
        $this->sendJobs();
        $this->go($this->uploadGraph(), 'order po', '2026-10-23 11:00', psid: self::PSID + 1);

        $this->assertSame([], $this->sendJobs()); // walang throw
        $this->assertCount(1, $this->puts()); // ang ikalawa ay naka-pause na, walang tawag
        $this->assertSame(['paused', 'paused'], OrderEncode::orderBy('id')->pluck('forbeast_state')->all());
        $this->assertStringContainsString('401', OrderEncode::orderBy('id')->first()->forbeast['reason']);

        // Habang naka-pause: ang bantay ay walang ginagawa.
        Artisan::call('order-encode:forbeast-resume');
        $this->assertSame([], $this->sendJobs());
        $this->assertCount(1, $this->puts());

        // Naayos ang key at lumipas ang pause: isang probe, saka ang lahat ng naka-pause.
        $this->answers = [[201, ['order_id' => 1]], [201, ['order_id' => 2]]];
        $this->travel(ForbeastOrders::pauseMinutes() + 1)->minutes();
        Artisan::call('order-encode:forbeast-resume');
        $this->sendJobs();
        $this->sendJobs();

        $this->assertSame(['sent', 'sent'], OrderEncode::orderBy('id')->pluck('forbeast_state')->all());
        $this->assertCount(3, $this->puts());
    }

    public function test_S_211_1_11_ordered_at_carries_the_manila_offset_and_is_never_in_the_future(): void
    {
        $this->go($this->uploadGraph(), 'order po', '2026-10-23 23:45');
        $this->sendJobs();
        $this->assertSame('2026-10-23T23:45:00+08:00', $this->puts()[0]->data()['ordered_at']);

        MacroOutput::query()->update(['TIMESTAMP' => '10:00 23-10-2099']);
        app(ForbeastOrders::class)->queue(OrderEncode::sole(), MacroOutput::sole(), 'uploaded');
        $this->sendJobs();
        $at = $this->puts()[1]->data()['ordered_at'];
        $this->assertStringEndsWith('+08:00', $at);
        $this->assertTrue(Carbon::parse($at)->lte(now()));
    }

    public function test_S_211_1_12_filipino_text_and_emoji_are_sent_unchanged(): void
    {
        $name = 'Ñiña Dela Peña 🌸';
        $shop = "ITEM: Kape ☕ ng Batangas\nPRICE: ₱998";
        $this->fields()->set('PHONE', '09181112222');
        $run = new \App\Services\Chatbot\FlowRun(5, $this->uploadGraph(['shop' => $shop]), self::PAGE, self::PAGE_ID, [], $this->fields(), 13, 'Salamat po! 🙏 Pakisend sa Parañaque', $name, '2026-10-23 10:15', false);
        app(\App\Services\Chatbot\FlowEngine::class)->run($run, $this->outbox());
        $this->sendJobs();

        $put = $this->puts()[0];
        $raw = json_decode($put->body(), true);
        $this->assertSame([$name, $shop, $shop], [$raw['name'], $raw['item'], $raw['shop']]);
        $this->assertStringContainsString('Salamat po! 🙏 Pakisend sa Parañaque', $raw['details']);
        $this->assertSame($name, $raw['fields']['C']);
    }

    public function test_S_211_1_13_the_owner_sees_sends_that_failed_for_good_and_can_send_again(): void
    {
        $this->answers = [[404, ['error' => 'page_not_found', 'message' => 'Forbeast has no page with that id for this site.']]];
        $this->go($this->uploadGraph(), 'order po');
        $this->sendJobs();
        $o = OrderEncode::sole();
        $row = MacroOutput::sole();

        $this->actingAs($this->user('Marketing', 'm@example.test'))->get('/order-encode/forbeast')->assertNotFound();
        $this->actingAs($this->user('CEO'))->get('/order-encode/forbeast')->assertOk()
            ->assertSee('likha-mo-' . $row->id)->assertSee(self::PAGE)->assertSee('Forbeast has no page with that id for this site.')->assertSee('Send again');

        $first = (int) $o->forbeast_version;
        $this->travel(1)->seconds();
        $this->answers = [[201, ['order_id' => 77]]];
        $this->post('/order-encode/forbeast/' . $o->id . '/resend')->assertRedirect();
        $this->assertSame('queued', $o->fresh()->forbeast_state);
        $this->assertGreaterThan($first, (int) $o->fresh()->forbeast_version);
        $this->sendJobs();

        $this->assertSame(['sent', 77], [$o->fresh()->forbeast_state, $o->fresh()->forbeast['order_id']]);
        $this->get('/order-encode/forbeast')->assertOk()->assertSee('Nothing failed or paused.')->assertDontSee('Send again');
    }

    public function test_S_211_1_14_orders_from_before_go_live_are_never_sent(): void
    {
        // Ang row ng customer na nagawa bago ang go-live: ang Update nito ngayon ay hindi ipinapadala.
        $old = $this->existing(['TIMESTAMP' => '09:00 23-10-2026', 'all_user_input' => "FB NAME: Ana Cruz\norder po", 'CXD' => $this->details()]);
        DB::table('macro_output')->where('id', $old->id)->update(['created_at' => '2026-10-23 09:00:00']);
        config(['order_encode.forbeast.since' => '2026-10-24T00:00:00+08:00']);
        $this->go($this->uploadGraph(['mode' => 'update']), "order po\nBlk 5", '2026-10-23 10:15');
        $this->assertSame(1, MacroOutput::count());

        // Walang go-live (walang since): walang ipinapadala kahit kailan.
        config(['order_encode.forbeast.since' => null]);
        $this->go($this->uploadGraph(), 'bago po', '2026-10-24 10:15', psid: self::PSID + 1);
        $this->assertSame(2, MacroOutput::count());

        Queue::assertNotPushed(SendOrderToForbeast::class);
        Artisan::call('order-encode:forbeast-resume');
        Queue::assertNotPushed(SendOrderToForbeast::class);
        $this->assertSame([], $this->puts());
    }

    public function test_S_211_1_7_a_404_that_is_not_page_not_found_is_retried_not_failed(): void
    {
        // Ang gateway na wala pang daan para sa orders (o maling URL): hindi pagtanggi sa order.
        $this->answers = [[404, ['message' => 'Not Found']], [201, ['order_id' => 5]]];
        $this->go($this->uploadGraph(), 'order po');

        $released = $this->sendJobs();

        $this->assertCount(1, $released);
        $this->assertSame('queued', OrderEncode::sole()->forbeast_state);
        $released[0]->withFakeQueueInteractions()->handle(app(ForbeastOrders::class));
        $this->assertSame(['sent', 5], [OrderEncode::sole()->forbeast_state, OrderEncode::sole()->forbeast['order_id']]);
    }

    public function test_S_211_1_13_send_again_never_replaces_a_newer_send_of_the_same_order(): void
    {
        $this->answers = [[422, ['error' => 'invalid_request', 'message' => 'Send phone as text of at most 20 characters.']]];
        $this->go($this->uploadGraph(), "hello\nBlk 5 Bungad QC", '2026-10-23 10:15');
        $this->sendJobs();
        $old = OrderEncode::orderBy('id')->first();
        $this->assertSame('failed', $old->forbeast_state);

        $this->answers = [[200, ['order_id' => 812]]];
        $this->travel(2)->seconds();
        $this->go($this->uploadGraph(['mode' => 'update']), "hello\nBlk 5 Bungad QC\nsa may simbahan po", '2026-10-23 10:20');
        $this->sendJobs();

        // Ang mas bagong padala ng parehong row ay naipadala: ang luma ay superseded, wala sa listahan.
        $this->assertSame(['superseded', 'sent'], OrderEncode::orderBy('id')->pluck('forbeast_state')->all());
        $this->actingAs($this->user('CEO'))->get('/order-encode/forbeast')->assertOk()->assertSee('Nothing failed or paused.');

        // Kahit ipilit ang Send again ng luma (hal. bukas pa ang lumang listahan): walang padala.
        OrderEncode::whereKey($old->id)->update(['forbeast_state' => 'failed']);
        $this->post('/order-encode/forbeast/' . $old->id . '/resend')->assertRedirect()->assertSessionHas('status', 'A newer send of this order already went to Forbeast, so this older one was not sent.');
        $this->assertSame('superseded', $old->fresh()->forbeast_state);
        $this->sendJobs();
        $this->assertCount(2, $this->puts());
    }

    public function test_S_211_1_13_a_send_stuck_in_queued_shows_and_can_be_sent_again(): void
    {
        $this->go($this->uploadGraph(), 'order po');
        $o = OrderEncode::sole();
        $this->ran = array_map('spl_object_id', Queue::pushed(SendOrderToForbeast::class)->all()); // ang job ay nawala (walang worker)

        $this->actingAs($this->user('CEO'))->get('/order-encode/forbeast')->assertOk()->assertSee('Nothing failed or paused.');
        $this->travel(27)->hours();
        $this->get('/order-encode/forbeast')->assertOk()->assertSee('stuck (queued, never answered)')->assertSee('Send again');

        $this->post('/order-encode/forbeast/' . $o->id . '/resend')->assertRedirect();
        $this->sendJobs();

        $this->assertSame('sent', $o->fresh()->forbeast_state);
        $this->assertCount(1, $this->puts());
    }

    public function test_S_211_1_10_paused_sends_of_one_gateway_never_hold_back_the_other(): void
    {
        $this->answers = [[401, ['error' => 'unauthorized', 'message' => 'Missing, wrong or inactive key.']]];
        $this->go($this->uploadGraph(), 'order po');
        app(PageSettings::class)->set(self::PAGE, 'chatbot', 'gateway', 'incepxionbot', false, null, true);
        $this->go($this->uploadGraph(), 'order po', '2026-10-23 11:00', psid: self::PSID + 1);
        $this->sendJobs();
        $this->assertSame(['paused', 'paused'], OrderEncode::orderBy('id')->pluck('forbeast_state')->all());

        // Ang key ng incepxionbot lang ang naayos (lipas ang pause nito); ang forbeast ay naka-pause pa.
        Cache::forget(ForbeastOrders::pauseKey('incepxionbot'));
        $this->answers = [[201, ['order_id' => 3]]];
        Artisan::call('order-encode:forbeast-resume');
        $this->sendJobs();

        $this->assertSame(['paused', 'sent'], OrderEncode::orderBy('id')->pluck('forbeast_state')->all());
        $puts = $this->puts();
        $this->assertStringStartsWith(self::IX, end($puts)->url());
    }
}
