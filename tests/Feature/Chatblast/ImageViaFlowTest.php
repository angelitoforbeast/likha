<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\CheckImageFlow;
use App\Services\Chatbot\BotcakeProvider;
use App\Services\Chatbot\BotcakeSendPool;
use App\Services\Chatbot\BotcakeSendQueue;
use App\Services\Chatbot\ImageFlow;
use App\Services\PageSettings;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;

/**
 * Spec 274: bot images through the page's SEND IMAGE flow. The setup check (S1), images by field write + send_flow on an
 * OK page with text still by send_content (S2), unchanged sends elsewhere (S3), the send_content fallback (S4), the daily
 * check and "Check again" (S5), and the badge (S6). Botcake is faked (Http::fake, and the pool's own fake handler).
 */
class ImageViaFlowTest extends ChatblastTestCase
{
    private const IMG_A = 'https://img.example.test/a.jpg';

    private const IMG_B = 'https://img.example.test/b.jpg';

    private array $flows = [['id' => 501, 'name' => 'SEND IMAGE'], ['id' => 502, 'name' => 'UPDATE SHIPPING STATUS 2026']];

    private array $fields = [['id' => 77, 'name' => 'image_url'], ['id' => 7701, 'name' => 'message']];

    private array $content = ['status' => 'published', 'blocks' => [['cards' => [['plugin_id' => 'text', 'config' => ['text' => 'Hi']], ['plugin_id' => 'image', 'config' => ['url' => ' {{77//image_url}} ']]]]]];

    /** @var array<string, array{0: int, 1: mixed}> last path part => [status, body] */
    private array $answers = [];

    /** @var array<int, array{path: string, body: array}> */
    private array $sent = [];

    /** @var array<int, array{req: RequestInterface, p: Promise}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_25_100000_create_chatbot_waits_table.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
            'database/migrations/2026_10_28_100000_create_chatbot_ai_requests_table.php',
            'database/migrations/2026_10_29_100000_create_botcake_send_requests_table.php',
        ]]);
        config(['services.chatbot.replies_enabled' => true, 'services.botcake.async_sends' => true, 'services.botcake.rate_per_page' => 2]);
        $this->page('Page Uno', '1001');
        $this->page('Page Dos', '1002');
        Http::fake(function (Request $r) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            $this->sent[] = ['path' => $path, 'body' => $r->method() === 'GET' ? [] : (array) $r->data()];
            $last = basename($path);
            if (isset($this->answers[$last])) {
                [$status, $body] = $this->answers[$last];

                return $status === 0 ? Http::failedConnection() : Http::response($body, $status);
            }

            return match (true) {
                $last === 'custom_fields' => Http::response(['success' => true, 'data' => $this->fields]),
                $last === 'flows' => Http::response(['success' => true, 'data' => ['flows' => $this->flows, 'folders' => []]]),
                $last === 'content' => Http::response(['success' => true, 'data' => $this->content]),
                default => Http::response(['success' => true]),
            };
        });
        $this->actingAs($this->user());
    }

    private function settings(): PageSettings
    {
        return app(PageSettings::class);
    }

    private function stored(string $page = 'Page Uno'): ?array
    {
        return json_decode((string) $this->settings()->get($page, 'botcake', ImageFlow::CHECK_KEY), true);
    }

    /** The calls Botcake got after the check (paths without the base). */
    private function sends(): array
    {
        return array_map(fn ($s) => ['path' => str_replace('/api/public_api/v1', '', $s['path']), 'body' => $s['body']], $this->sent);
    }

    private function okPage(string $page = 'Page Uno'): void
    {
        $this->assertSame('checked', app(ImageFlow::class)->run($page)['status']);
        $this->assertTrue($this->stored($page)['ok']);
        $this->sent = [];
    }

    // ---- S1: the check ----

    public function test_s_274_1_a_page_with_one_published_send_image_flow_using_the_image_url_field_passes(): void
    {
        $r = app(ImageFlow::class)->run('Page Uno');

        $this->assertSame('checked', $r['status']);
        $c = $this->stored();
        $this->assertSame(['ok' => true, 'flow_id' => '501', 'field_id' => '77', 'reasons' => []], array_diff_key($c, ['at' => 1]));
        $this->assertNotEmpty($c['at']);
        $this->assertContains('/api/public_api/v1/pages/1001/flows/501/content', array_column($this->sent, 'path'));
    }

