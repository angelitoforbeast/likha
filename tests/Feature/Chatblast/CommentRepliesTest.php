<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\HandleComment;
use App\Models\ChatbotCommentEvent;
use App\Models\PageSetting;
use App\Services\Chatbot\CommentReplies;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ang sagot sa comment ng mga page na nasa Forbeast: ang Comment Reply 1-3 (public, isa na random) at ang Comment PM
 * (isang private reply), per page sa /chatbot/pages. Fake na HTTP; walang tawag sa totoong Forbeast o Meta.
 */
class CommentRepliesTest extends ChatblastTestCase
{
    private const COLUMNS = ['comment_reply_1' => 'Comment Reply 1', 'comment_reply_2' => 'Comment Reply 2',
        'comment_reply_3' => 'Comment Reply 3', 'comment_pm' => 'Comment PM'];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_20_100000_create_botcake_chats_table.php']]);
        $this->page('Page Uno', '1001');
    }

    private function settings(): PageSettings
    {
        return app(PageSettings::class);
    }

    public function test_S_149_1_the_four_comment_columns_are_editable_with_history_and_in_the_paste_mapping(): void
    {
        Queue::fake();
        $this->actingAs($this->user());

        $columns = collect($this->getJson('/chatbot/data')->assertOk()->json('columns'))->keyBy('key');
        foreach (self::COLUMNS as $key => $label) {
            $this->assertSame([$label, 'long'], [$columns[$key]['label'] ?? null, $columns[$key]['kind'] ?? null], $key);
        }

        $this->postJson('/chatbot/cell', ['page_name' => 'Page Uno', 'key' => 'comment_pm', 'value' => 'Hi po! {{PRICE}} lang.'])->assertOk()->assertJson(['status' => 'created']);
        $this->postJson('/chatbot/cell', ['page_name' => 'Page Uno', 'key' => 'comment_pm', 'value' => 'Hello po!'])->assertOk();
        $this->assertSame('Hello po!', $this->settings()->get('Page Uno', 'chatbot', 'comment_pm'));
        $versions = $this->getJson('/chatbot/history?page_name=Page%20Uno&key=comment_pm')->assertOk()->json('versions');
        $this->assertSame(['Hello po!', 'Hi po! {{PRICE}} lang.'], array_column($versions, 'value'));

        // Ang paste mapping: ang AI ay may isang linyang paliwanag ng bawat bagong field.
        config(['services.openai.key' => 'test-key', 'services.openai.content_check_model' => 'gpt-4o-mini',
            'services.openai.ai_checker_prices' => ['gpt-4o-mini' => [0.15, 0.60]]]);
        $asked = '';
        Http::fake(['api.openai.com/*' => function (HttpRequest $req) use (&$asked) {
            $asked = $req['messages'][1]['content'][0]['text'];
            return Http::response(['choices' => [['message' => ['content' => json_encode(['mapping' => [['cell' => 0, 'field' => 'comment_pm', 'confidence' => 'high']]])]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5]]);
        }]);
        $this->postJson('/chatbot/paste-map', ['page_name' => 'Page Uno', 'cells' => ['Salamat po sa comment!']])->assertOk();
        $this->assertStringContainsString('comment_pm | Comment PM | the one private message sent to a person who commented on a post', $asked);
        $this->assertStringContainsString('comment_reply_2 | Comment Reply 2 | a public reply to a comment on a post (variant 2', $asked);
        $this->assertSame(0, PageSetting::where('key', 'comment_reply_1')->count());
    }

    // ── ang mga comment event ─────────────────────────────────────────────────────────────────────────────────────

    private const GW = 'https://gw.example.test';
    private const SECRET = 'gw-webhook-secret-test-9o8i7u';
    private const PAGE_TOKEN = 'fb_page_token_test_z9x8c7';
    private const REPLY_URL = self::GW . '/v1/pages/1001/comments/%s/reply';
    private const PM_URL = self::GW . '/v1/pages/1001/comments/%s/private-reply';
    private const COMMENT_TEXT = 'Magkano po ito? secret-comment-text';

    /** @var array<int, array{url: string, body: array, token: string}> ang mga tawag sa Forbeast */
    private array $calls = [];

    protected function tearDown(): void
    {
        CommentReplies::pickUsing(null);
        parent::tearDown();
    }

    private function migrateComments(): void
    {
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_11_08_100000_create_chatbot_comment_events_table.php']]);
        config(['services.gateway.url' => self::GW, 'services.gateway.key' => 'gw_global_key', 'services.gateway.webhook_secret' => self::SECRET]);
    }

    /** Page Uno sa Forbeast, bot On, may token, at ang mga comment value ($values ang nananaig). */
    private function forbeastPage(array $values = []): void
    {
        $this->migrateComments();
        $s = $this->settings();
        $s->set('Page Uno', 'chatbot', 'provider', 'gateway');
        $s->set('Page Uno', 'forbeast', 'token', self::PAGE_TOKEN, true);
        $s->set('Page Uno', 'chatbot', 'price', '499');
        $values += ['comment_pm' => 'Hi po! {{PRICE}} lang po ito.', 'comment_reply_1' => 'Reply A', 'comment_reply_2' => 'Reply B {{price}}', 'comment_reply_3' => 'Reply C'];
        foreach ($values as $key => $value) if ($value !== '') $s->set('Page Uno', 'chatbot', $key, $value);
    }

    /** Fake na Forbeast: ang reply ay may bagong comment_id; $answers = [dulo ng URL => [status, body]]. */
    private function gateway(array $answers = []): void
    {
        Http::fake(['gw.example.test/*' => function (HttpRequest $req) use ($answers) {
            $this->calls[] = ['url' => $req->url(), 'body' => $req->data(), 'token' => $req->header('Authorization')[0] ?? ''];
            foreach ($answers as $end => [$status, $body]) if (str_ends_with($req->url(), $end)) return Http::response($body, $status);
            return str_ends_with($req->url(), '/private-reply')
                ? Http::response(['ok' => true, 'message_id' => 'm_1', 'recipient_id' => '777'])
                : Http::response(['ok' => true, 'comment_id' => 'our_' . count($this->calls)]);
        }]);
    }

    /** Ang comment event ng Forbeast, pinirmahan ng $secret (null = walang pirma). */
    private function comment(array $e = [], ?string $secret = self::SECRET)
    {
        // Ang hugis na ipinapadala ng Forbeast (ang kontrata nito): kind event, event_id, unix na created_time, received_at, attempt.
        $body = json_encode($e + ['kind' => 'event', 'event_id' => 42, 'page_id' => '1001', 'type' => 'comment', 'comment_id' => '111_222', 'post_id' => '1001_9',
            'parent_id' => '1001_9', 'message' => self::COMMENT_TEXT, 'created_time' => 1761357600, 'from_id' => '555', 'from_name' => null,
            'is_reply' => false, 'is_page_author' => false, 'received_at' => '2026-10-25T02:00:01Z', 'attempt' => 1]);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($secret !== null) $headers['HTTP_X_GATEWAY_SIGNATURE'] = 'sha256=' . hash_hmac('sha256', $body, $secret);

        return $this->call('POST', '/api/gateway/events', [], [], [], $headers, $body);
    }

    private function event(string $commentId = '111_222'): ChatbotCommentEvent
    {
        return ChatbotCommentEvent::where('comment_id', $commentId)->firstOrFail();
    }

    public function test_S_149_2_a_comment_gets_the_private_message_first_then_one_random_public_reply(): void
    {
        $this->forbeastPage();
        $this->gateway();
        $offered = null;
        CommentReplies::pickUsing(function (array $options) use (&$offered) {
            $offered = $options;
            return $options[1];
        });

        $this->comment()->assertOk();

        $this->assertSame(['Reply A', 'Reply B 499', 'Reply C'], $offered, 'the three variants, variables filled');
        $this->assertSame([
            [sprintf(self::PM_URL, '111_222'), ['text' => 'Hi po! 499 lang po ito.'], 'Bearer ' . self::PAGE_TOKEN],
            [sprintf(self::REPLY_URL, '111_222'), ['message' => 'Reply B 499'], 'Bearer ' . self::PAGE_TOKEN],
        ], array_map(fn ($c) => array_values($c), $this->calls));
        $e = $this->event();
        $this->assertSame(['sent', 'sent', 'sent', 'our_2', '555'], [$e->status, $e->pm_status, $e->reply_status, $e->reply_comment_id, $e->from_id]);
        $this->assertSame(['Hi po! 499 lang po ito.', 'Reply B 499'], [$e->pm_text, $e->reply_text]);
    }

    public function test_S_192_1_a_commenter_whose_latest_ad_is_in_a_group_gets_the_groups_comment_replies(): void
    {
        $this->forbeastPage();
        $this->gateway();
        CommentReplies::pickUsing(fn (array $options) => $options[0]);
        $groups = app(\App\Services\Chatbot\AdGroups::class);
        $groups->setMode('Page Uno', true);
        $groups->save('Page Uno', null, 'Serum', ['111'], ['comment_pm' => 'Serum PM {{PRICE}}', 'price' => '599']);
        \App\Models\BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => '555', 'name' => 'Ana', 'message' => 'Hi', 'payload' => ['ad_id' => '111'], 'received_at' => now()]);

        $this->comment()->assertOk();                                                                   // from_id 555: ang ad niya ay nasa Serum
        $this->comment(['comment_id' => '111_333', 'event_id' => 43, 'from_id' => '556'])->assertOk(); // hindi kilala: ang page values

        $this->assertSame(['Serum PM 599', 'Reply A'], [$this->event()->pm_text, $this->event()->reply_text], 'walang Comment Reply 1 ang group: ang sa page');
        $this->assertSame('Hi po! 499 lang po ito.', $this->event('111_333')->pm_text);
    }

    public function test_S_212_3_5_a_commenter_whose_ad_is_under_a_grouped_campaign_gets_the_groups_comment_replies(): void
    {
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_05_22_140000_create_ad_catalog_table.php']]);
        \Illuminate\Support\Facades\DB::table('ad_catalog')->insert(['ad_id' => '40001', 'ad_set_id' => '50001', 'campaign_id' => '60001', 'created_at' => now(), 'updated_at' => now()]);
        $this->forbeastPage();
        $this->gateway();
        CommentReplies::pickUsing(fn (array $options) => $options[0]);
        $groups = app(\App\Services\Chatbot\AdGroups::class);
        $groups->setMode('Page Uno', true);
        $groups->save('Page Uno', null, 'Serum', ['60001'], ['comment_pm' => 'Serum PM {{PRICE}}', 'price' => '599']);
        \App\Models\BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => '555', 'name' => 'Ana', 'message' => 'Hi', 'payload' => ['ad_id' => '40001'], 'received_at' => now()]);

        $this->comment()->assertOk();
        $this->assertSame('Serum PM 599', $this->event()->pm_text, 'ang campaign ng ad ang nasa group');
    }

    /** @return array<string, array{0: array, 1: array<int, string>, 2: string, 3: ?string}> values, mga tawag, status, reason */
    public static function parts(): array
    {
        $none = ['comment_reply_1' => '', 'comment_reply_2' => '', 'comment_reply_3' => ''];

        return [
            'no Comment PM: public reply only' => [['comment_pm' => ''], ['reply'], 'sent', null],
            'no Comment Reply: private message only' => [$none, ['private-reply'], 'sent', null],
            'nothing set: skipped' => [$none + ['comment_pm' => ''], [], 'skipped', 'not_set'],
        ];
    }

    #[DataProvider('parts')]
    public function test_S_149_3_an_empty_column_turns_its_part_off(array $values, array $calls, string $status, ?string $reason): void
    {
        $this->forbeastPage($values);
        $this->gateway();

        $this->comment()->assertOk();

        $this->assertSame($calls, array_map(fn ($c) => basename($c['url']), $this->calls));
        $this->assertSame([$status, $reason], [$this->event()->status, $this->event()->reason]);
    }

    /** @return array<string, array{0: \Closure, 1: array, 2: string}> */
    public static function skipped(): array
    {
        return [
            'Botcake page (S-149.4)' => [fn (PageSettings $s) => $s->set('Page Uno', 'chatbot', 'provider', 'botcake'), [], 'botcake_page'],
            'bot Off (S-149.4)' => [fn (PageSettings $s) => $s->set('Page Uno', 'chatbot', 'active', '0', false, null, true), [], 'bot_off'],
            'reply to a reply (S-149.5)' => [fn () => null, ['is_reply' => true, 'parent_id' => '111_000'], 'reply'],
            'page-authored (S-149.5)' => [fn () => null, ['is_page_author' => true, 'from_id' => '1001'], 'page_author'],
            'one of our replies (S-149.5)' => [fn () => ChatbotCommentEvent::create(['page_id' => 1001, 'comment_id' => '111_000', 'status' => 'sent',
                'reply_status' => 'sent', 'reply_comment_id' => '111_222', 'received_at' => now()]), [], 'ours'],
        ];
    }

    #[DataProvider('skipped')]
    public function test_S_149_4_and_5_botcake_or_off_pages_replies_page_authored_and_our_own_comments_are_skipped(\Closure $given, array $event, string $reason): void
    {
        $this->forbeastPage();
        $given($this->settings());
        $this->gateway();

        $this->comment($event)->assertOk();

        $this->assertSame([], $this->calls);
        $this->assertSame(['skipped', $reason], [$this->event()->status, $this->event()->reason]);
    }

    public function test_S_149_6_the_same_comment_twice_is_handled_once(): void
    {
        $this->forbeastPage();
        $this->gateway();

        $this->comment()->assertOk();
        $this->comment()->assertOk();

        $this->assertCount(2, $this->calls);
        $this->assertSame(1, ChatbotCommentEvent::count());
    }

    public function test_S_149_7_already_replied_is_recorded_and_not_retried(): void
    {
        $this->forbeastPage();
        $this->gateway(['/private-reply' => [409, ['ok' => false, 'error' => 'already_replied', 'meta_code' => 10900, 'meta_subcode' => null]]]);

        $this->comment()->assertOk();
        (new HandleComment($this->event()->id))->withFakeQueueInteractions()->handle(app(CommentReplies::class));

        $e = $this->event();
        $this->assertSame(['already_replied', 'sent', 'sent'], [$e->pm_status, $e->reply_status, $e->status]);
        $this->assertCount(2, $this->calls, 'one private-reply try and one public reply, nothing on the second run');
    }

    public function test_S_149_8_the_21st_comment_in_a_minute_waits_for_the_next_minute(): void
    {
        $this->forbeastPage(['comment_pm' => '']);
        $this->gateway();
        $this->travelTo('2026-10-25 10:00:15');
        Queue::fake([HandleComment::class]);
        // Isang tao kada comment: ang paksa ay ang minuto ng page, hindi ang isang sagot kada araw.
        for ($i = 1; $i <= 21; $i++) $this->comment(['comment_id' => 'c_' . $i, 'from_id' => (string) (600 + $i)])->assertOk();
        $jobs = Queue::pushed(HandleComment::class)->values();
        $this->assertCount(21, $jobs);

        foreach ($jobs->take(20) as $job) $job->withFakeQueueInteractions()->handle(app(CommentReplies::class));
        $last = $jobs[20]->withFakeQueueInteractions();
        $last->handle(app(CommentReplies::class));

        $last->assertReleased(45);
        $this->assertSame([20, 'queued'], [count($this->calls), $this->event('c_21')->status]);

        $this->travelTo('2026-10-25 10:01:00');
        $jobs[20]->withFakeQueueInteractions()->handle(app(CommentReplies::class));
        $this->assertSame([21, 'sent'], [count($this->calls), $this->event('c_21')->status]);
    }

    /** @return array<string, array{0: \Closure, 1: string}> */
    public static function changedWhileWaiting(): array
    {
        return [
            'switched to Botcake' => [fn (PageSettings $s) => $s->set('Page Uno', 'chatbot', 'provider', 'botcake'), 'botcake_page'],
            'bot switched Off' => [fn (PageSettings $s) => $s->set('Page Uno', 'chatbot', 'active', '0', false, null, true), 'bot_off'],
        ];
    }

    #[DataProvider('changedWhileWaiting')]
    public function test_S_149_8_a_waiting_comment_is_skipped_when_the_page_changed_before_its_turn(\Closure $change, string $reason): void
    {
        $this->forbeastPage();
        $this->gateway();
        Queue::fake([HandleComment::class]);
        $this->comment()->assertOk();
        $change($this->settings());

        Queue::pushed(HandleComment::class)->first()->withFakeQueueInteractions()->handle(app(CommentReplies::class));

        $this->assertSame([[], 'skipped', $reason], [$this->calls, $this->event()->status, $this->event()->reason]);
    }

    /** @return array<string, array{0: array}> */
    public static function badAnswers(): array
    {
        return [
            'no answer' => [[0, null]],
            '2xx with ok false' => [[200, ['ok' => false, 'error' => 'meta_error']]],
        ];
    }

    #[DataProvider('badAnswers')]
    public function test_S_149_7_a_failed_send_is_recorded_failed_and_not_retried(array $answer): void
    {
        $this->forbeastPage(['comment_reply_1' => '', 'comment_reply_2' => '', 'comment_reply_3' => '']);
        if ($answer[0] === 0) {
            Http::fake(['gw.example.test/*' => function () {
                $this->calls[] = 1;
                throw new \Illuminate\Http\Client\ConnectionException('timeout');
            }]);
        } else {
            $this->gateway(['/private-reply' => $answer]);
        }

        $this->comment(['from_id' => null])->assertOk();
        (new HandleComment($this->event()->id))->withFakeQueueInteractions()->handle(app(CommentReplies::class));

        $this->assertSame(['failed', 'failed', null], [$this->event()->status, $this->event()->pm_status, $this->event()->from_id]);
        $this->assertCount(1, $this->calls);
    }

    public function test_S_149_17_an_unknown_outcome_is_logged_as_unknown_and_never_retried(): void
    {
        $this->forbeastPage();
        $this->gateway(['/private-reply' => [504, ['ok' => false, 'error' => 'unknown_outcome', 'meta_code' => null, 'meta_subcode' => null]]]);

        $this->comment()->assertOk();
        (new HandleComment($this->event()->id))->withFakeQueueInteractions()->handle(app(CommentReplies::class));

        $e = $this->event();
        $this->assertSame(['unknown', 'sent', 'unknown'], [$e->pm_status, $e->reply_status, $e->status]);
        $this->assertStringContainsString('unknown_outcome', (string) $e->pm_error);
        $this->assertSame(['private-reply', 'reply'], array_map(fn ($c) => basename($c['url']), $this->calls), 'no second private reply');
        $this->actingAs($this->user());
        $rows = $this->getJson('/chatbot/replies/data?date=' . now('Asia/Manila')->toDateString() . '&kind=comments&status=unknown')->assertOk()->json('rows');
        $this->assertSame(['111_222'], array_column($rows, 'comment_id'));
        $this->assertStringContainsString('Private message: unknown', $rows[0]['summary']);
    }

    public function test_S_149_18_a_429_waits_for_the_next_minute_and_sends_only_what_is_left(): void
    {
        $this->forbeastPage();
        $this->travelTo('2026-10-25 10:00:15');
        $replies = 0;
        Http::fake(['gw.example.test/*' => function (HttpRequest $req) use (&$replies) {
            $this->calls[] = ['url' => $req->url()];
            if (str_ends_with($req->url(), '/private-reply')) return Http::response(['ok' => true, 'message_id' => 'm_1', 'recipient_id' => '777']);
            return ++$replies === 1 ? Http::response(['ok' => false, 'error' => 'rate_limited'], 429) : Http::response(['ok' => true, 'comment_id' => 'our_9']);
        }]);
        Queue::fake([HandleComment::class]);
        $this->comment()->assertOk();
        $job = Queue::pushed(HandleComment::class)->first();

        $first = $job->withFakeQueueInteractions();
        $first->handle(app(CommentReplies::class));
        $first->assertReleased(45);
        $this->assertSame(['queued', 'sent', null], [$this->event()->status, $this->event()->pm_status, $this->event()->reply_status]);
        // Ang ibang comment ng page ay naghihintay din hanggang sa susunod na minuto.
        $this->comment(['comment_id' => 'other', 'from_id' => '556'])->assertOk();
        $other = Queue::pushed(HandleComment::class)->last()->withFakeQueueInteractions();
        $other->handle(app(CommentReplies::class));
        $other->assertReleased(45);

        $this->travelTo('2026-10-25 10:01:00');
        $job->withFakeQueueInteractions()->handle(app(CommentReplies::class));

        $this->assertSame(['sent', 'sent', 'sent', 'our_9'], [$this->event()->status, $this->event()->pm_status, $this->event()->reply_status, $this->event()->reply_comment_id]);
        $this->assertSame(['private-reply', 'reply', 'reply'], array_map(fn ($c) => basename($c['url']), $this->calls));
    }

    public function test_S_149_18_a_failed_private_message_keeps_its_code_when_the_reply_waits_for_a_429(): void
    {
        $this->forbeastPage();
        $this->travelTo('2026-10-25 10:00:15');
        $replies = 0;
        Http::fake(['gw.example.test/*' => function (HttpRequest $req) use (&$replies) {
            $this->calls[] = ['url' => $req->url()];
            if (str_ends_with($req->url(), '/private-reply')) return Http::response(['ok' => false, 'error' => 'forbidden'], 403);
            return ++$replies === 1 ? Http::response(['ok' => false, 'error' => 'rate_limited'], 429) : Http::response(['ok' => true, 'comment_id' => 'our_9']);
        }]);
        Queue::fake([HandleComment::class]);
        $this->comment()->assertOk();
        $job = Queue::pushed(HandleComment::class)->first();

        $job->withFakeQueueInteractions()->handle(app(CommentReplies::class));
        $this->travelTo('2026-10-25 10:01:00');
        $job->withFakeQueueInteractions()->handle(app(CommentReplies::class));

        $this->assertSame(['failed', 'forbidden', 'failed', 'sent'], [$this->event()->status, $this->event()->reason, $this->event()->pm_status, $this->event()->reply_status]);
        $this->assertSame(['private-reply', 'reply', 'reply'], array_map(fn ($c) => basename($c['url']), $this->calls));
    }

    public function test_S_149_19_a_comment_without_text_still_gets_the_private_message_and_the_reply(): void
    {
        $this->forbeastPage();
        $this->gateway();

        $this->comment(['message' => null])->assertOk();

        $this->assertSame(['private-reply', 'reply'], array_map(fn ($c) => basename($c['url']), $this->calls));
        $this->assertSame('sent', $this->event()->status);
    }

    /** @return array<string, array{0: int, 1: array, 2: string}> ang mga tunay na anyo ng sagot ng Forbeast, at ang reason */
    public static function refused(): array
    {
        $object = fn (string $code) => ['error' => ['code' => $code, 'message' => 'Refused: ' . $code]];

        return [
            '401' => [401, $object('invalid_token'), 'invalid_token'],
            '403' => [403, $object('forbidden'), 'forbidden'],
            '404' => [404, $object('comment_not_found'), 'comment_not_found'],
            '422 from Forbeast' => [422, $object('text_too_long'), 'text_too_long'],
            '422 from validation' => [422, ['message' => 'The text field is required.', 'errors' => ['text' => ['The text field is required.']]], 'invalid_text'],
            '502' => [502, ['ok' => false, 'error' => 'meta_error', 'meta_code' => 100, 'meta_subcode' => null], 'meta_error'],
        ];
    }

    #[DataProvider('refused')]
    public function test_S_149_17_a_refused_send_is_failed_with_the_error_code_as_the_reason(int $http, array $body, string $code): void
    {
        $this->forbeastPage(['comment_reply_1' => '', 'comment_reply_2' => '', 'comment_reply_3' => '']);
        $this->gateway(['/private-reply' => [$http, $body]]);

        $this->comment()->assertOk();

        $this->assertSame(['failed', 'failed', $code], [$this->event()->status, $this->event()->pm_status, $this->event()->reason]);
        $this->assertCount(1, $this->calls);
    }

    public function test_S_149_2_a_botfield_inside_a_comment_value_is_filled_too(): void
    {
        $this->forbeastPage(['comment_pm' => '{{Main Flow}}', 'main_flow' => 'Only {{PRICE}} pesos']);
        $this->gateway();

        $this->comment()->assertOk();

        $this->assertSame('Only 499 pesos', $this->event()->pm_text);
    }

    public function test_S_149_9_comments_show_on_replies_with_a_filter_and_are_counted_on_health_without_comment_text_in_the_log(): void
    {
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_28_100000_create_chatbot_ai_requests_table.php',
        ]]);
        $logged = [];
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class, function ($m) use (&$logged) {
            $logged[] = $m->message . json_encode($m->context);
        });
        $this->travelTo('2026-10-25 10:00:00');
        $this->forbeastPage(['comment_reply_2' => '', 'comment_reply_3' => '']);
        $this->gateway(['/c_2/reply' => [500, ['ok' => false, 'error' => 'meta_error', 'meta_code' => 1, 'meta_subcode' => null]]]);
        $this->comment(['comment_id' => 'c_1'])->assertOk();
        $this->comment(['comment_id' => 'c_2', 'from_id' => '556'])->assertOk();
        $this->comment(['comment_id' => 'c_3', 'is_reply' => true])->assertOk();

        $this->actingAs($this->user());
        $rows = $this->getJson('/chatbot/replies/data?date=2026-10-25&kind=comments')->assertOk()->json('rows');
        $this->assertSame([
            ['c_3', 'skipped', 'Comment c_3 · Skipped: a reply to a reply'],
            ['c_2', 'failed', 'Comment c_2 · Private message: sent "Hi po! 499 lang po ito." · Public reply: failed "Reply A"'],
            ['c_1', 'sent', 'Comment c_1 · Private message: sent "Hi po! 499 lang po ito." · Public reply: sent "Reply A"'],
        ], array_map(fn ($r) => [$r['comment_id'], $r['status'], $r['summary']], $rows));
        $this->assertSame(['Page Uno', 'comment', 'Public reply: Forbeast said "meta_error" (Meta #1) (HTTP 500)'], [$rows[1]['page'], $rows[1]['kind'], $rows[1]['error']]);
        $this->assertSame(['c_2'], array_column($this->getJson('/chatbot/replies/data?date=2026-10-25&kind=comments&status=failed')->json('rows'), 'comment_id'));
        $this->assertSame([], $this->getJson('/chatbot/replies/data?date=2026-10-25')->json('rows'), 'the bot replies view is unchanged');
        $this->assertStringContainsString('<option value="comments">Comments</option>', $this->get('/chatbot/replies')->assertOk()->getContent());

        $today = $this->get('/chatbot/health')->assertOk()->viewData('tiles')['today'];
        $this->assertSame([3, 1, 1], [$today['comments_in'], $today['comments_replied'], $today['comments_failed']]);
        $this->assertStringContainsString('Comments replied', $this->get('/chatbot/health')->getContent());

        $this->assertSame([], array_filter($logged, fn ($l) => str_contains($l, 'secret-comment-text')));
    }

    public function test_S_149_10_an_unknown_page_or_a_bad_signature_is_refused(): void
    {
        $this->forbeastPage();
        $this->gateway();

        $this->comment(['page_id' => '9999'])->assertStatus(401);
        $this->comment([], 'wrong-secret')->assertStatus(401);
        $this->comment([], null)->assertStatus(401);

        $this->assertSame([0, []], [ChatbotCommentEvent::count(), $this->calls]);
    }

    // ── isang sagot kada tao kada page kada araw (spec 218) ─────────────────────────────────────────────────────────

    /** Ang naunang comment ng 555 sa page, ngayon ($row ang nananaig). */
    private function earlier(array $row): ChatbotCommentEvent
    {
        return ChatbotCommentEvent::create($row + ['page_id' => 1001, 'comment_id' => 'earlier_' . (ChatbotCommentEvent::count() + 1), 'post_id' => '1001_9',
            'from_id' => '555', 'received_at' => now(), 'handled_at' => now()]);
    }

    /** Ang mga endpoint na tinawag (private-reply, reply), sa pagkakasunod. */
    private function called(): array
    {
        return array_map(fn ($c) => basename($c['url']), $this->calls);
    }

    /** Ang job ng comment, pinatakbo nang isang beses (gaya ng worker). */
    private function handled(string $commentId): HandleComment
    {
        $job = (new HandleComment($this->event($commentId)->id))->withFakeQueueInteractions();
        $job->handle(app(CommentReplies::class));

        return $job;
    }

    public function test_S_218_1_1_a_customers_first_comment_today_gets_the_private_message_and_the_reply(): void
    {
        $this->forbeastPage();
        $this->gateway();
        $this->earlier(['from_id' => '556', 'status' => 'sent', 'pm_status' => 'sent', 'reply_status' => 'sent']); // ibang tao

        $this->comment()->assertOk();

        $this->assertSame(['private-reply', 'reply'], $this->called());
        $this->assertSame(['sent', 'sent', 'sent', null], [$this->event()->status, $this->event()->pm_status, $this->event()->reply_status, $this->event()->reason]);
    }

    public function test_S_218_1_2_a_second_and_third_comment_today_on_the_page_get_nothing_and_are_skipped_daily_limit(): void
    {
        $this->forbeastPage();
        $this->gateway();

        $this->comment()->assertOk();
        $this->comment(['comment_id' => '111_333', 'event_id' => 43])->assertOk();                                                // ang parehong post
        $this->comment(['comment_id' => '222_444', 'event_id' => 44, 'post_id' => '1001_8', 'parent_id' => '1001_8'])->assertOk(); // ibang post

        $this->assertSame(['private-reply', 'reply'], $this->called(), 'only the first comment is answered');
        foreach (['111_333', '222_444'] as $id) {
            $e = $this->event($id);
            $this->assertSame(['skipped', 'daily_limit', null, null], [$e->status, $e->reason, $e->pm_status, $e->reply_status], $id);
        }
    }

    /** @return array<string, array{0: array}> ang naunang row ngayon na sinagot na (o maaaring naipost na) */
    public static function answeredEarlier(): array
    {
        return [
            'private message and reply sent' => [['status' => 'sent', 'pm_status' => 'sent', 'reply_status' => 'sent']],
            'public reply only' => [['status' => 'sent', 'reply_status' => 'sent']],
            'private message only' => [['status' => 'sent', 'pm_status' => 'sent']],
            'unknown outcome (504)' => [['status' => 'unknown', 'pm_status' => 'unknown', 'reply_status' => 'sent']],
            'already replied (Meta)' => [['status' => 'skipped', 'reason' => 'already_replied', 'pm_status' => 'already_replied']],
            'private message failed, reply sent' => [['status' => 'failed', 'reason' => 'forbidden', 'pm_status' => 'failed', 'reply_status' => 'sent']],
            'still sending' => [['status' => 'sending', 'handled_at' => null]],
            'waiting for the next minute with the private message sent' => [['status' => 'queued', 'pm_status' => 'sent', 'handled_at' => null]],
        ];
    }

    #[DataProvider('answeredEarlier')]
    public function test_S_218_1_2_a_customer_answered_today_on_the_page_is_skipped_daily_limit(array $row): void
    {
        $this->forbeastPage();
        $this->gateway();
        $this->earlier($row);

        $this->comment()->assertOk();

        $this->assertSame([], $this->calls);
        $this->assertSame(['skipped', 'daily_limit'], [$this->event()->status, $this->event()->reason]);
    }

    public function test_S_218_1_3_the_day_is_the_manila_calendar_day(): void
    {
        $this->forbeastPage();
        $this->gateway();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-25 23:50:00', 'Asia/Manila'));
        $this->comment(['comment_id' => 'c_1'])->assertOk();

        $this->travelTo(\Carbon\Carbon::parse('2026-10-26 00:10:00', 'Asia/Manila'));
        $this->comment(['comment_id' => 'c_2'])->assertOk();
        $this->comment(['comment_id' => 'c_3'])->assertOk();

        $this->assertSame(['private-reply', 'reply', 'private-reply', 'reply'], $this->called());
        $this->assertSame(['sent', 'sent', 'skipped', 'daily_limit'],
            [$this->event('c_1')->status, $this->event('c_2')->status, $this->event('c_3')->status, $this->event('c_3')->reason]);
    }

    public function test_S_218_1_4_the_limit_is_per_page(): void
    {
        $this->forbeastPage();
        $this->gateway();
        $this->earlier(['page_id' => 1002, 'status' => 'sent', 'pm_status' => 'sent', 'reply_status' => 'sent']); // sinagot na sa ibang page

        $this->comment(['comment_id' => 'c_1'])->assertOk();
        $this->comment(['comment_id' => 'c_2'])->assertOk();

        $this->assertSame(['private-reply', 'reply'], $this->called());
        $this->assertSame(['sent', 'skipped'], [$this->event('c_1')->status, $this->event('c_2')->status]);
    }

    public function test_S_218_1_5_an_earlier_comment_skipped_or_failed_before_sending_does_not_count(): void
    {
        $this->forbeastPage();
        $this->gateway();
        foreach (['bot_off', 'not_set', 'reply', 'page_author', 'botcake_page', 'daily_limit'] as $reason) $this->earlier(['status' => 'skipped', 'reason' => $reason]);
        $this->earlier(['status' => 'failed', 'reason' => 'forbidden', 'pm_status' => 'failed']); // pumalya ang PM, walang Comment Reply
        $this->earlier(['status' => 'failed', 'reason' => null]);                                  // pumalya ang job bago magpadala
        $this->earlier(['status' => 'queued', 'handled_at' => null]);                              // naghihintay, wala pang naipadala

        $this->comment(['comment_id' => 'c_1'])->assertOk();
        $this->comment(['comment_id' => 'c_2'])->assertOk();

        $this->assertSame(['private-reply', 'reply'], $this->called());
        $this->assertSame(['sent', 'skipped', 'daily_limit'], [$this->event('c_1')->status, $this->event('c_2')->status, $this->event('c_2')->reason]);
    }

    public function test_S_218_1_6_two_comments_at_the_same_moment_get_one_answer(): void
    {
        $this->forbeastPage();
        Queue::fake([HandleComment::class]);
        $this->comment(['comment_id' => 'c_1'])->assertOk();
        $this->comment(['comment_id' => 'c_2'])->assertOk();
        // Ang ikalawang worker ay tumatakbo habang ang una ay nasa tawag pa sa Forbeast.
        $second = null;
        Http::fake(['gw.example.test/*' => function (HttpRequest $req) use (&$second) {
            $this->calls[] = ['url' => $req->url()];
            $second ??= $this->handled('c_2');
            return Http::response(['ok' => true, 'comment_id' => 'our_' . count($this->calls)]);
        }]);

        $this->handled('c_1');

        $this->assertSame(['private-reply', 'reply'], $this->called());
        $this->assertSame(['sent', 'skipped', 'daily_limit'], [$this->event('c_1')->status, $this->event('c_2')->status, $this->event('c_2')->reason]);
        $second->assertNotReleased();
    }

    public function test_S_218_1_6_a_comment_waits_while_another_worker_decides_for_the_same_customer(): void
    {
        $this->forbeastPage();
        $this->gateway();
        Queue::fake([HandleComment::class]);
        $this->comment(['comment_id' => 'c_1'])->assertOk();
        $this->comment(['comment_id' => 'c_2'])->assertOk();

        $held = \Illuminate\Support\Facades\Cache::lock('comment-replies:once:1001:555', 10);
        $this->assertTrue($held->get());
        $this->handled('c_2')->assertReleased(5);
        $this->assertSame([[], 'queued'], [$this->calls, $this->event('c_2')->status]);
        $held->release();

        $this->handled('c_1');
        $this->handled('c_2')->assertNotReleased();

        $this->assertSame(['private-reply', 'reply'], $this->called());
        $this->assertSame(['sent', 'skipped', 'daily_limit'], [$this->event('c_1')->status, $this->event('c_2')->status, $this->event('c_2')->reason]);
    }

    public function test_S_218_1_7_a_comment_without_from_id_is_answered_as_today(): void
    {
        $this->forbeastPage();
        $this->gateway();

        $this->comment(['comment_id' => 'c_1', 'from_id' => null])->assertOk();
        $this->comment(['comment_id' => 'c_2', 'from_id' => null])->assertOk();
        $this->comment(['comment_id' => 'c_3', 'from_id' => ''])->assertOk();

        $this->assertSame(['private-reply', 'reply', 'private-reply', 'reply', 'private-reply', 'reply'], $this->called());
        $this->assertSame(['sent', 'sent', 'sent'], [$this->event('c_1')->status, $this->event('c_2')->status, $this->event('c_3')->status]);
    }

    public function test_S_218_1_8_a_comment_waiting_for_the_next_minute_is_checked_when_it_is_sent(): void
    {
        $this->forbeastPage();
        $this->travelTo('2026-10-25 10:00:15');
        $pms = 0;
        Http::fake(['gw.example.test/*' => function (HttpRequest $req) use (&$pms) {
            $this->calls[] = ['url' => $req->url()];
            if (str_ends_with($req->url(), '/private-reply') && ++$pms === 1) return Http::response(['ok' => false, 'error' => 'rate_limited'], 429);
            return Http::response(['ok' => true, 'comment_id' => 'our_' . count($this->calls)]);
        }]);
        Queue::fake([HandleComment::class]);
        $this->comment(['comment_id' => 'c_1'])->assertOk();
        $this->handled('c_1')->assertReleased(45);                       // 429: wala pang naipadala, naghihintay sa susunod na minuto
        $this->assertSame(['queued', null], [$this->event('c_1')->status, $this->event('c_1')->pm_status]);

        $this->travelTo('2026-10-25 10:01:00');
        $this->comment(['comment_id' => 'c_2'])->assertOk();
        $this->handled('c_2');                                           // ang bagong comment ang naunang naipadala
        $this->handled('c_1')->assertNotReleased();

        $this->assertSame(['private-reply', 'private-reply', 'reply'], $this->called());
        $this->assertSame(['sent', 'skipped', 'daily_limit'], [$this->event('c_2')->status, $this->event('c_1')->status, $this->event('c_1')->reason]);
    }

    public function test_S_218_1_9_the_replies_view_says_already_answered_today(): void
    {
        $this->forbeastPage();
        $this->gateway();
        $this->comment(['comment_id' => 'c_1'])->assertOk();
        $this->comment(['comment_id' => 'c_2'])->assertOk();

        $this->assertSame('already answered today', CommentReplies::REASONS['daily_limit'] ?? null);
        $this->actingAs($this->user());
        $rows = $this->getJson('/chatbot/replies/data?date=' . now('Asia/Manila')->toDateString() . '&kind=comments&status=skipped')->assertOk()->json('rows');
        $this->assertSame([['c_2', 'Comment c_2 · Skipped: already answered today']], array_map(fn ($r) => [$r['comment_id'], $r['summary']], $rows));
    }
}
