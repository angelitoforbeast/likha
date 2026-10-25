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
        $body = json_encode($e + ['page_id' => '1001', 'type' => 'comment', 'comment_id' => '111_222', 'post_id' => '1001_9', 'parent_id' => '1001_9',
            'message' => self::COMMENT_TEXT, 'created_time' => '2026-10-25T02:00:00+0000', 'from_id' => '555', 'from_name' => null,
            'is_reply' => false, 'is_page_author' => false]);
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
        for ($i = 1; $i <= 21; $i++) $this->comment(['comment_id' => 'c_' . $i])->assertOk();
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

    public function test_S_149_2_a_botfield_inside_a_comment_value_is_filled_too(): void
    {
        $this->forbeastPage(['comment_pm' => '{{SHOP DETAILS}}', 'shop_details' => 'Only {{PRICE}} pesos']);
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
        $this->comment(['comment_id' => 'c_2'])->assertOk();
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
}