    public static function failures(): array
    {
        $image = fn (string $url) => ['status' => 'published', 'blocks' => [['cards' => [['plugin_id' => 'image', 'config' => ['url' => $url]]]]]];

        return [
            'no flow' => [['flows' => [['id' => 502, 'name' => 'Other']]], ['No flow named SEND IMAGE']],
            'two flows' => [['flows' => [['id' => 501, 'name' => 'SEND IMAGE'], ['id' => 503, 'name' => ' send  image ']]], ['Two flows named SEND IMAGE']],
            'a removed flow does not count' => [['flows' => [['id' => 501, 'name' => 'SEND IMAGE', 'is_removed' => true]]], ['No flow named SEND IMAGE']],
            'no field' => [['fields' => [['id' => 7701, 'name' => 'message']]], ['No custom field named image_url']],
            'two fields' => [['fields' => [['id' => 77, 'name' => 'image_url'], ['id' => 78, 'name' => 'IMAGE_URL']]], ['Two custom fields named image_url']],
            'no flow and no field' => [['flows' => [], 'fields' => []], ['No flow named SEND IMAGE', 'No custom field named image_url']],
            'unpublished' => [['content' => ['status' => 'unpublished'] + $image('{{77//image_url}}')], ['SEND IMAGE is not published']],
            'image from another field' => [['content' => $image('{{587510//Gsheet Contet}}')], ['The SEND IMAGE flow\'s image does not use {{77//image_url}}']],
            'no image card' => [['content' => ['status' => 'published', 'blocks' => [['cards' => [['plugin_id' => 'text', 'config' => ['text' => '{{77//image_url}}']]]]]]], ['The SEND IMAGE flow\'s image does not use {{77//image_url}}']],
        ];
    }

    /** @dataProvider failures */
    public function test_s_274_1_each_setup_problem_is_a_plain_english_reason(array $setup, array $reasons): void
    {
        foreach ($setup as $k => $v) {
            $this->{$k} = $v;
        }

        $r = app(ImageFlow::class)->run('Page Uno');

        $this->assertSame('checked', $r['status']);
        $this->assertFalse($this->stored()['ok']);
        $this->assertSame($reasons, $this->stored()['reasons']);
        $this->assertNull(ImageFlow::forSend('Page Uno', [['type' => 'image', 'url' => self::IMG_A]]), 'images keep send_content');
    }

    public function test_s_274_1_botcake_not_answering_keeps_the_last_result_with_the_error(): void
    {
        $this->okPage();
        $this->answers['flows'] = [500, ['message' => 'down']];

        $r = app(ImageFlow::class)->run('Page Uno');

        $this->assertSame('error', $r['status']);
        $c = $this->stored();
        $this->assertTrue($c['ok'], 'the last result stays');
        $this->assertSame('Botcake: HTTP 500', $c['error']);
    }

    // ---- S2 / S3: the direct send ----

    private function directSend(string $page, string $pageId, array $messages): array
    {
        return (new BotcakeProvider($this->settings()))->send($page, $pageId, '7001', $messages, 'ref-1');
    }

    public function test_s_274_2_on_an_ok_page_the_text_goes_by_send_content_and_the_image_by_field_write_then_send_flow(): void
    {
        $this->okPage();

        $o = $this->directSend('Page Uno', '1001', [['type' => 'text', 'text' => 'Here is the photo'], ['type' => 'image', 'url' => self::IMG_A]]);

        $this->assertSame('sent', $o['status']);
        $this->assertSame([
            ['path' => '/pages/1001/flows/send_content', 'body' => ['psid' => '7001', 'data' => ['version' => 'v2', 'content' => ['messages' => [['type' => 'text', 'text' => 'Here is the photo']]]]]],
            ['path' => '/pages/1001/customer/7001/customer_fields', 'body' => ['data' => [['id' => '77', 'value' => self::IMG_A]]]],
            ['path' => '/pages/1001/flows/send_flow', 'body' => ['psid' => '7001', 'flow_id' => '501']],
        ], $this->sends());
        $this->assertSame('flow', $o['messages'][1][ImageFlow::VIA], 'recorded as sent via flow');
        $this->assertArrayNotHasKey(ImageFlow::VIA, $o['messages'][0]);
    }

