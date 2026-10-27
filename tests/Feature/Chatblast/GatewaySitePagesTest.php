<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\RunChatbotSequenceStep;
use App\Models\ChatbotFlow;
use App\Models\ChatbotSequence;
use App\Models\PageSetting;
use App\Services\Chatbot\ChatbotSequences;
use App\Services\Chatbot\ForbeastPageSetup;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * /api/gateway/site/pages/{meta_page_id}/...: ang Forbeast ang naglilipat ng page sa pagitan ng dalawang site (adopt,
 * export, import, release, activate), pinirmahan ng FORBEAST_SITE_SECRET (HMAC ng "<timestamp>.<body>"). Ang Forbeast
 * ay pinepeke ng mga request dito, at ang ForbeastPageSetup::connect ay fake.
 */
class GatewaySitePagesTest extends ChatblastTestCase
{
    private const SECRET = 'site-secret-for-tests-z9x8c7v6';

    /** @var array<int, string> ang mga page na ikinonekta ng fake na connect */
    private array $connected = [];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_25_100000_create_chatbot_waits_table.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
            'database/migrations/2026_10_28_100000_create_chatbot_ai_requests_table.php',
            'database/migrations/2026_10_29_100000_create_botcake_send_requests_table.php',
            'database/migrations/2026_11_12_100000_add_source_to_page_settings.php',
        ]]);
        config(['services.gateway.url' => 'https://forbeast.example.test/api', 'services.forbeast_site.key' => 'fbs_test_key', 'services.forbeast_site.secret' => self::SECRET, 'app.url' => 'https://likhaaitech.com']);
        $connected = &$this->connected;
        $this->app->instance(ForbeastPageSetup::class, new class(app(PageSettings::class), $connected) extends ForbeastPageSetup {
            public function __construct(PageSettings $s, private array &$calls)
            {
                parent::__construct($s);
            }

            public function connect(string $page, ?int $userId = null): array
            {
                $this->calls[] = $page;

                return ['status' => 'ok', 'name' => $page, 'error' => null, 'written' => []];
            }
        });
        $this->page('Page Uno', '1001');
    }

    private function signed(string $method, string $url, array|string $body = '', ?int $ts = null, string $secret = self::SECRET, string $transfer = '77')
    {
        $raw = is_array($body) ? json_encode($body) : $body;
        $ts ??= time();
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_GATEWAY_TIMESTAMP' => (string) $ts, 'HTTP_X_GATEWAY_TRANSFER' => $transfer,
            'HTTP_X_GATEWAY_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $ts . '.' . ($method === 'GET' ? '' : $raw), $secret)];

        return $this->call($method, $url, [], [], [], $server, $method === 'GET' ? '' : $raw);
    }

    private function setting(string $page, string $provider, string $key): ?PageSetting
    {
        return app(PageSettings::class)->pageQuery($page)->where('provider', $provider)->where('key', $key)->where('active', true)->first();
    }

    private function flow(string $name): ChatbotFlow
    {
        return ChatbotFlow::create(['name' => $name, 'graph' => ['nodes' => [], 'edges' => []]]);
    }

    private function sequence(string $name): ChatbotSequence
    {
        $flow = ChatbotFlow::firstOrCreate(['name' => 'Seq flow'], ['graph' => ['nodes' => [], 'edges' => []]]);
        $seq = ChatbotSequence::create(['name' => $name, 'active' => true, 'exit_on_reply' => false]);
        foreach ([1, 2, 3] as $p) $seq->steps()->create(['position' => $p, 'delay_amount' => 1, 'delay_unit' => 'hours', 'flow_id' => $flow->id, 'active' => true]);

        return $seq;
    }

    private function sub(int $sequenceId, int $psid, string $status = 'active', int $step = 2, string $page = '1001'): int
    {
        return DB::table('chatbot_sequence_subscriptions')->insertGetId(['sequence_id' => $sequenceId, 'page_id' => $page, 'psid' => $psid, 'status' => $status,
            'current_step' => $step, 'next_send_at' => '2026-10-28 09:30:00', 'enrolled_via' => 'flow', 'created_at' => now(), 'updated_at' => now()]);
    }

    // ---- adopt ----

    public function test_S_164_3_adopt_saves_the_page_and_connects_it_take_leaves_the_bot_off(): void
    {
        $res = $this->signed('POST', '/api/gateway/site/pages/2002/adopt', ['name' => 'Page Tres', 'token' => 'fbp_secret_token_123', 'mode' => 'transfer'])->assertOk();

        $this->assertStringNotContainsString('fbp_secret_token_123', $res->getContent());
        $s = app(PageSettings::class);
        $this->assertSame('2002', $s->get('Page Tres', 'botcake', 'page_id'));
        $this->assertSame('gateway', $s->get('Page Tres', 'chatbot', 'provider'));
        $this->assertSame('fbp_secret_token_123', $s->get('Page Tres', 'forbeast', 'token'));
        $token = $this->setting('Page Tres', 'forbeast', 'token');
        $this->assertTrue((bool) $token->is_secret);
        $this->assertSame('transfer 77 from incepxion', $token->source);
        $this->assertNull($s->get('Page Tres', 'chatbot', 'active'), 'a transfer keeps the imported on/off');
        $this->assertSame(['Page Tres'], $this->connected);

        // take: the bot starts off; an existing page (by Meta id) keeps its own name.
        $this->signed('POST', '/api/gateway/site/pages/1001/adopt', ['name' => 'Renamed in Meta', 'token' => 'fbp_other_456', 'mode' => 'take', 'actor' => 'Ana Cruz ana@x.test'])->assertOk();
        $this->assertSame('0', $s->get('Page Uno', 'chatbot', 'active'));
        $this->assertSame('taken by Ana Cruz ana@x.test', $this->setting('Page Uno', 'chatbot', 'active')->source);
        $this->assertSame('fbp_other_456', $s->get('Page Uno', 'forbeast', 'token'));
        $this->assertSame(0, PageSetting::where('page_name', 'Renamed in Meta')->count());
        $this->assertSame(['Page Tres', 'Page Uno'], $this->connected);
    }

    public function test_S_164_3_a_taken_page_with_only_a_forbeast_token_is_listed_on_the_chatbot_pages_with_the_bot_off(): void
    {
        $this->signed('POST', '/api/gateway/site/pages/2002/adopt', ['name' => 'Page Tres', 'token' => 'fbp_x', 'mode' => 'take'])->assertOk();
        app(PageSettings::class)->set('Page Cuatro', 'forbeast', 'token', 'fbp_no_page_id', true); // walang Meta page id: hindi page

        $this->actingAs($this->user());
        $rows = collect($this->getJson('/chatbot/data')->assertOk()->json('rows'));

        $this->assertSame(['Page Tres', 'Page Uno'], $rows->pluck('page')->all());
        $this->assertSame(['page_id' => '2002', 'active' => false, 'provider' => 'gateway'], array_intersect_key($rows->firstWhere('page', 'Page Tres'), ['page_id' => 1, 'active' => 1, 'provider' => 1]));
    }

    public function test_S_164_3_a_name_used_by_another_meta_page_is_409_and_nothing_is_saved(): void
    {
        $before = PageSetting::count();
        $this->signed('POST', '/api/gateway/site/pages/2002/adopt', ['name' => ' page  uno ', 'token' => 'fbp_x', 'mode' => 'take'])
            ->assertStatus(409)->assertJson(['error' => 'name_taken']);

        $this->assertSame($before, PageSetting::count());
        $this->assertSame([], $this->connected);
    }

    public static function badRequests(): array
    {
        return [
            'wrong secret' => [fn ($t) => $t->signed('POST', '/api/gateway/site/pages/2002/adopt', ['name' => 'Page Tres', 'token' => 'fbp_x', 'mode' => 'take'], null, 'wrong'), 401],
            'timestamp 301 s old' => [fn ($t) => $t->signed('POST', '/api/gateway/site/pages/2002/adopt', ['name' => 'Page Tres', 'token' => 'fbp_x', 'mode' => 'take'], time() - 301), 401],
            'no signature' => [fn ($t) => $t->postJson('/api/gateway/site/pages/2002/adopt', ['name' => 'Page Tres', 'token' => 'fbp_x', 'mode' => 'take']), 401],
            'export, wrong secret' => [fn ($t) => $t->signed('GET', '/api/gateway/site/pages/1001/export?part=fields', '', null, 'wrong'), 401],
            'release, old timestamp' => [fn ($t) => $t->signed('POST', '/api/gateway/site/pages/1001/release', ['to' => 'incepxion'], time() - 400), 401],
            'bad mode' => [fn ($t) => $t->signed('POST', '/api/gateway/site/pages/2002/adopt', ['name' => 'Page Tres', 'token' => 'fbp_x', 'mode' => 'steal']), 422],
            'the Setup box name' => [fn ($t) => $t->signed('POST', '/api/gateway/site/pages/2002/adopt', ['name' => ' _ALL ', 'token' => 'fbp_x', 'mode' => 'take']), 422],
            'import values named like the Setup box' => [fn ($t) => $t->signed('POST', '/api/gateway/site/pages/2002/import?part=values', ['items' => [['key' => 'name', 'value' => '_all']]]), 422],
            'no token' => [fn ($t) => $t->signed('POST', '/api/gateway/site/pages/2002/adopt', ['name' => 'Page Tres', 'mode' => 'take']), 422],
        ];
    }

    #[DataProvider('badRequests')]
    public function test_S_164_3_bad_signatures_and_bad_bodies_are_refused_and_nothing_is_saved(\Closure $send, int $status): void
    {
        $before = PageSetting::count();
        $send($this)->assertStatus($status);

        $this->assertSame($before, PageSetting::count());
        $this->assertSame([], $this->connected);
    }

    public function test_S_164_3_a_site_without_its_key_and_secret_answers_503(): void
    {
        config(['services.forbeast_site.secret' => '']);
        $this->signed('POST', '/api/gateway/site/pages/2002/adopt', ['name' => 'Page Tres', 'token' => 'fbp_x', 'mode' => 'take'], null, '')
            ->assertStatus(503)->assertJson(['error' => 'not_linked']);
        config(['services.forbeast_site.secret' => self::SECRET, 'services.forbeast_site.key' => '']);
        $this->signed('GET', '/api/gateway/site/pages/1001/export?part=values')->assertStatus(503);
    }

    // ---- export ----

    public function test_S_164_4_export_values_by_flow_name_without_secrets_tokens_or_prompts(): void
    {
        $s = app(PageSettings::class);
        $flow = $this->flow('Main PREORDER');
        foreach (['main_flow' => 'Hello', 'price' => '199', 'prompt_1' => 'You are a seller'] as $k => $v) $s->set('Page Uno', ChatbotFields::PROVIDER, $k, $v);
        $s->set('Page Uno', 'chatbot', 'flow', $flow->name);
        $s->set('Page Uno', 'chatbot', 'flow_id', (string) $flow->id, false, null, true);
        $s->set('Page Uno', 'chatbot', 'active', '0', false, null, true);
        $s->set('Page Uno', 'forbeast', 'token', 'fbp_never_exported', true);
        $s->set('Page Uno', 'forbeast', 'webhook_secret', 'whsec_never_exported', true, null, true);

        $res = $this->signed('GET', '/api/gateway/site/pages/1001/export?part=values')->assertOk();

        $items = collect($res->json('items'))->pluck('value', 'key');
        $this->assertNull($res->json('next'));
        $this->assertSame(['name' => 'Page Uno', 'active' => '0', 'flow' => 'Main PREORDER', 'main_flow' => 'Hello', 'price' => '199'],
            $items->only(['name', 'active', 'flow', 'main_flow', 'price'])->all());
        $this->assertTrue($items->has('seq_1') && $items['seq_1'] === null, 'unset fields are sent as null, so the target clears them');
        $raw = $res->getContent();
        foreach (['fbp_never_exported', 'whsec_never_exported', 'You are a seller', 'prompt_1', 'flow_id', 'shop_details', self::TOKEN] as $never) {
            $this->assertStringNotContainsString($never, $raw);
        }
        $this->signed('GET', '/api/gateway/site/pages/9999/export?part=values')->assertStatus(404)->assertJson(['error' => 'unknown_page']);
        $this->signed('GET', '/api/gateway/site/pages/1001/export?part=secrets')->assertStatus(422);
    }

    public function test_S_164_4_export_fields_pages_with_cursors_without_run_state_and_since_filters(): void
    {
        $rows = [];
        for ($i = 1; $i <= 600; $i++) {
            $rows[] = ['page_id' => 1001, 'psid' => 5000 + $i, 'key' => 'phone', 'value' => '0917' . $i, 'updated_at' => $i <= 590 ? '2026-10-01 08:00:00' : '2026-10-27 08:00:00'];
            if ($i % 3 === 0) $rows[] = ['page_id' => 1001, 'psid' => 5000 + $i, 'key' => '__wait', 'value' => '{"node":"n2"}', 'updated_at' => '2026-10-27 08:00:00'];
        }
        $rows[] = ['page_id' => 1001, 'psid' => 0, 'key' => 'phone', 'value' => 'test customer', 'updated_at' => '2026-10-27 08:00:00'];
        $rows[] = ['page_id' => 1002, 'psid' => 1, 'key' => 'phone', 'value' => 'other page', 'updated_at' => '2026-10-27 08:00:00'];
        foreach (array_chunk($rows, 200) as $chunk) DB::table('chatbot_customer_values')->insert($chunk);

        $all = [];
        $cursor = '';
        $calls = 0;
        do {
            $res = $this->signed('GET', '/api/gateway/site/pages/1001/export?part=fields' . ($cursor !== '' ? '&cursor=' . $cursor : ''))->assertOk();
            $items = $res->json('items');
            $this->assertLessThanOrEqual(500, count($items));
            $all = array_merge($all, $items);
            $cursor = (string) $res->json('next');
            $calls++;
        } while ($cursor !== '' && $calls < 10);

        $this->assertCount(600, $all);
        $this->assertSame([], array_values(array_filter($all, fn ($it) => str_starts_with($it['key'], '__') || $it['psid'] === 0 || $it['value'] === 'other page')));
        $this->assertSame(['psid' => 5001, 'key' => 'phone', 'value' => '09171'], array_diff_key($all[0], ['updated_at' => 1]));
        $this->assertStringStartsWith('2026-10-01T08:00:00', $all[0]['updated_at']);

        $since = $this->signed('GET', '/api/gateway/site/pages/1001/export?part=fields&since=' . urlencode('2026-10-20T00:00:00+08:00'))->assertOk();
        $this->assertSame(range(5591, 5600), array_column($since->json('items'), 'psid'));
        $this->signed('GET', '/api/gateway/site/pages/1001/export?part=fields&since=yesterday-ish')->assertStatus(422);
    }

    public function test_S_164_4_export_sequences_by_name_with_step_and_send_time(): void
    {
        $seq = $this->sequence('PREORDER');
        $this->sub($seq->id, 7001, 'active', 2);
        $this->sub($seq->id, 7002, 'completed', 3);
        $this->sub($seq->id, 7003, 'active', 1, '1002');

        $res = $this->signed('GET', '/api/gateway/site/pages/1001/export?part=sequences')->assertOk();

        $this->assertSame([['psid' => 7001, 'sequence' => 'PREORDER', 'current_step' => 2, 'next_send_at' => '2026-10-28T09:30:00+08:00', 'enrolled_via' => 'flow', 'last_message_at' => null]], $res->json('items'));
        $this->assertNull($res->json('next'));
    }

    public function test_S_164_9_sequences_carry_the_customer_last_message_time_and_the_import_keeps_the_newer_one(): void
    {
        $this->travelTo('2026-10-28 12:00:00');
        $seq = $this->sequence('PREORDER');
        foreach ([7001, 7002, 7003] as $psid) $this->sub($seq->id, $psid);
        $chat = fn (int $psid, string $at, array $payload = []) => DB::table('botcake_chats')->insert(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => (string) $psid,
            'name' => 'Ana', 'message' => 'Hi', 'payload' => json_encode($payload), 'received_at' => $at]);
        $chat(7001, '2026-10-27 09:00:00');
        $chat(7001, '2026-10-27 10:00:00');
        $chat(7001, '2026-10-27 11:00:00', ['_standby' => true]); // not a message to the bot
        DB::table('chatbot_customer_values')->insert([['page_id' => 1001, 'psid' => 7001, 'key' => '__last_message_at', 'value' => '2026-10-27 08:00:00', 'updated_at' => now()],
            ['page_id' => 1001, 'psid' => 7002, 'key' => '__last_message_at', 'value' => '2026-10-27 07:00:00', 'updated_at' => now()]]);

        $items = $this->signed('GET', '/api/gateway/site/pages/1001/export?part=sequences')->assertOk()->json('items');

        // The latest of the chats here and the time a previous move brought; null when there is neither.
        $this->assertSame([7001 => '2026-10-27T10:00:00+08:00', 7002 => '2026-10-27T07:00:00+08:00', 7003 => null], array_column($items, 'last_message_at', 'psid'));

        // Import on the other page: recorded per customer, twice is the same, and an older time never replaces a newer one.
        $import = fn (?string $at, int $psid = 8) => $this->signed('POST', '/api/gateway/site/pages/2002/import?part=sequences',
            ['items' => [['psid' => $psid, 'sequence' => 'PREORDER', 'current_step' => 2, 'next_send_at' => null, 'enrolled_via' => 'flow', 'last_message_at' => $at]]]);
        $stored = fn (int $psid = 8) => DB::table('chatbot_customer_values')->where('page_id', 2002)->where('psid', $psid)->where('key', '__last_message_at')->pluck('value')->all();
        $import('2026-10-27T02:00:00Z')->assertOk();
        $import('2026-10-27T02:00:00Z')->assertOk();
        $this->assertSame(['2026-10-27 10:00:00'], $stored());
        $import('2026-10-27T09:00:00+08:00')->assertOk();
        $import(null)->assertOk();
        $this->assertSame(['2026-10-27 10:00:00'], $stored());
        $import('2026-10-27T11:30:00+08:00')->assertOk();
        $this->assertSame(['2026-10-27 11:30:00'], $stored());
        $import(null, 9)->assertOk();
        $this->assertSame([], $stored(9));
        // A time ahead of this site's clock is kept as now, so it cannot hold the 24 h open.
        $import('2026-12-31T00:00:00+08:00', 9)->assertOk();
        $this->assertSame(['2026-10-28 12:00:00'], $stored(9));
        $import('yesterday', 9)->assertStatus(422)->assertJson(['error' => 'invalid_value']);
    }

    // ---- import ----

    public function test_S_164_5_import_fields_is_idempotent_and_the_newer_value_wins(): void
    {
        DB::table('chatbot_customer_values')->insert(['page_id' => 2002, 'psid' => 9, 'key' => 'address', 'value' => 'newer here', 'updated_at' => '2026-10-27 10:00:00']);
        $items = [
            ['psid' => 8, 'key' => 'phone', 'value' => '09170000000', 'updated_at' => '2026-10-27T08:00:00+08:00'],
            ['psid' => 8, 'key' => 'full name', 'value' => 'Ana', 'updated_at' => '2026-10-27T08:00:00+08:00'],
            ['psid' => 9, 'key' => 'address', 'value' => 'older there', 'updated_at' => '2026-10-26T08:00:00+08:00'],
        ];

        foreach ([1, 2] as $_) {
            $this->signed('POST', '/api/gateway/site/pages/2002/import?part=fields', ['items' => $items])->assertOk()->assertJson(['saved' => 3]);
        }

        $rows = DB::table('chatbot_customer_values')->where('page_id', 2002)->orderBy('psid')->orderBy('key')->get(['psid', 'key', 'value'])->map(fn ($r) => (array) $r)->all();
        $this->assertSame([['psid' => 8, 'key' => 'full name', 'value' => 'Ana'], ['psid' => 8, 'key' => 'phone', 'value' => '09170000000'], ['psid' => 9, 'key' => 'address', 'value' => 'newer here']], $rows);
        $this->signed('POST', '/api/gateway/site/pages/2002/import?part=fields', ['items' => [['psid' => 8, 'key' => '__wait', 'value' => 'x', 'updated_at' => null]]])->assertStatus(422);
        $this->signed('POST', '/api/gateway/site/pages/2002/import?part=fields', ['items' => array_fill(0, 501, $items[0])])->assertStatus(422);
        $this->assertSame(3, DB::table('chatbot_customer_values')->where('page_id', 2002)->count());
    }

    public function test_S_164_5_import_sequences_by_name_held_until_activate_and_missing_names_save_nothing(): void
    {
        Queue::fake();
        $seq = $this->sequence('PREORDER');
        $items = [['psid' => 8, 'sequence' => 'PREORDER', 'current_step' => 2, 'next_send_at' => '2026-10-27T08:00:00+08:00', 'enrolled_via' => 'flow']];

        $this->signed('POST', '/api/gateway/site/pages/2002/import?part=sequences', ['items' => array_merge($items, [['psid' => 9, 'sequence' => 'Gone Seq', 'current_step' => 1, 'next_send_at' => null, 'enrolled_via' => 'inbox']])])
            ->assertStatus(422)->assertJson(['error' => 'missing_names', 'names' => ['Gone Seq']]);
        $this->assertSame(0, DB::table('chatbot_sequence_subscriptions')->count());

        foreach ([1, 2] as $_) {
            $this->signed('POST', '/api/gateway/site/pages/2002/import?part=sequences', ['items' => $items])->assertOk()->assertJson(['saved' => 1]);
        }
        $row = DB::table('chatbot_sequence_subscriptions')->where('page_id', 2002)->get();
        $this->assertCount(1, $row);
        $this->assertSame(['sequence_id' => $seq->id, 'status' => 'paused', 'current_step' => 2, 'next_send_at' => '2026-10-27 08:00:00', 'enrolled_via' => 'flow', 'exit_reason' => 'transfer 77'],
            array_intersect_key((array) $row[0], array_flip(['sequence_id', 'status', 'exit_reason', 'current_step', 'next_send_at', 'enrolled_via'])));

        // Due already, but held: the tick sends nothing until activate.
        $this->travelTo('2026-10-27 09:00:00');
        app(ChatbotSequences::class)->tick();
        Queue::assertNotPushed(RunChatbotSequenceStep::class);

        // While held, a flow that subscribes the customer again does not restart them at step 1 ...
        $this->assertSame('already', app(ChatbotSequences::class)->subscribe($seq->id, 2002, 8, 'flow')['result']);
        $this->assertSame([2, 'paused'], [(int) DB::table('chatbot_sequence_subscriptions')->value('current_step'), DB::table('chatbot_sequence_subscriptions')->value('status')]);
        // ... and a reply with exit-on-reply ends the held row, so activate does not send it.
        ChatbotSequence::whereKey($seq->id)->update(['exit_on_reply' => true]);
        app(ChatbotSequences::class)->exitOnReply(2002, 8);
        $this->assertSame('unsubscribed', DB::table('chatbot_sequence_subscriptions')->value('status'));
    }

    public function test_S_164_6_activate_releases_only_the_rows_held_by_its_own_transfer(): void
    {
        $seq = $this->sequence('PREORDER');
        $items = [['psid' => 8, 'sequence' => 'PREORDER', 'current_step' => 2, 'next_send_at' => '2026-10-28T08:00:00+08:00', 'enrolled_via' => 'flow']];
        $this->signed('POST', '/api/gateway/site/pages/2002/import?part=sequences', ['items' => $items], null, self::SECRET, '70')->assertOk(); // a transfer that failed later
        $this->signed('POST', '/api/gateway/site/pages/2002/import?part=sequences', ['items' => [['psid' => 9] + $items[0]]], null, self::SECRET, '77')->assertOk();
        $this->signed('POST', '/api/gateway/site/pages/2002/import?part=values', ['items' => [['key' => 'name', 'value' => 'Page Tres']]])->assertOk();

        $this->signed('POST', '/api/gateway/site/pages/2002/activate', '', null, self::SECRET, '77')->assertOk()->assertJson(['activated' => 1]);

        $this->assertSame([8 => 'paused', 9 => 'active'], DB::table('chatbot_sequence_subscriptions')->orderBy('psid')->pluck('status', 'psid')->all());
        // The export of the page sends only real subscriptions, not rows still held by a transfer.
        $this->assertSame([9], array_column($this->signed('GET', '/api/gateway/site/pages/2002/export?part=sequences')->json('items'), 'psid'));
    }

    public function test_S_164_5_import_values_maps_the_flow_by_name_creates_the_page_and_a_missing_flow_saves_nothing(): void
    {
        $flow = $this->flow('Main PREORDER');
        $items = [['key' => 'name', 'value' => 'Page Tres'], ['key' => 'active', 'value' => '1'], ['key' => 'flow', 'value' => 'Main PREORDER'],
            ['key' => 'main_flow', 'value' => 'Hello'], ['key' => 'price', 'value' => '199'], ['key' => 'seq_1', 'value' => null], ['key' => 'seq_99', 'value' => 'only on the other site']];

        $this->signed('POST', '/api/gateway/site/pages/2002/import?part=values', ['items' => array_merge($items, [['key' => 'flow', 'value' => 'No Such Flow']])])
            ->assertStatus(422)->assertJson(['error' => 'missing_names', 'names' => ['No Such Flow']]);
        $this->assertSame(0, PageSetting::where('page_name', 'Page Tres')->count());

        foreach ([1, 2] as $_) {
            $res = $this->signed('POST', '/api/gateway/site/pages/2002/import?part=values', ['items' => $items])->assertOk();
        }
        $this->assertSame(['seq_99'], $res->json('skipped'));
        $s = app(PageSettings::class);
        $this->assertSame('2002', $s->get('Page Tres', 'botcake', 'page_id'));
        $this->assertSame((string) $flow->id, $s->get('Page Tres', 'chatbot', 'flow_id'));
        $this->assertSame('Main PREORDER', $s->get('Page Tres', 'chatbot', 'flow'));
        $this->assertSame('1', $s->get('Page Tres', 'chatbot', 'active'));
        $this->assertSame('Hello', $s->get('Page Tres', 'chatbot', 'main_flow'));
        $this->assertSame('transfer 77 from incepxion', $this->setting('Page Tres', 'chatbot', 'main_flow')->source);
        $this->assertSame(1, PageSetting::where('page_name', 'Page Tres')->where('key', 'main_flow')->count(), 'twice = same rows');

        // A name already used by another Meta page id.
        $this->signed('POST', '/api/gateway/site/pages/3003/import?part=values', ['items' => [['key' => 'name', 'value' => 'Page Uno'], ['key' => 'price', 'value' => '1']]])->assertStatus(409);
        $this->signed('POST', '/api/gateway/site/pages/2002/import?part=values', ['items' => [['key' => 'picture', 'value' => 'http://not-https.example/x.jpg']]])->assertStatus(422)->assertJson(['error' => 'invalid_value']);
    }

    // ---- release / activate ----

    public function test_S_164_6_release_stops_the_page_here_and_activate_releases_held_subscriptions(): void
    {
        $s = app(PageSettings::class);
        $s->set('Page Uno', 'forbeast', 'token', 'fbp_old', true);
        $s->set('Page Uno', 'forbeast', 'webhook_secret', 'whsec_old', true, null, true);
        $s->set('Page Uno', 'forbeast', 'token_status', 'ok · Forbeast connected', false, null, true);
        $seq = $this->sequence('PREORDER');
        $active = $this->sub($seq->id, 7001, 'active');
        $paused = $this->sub($seq->id, 7002, 'paused');
        $other = $this->sub($seq->id, 7003, 'active', 2, '1002');
        DB::table('chatbot_waits')->insert([['page_id' => 1001, 'psid' => 7001, 'flow_id' => 1, 'node_id' => 'n2', 'due_at' => now()->addHour(), 'created_at' => now()],
            ['page_id' => 1002, 'psid' => 7003, 'flow_id' => 1, 'node_id' => 'n2', 'due_at' => now()->addHour(), 'created_at' => now()]]);
        DB::table('chatbot_customer_values')->insert([['page_id' => 1001, 'psid' => 7001, 'key' => '__wait', 'value' => '{}', 'updated_at' => now()],
            ['page_id' => 1001, 'psid' => 7001, 'key' => '__ai', 'value' => 'req-1', 'updated_at' => now()], ['page_id' => 1001, 'psid' => 7001, 'key' => 'phone', 'value' => '0917', 'updated_at' => now()]]);
        DB::table('chatbot_ai_requests')->insert(['request_id' => 'req-1', 'page_id' => 1001, 'psid' => 7001, 'flow_id' => 1, 'node_id' => 'n3', 'page_name' => 'Page Uno',
            'model' => 'm', 'messages' => '[]', 'context' => '{}', 'max_tokens' => 10, 'status' => 'pending', 'deadline_at' => now()->addMinute(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('botcake_send_requests')->insert(['request_id' => 'snd-1', 'kind' => 'chatbot', 'priority' => 0, 'customer' => 'chatbot:1001:7001', 'page_id' => 1001,
            'page_name' => 'Page Uno', 'psid' => '7001', 'payload' => '{}', 'status' => 'pending', 'deadline_at' => now()->addMinute(), 'created_at' => now(), 'updated_at' => now()]);

        foreach ([1, 2] as $_) $this->signed('POST', '/api/gateway/site/pages/1001/release', ['to' => 'incepxion'])->assertOk();

        $this->assertSame('0', $s->get('Page Uno', 'chatbot', 'active'));
        $this->assertSame('transferred to incepxion', $this->setting('Page Uno', 'chatbot', 'active')->source);
        $subs = DB::table('chatbot_sequence_subscriptions')->pluck('status', 'id');
        $this->assertSame(['unsubscribed', 'unsubscribed', 'active'], [$subs[$active], $subs[$paused], $subs[$other]]);
        $this->assertSame('transferred', DB::table('chatbot_sequence_subscriptions')->where('id', $active)->value('exit_reason'));
        $this->assertSame([1002], DB::table('chatbot_waits')->pluck('page_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['phone'], DB::table('chatbot_customer_values')->where('page_id', 1001)->pluck('key')->all());
        $this->assertSame('resumed', DB::table('chatbot_ai_requests')->where('request_id', 'req-1')->value('status'));
        $this->assertSame('resumed', DB::table('botcake_send_requests')->where('request_id', 'snd-1')->value('status'));
        foreach (['token', 'webhook_secret', 'token_status'] as $key) {
            $this->assertNull($s->get('Page Uno', 'forbeast', $key));
            $this->assertSame(1, PageSetting::where('page_name', 'Page Uno')->where('provider', 'forbeast')->where('key', $key)->where('active', false)->count(), 'history kept');
        }
        $this->signed('POST', '/api/gateway/site/pages/9999/release', ['to' => 'incepxion'])->assertStatus(404);

        // activate on the new site: the held ones run again, same step and send time.
        $held = $this->sub($seq->id, 8001, 'paused', 3, '2002');
        DB::table('chatbot_sequence_subscriptions')->where('id', $held)->update(['exit_reason' => 'transfer 77']);
        $this->signed('POST', '/api/gateway/site/pages/2002/adopt', ['name' => 'Page Tres', 'token' => 'fbp_new', 'mode' => 'transfer'])->assertOk();
        $this->signed('POST', '/api/gateway/site/pages/2002/activate', '')->assertOk()->assertJson(['activated' => 1]);
        $row = DB::table('chatbot_sequence_subscriptions')->where('id', $held)->first();
        $this->assertSame(['active', 3, '2026-10-28 09:30:00', null], [$row->status, (int) $row->current_step, $row->next_send_at, $row->exit_reason]);
    }
}
