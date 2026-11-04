<?php

namespace Tests\Feature\Chatblast;

use App\Models\ChatbotReply;
use App\Services\Chatbot\ChatEchoes;
use App\Services\Chatbot\Gateways;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Ang echo ng padala sa ibang address ng Forbeast gateway (ibang Meta app, GATEWAY_APP_IDS): sagot ng Likha bot pa rin,
 * hindi "Other app", sa Inbox at sa Bot Health.
 */
class IncepxionEchoTest extends ChatblastTestCase
{
    private const SECRET = 'gw-webhook-secret-test-226x';
    private const PSID = '6012345678901234';
    private const FORBEAST = '1115162827653315';
    private const INCEPXION = '2067128400607287';
    private const BOTCAKE = '556376998159104';

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
            'database/migrations/2026_11_17_100000_add_route_to_chatbot_replies.php',
        ]]);
        config(['services.chatbot.replies_enabled' => true, 'services.gateway.url' => 'https://gw.example.test', 'services.gateway.key' => 'gw_test_key_226x',
            'services.gateway.webhook_secret' => self::SECRET, 'services.gateway.urls' => 'incepxion=https://ix.example.test']);
        $this->page('Page Uno', '1001');
        app(PageSettings::class)->set('Page Uno', 'chatbot', 'provider', 'gateway');
    }

    private function appIds(string $value = 'incepxion=' . self::INCEPXION): void
    {
        config(['services.gateway.app_ids' => $value]);
    }

    private static function echo(string $mid, ?string $app, string $text, ?int $ms = null, string $psid = self::PSID): array
    {
        return ['kind' => 'event', 'event_id' => crc32($mid), 'page_id' => '1001', 'psid' => $psid, 'type' => 'message', 'mid' => $mid,
            'timestamp' => $ms ?? now()->getTimestampMs(), 'text' => $text, 'attachments' => [], 'is_echo' => true, 'app_id' => $app];
    }

    private function send(array $body)
    {
        $raw = json_encode($body);

        return $this->call('POST', '/api/gateway/events', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_GATEWAY_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $raw, self::SECRET)], $raw);
    }

    private function reply(string $text, $at, ?string $route): void
    {
        ChatbotReply::create(['page_id' => 1001, 'psid' => (int) self::PSID, 'kind' => 'send', 'content' => [['type' => 'text', 'text' => $text]],
            'status' => 'sent', 'sent_at' => $at, 'via' => 'forbeast', 'route' => $route]);
    }

    /** @return array<int, array{0: string, 1: string|null}> ang mga palabas sa Inbox: [by, sender] */
    private function outs(): array
    {
        $this->actingAs($this->user());

        return collect($this->getJson('/chatbot/inbox/thread?page_id=1001&psid=' . self::PSID)->assertOk()->json('items'))
            ->where('side', 'out')->map(fn ($x) => [$x['by'], $x['sender'] ?? null])->values()->all();
    }

    private function stored(): array
    {
        return DB::table('chatbot_events')->where('type', 'echo')->orderBy('id')->get()->map(fn ($r) => json_decode($r->detail, true))->all();
    }

    public function test_S_226_1_1_an_incepxion_echo_folds_into_the_bot_reply_and_a_forbeast_echo_is_unchanged(): void
    {
        $this->appIds();
        $t = now()->subMinutes(2);
        $this->reply('Hello po!', $t, 'incepxion');
        $this->reply('Ano po order nila?', $t->copy()->addSeconds(20), 'forbeast');
        $this->send(self::echo('m_ix', self::INCEPXION, 'Hello po!', $t->copy()->addSecond()->getTimestampMs()))->assertOk();
        $this->send(self::echo('m_fb', self::FORBEAST, 'Ano po order nila?', $t->copy()->addSeconds(21)->getTimestampMs()))->assertOk();

        $this->assertSame([['Bot', 'Bot (Incepxion)'], ['Bot', 'Bot (Forbeast)']], $this->outs(), 'no "Other app" bubble');
        $this->assertSame([['forbeast', 'incepxion'], ['forbeast', null]], array_map(fn ($d) => [$d['sender'], $d['route'] ?? null], $this->stored()));
    }

    public function test_S_226_1_2_without_gateway_app_ids_the_same_echo_is_todays_other_app(): void
    {
        $t = now()->subMinutes(2);
        $this->reply('Hello po!', $t, 'incepxion');
        $this->send(self::echo('m_ix', self::INCEPXION, 'Hello po!', $t->copy()->addSecond()->getTimestampMs()))->assertOk();

        $this->assertSame([['Bot', null], ['Other app', 'Other app']], $this->outs());
        $this->assertSame([['other', null]], array_map(fn ($d) => [$d['sender'], $d['route'] ?? null], $this->stored()));
    }

    public function test_S_226_1_3_an_unmatched_echo_from_that_app_is_bot_incepxion_and_so_is_one_stored_before_the_setting(): void
    {
        // Naimbak bago pa ang GATEWAY_APP_IDS (sender other): sa pagbasa ay sa address na rin.
        $this->send(self::echo('m_old', self::INCEPXION, 'Galing sa tool', now()->subMinutes(3)->getTimestampMs()))->assertOk();
        $this->appIds();
        $this->send(self::echo('m_new', self::INCEPXION, 'Typed sa tool', now()->subMinute()->getTimestampMs()))->assertOk();

        $this->assertSame([['Bot (Incepxion)', 'Bot (Incepxion)'], ['Bot (Incepxion)', 'Bot (Incepxion)']], $this->outs());
        $this->assertSame(['sender' => 'forbeast', 'label' => 'Bot (Incepxion)'], array_intersect_key(ChatEchoes::latest('1001', self::PSID), ['sender' => 1, 'label' => 1]));
    }

    /** [GATEWAY_APP_IDS, ang inaasahang address => app id] */
    public static function appIdLists(): array
    {
        return [
            'one' => ['incepxion=' . self::INCEPXION, [self::INCEPXION => 'incepxion']],
            'two, comma and space' => ['incepxion=' . self::INCEPXION . ', other_one=12345', [self::INCEPXION => 'incepxion', '12345' => 'other_one']],
            'forbeast= and bad entries skipped' => ['forbeast=999 bad incepxion=abc =1 incepxion=' . self::INCEPXION, [self::INCEPXION => 'incepxion']],
            'unset' => ['', []],
        ];
    }

    /** @dataProvider appIdLists */
    public function test_S_226_1_4_gateway_app_ids_reads_like_gateway_urls(string $value, array $expected): void
    {
        $this->appIds($value);

        $this->assertSame($expected, Gateways::appIds());
    }

    public function test_S_226_2_1_bot_health_counts_an_incepxion_echo_as_the_bot_not_another_app(): void
    {
        $this->appIds();
        $t = now()->subMinutes(10);
        // Forbeast app + Incepxion app sa iisang customer: iisang bot, hindi doble.
        $this->send(self::echo('m_a1', self::FORBEAST, 'Hi po', $t->getTimestampMs()))->assertOk();
        $this->send(self::echo('m_a2', self::INCEPXION, 'Ano po?', $t->copy()->addSeconds(10)->getTimestampMs()))->assertOk();
        // Incepxion + Botcake: doble, ang label ay ang address.
        $this->send(self::echo('m_b1', self::INCEPXION, 'Hi po', $t->getTimestampMs(), '6000000000000002'))->assertOk();
        $this->send(self::echo('m_b2', self::BOTCAKE, 'Hello po', $t->copy()->addSeconds(10)->getTimestampMs(), '6000000000000002'))->assertOk();

        $this->actingAs($this->user());
        $page = collect($this->get('/chatbot/health')->assertOk()->viewData('echoes')['pages'])->firstWhere('page_id', '1001');
        $this->assertSame([1, 'Bot (Incepxion) + Botcake'], [$page['doubles'], $page['double_examples'][0]['senders']]);
        $this->assertStringContainsString('psid=6000000000000002', $page['double_examples'][0]['inbox']);
    }
}