    public function test_s_274_3_a_page_without_an_ok_check_sends_text_and_image_by_send_content_as_before(): void
    {
        $this->flows = [];
        app(ImageFlow::class)->run('Page Uno'); // not OK: no flow
        $this->sent = [];
        $messages = [['type' => 'text', 'text' => 'Here is the photo'], ['type' => 'image', 'url' => self::IMG_A]];

        $this->assertSame('sent', $this->directSend('Page Uno', '1001', $messages)['status']);
        $this->assertSame('sent', $this->directSend('Page Dos', '1002', $messages)['status'], 'never checked');

        $this->assertSame(['/pages/1001/flows/send_content', '/pages/1002/flows/send_content'], array_column($this->sends(), 'path'));
        $this->assertSame($messages, $this->sends()[0]['body']['data']['content']['messages']);
    }

    public function test_s_274_2_two_images_to_one_customer_go_one_at_a_time_in_order(): void
    {
        $this->okPage();

        $this->directSend('Page Uno', '1001', [['type' => 'image', 'url' => self::IMG_A], ['type' => 'image', 'url' => self::IMG_B]]);

        $this->assertSame([
            ['customer_fields', self::IMG_A], ['send_flow', null], ['customer_fields', self::IMG_B], ['send_flow', null],
        ], array_map(fn ($s) => [basename($s['path']), $s['body']['data'][0]['value'] ?? null], $this->sends()));
    }

    public function test_s_274_2_the_field_write_waits_for_the_customers_lock_and_falls_back_when_it_stays_taken(): void
    {
        $this->okPage();
        $held = Cache::lock(ImageFlow::lockKey('1001', '7001'), 60);
        $this->assertTrue($held->get(), 'another send holds this customer');
        config(['services.botcake.image_flow_lock_wait' => 1]); // the wait itself, shortened for the test

        $o = $this->directSend('Page Uno', '1001', [['type' => 'image', 'url' => self::IMG_A]]);

        $this->assertSame(['/pages/1001/flows/send_content'], array_column($this->sends(), 'path'), 'no field write while another send holds it');
        $this->assertSame(ImageFlow::BUSY, $o['messages'][0][ImageFlow::FALLBACK]);
        $held->release();
    }

    // ---- S4: the fallback ----

    public static function flowFailures(): array
    {
        return [
            'write refused' => ['customer_fields', [400, ['success' => false, 'message' => 'bad']], false],
            'write no answer' => ['customer_fields', [0, null], false],
            'flow success:false' => ['send_flow', [200, ['success' => false, 'message' => 'Outside the 24h window']], false],
            'flow 500' => ['send_flow', [500, ['message' => 'oops']], false],
            'flow gone' => ['send_flow', [400, ['success' => false, 'message' => 'Flow not found']], true],
        ];
    }

    /** @dataProvider flowFailures */
    public function test_s_274_4_a_failed_write_or_send_flow_sends_that_image_by_send_content_once(string $step, array $answer, bool $gone): void
    {
        $this->okPage();
        $this->answers[$step] = $answer;

        $o = $this->directSend('Page Uno', '1001', [['type' => 'image', 'url' => self::IMG_A]]);

        $paths = array_map('basename', array_column($this->sends(), 'path'));
        $this->assertSame($step === 'customer_fields' ? ['customer_fields', 'send_content'] : ['customer_fields', 'send_flow', 'send_content'], $paths);
        $this->assertSame([['type' => 'image', 'url' => self::IMG_A]], end($this->sent)['body']['data']['content']['messages'], 'the image alone, by send_content');
        $this->assertSame('sent', $o['status']);
        $this->assertSame('send_content', $o['messages'][0][ImageFlow::VIA]);
        $this->assertNotEmpty($o['messages'][0][ImageFlow::FALLBACK], 'the reason is recorded');
        $this->assertSame(! $gone, $this->stored()['ok'], 'a flow that is gone marks the check failed');
        if ($gone) {
            $this->assertSame(['SEND IMAGE was not sent (HTTP 400: Flow not found)'], $this->stored()['reasons']);
        }
        if ($step === 'send_flow') {
            Queue::assertPushed(CheckImageFlow::class, fn ($j) => $j->page === 'Page Uno');
        } else {
            Queue::assertNotPushed(CheckImageFlow::class);
        }
    }

    // ---- S2 / S4: the send pool ----

