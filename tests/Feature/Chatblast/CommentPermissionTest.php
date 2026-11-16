<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\HandleComment;
use App\Models\ChatbotCommentEvent;
use App\Models\PageSetting;
use App\Services\Chatbot\ChatbotProvider;
use App\Services\Chatbot\CommentCheck;
use App\Services\Chatbot\CommentReplies;
use App\Services\Chatbot\ForbeastPageSetup;
use App\Services\Chatbot\Gateways;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spec 279: ang "comments" ng page check ng gateway at ang comments_not_allowed ng comment reply. Ang walang comment
 * permission ay label lang ("Walang comment permission"): ang page ay nananatili sa gateway. Fake na HTTP.
 */
class CommentPermissionTest extends ChatblastTestCase
{
    private const GW = 'https://gw.example.test/api';
    private const APP2 = 'https://incepxion.example.test/api';
    private const SECRET = 'gw-webhook-secret-test-279a';
    private const PAGE_TOKEN = 'fbp_1001_token_279';

    /** @var array<int, string> ang mga tawag sa gateway (URL) */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_inbox_indexes.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_29_100000_create_botcake_send_requests_table.php',
            'database/migrations/2026_11_08_100000_create_chatbot_comment_events_table.php',
        ]]);
        config([
            'services.gateway.url' => self::GW, 'services.gateway.key' => 'gw_global_key', 'services.gateway.webhook_secret' => self::SECRET,
            'services.gateway.urls' => 'incepxion=' . self::APP2, 'services.gateway.timeout' => 10,
            'services.incepxionbot.url' => '',
        ]);
        $s = app(PageSettings::class);
        foreach (['Page Uno' => '1001', 'Page Dos' => '1002'] as $page => $id) {
            $this->page($page, $id);
            $s->set($page, 'forbeast', 'token', $id === '1001' ? self::PAGE_TOKEN : 'fbp_1002_token_279', true, null, true);
            $s->set($page, 'forbeast', 'token_status', 'ok · connected', false, null, true);
            $s->set($page, 'forbeast', 'webhook_secret', self::SECRET, true, null, true);
        }
        // Page Uno: sa gateway, Bot 2 (incepxion), may comment columns.
        $s->set('Page Uno', 'chatbot', 'provider', 'gateway');
        PageSetting::where('provider', 'chatbot')->where('key', 'provider')->update(['created_at' => now()->subHours(3)]);
        $s->set('Page Uno', 'chatbot', 'gateway_app', 'incepxion', false, null, true);
        $s->set('Page Uno', 'chatbot', 'comment_pm', 'Hi po! Paki-reply po ng Name at Address.');
        $s->set('Page Uno', 'chatbot', 'comment_reply_1', 'Salamat po! Nag-message po kami 😊');
        $s->set('Page Uno', 'chatbot', 'comment_reply_2', 'Check your inbox po 😊');
        CommentReplies::pickUsing(fn (array $options) => $options[0]);
    }

    protected function tearDown(): void
    {
        CommentReplies::pickUsing(null);
        parent::tearDown();
    }

    private function pageSetup(): ForbeastPageSetup
    {
        return app(ForbeastPageSetup::class);
    }

    /** Lahat ng row (active o hindi) ng forbeast.comments ng page. */
    private function commentRows(string $page = 'Page Uno'): int
    {
        return app(PageSettings::class)->pageQuery($page)->where('provider', 'forbeast')->where('key', 'comments')->count();
    }

    private function pageCheck(array $extra = []): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response($extra + ['id' => '1001', 'name' => 'Page Uno', 'connected' => true, 'can_send' => true,
            'webhook' => ['url' => Gateways::eventsUrl('forbeast')], 'apps' => ['forbeast' => 'ok', 'incepxion' => 'ok']]);
    }

    /** Fake na gateway para sa comment: $answer = [status, body] ng private-reply at reply (null = naipadala). */
    private function gateway(?array $answer): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*' => function (HttpRequest $req) use ($answer) {
            $this->calls[] = $req->url();
            if ($answer !== null) return Http::response($answer[1], $answer[0]);
            return str_ends_with($req->url(), '/private-reply') ? Http::response(['ok' => true, 'message_id' => 'm_1'])
                : Http::response(['ok' => true, 'comment_id' => 'our_' . count($this->calls)]);
        }]);
    }

    private function comment(string $commentId = '111_222', string $from = '555')
    {
        $body = json_encode(['kind' => 'event', 'event_id' => crc32($commentId), 'page_id' => '1001', 'type' => 'comment', 'comment_id' => $commentId,
            'post_id' => '1001_9', 'parent_id' => '1001_9', 'message' => 'Magkano po?', 'created_time' => 1761357600, 'from_id' => $from,
            'from_name' => null, 'is_reply' => false, 'is_page_author' => false, 'received_at' => '2026-10-25T02:00:01Z', 'attempt' => 1]);

        return $this->call('POST', '/api/gateway/events', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_GATEWAY_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $body, self::SECRET)], $body);
    }

    private function switchEvents(): array
    {
        return PageSetting::where('provider', 'chatbot')->where('key', 'auto_switch')->get()->all();
    }

    // ── Story 1 ───────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_S_279_1_the_page_checks_comments_are_kept_per_page_and_gateway_only_when_they_change(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*' => $this->pageCheck(['comments' => ['forbeast' => 'no_permission', 'incepxion' => 'ok', 'Bad Key!' => 'ok', 'other' => 'weird']])]);

        $this->pageSetup()->retest('Page Uno');
        $this->assertSame(['forbeast' => 'no_permission', 'incepxion' => 'ok', 'other' => null], $this->pageSetup()->comments('Page Uno'), 'known keys and values only');
        $this->assertSame(1, $this->commentRows());

        // The same answer again: no new row.
        $this->pageSetup()->retest('Page Uno');
        $this->assertSame(1, $this->commentRows(), 'written only when it changes');

        // An older gateway without the field: nothing changes.
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*' => $this->pageCheck()]);
        $this->pageSetup()->retest('Page Uno');
        $this->assertSame(['forbeast' => 'no_permission', 'incepxion' => 'ok', 'other' => null], $this->pageSetup()->comments('Page Uno'));
        $this->assertSame(1, $this->commentRows());

        // The health check (ForbeastWatch) keeps it too, per gateway (forbeast.comments), with the other page untouched.
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*' => $this->pageCheck(['comments' => ['forbeast' => 'ok', 'incepxion' => 'no_permission']])]);
        $this->artisan('chatbot:forbeast-watch')->assertSuccessful();
        $this->assertSame(['forbeast' => 'ok', 'incepxion' => 'no_permission', 'other' => null], $this->pageSetup()->comments('Page Uno'));
        $this->assertNull($this->pageSetup()->comments('Page Dos'), 'Page Dos is on Botcake: no health check');
        $this->assertSame(2, $this->commentRows());
        $this->assertSame('{"forbeast":"ok","incepxion":"no_permission","other":null}', app(PageSettings::class)->get('Page Uno', 'forbeast', 'comments'));

        // The address check of "Replies via" (ChatbotController, a page never checked) keeps it as well.
        $this->actingAs($this->user());
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*' => $this->pageCheck(['id' => '1002', 'comments' => ['incepxion' => 'no_permission']])]);
        $this->postJson('/chatbot/provider', ['page_name' => 'Page Dos', 'provider' => 'gateway', 'gateway_app' => 'incepxion'])->assertOk();
        $this->assertSame(['incepxion' => 'no_permission'], $this->pageSetup()->comments('Page Dos'));

        // The key is written by the system only.
        $this->assertTrue(PageSettings::isSystemKey('forbeast.comments'));
        $this->assertTrue(PageSettings::isSystemKey('incepxionbot.comments'));
    }

    public function test_S_279_1_a_null_from_the_gateway_does_not_erase_a_known_no_permission(): void
    {
        $this->pageSetup()->markComment('Page Uno', false);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*' => $this->pageCheck(['comments' => ['forbeast' => 'ok', 'incepxion' => null]])]);

        $this->pageSetup()->retest('Page Uno');

        $this->assertSame(['forbeast' => 'ok', 'incepxion' => 'no_permission'], $this->pageSetup()->comments('Page Uno'));
    }

    // ── Story 2 ───────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, array{0: int, 1: array}> */
    public static function refusals(): array
    {
        return [
            'error object, 403' => [403, ['error' => ['code' => 'comments_not_allowed', 'message' => 'Comments are not allowed for this page and app']]],
            'error text, 400' => [400, ['ok' => false, 'error' => 'comments_not_allowed']],
            'error object with a Meta code, 422' => [422, ['error' => ['code' => 'comments_not_allowed', 'message' => 'x', 'meta_code' => 10]]],
        ];
    }

    #[DataProvider('refusals')]
    public function test_S_279_2_a_refused_comment_records_no_permission_with_its_own_reason_and_is_not_retried(int $http, array $body): void
    {
        $this->gateway([$http, $body]);

        $this->comment()->assertOk();

        $e = ChatbotCommentEvent::where('comment_id', '111_222')->sole();
        $this->assertSame(['skipped', 'comments_not_allowed', 'no_permission', 'no_permission'], [$e->status, $e->reason, $e->pm_status, $e->reply_status]);
        $this->assertStringStartsWith('Walang comment permission: Forbeast said "comments_not_allowed', $e->pm_error);
        $this->assertStringEndsWith('(HTTP ' . $http . ')', $e->reply_error);
        $this->assertSame([self::APP2 . '/v1/pages/1001/comments/111_222/private-reply', self::APP2 . '/v1/pages/1001/comments/111_222/reply'], $this->calls, 'each part once, on the page route');
        $this->assertSame(['incepxion' => 'no_permission'], $this->pageSetup()->comments('Page Uno'), 'recorded for that page and route');

        // Not retried: the job again does nothing; the page stays on the gateway.
        (new HandleComment($e->id))->handle(app(CommentReplies::class));
        $this->assertCount(2, $this->calls);
        $this->assertSame('gateway', ChatbotProvider::name('Page Uno'));

        // Its own reason on Replies, not a failure; health does not count it as failed.
        $this->actingAs($this->user());
        $date = $e->received_at->timezone('Asia/Manila')->toDateString();
        $row = $this->getJson('/chatbot/replies/data?date=' . $date . '&kind=comments')->assertOk()->json('rows.0');
        $this->assertSame('skipped', $row['status']);
        $this->assertStringContainsString('Skipped: Walang comment permission', $row['summary']);
        $this->assertStringContainsString('Private message: no permission', $row['summary']);
        $this->assertSame(0, ChatbotCommentEvent::where('status', 'failed')->count());
    }

    // ── Story 3 ───────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_S_279_3_a_later_successful_comment_reply_sets_the_route_back_to_ok(): void
    {
        app(PageSettings::class)->set('Page Uno', 'forbeast', 'comments', json_encode(['forbeast' => 'no_permission', 'incepxion' => 'no_permission']), false, null, true);
        $this->gateway(null);

        $this->comment()->assertOk();

        $this->assertSame('sent', ChatbotCommentEvent::where('comment_id', '111_222')->value('status'));
        $this->assertSame(['forbeast' => 'no_permission', 'incepxion' => 'ok'], $this->pageSetup()->comments('Page Uno'), 'only the route that answered');
        $this->assertSame(2, $this->commentRows());

        // Further successes write nothing.
        $this->comment('111_333', '556')->assertOk();
        $this->assertSame('sent', ChatbotCommentEvent::where('comment_id', '111_333')->value('status'));
        $this->assertSame(2, $this->commentRows());
    }

    public function test_S_279_3_after_a_refusal_then_a_success_the_check_shows_no_permission_finding(): void
    {
        $this->gateway([403, ['error' => ['code' => 'comments_not_allowed', 'message' => 'x', 'meta_code' => 10]]]);
        $this->comment()->assertOk();
        $this->assertSame('no_permission', $this->pageSetup()->commentStatus('Page Uno'));

        $this->gateway(null);
        $this->comment('111_333', '556')->assertOk();

        $this->assertSame('ok', $this->pageSetup()->commentStatus('Page Uno'));
        // The refused comment (Meta #10 in its error) is not read as "reconnect the page" either.
        $this->assertSame([], array_values(array_filter(app(CommentCheck::class)->rules('Page Uno'), fn ($f) => stripos($f['reason'], 'comment permission') !== false)));
    }

    public function test_S_279_3_a_success_on_an_unknown_route_writes_nothing(): void
    {
        $this->gateway(null);

        $this->comment()->assertOk();

        $this->assertSame(0, $this->commentRows());
    }

    // ── Story 4 ───────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_S_279_4_the_page_list_and_the_comment_check_show_walang_comment_permission(): void
    {
        $this->actingAs($this->user());
        app(PageSettings::class)->set('Page Uno', 'forbeast', 'comments', json_encode(['forbeast' => 'ok', 'incepxion' => 'no_permission']), false, null, true);

        $rows = collect($this->getJson('/chatbot/data')->assertOk()->json('rows'))->keyBy('page');
        $this->assertSame(['no_permission', 'incepxion', 'gateway'], [$rows['Page Uno']['comment_permission'], $rows['Page Uno']['comment_route'], $rows['Page Uno']['provider']]);
        $this->assertArrayNotHasKey('comment_permission', $rows['Page Dos'], 'not known: the row JSON is as before');

        $html = $this->get('/chatbot/pages')->assertOk()->getContent();
        $this->assertStringContainsString('function commentPermissionNote(r)', $html);
        $this->assertStringContainsString("r.comment_permission !== 'no_permission'", $html);
        $this->assertStringContainsString("text: 'Walang comment permission'", $html);
        $this->assertStringContainsString('repliesViaSelect(r), gatewaySelect(r), commentPermissionNote(r)', $html, 'beside the route');

        $findings = collect(app(CommentCheck::class)->rules('Page Uno'))->where('field', 'Forbeast')->values()->all();
        $this->assertSame([['kind' => 'comment', 'field' => 'Forbeast', 'level' => 'warn',
            'reason' => 'Forbeast (Incepxion): Walang comment permission — hindi masasagot ang comment; tuloy ang messages']], $findings);

        // The route the page uses now decides: on Bot 1 (ok) there is no such finding.
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'gateway_app', 'forbeast', false, null, true);
        $this->assertSame('ok', collect($this->getJson('/chatbot/data')->json('rows'))->firstWhere('page', 'Page Uno')['comment_permission']);
        $this->assertSame([], array_values(array_filter(app(CommentCheck::class)->rules('Page Uno'), fn ($f) => str_contains($f['reason'], 'Walang comment permission'))));
    }

    // ── Story 5 ───────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_S_279_5_comment_refusals_and_no_permission_never_move_the_page_to_botcake(): void
    {
        $this->gateway([403, ['error' => ['code' => 'comments_not_allowed', 'message' => 'x', 'meta_code' => 10]]]);
        foreach (['111_201', '111_202', '111_203', '111_204', '111_205'] as $i => $id) $this->comment($id, (string) (600 + $i))->assertOk();
        $this->assertSame(5, ChatbotCommentEvent::where('reason', 'comments_not_allowed')->count());

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*' => $this->pageCheck(['comments' => ['forbeast' => 'no_permission', 'incepxion' => 'no_permission']])]);
        for ($i = 0; $i < 4; $i++) $this->artisan('chatbot:forbeast-watch')->assertSuccessful();

        // The watch saw the page's route as no_permission, and still kept the page on the gateway.
        $this->assertSame(['forbeast' => 'no_permission', 'incepxion' => 'no_permission'], $this->pageSetup()->comments('Page Uno'));
        $this->assertSame(ForbeastPageSetup::NO_COMMENT_PERMISSION, $this->pageSetup()->commentStatus('Page Uno'));
        $this->assertSame('gateway', ChatbotProvider::name('Page Uno'));
        $this->assertSame([], $this->switchEvents());

        // The existing apps check is unchanged: no working token on the route still moves the page.
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*' => $this->pageCheck(['apps' => ['forbeast' => 'ok', 'incepxion' => 'reconnect_needed'], 'comments' => ['incepxion' => 'ok']])]);
        $this->artisan('chatbot:forbeast-watch')->assertSuccessful();
        $this->assertSame('botcake', ChatbotProvider::name('Page Uno'));
    }
}
