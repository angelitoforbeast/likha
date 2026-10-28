<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\RunChatbotFlow;
use App\Models\BotcakeChat;
use App\Models\ChatbotFlow;
use App\Services\Chatbot\BotHealth;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\CustomerFields;
use App\Services\Chatbot\FlowGraph;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Ang echo ng bawat padala ng page (galing sa Forbeast Gateway, kasama ang app ng nagpadala): iniimbak nang maikli, ang
 * label nito sa Inbox, ang hindi nasagot sa loob ng 5 minuto at ang dobleng sagot sa Bot Health.
 */
class ChatEchoTest extends ChatblastTestCase
{
    private const SECRET = 'gw-webhook-secret-test-9o8i7u';
    private const PSID = '6012345678901234';
    private const FORBEAST = '1115162827653315';
    private const BOTCAKE = '556376998159104';
    private const PAGE_INBOX = '263902037430900';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_22_100000_widen_source_on_botcake_psids.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_inbox_indexes.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_28_100000_create_chatbot_ai_requests_table.php',
            'database/migrations/2026_10_29_100000_create_botcake_send_requests_table.php',
            'database/migrations/2026_10_30_098000_add_raw_to_chatbot_replies.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
            'database/migrations/2026_11_06_100000_add_chat_index_to_chatbot_replies.php',
            'database/migrations/2026_11_08_100000_create_chatbot_comment_events_table.php',
            'database/migrations/2026_11_09_100000_add_via_to_chatbot_replies.php',
        ]]);
        config(['services.chatbot.replies_enabled' => true, 'services.gateway.url' => 'https://gw.example.test', 'services.gateway.key' => 'gw_test_key_1q2w3e4r',
            'services.gateway.webhook_secret' => self::SECRET]);
        $this->page('Page Uno', '1001');
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'provider', 'gateway');
    }

    /** Isang echo event ng gateway (ang padala ng page sa customer), galing sa $app. */
    private static function echo(string $mid, ?string $app, string $text, ?int $ms = null, string $psid = self::PSID, string $page = '1001'): array
    {
        return ['kind' => 'event', 'event_id' => crc32($mid), 'page_id' => $page, 'psid' => $psid, 'type' => 'message', 'mid' => $mid,
            'timestamp' => $ms ?? now()->getTimestampMs(), 'text' => $text, 'attachments' => [], 'is_echo' => true, 'app_id' => $app];
    }

    /** POST ng body sa webhook, pinirmahan. */
    private function send(mixed $body, bool $batch = false)
    {
        $raw = json_encode($body);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_GATEWAY_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $raw, self::SECRET)];
        if ($batch) $headers['HTTP_X_GATEWAY_BATCH'] = '1';

        return $this->call('POST', '/api/gateway/events', [], [], [], $headers, $raw);
    }

    /** @return \Illuminate\Support\Collection ang mga echo row, may detail na naka-decode */
    private function echoes()
    {
        return DB::table('chatbot_events')->where('type', 'echo')->orderBy('id')->get()->map(fn ($r) => ['psid' => (string) $r->psid, 'at' => $r->created_at] + json_decode($r->detail, true));
    }

    private function thread(string $psid = self::PSID): array
    {
        return $this->getJson('/chatbot/inbox/thread?page_id=1001&psid=' . $psid)->assertOk()->json('items');
    }

    /** Ang mensahe ng customer na naimbak na (galing sa Forbeast), $ago segundo na ang nakalipas. */
    private function said(string $psid, int $ago, string $message = 'Available po?', array $payload = [], string $pageId = '1001', string $page = 'Page Uno'): void
    {
        BotcakeChat::create(['page_id' => $pageId, 'page_name' => $page, 'psid' => $psid, 'message' => $message, 'message_id' => 'm_in_' . $psid . '_' . $ago,
            'payload' => ['source' => 'gateway'] + $payload, 'received_at' => now()->subSeconds($ago)]);
    }

    public function test_S_183_1_a_botcake_echo_is_stored_once_with_its_sender_and_shows_on_the_inbox_chat(): void
    {
        $this->send(self::echo('m_echo_1', self::BOTCAKE, 'Hello po! Ano po ang order nila?'))->assertOk();
        $this->send(self::echo('m_echo_1', self::BOTCAKE, 'Hello po! Ano po ang order nila?'))->assertOk();

        $rows = $this->echoes();
        $this->assertCount(1, $rows, 'the same Meta message id is stored once');
        $this->assertSame([self::PSID, 'm_echo_1', self::BOTCAKE, 'botcake', 'Hello po! Ano po ang order nila?'],
            [$rows[0]['psid'], $rows[0]['mid'], $rows[0]['app_id'], $rows[0]['sender'], $rows[0]['text']]);
        $this->assertSame(16, strlen($rows[0]['hash']));

        $this->actingAs($this->user());
        $item = collect($this->thread())->firstWhere('side', 'out');
        $this->assertSame(['Botcake', [['type' => 'text', 'text' => 'Hello po! Ano po ang order nila?']]], [$item['sender'], $item['messages']]);
        $this->assertStringContainsString('x.sender', $this->get('/chatbot/inbox')->getContent());
    }

    public function test_S_183_1b_a_batch_of_three_echoes_is_stored_once_each_in_either_body_shape(): void
    {
        $three = [self::echo('m_b1', self::FORBEAST, 'Hi po'), self::echo('m_b2', self::BOTCAKE, 'Hello'), self::echo('m_b3', self::PAGE_INBOX, 'Sige po')];

        $this->send(['kind' => 'batch', 'events' => $three], true)->assertOk()->assertJson(['accepted' => 3]);
        $this->send($three, true)->assertOk();

        $this->assertSame(['m_b1', 'm_b2', 'm_b3'], $this->echoes()->pluck('mid')->all());
        $this->assertSame(['forbeast', 'botcake', 'person'], $this->echoes()->pluck('sender')->all());
    }

    public function test_S_183_1c_text_is_cut_to_200_characters_an_unknown_app_is_other_and_an_echo_to_the_page_itself_is_not_stored(): void
    {
        $this->send(self::echo('m_long', '999000111', str_repeat('a', 300)))->assertOk();
        $this->send(self::echo('m_self', self::BOTCAKE, 'Hi', null, '1001'))->assertOk();

        $row = $this->echoes()->first();
        $this->assertSame([1, 200, 'other'], [$this->echoes()->count(), mb_strlen($row['text']), $row['sender']]);
    }

    public function test_S_183_2_a_person_reply_is_labelled_person_and_the_bot_is_not_paused(): void
    {
        Queue::fake([RunChatbotFlow::class]);
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::clean(['nodes' => [
            ['id' => 'n1', 'type' => 'start'],
            ['id' => 'n2', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => 'Hi']]]],
        ], 'edges' => [['from' => 'n1', 'port' => 'next', 'to' => 'n2']]])]);
        app(ChatbotPage::class)->link('Page Uno', $flow);

        $this->send(self::echo('m_person', self::PAGE_INBOX, 'Ako na po sasagot'))->assertOk();
        $this->send(['kind' => 'event', 'event_id' => 501, 'page_id' => '1001', 'psid' => self::PSID, 'type' => 'message', 'mid' => 'm_cust_1',
            'text' => 'Salamat po', 'attachments' => [], 'is_echo' => false])->assertOk();

        $this->assertNotSame('1', (string) (new CustomerFields(1001, (int) self::PSID))->get(CustomerFields::BOT_PAUSED));
        Queue::assertPushed(RunChatbotFlow::class, 1);
        $this->actingAs($this->user());
        $this->assertSame('Person', collect($this->thread())->firstWhere('side', 'out')['sender']);
    }

    public function test_S_183_3_customers_with_no_echo_in_5_minutes_are_unanswered_and_three_make_the_page_red(): void
    {
        // Ang page ay may echo (nakakabit ang Forbeast sa echo ng page).
        $this->send(self::echo('m_old', self::FORBEAST, 'Hi', now()->subHours(2)->getTimestampMs(), '6000000000000009'))->assertOk();
        foreach (['6000000000000001', '6000000000000002'] as $psid) $this->said($psid, 600);
        // Sinagot sa loob ng 5 minuto: hindi binibilang. Sinagot pagkalipas ng 6 na minuto: binibilang pa rin.
        $this->said('6000000000000004', 600);
        $this->said('6000000000000007', 900);
        $this->send(self::echo('m_late', self::BOTCAKE, 'Sorry po', now()->subSeconds(540)->getTimestampMs(), '6000000000000007'))->assertOk();
        $this->send(self::echo('m_ok', self::FORBEAST, 'Yes po', now()->subSeconds(480)->getTimestampMs(), '6000000000000004'))->assertOk();
        // Ang attachment na hindi sinasagot ng bot, at ang mensaheng wala pang 5 minuto: hindi binibilang.
        $this->said('6000000000000005', 600, '[Audio]', ['_ignored' => 'audio', 'attachment' => ['type' => 'audio', 'url' => null]]);
        $this->said('6000000000000006', 120);

        $this->actingAs($this->user());
        $echoes = $this->get('/chatbot/health')->assertOk()->assertSee('Who answered')->viewData('echoes');
        $page = collect($echoes['pages'])->firstWhere('page_id', '1001');
        $this->assertSame(3, $page['unanswered']);
        $banner = app(BotHealth::class)->banner();
        $this->assertSame(['Page Uno', '3 customers not answered in 5 min'], [$banner['pages'][0]['page'], $banner['pages'][0]['reason']]);
    }

    public function test_S_183_3b_a_bot_off_page_and_fewer_than_three_customers_make_no_banner(): void
    {
        $s = app(PageSettings::class);
        $this->page('Page Dos', '1002');
        $s->set('Page Dos', 'chatbot', 'provider', 'gateway');
        $this->send(self::echo('m_old2', self::FORBEAST, 'Hi', now()->subHour()->getTimestampMs(), '6000000000000009', '1002'))->assertOk();
        $this->send(self::echo('m_old1', self::FORBEAST, 'Hi', now()->subHour()->getTimestampMs(), '6000000000000009'))->assertOk();
        foreach (['6000000000000001', '6000000000000002', '6000000000000003'] as $psid) $this->said($psid, 600, 'Hm', [], '1002', 'Page Dos');
        $s->set('Page Dos', 'chatbot', 'active', '0', false, null, true);
        foreach (['6000000000000001', '6000000000000002'] as $psid) $this->said($psid, 600);

        $this->actingAs($this->user());
        $pages = collect($this->get('/chatbot/health')->assertOk()->viewData('echoes')['pages']);
        $this->assertNull($pages->firstWhere('page_id', '1002'), 'bot Off');
        $this->assertSame(2, $pages->firstWhere('page_id', '1001')['unanswered']);
        $this->assertNull(app(BotHealth::class)->banner());
    }

    public function test_S_183_4_two_apps_replying_to_one_customer_within_60_s_is_a_double_reply(): void
    {
        $t = now()->subMinutes(10);
        $this->send(self::echo('m_d1', self::FORBEAST, 'Hi po', $t->getTimestampMs()))->assertOk();
        $this->send(self::echo('m_d2', self::BOTCAKE, 'Hello po', $t->copy()->addSeconds(30)->getTimestampMs()))->assertOk();
        // Ibang customer: 90 s ang pagitan (hindi doble); ang iisang app nang dalawang beses (hindi doble).
        $this->send(self::echo('m_d3', self::FORBEAST, 'Hi po', $t->getTimestampMs(), '6000000000000002'))->assertOk();
        $this->send(self::echo('m_d4', self::BOTCAKE, 'Hello po', $t->copy()->addSeconds(90)->getTimestampMs(), '6000000000000002'))->assertOk();
        $this->send(self::echo('m_d5', self::FORBEAST, 'Hi po', $t->getTimestampMs(), '6000000000000003'))->assertOk();
        $this->send(self::echo('m_d6', self::FORBEAST, 'Ano po?', $t->copy()->addSeconds(5)->getTimestampMs(), '6000000000000003'))->assertOk();

        $this->actingAs($this->user());
        $page = collect($this->get('/chatbot/health')->assertOk()->viewData('echoes')['pages'])->firstWhere('page_id', '1001');
        $this->assertSame(1, $page['doubles']);
        $this->assertSame('Bot (Forbeast) + Botcake', $page['double_examples'][0]['senders']);
        $this->assertStringContainsString('psid=' . self::PSID, $page['double_examples'][0]['inbox']);
    }

    /** [ang body, ang status na inaasahan, may X-Gateway-Batch] */
    public static function refusedLists(): array
    {
        $echo = self::echo('m_r1', self::BOTCAKE, 'Hello');

        return [
            'mixed pages' => [[$echo, ['page_id' => '1002'] + self::echo('m_r2', self::BOTCAKE, 'Hi')], 422],
            'a customer message' => [[$echo, ['is_echo' => false] + self::echo('m_r3', null, 'Available po?')], 422],
            'is_echo as text' => [[['is_echo' => 'true'] + $echo], 422],
            'over 64 KB without the header' => [[$echo + ['note' => str_repeat('x', 70000)]], 413, false],
        ];
    }

    /** @dataProvider refusedLists */
    public function test_S_183_1d_a_refused_list_stores_nothing(array $body, int $status, bool $header = true): void
    {
        $this->page('Page Dos', '1002');

        $this->send($body, $header)->assertStatus($status);

        $this->assertCount(0, $this->echoes());
        $this->assertSame(0, BotcakeChat::count());
    }

    public function test_S_183_1e_an_echo_labels_only_a_reply_sent_through_its_own_app(): void
    {
        $t = now()->subMinute();
        \App\Models\ChatbotReply::create(['page_id' => 1001, 'psid' => (int) self::PSID, 'kind' => 'send', 'content' => [['type' => 'text', 'text' => 'Hello po!']],
            'status' => 'sent', 'sent_at' => $t, 'via' => 'forbeast']);
        // Ang Botcake ay nagpadala rin ng parehong text: hiwalay na bubble, hindi label ng sagot ng Forbeast.
        $this->send(self::echo('m_bc', self::BOTCAKE, 'Hello po!', $t->copy()->addSeconds(5)->getTimestampMs()))->assertOk();
        $this->send(self::echo('m_fb', self::FORBEAST, 'Hello po!', $t->copy()->addSecond()->getTimestampMs()))->assertOk();

        $this->actingAs($this->user());
        $outs = collect($this->thread())->where('side', 'out')->values();
        $this->assertSame([['Bot', 'Bot (Forbeast)'], ['Botcake', 'Botcake']], $outs->map(fn ($x) => [$x['by'], $x['sender']])->all());
    }
}