    private function pool(): BotcakeSendPool
    {
        $pool = new BotcakeSendPool(function (RequestInterface $req, array $options) {
            $p = new Promise;
            $this->calls[] = ['req' => $req, 'p' => $p];

            return $p;
        }, $this->clock);
        $this->assertTrue($pool->start());

        return $pool;
    }

    private function ticket(array $messages, string $psid = '7001'): void
    {
        DB::table(BotcakeSendQueue::TABLE)->insert([
            'request_id' => (string) Str::uuid(), 'kind' => 'chatbot', 'priority' => 0, 'customer' => BotcakeSendQueue::chatbotCustomer(1001, $psid),
            'page_id' => 1001, 'page_name' => 'Page Uno', 'psid' => $psid, 'chat_id' => null, 'flow_id' => 1, 'node_id' => 'n2', 'run_id' => 'r1',
            'payload' => json_encode(['messages' => $messages]), 'context' => '{}', 'status' => 'pending', 'deadline_at' => now()->addMinutes(10),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private static function poolCall(array $c): array
    {
        return [basename($c['req']->getUri()->getPath()), json_decode((string) $c['req']->getBody(), true)];
    }

    /** Answer the last call, let the lane interval pass and tick. */
    private function answerLast(BotcakeSendPool $pool, int $status = 200, array $body = ['success' => true]): void
    {
        end($this->calls)['p']->resolve(new Response($status, ['Content-Type' => 'application/json'], json_encode($body)));
        $pool->tick(false);
        $this->clock->time += 0.6;
        $pool->tick(false);
    }

    public function test_s_274_2_the_pool_sends_text_then_each_image_write_and_flow_one_at_a_time_and_records_via_flow(): void
    {
        $this->okPage();
        $pool = $this->pool();
        $this->ticket([['type' => 'text', 'text' => 'Photos:'], ['type' => 'image', 'url' => self::IMG_A], ['type' => 'image', 'url' => self::IMG_B]]);

        $pool->tick();
        for ($i = 0; $i < 4; $i++) {
            $this->assertCount($i + 1, $this->calls, 'one call at a time: the next waits for the answer');
            $this->answerLast($pool);
        }
        $this->answerLast($pool);

        $this->assertSame([
            ['send_content', ['psid' => '7001', 'data' => ['version' => 'v2', 'content' => ['messages' => [['type' => 'text', 'text' => 'Photos:']]]]]],
            ['customer_fields', ['data' => [['id' => '77', 'value' => self::IMG_A]]]],
            ['send_flow', ['psid' => '7001', 'flow_id' => '501']],
            ['customer_fields', ['data' => [['id' => '77', 'value' => self::IMG_B]]]],
            ['send_flow', ['psid' => '7001', 'flow_id' => '501']],
        ], array_map([self::class, 'poolCall'], $this->calls));
        $row = DB::table('chatbot_replies')->where('psid', 7001)->first();
        $this->assertSame('sent', $row->status);
        $this->assertSame([null, 'flow', 'flow'], array_map(fn ($m) => $m[ImageFlow::VIA] ?? null, json_decode($row->content, true)));
        $this->assertSame('done', DB::table(BotcakeSendQueue::TABLE)->value('status'));
        $this->assertTrue(Cache::lock(ImageFlow::lockKey(1001, 7001), 1)->get(), 'the customer lock was let go');
    }

    public function test_s_274_2_the_pool_waits_for_the_customers_lock_before_writing_the_field(): void
    {
        $this->okPage();
        $pool = $this->pool();
        $held = Cache::lock(ImageFlow::lockKey(1001, 7001), 60);
        $this->assertTrue($held->get());
        $this->ticket([['type' => 'image', 'url' => self::IMG_A]]);

        $pool->tick();
        $this->clock->time += 2;
        $pool->tick(false);
        $this->assertCount(0, $this->calls, 'no field write while the lock is held');

        $held->release();
        $this->clock->time += 1;
        $pool->tick(false);
        $this->assertSame(['customer_fields'], array_map(fn ($c) => self::poolCall($c)[0], $this->calls));
        $this->assertNotNull(DB::table(BotcakeSendQueue::TABLE)->value('called_at'), 'marked called only once the lock was free');
    }

    public function test_s_274_4_the_pool_falls_back_to_send_content_once_when_send_flow_fails_and_marks_the_check(): void
    {
        $this->okPage();
        $pool = $this->pool();
        $this->ticket([['type' => 'image', 'url' => self::IMG_A]]);

        $pool->tick();
        $this->answerLast($pool);                                                      // the write
        $this->answerLast($pool, 404, ['success' => false, 'message' => 'Flow does not exist']); // the flow
        $this->answerLast($pool);                                                      // the fallback

        $this->assertSame(['customer_fields', 'send_flow', 'send_content'], array_map(fn ($c) => self::poolCall($c)[0], $this->calls));
        $this->assertSame([['type' => 'image', 'url' => self::IMG_A]], self::poolCall(end($this->calls))[1]['data']['content']['messages']);
        $m = json_decode(DB::table('chatbot_replies')->where('psid', 7001)->value('content'), true)[0];
        $this->assertSame('send_content', $m[ImageFlow::VIA]);
        $this->assertStringContainsString('SEND IMAGE was not sent (HTTP 404', $m[ImageFlow::FALLBACK]);
        $this->assertFalse($this->stored()['ok']);
        Queue::assertPushed(CheckImageFlow::class);
    }

    public function test_s_274_3_the_pool_sends_one_send_content_on_a_page_without_the_setup(): void
    {
        $pool = $this->pool();
        $messages = [['type' => 'text', 'text' => 'Photos:'], ['type' => 'image', 'url' => self::IMG_A]];
        $this->ticket($messages);

        $pool->tick();
        $this->answerLast($pool);

        $this->assertCount(1, $this->calls);
        $this->assertSame(['send_content', ['psid' => '7001', 'data' => ['version' => 'v2', 'content' => ['messages' => $messages]]]], self::poolCall($this->calls[0]));
        $this->assertSame($messages, json_decode(DB::table('chatbot_replies')->where('psid', 7001)->value('content'), true));
    }

    // ---- S5: when the check runs ----

    public function test_s_274_5_the_daily_check_is_scheduled_early_morning_manila_and_check_again_runs_it(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'botcake:image-flow-check --all'));
        $this->assertNotNull($event, 'scheduled');
        $this->assertSame('20 5 * * *', $event->expression);
        $this->assertSame('Asia/Manila', (string) $event->timezone);

        $res = $this->postJson('/chatblast/page-settings/image-check', ['page_name' => 'Page Uno'])->assertOk();
        $this->assertSame('checked', $res->json('status'));
        $this->assertTrue(json_decode($res->json('cell.value'), true)['ok']);

        $this->sent = [];
        $this->artisan('botcake:image-flow-check', ['--all' => true])->assertSuccessful();
        $this->assertContains('/api/public_api/v1/pages/1002/flows', array_column($this->sent, 'path'), 'every Botcake page');
    }

    public function test_s_274_5_loading_the_pages_calls_no_botcake(): void
    {
        $this->okPage();
        $this->get('/chatbot/pages')->assertOk();
        $this->getJson('/chatbot/data')->assertOk();
        $this->get('/chatblast/page-settings')->assertOk();

        $this->assertSame([], $this->sent);
    }

    // ---- S6: the badge ----

    public function test_s_274_6_the_pages_list_carries_the_image_badge_and_page_settings_has_check_again(): void
    {
        $this->okPage();
        $this->flows = [];
        app(ImageFlow::class)->run('Page Dos');

        $rows = collect($this->getJson('/chatbot/data')->assertOk()->json('rows'))->keyBy('page');
        $this->assertTrue($rows['Page Uno']['image_flow']['ok']);
        $this->assertSame(['ok' => false, 'reasons' => ['No flow named SEND IMAGE']], array_diff_key($rows['Page Dos']['image_flow'], ['at' => 1]));

        $html = $this->get('/chatbot/pages')->assertOk()->getContent();
        $this->assertStringContainsString("'Image: via flow'", $html);
        $this->assertStringContainsString("'Image: send_content'", $html);
        $this->assertSame(1, preg_match('~shippingBadgeEl\(r\), imageBadgeEl\(r\),~', $html), 'the badge next to the shipping badge');

        $settings = $this->get('/chatblast/page-settings')->assertOk()->getContent();
        $this->assertStringContainsString('data-image-check-url="'.route('page-settings.image-check').'"', $settings);
        $this->assertStringContainsString('[...checkCell(r), ...imageCell(r)]', $settings);
    }
}
