<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * Ang mga chat ng customer mula sa JSON API block ng Botcake (/api/botcake/chat) at ang Chats page ng Chatblast.
 * Fake na HTTP at queue; walang tawag sa Botcake o J&T.
 */
class BotcakeChatTest extends ChatblastTestCase
{
    private const KEY = 'chat-key-test-7f3k9q2m';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Queue::fake();
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_20_100000_create_botcake_chats_table.php']]);
        config(['services.botcake.chat_key' => self::KEY, 'app.url' => 'https://app.example.test']);
    }

    private function chat(array $params = [], array $headers = ['X-Chat-Key' => self::KEY])
    {
        return $this->postJson('/api/botcake/chat', $params + ['page_id' => '1001', 'psid' => 'psid-a', 'name' => 'Ana Cruz', 'message' => 'Hello po'], $headers);
    }

    private function stored(array $over = [], ?string $at = null): BotcakeChat
    {
        return BotcakeChat::create($over + [
            'page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => 'psid-a', 'name' => 'Ana Cruz', 'message' => 'Hello po',
            'payload' => [], 'received_at' => $at ?? now(),
        ]);
    }

    public static function badKeys(): array
    {
        return [
            'no key'        => [[], self::KEY],
            'wrong header'  => [['X-Chat-Key' => 'nope'], self::KEY],
            'wrong param'   => [['key' => 'nope'], self::KEY],
            'not on server' => [['X-Chat-Key' => ''], ''],
        ];
    }

    /** @dataProvider badKeys */
    public function test_S_059_1_a_missing_or_wrong_key_gets_401_with_no_detail(array $auth, string $serverKey): void
    {
        config(['services.botcake.chat_key' => $serverKey]);
        $headers = isset($auth['X-Chat-Key']) ? ['X-Chat-Key' => $auth['X-Chat-Key']] : [];
        $params = isset($auth['key']) ? ['key' => $auth['key']] : [];

        $res = $this->chat($params, $headers)->assertStatus(401);

        $this->assertStringNotContainsString('key', strtolower((string) $res->getContent()));
        $this->assertSame(0, BotcakeChat::count());
    }

    public static function transports(): array
    {
        return ['json' => ['json'], 'form' => ['form'], 'get' => ['get'], 'json as text/plain' => ['text']];
    }

    /** @dataProvider transports */
    public function test_S_059_2_json_form_and_get_are_stored(string $how): void
    {
        $data = ['page_id' => '1001', 'psid' => 'psid-a', 'name' => 'Ana Cruz', 'message' => 'Magkano po?'];
        $res = match ($how) {
            'json' => $this->postJson('/api/botcake/chat', $data, ['X-Chat-Key' => self::KEY]),
            'form' => $this->post('/api/botcake/chat', $data + ['key' => self::KEY]),
            'get'  => $this->get('/api/botcake/chat?' . http_build_query($data + ['key' => self::KEY])),
            'text' => $this->call('POST', '/api/botcake/chat', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_X_CHAT_KEY' => self::KEY], json_encode($data)),
        };

        $res->assertOk()->assertExactJson(['ok' => true]);
        $row = BotcakeChat::sole();
        $this->assertSame(['1001', 'psid-a', 'Ana Cruz', 'Magkano po?'], [$row->page_id, $row->psid, $row->name, $row->message]);
        $this->assertNotNull($row->received_at);
        Http::assertNothingSent();
    }

    public function test_S_059_3_aliases_and_any_case_fill_the_fields_and_extra_params_are_kept_without_the_key(): void
    {
        $this->post('/api/botcake/chat', [
            'Page_ID' => '1001', 'FB_ID' => 'psid-b', 'Full_Name' => 'Ben Reyes', 'Last_User_Input' => 'Order po',
            'Message_Id' => 'm-1', 'sent_at' => '2026-10-18 10:00', 'Phone' => '09170000000', 'KEY' => self::KEY,
        ])->assertOk();
        $this->postJson('/api/botcake/chat', ['page_id' => '1001', 'user_id' => 'psid-c', 'text' => 'Hi'], ['X-Chat-Key' => self::KEY])->assertOk();
        $this->postJson('/api/botcake/chat', ['page_id' => '1001', 'psid' => 'psid-d', 'last_input' => 'Yes'], ['X-Chat-Key' => self::KEY])->assertOk();

        $rows = BotcakeChat::orderBy('id')->get();
        $this->assertSame(['psid-b', 'Ben Reyes', 'Order po', 'm-1'], [$rows[0]->psid, $rows[0]->name, $rows[0]->message, $rows[0]->message_id]);
        $this->assertSame('09170000000', $rows[0]->payload['Phone']);
        $this->assertSame('2026-10-18 10:00', $rows[0]->payload['sent_at']);
        $this->assertArrayNotHasKey('KEY', $rows[0]->payload);
        $this->assertStringNotContainsString(self::KEY, json_encode($rows[0]->payload));
        $this->assertSame(['psid-c', 'Hi'], [$rows[1]->psid, $rows[1]->message]);
        $this->assertSame(['psid-d', 'Yes'], [$rows[2]->psid, $rows[2]->message]);
    }

    public function test_S_059_13_oversized_nested_or_invalid_values_are_cut_and_stored_without_an_error(): void
    {
        $extra = [];
        for ($i = 1; $i <= 60; $i++) $extra['extra_' . $i] = 'v' . $i;
        $this->post('/api/botcake/chat', [
            'key' => self::KEY, 'page_id' => '1001', 'psid' => str_repeat('p', 100), 'name' => str_repeat('n', 300),
            'message' => str_repeat('m', 6000), 'nested' => ['a' => ['b' => 'c']], 'bad' => "ok\xFF",
        ] + $extra)->assertOk()->assertExactJson(['ok' => true]);

        $row = BotcakeChat::sole();
        $this->assertSame([64, 255, 5000], [mb_strlen($row->psid), mb_strlen($row->name), mb_strlen($row->message)]);
        $this->assertSame('{"a":{"b":"c"}}', $row->payload['nested']);
        $this->assertSame(2000, mb_strlen($row->payload['message']));
        $this->assertTrue(mb_check_encoding($row->payload['bad'], 'UTF-8'));
        $this->assertCount(50, $row->payload);
    }

    public function test_S_059_14_stored_name_and_text_show_as_text_not_html(): void
    {
        $this->stored(['name' => '<b>Bold</b> Name', 'message' => '<script>alert(1)</script>']);
        $this->actingAs($this->user());

        foreach (['/chatblast/chats', '/chatblast/chats/customer?page_id=1001&psid=psid-a'] as $url) {
            $this->get($url)->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
                ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<b>Bold</b>', false);
        }
    }

    public function test_S_059_4_duplicates_are_not_stored_twice(): void
    {
        $this->travelTo('2026-10-18 10:00:00');
        $this->chat(['message_id' => 'm-9'])->assertOk()->assertExactJson(['ok' => true]);
        $this->chat(['message_id' => 'm-9', 'message' => 'Other text'])->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSame(1, BotcakeChat::count(), 'parehong message_id');

        $this->chat(['message' => 'Same text'])->assertOk();
        $this->travelTo('2026-10-18 10:00:09');
        $this->chat(['message' => 'Same text'])->assertOk();
        $this->assertSame(2, BotcakeChat::count(), 'parehong page, PSID at text sa loob ng 10 segundo');

        $this->chat(['message' => 'Same text', 'psid' => 'psid-other'])->assertOk();
        $this->travelTo('2026-10-18 10:00:20');
        $this->chat(['message' => 'Same text'])->assertOk();
        $this->assertSame(4, BotcakeChat::count(), 'ibang customer, at lampas 10 segundo');
    }

    public function test_S_059_5_the_page_name_comes_from_the_page_settings_page_id(): void
    {
        $this->page('Page Uno', '1001');
        $this->page('Page Dos', '2002');

        $this->chat(['page_id' => '2002'])->assertOk();
        $this->chat(['page_id' => '9999'])->assertOk();

        $this->assertSame(['Page Dos', null], BotcakeChat::orderBy('id')->pluck('page_name')->all());
    }

    public function test_S_059_6_one_ip_over_the_limit_gets_429(): void
    {
        config(['services.botcake.chat_rate' => 2]);

        $this->chat(['message' => 'one'])->assertOk();
        $this->chat(['message' => 'two'])->assertOk();
        $this->chat(['message' => 'three'])->assertStatus(429);
        $this->assertSame(2, BotcakeChat::count());
    }

    public function test_S_059_7_a_request_without_psid_or_message_is_answered_but_not_stored(): void
    {
        $this->postJson('/api/botcake/chat', ['page_id' => '1001', 'name' => 'Ana Cruz'], ['X-Chat-Key' => self::KEY])
            ->assertOk()->assertExactJson(['ok' => true]);

        $this->assertSame(0, BotcakeChat::count());
    }

    /** Isang nav link gaya ng sa /owner/nav-settings, naka-check para sa mga role na ibinigay. */
    private function navLink(string $url, array $roles): void
    {
        if (!Schema::hasTable('nav_links')) {
            Artisan::call('migrate', ['--path' => 'database/migrations/2026_05_18_140000_create_nav_links_and_visibility_tables.php', '--force' => true]);
        }
        $id = DB::table('nav_links')->insertGetId([
            'key' => 'chatblast', 'label' => 'Chatblast', 'route_url' => $url, 'icon' => 'fa-solid fa-link', 'active_pattern' => 'chatblast*',
            'is_active' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($roles as $role) {
            DB::table('nav_link_role_visibility')->insert(['nav_link_id' => $id, 'role' => $role, 'is_visible' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_S_059_8_the_chats_page_lists_the_latest_messages_for_roles_with_chatblast_access(): void
    {
        $this->navLink('/chatblast', ['Marketing']);
        $this->stored(['message' => 'Older message'], '2026-10-18 09:00:00');
        $this->stored(['message' => 'Newer message', 'name' => 'Ben Reyes', 'psid' => 'psid-b'], '2026-10-18 10:30:00');

        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $html = $this->get('/chatblast/chats')->assertOk()
            ->assertSeeInOrder(['Newer message', 'Older message'])
            ->assertSee('Page Uno')->assertSee('Ben Reyes')->assertSee('2026-10-18 10:30')
            ->getContent();
        $this->assertMatchesRegularExpression('#data-cb-nav="Chats"#', $html);

        $this->actingAs($this->user('Data Encoder', 'enc@example.test'));
        $this->get('/chatblast/chats')->assertNotFound();
        $this->get('/chatblast/chats/customer?page_id=1001&psid=psid-a')->assertNotFound();
    }

    public function test_S_059_9_the_page_date_and_search_filters_narrow_the_list(): void
    {
        $this->stored(['message' => 'Uno today'], '2026-10-18 10:00:00');
        $this->stored(['message' => 'Uno yesterday'], '2026-10-17 10:00:00');
        $this->stored(['message' => 'Dos today', 'page_id' => '2002', 'page_name' => 'Page Dos', 'name' => 'Carla Diaz', 'psid' => 'psid-c'], '2026-10-18 11:00:00');
        $this->actingAs($this->user());

        $this->get('/chatblast/chats?page_id=2002')->assertSee('Dos today')->assertDontSee('Uno today')->assertDontSee('Uno yesterday');
        $this->get('/chatblast/chats?date=2026-10-17')->assertSee('Uno yesterday')->assertDontSee('Uno today')->assertDontSee('Dos today');
        $this->get('/chatblast/chats?q=carla')->assertSee('Dos today')->assertDontSee('Uno today');
        $this->get('/chatblast/chats?q=yesterday')->assertSee('Uno yesterday')->assertDontSee('Uno today');
        $this->get('/chatblast/chats?q=%25')->assertOk()->assertDontSee('Uno today'); // ang % ay letra, hindi wildcard
    }

    public function test_S_059_10_a_customer_shows_their_messages_oldest_first(): void
    {
        $this->stored(['message' => 'Second'], '2026-10-18 10:05:00');
        $this->stored(['message' => 'First'], '2026-10-18 10:00:00');
        $this->stored(['message' => 'Someone else', 'psid' => 'psid-z', 'name' => 'Zed Cruz'], '2026-10-18 10:02:00');
        $this->actingAs($this->user());

        $html = $this->get('/chatblast/chats')->getContent();
        $this->assertStringContainsString(e('/chatblast/chats/customer?page_id=1001&psid=psid-a'), $html);

        $this->get('/chatblast/chats/customer?page_id=1001&psid=psid-a')->assertOk()
            ->assertSeeInOrder(['Ana Cruz', 'First', 'Second'])->assertDontSee('Someone else');
    }

    public function test_S_059_11_the_setup_box_shows_the_values_with_the_key_masked_and_the_full_key_for_ceo_only(): void
    {
        $this->navLink('/chatblast', ['Marketing']);

        $this->actingAs($this->user());
        $this->get('/chatblast/chats')->assertOk()
            ->assertSee('POST')->assertSee('https://app.example.test/api/botcake/chat')->assertSee('X-Chat-Key')
            ->assertSee('••••' . substr(self::KEY, -4))
            ->assertSeeInOrder(['page_id', 'psid', 'name', 'message'])
            ->assertSee('Botcake variable')
            ->assertSee(self::KEY);

        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $this->get('/chatblast/chats')->assertOk()->assertSee('••••' . substr(self::KEY, -4))->assertDontSee(self::KEY);
    }

    public function test_S_059_12_the_prune_deletes_chats_older_than_90_days_from_its_command_and_from_chatblast_auto(): void
    {
        $this->travelTo('2026-10-18 03:00:00');
        $this->stored(['message' => 'old'], now()->subDays(91));
        $this->stored(['message' => 'kept'], now()->subDays(89));

        Artisan::call('botcake:prune-chats');
        $this->assertSame(['kept'], BotcakeChat::pluck('message')->all());

        $this->stored(['message' => 'old again'], now()->subDays(95));
        Artisan::call('chatblast:auto');
        $this->assertSame(['kept'], BotcakeChat::pluck('message')->all());

        $this->stored(['message' => 'same day'], now()->subDays(95));
        Artisan::call('chatblast:auto');
        $this->assertSame(2, BotcakeChat::count(), 'isang prune lang kada araw mula sa chatblast:auto');
    }
}
