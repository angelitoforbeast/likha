<?php

namespace Tests\Feature\Chatblast;

use App\Models\BotcakeChat;
use App\Models\PageSetting;
use App\Services\BotcakeChatBlock;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * /chatbot: ang listahan ng page ng sariling chatbot (On/Off, FLOW at mga botfield sa page_settings, provider chatbot).
 * Fake na HTTP; walang tawag sa totoong Botcake.
 */
class ChatbotPagesTest extends ChatblastTestCase
{
    private const NEW_TOKEN = 'tok-chatbot-new-test-5t6y7u';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_20_100000_create_botcake_chats_table.php']]);
        config(['app.url' => 'https://app.example.test']);
        $this->page('Page Uno', '1001');
        $this->page('Page Dos', '2002');
        app(PageSettings::class)->set('Page Tres', 'botcake', 'page_id', '3003'); // walang token: wala pa sa listahan
    }

    private function settings(): PageSettings
    {
        return app(PageSettings::class);
    }

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

    private function row(array $data, string $page): ?array
    {
        return collect($data['rows'])->firstWhere('page', $page);
    }

    public function test_S_068_1_the_list_shows_pages_with_a_botcake_id_and_token_with_their_chats_and_access_rules(): void
    {
        Http::fake();
        $this->travelTo('2026-10-19 15:00:00');
        $this->settings()->set('Page Dos', 'chatbot', 'active', '0', false, null, true);
        $this->settings()->set('Page Uno', 'chatbot', 'flow', 'Main COD');
        BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => 'p1', 'message' => 'a', 'payload' => [], 'received_at' => '2026-10-18 09:00:00']);
        BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => 'p1', 'message' => 'b', 'payload' => [], 'received_at' => '2026-10-19 10:30:00']);
        BotcakeChat::create(['page_id' => '1001', 'page_name' => 'Page Uno', 'psid' => 'p2', 'message' => 'c', 'payload' => [], 'received_at' => '2026-10-19 11:45:00']);

        $this->get('/chatbot/pages')->assertRedirect();
        $this->actingAs($this->user('Data Encoder', 'enc@example.test'));
        $this->get('/chatbot/pages')->assertForbidden();
        $this->getJson('/chatbot/data')->assertForbidden();

        $this->navLink('/chatblast', ['Marketing']);
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $this->get('/chatbot/pages')->assertOk();

        $this->actingAs($this->user());
        $html = $this->get('/chatbot/pages')->assertOk()->assertSee('Chatbot')->getContent();
        $this->assertStringNotContainsString(self::TOKEN, $html);
        $data = $this->getJson('/chatbot/data')->assertOk()->json();

        $this->assertSame(['Page Dos', 'Page Uno'], array_column($data['rows'], 'page'));
        $uno = $this->row($data, 'Page Uno');
        $this->assertSame([true, 'Main COD', '2026-10-19 11:45', 2], [$uno['active'], $uno['flow_text'], $uno['last_chat'], $uno['today']]);
        $dos = $this->row($data, 'Page Dos');
        $this->assertSame([false, null, 0], [$dos['active'], $dos['last_chat'], $dos['today']]);
        $this->assertSame(['flow', 'price', 'quantity', 'item_name_in_jnt_dashboard', 'main_flow', 'main_flow_2', 'fill_up_form', 'loop_3_form', 'prompt_1'],
            array_slice(array_column($data['columns'], 'key'), 0, 9));
        $this->assertSame(['FLOW', 'PRICE', 'QUANTITY', 'ITEM NAME IN JNT DASHBOARD', 'Main Flow'], array_slice(array_column($data['columns'], 'label'), 0, 5));
        $this->assertContains(['key' => 'seq_img_20', 'label' => 'Seq Img 20', 'kind' => 'image'], $data['columns']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($data));
    }

    public function test_S_068_2_the_ceo_adds_a_page_manually_or_from_an_existing_page(): void
    {
        Http::fake(['botcake.io/*' => Http::response(['success' => true, 'data' => []])]);
        $this->actingAs($this->user());

        $this->postJson('/chatbot/pages', ['page_name' => ' Page Cuatro ', 'page_id' => '4004', 'token' => self::NEW_TOKEN])->assertOk();
        $token = PageSetting::where('provider', 'botcake')->where('key', 'token')->where('page_name', 'Page Cuatro')->where('active', true)->sole();
        $this->assertTrue($token->is_secret);
        $this->assertNotSame(self::NEW_TOKEN, $token->getRawOriginal('value'), 'naka-encrypt gaya ng page settings');
        $this->assertSame('4004', $this->settings()->get('Page Cuatro', 'botcake', 'page_id'));

        // Ang Page Tres ay nasa page settings na (may Botcake ID), kulang ng token.
        $this->postJson('/chatbot/pages', ['page_name' => 'page tres', 'token' => self::NEW_TOKEN])->assertOk();
        $this->assertSame(['Page Cuatro', 'Page Dos', 'Page Tres', 'Page Uno'], array_column($this->getJson('/chatbot/data')->json('rows'), 'page'));

        $this->postJson('/chatbot/pages', ['page_name' => 'Page Cinco', 'page_id' => '50x5', 'token' => self::NEW_TOKEN])->assertStatus(422);
        $this->postJson('/chatbot/pages', ['page_name' => 'Page Cinco', 'page_id' => '5005'])->assertStatus(422); // walang token

        $this->navLink('/chatblast', ['Marketing']);
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $this->postJson('/chatbot/pages', ['page_name' => 'Page Seis', 'page_id' => '6006', 'token' => self::NEW_TOKEN])->assertForbidden();
        $this->assertNull($this->settings()->get('Page Seis', 'botcake', 'page_id'));
    }

    public function test_S_068_3_switching_on_writes_the_block_then_publishes_and_marks_the_page_on(): void
    {
        Http::fake(['botcake.io/*' => Http::response(['success' => true])]);
        $this->settings()->set('Page Dos', 'chatbot', 'active', '0', false, null, true);
        $this->actingAs($this->user());

        $this->postJson('/chatbot/active', ['page_name' => 'Page Dos', 'on' => true])->assertOk()->assertJson(['active' => true, 'error' => null]);

        $key = $this->settings()->get('Page Dos', 'botcake', 'chat.key');
        $this->assertGreaterThanOrEqual(32, strlen((string) $key));
        $sent = Http::recorded();
        $this->assertCount(2, $sent);
        $this->assertSame('https://botcake.io/api/public_api/v1/pages/2002/default_reply/content', $sent[0][0]->url());
        $this->assertSame(['name' => 'Default reply', 'blocks' => [app(BotcakeChatBlock::class)->block($key)]], $sent[0][0]->data());
        $this->assertSame('{"page_id":"{{page_id}}","psid":"{{psid}}","name":"{{user_full_name}}","message":"{{last_text_input}}","source":"default_reply"}',
            $sent[0][0]->data()['blocks'][0]['cards'][0]['body']);
        $this->assertSame('https://botcake.io/api/public_api/v1/pages/2002/default_reply', $sent[1][0]->url());
        $this->assertSame(['update' => ['is_published' => true, 'unlimit_default_reply' => true]], $sent[1][0]->data());
        $this->assertSame('1', $this->settings()->get('Page Dos', 'chatbot', 'active'));

        $this->navLink('/chatblast', ['Marketing']);
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));
        $this->postJson('/chatbot/active', ['page_name' => 'Page Dos', 'on' => false])->assertForbidden();
    }

    public function test_S_068_4_switching_off_only_unpublishes_and_keeps_the_block_and_key(): void
    {
        Http::fake(['botcake.io/*' => Http::response(['success' => true])]);
        $this->settings()->set('Page Uno', 'botcake', 'chat.key', 'page-key-uno-test-a1s2d3f4g5h6j7k8l9', true, null, true);
        $this->actingAs($this->user());

        $this->postJson('/chatbot/active', ['page_name' => 'Page Uno', 'on' => false])->assertOk()->assertJson(['active' => false, 'error' => null]);

        Http::assertSentCount(1);
        [$req] = Http::recorded()[0];
        $this->assertSame('https://botcake.io/api/public_api/v1/pages/1001/default_reply', $req->url());
        $this->assertSame(['update' => ['is_published' => false]], $req->data());
        $this->assertSame('0', $this->settings()->get('Page Uno', 'chatbot', 'active'));
        $this->assertSame('page-key-uno-test-a1s2d3f4g5h6j7k8l9', $this->settings()->get('Page Uno', 'botcake', 'chat.key'));
    }

    public function test_S_068_5_a_botcake_error_is_shown_on_the_row_and_changes_nothing(): void
    {
        Http::fake(['botcake.io/*' => Http::response(['success' => false, 'message' => 'Page is not connected'])]);
        $this->actingAs($this->user());

        $this->postJson('/chatbot/active', ['page_name' => 'Page Uno', 'on' => true])->assertOk()
            ->assertJson(['active' => true, 'error' => 'Could not write the block: Botcake said "Page is not connected" (HTTP 200).']);
        $this->postJson('/chatbot/active', ['page_name' => 'Page Uno', 'on' => false])->assertOk()
            ->assertJson(['active' => true, 'error' => 'Could not unpublish the Default Reply: Botcake said "Page is not connected" (HTTP 200).']);

        $this->assertNull($this->settings()->get('Page Uno', 'botcake', 'chat.key'));
        $this->assertNull($this->settings()->get('Page Uno', 'chatbot', 'active'));
        $this->assertSame(0, PageSetting::where('provider', 'chatbot')->count());
    }

    public function test_S_068_6_a_botfield_edit_writes_one_row_and_checks_its_value(): void
    {
        Http::fake();
        $this->navLink('/chatblast', ['Marketing']);
        $this->actingAs($this->user('Marketing', 'mkt@example.test'));

        $this->postJson('/chatbot/cell', ['page_name' => 'Page Uno', 'key' => 'price', 'value' => ' 499 '])->assertOk()->assertJson(['status' => 'created', 'value' => '499']);
        $this->postJson('/chatbot/cell', ['page_name' => 'Page Uno', 'key' => 'price', 'value' => '499'])->assertOk()->assertJson(['status' => 'unchanged']);
        $this->assertSame(1, PageSetting::where('provider', 'chatbot')->where('key', 'price')->count());
        $this->assertSame('499', $this->settings()->get('Page Uno', 'chatbot', 'price'));

        $this->postJson('/chatbot/cell', ['page_name' => 'Page Uno', 'key' => 'prompt_1', 'value' => "Line one\nLine two"])->assertOk();
        $this->assertSame("Line one\nLine two", $this->settings()->get('Page Uno', 'chatbot', 'prompt_1'));
        $this->postJson('/chatbot/cell', ['page_name' => 'Page Uno', 'key' => 'picture', 'value' => 'https://img.example.test/a.jpg'])->assertOk();

        foreach ([
            ['key' => 'picture', 'value' => 'javascript:alert(1)'],      // larawan: http(s) URL lang
            ['key' => 'active', 'value' => '1'],                          // ang ACTIVE ay sa switch lang
            ['key' => 'not_a_column', 'value' => 'x'],                    // hindi column
        ] as $bad) {
            $this->postJson('/chatbot/cell', ['page_name' => 'Page Uno'] + $bad)->assertStatus(422);
        }
        $this->postJson('/chatbot/cell', ['page_name' => 'Page Nowhere', 'key' => 'price', 'value' => '1'])->assertStatus(422); // wala sa listahan

        $this->postJson('/chatbot/cell', ['page_name' => 'Page Uno', 'key' => 'price', 'value' => ''])->assertOk()->assertJson(['status' => 'cleared']);
        $this->assertNull($this->settings()->get('Page Uno', 'chatbot', 'price'));
    }

    public function test_S_068_9_only_the_switch_changes_active_and_writes_need_chatblast_access(): void
    {
        Http::fake();
        $this->actingAs($this->user()); // CEO: kahit siya ay hindi makakasulat ng ACTIVE sa Page settings
        $this->postJson('/chatblast/page-settings/cell', ['page_name' => 'Page Uno', 'provider' => 'chatbot', 'key' => 'active', 'value' => '0'])->assertStatus(422);
        $this->assertNull($this->settings()->get('Page Uno', 'chatbot', 'active'));
        // Ang pag-deactivate ng row sa Page settings ay hindi rin nagpapalit ng ACTIVE.
        $this->settings()->set('Page Uno', 'chatbot', 'active', '0', false, null, true);
        $id = PageSetting::where('provider', 'chatbot')->where('key', 'active')->where('active', true)->value('id');
        $this->post('/chatblast/page-settings/' . $id . '/deactivate');
        $this->assertSame('0', $this->settings()->get('Page Uno', 'chatbot', 'active'));

        $this->actingAs($this->user('Data Encoder', 'enc@example.test'));
        $this->postJson('/chatbot/cell', ['page_name' => 'Page Uno', 'key' => 'price', 'value' => '1'])->assertForbidden();
        $this->postJson('/chatbot/column', ['name' => 'Promo'])->assertForbidden();
        $this->assertSame(0, PageSetting::where('provider', 'chatbot')->where('key', '!=', 'active')->count());
    }

    public function test_S_068_10_a_page_cannot_take_a_botcake_id_another_page_uses_or_join_twice(): void
    {
        Http::fake(['botcake.io/*' => Http::response(['success' => true, 'data' => []])]);
        $this->actingAs($this->user());

        $this->postJson('/chatbot/pages', ['page_name' => 'Page X', 'page_id' => '1001', 'token' => self::NEW_TOKEN])
            ->assertStatus(422)->assertJson(['error' => 'Botcake ID 1001 is already used by Page Uno.']);
        $this->postJson('/chatbot/pages', ['page_name' => 'page uno', 'page_id' => '7007', 'token' => self::NEW_TOKEN])->assertStatus(422);
        $this->assertNull($this->settings()->get('Page X', 'botcake', 'page_id'));
        $this->assertSame('1001', $this->settings()->get('Page Uno', 'botcake', 'page_id'));
    }

    public function test_S_068_11_on_that_fails_at_publish_keeps_the_new_key_and_says_so(): void
    {
        $calls = 0;
        Http::fake(['botcake.io/*' => function () use (&$calls) {
            return ++$calls === 1 ? Http::response(['success' => true]) : Http::response(['success' => false, 'message' => 'Busy']);
        }]);
        $this->actingAs($this->user());

        $this->postJson('/chatbot/active', ['page_name' => 'Page Uno', 'on' => true])->assertOk()
            ->assertJson(['active' => true, 'error' => 'Could not publish the Default Reply: Botcake said "Busy" (HTTP 200).']);
        $this->assertGreaterThanOrEqual(32, strlen((string) $this->settings()->get('Page Uno', 'botcake', 'chat.key')), 'ang block sa Botcake ay may hawak na ng key');
        $this->assertNull($this->settings()->get('Page Uno', 'chatbot', 'active'));
    }

    public function test_S_068_12_values_columns_and_names_have_limits_and_a_column_never_reads_botcake_settings(): void
    {
        Http::fake();
        $this->actingAs($this->user());

        $this->postJson('/chatbot/cell', ['page_name' => 'Page Uno', 'key' => 'prompt_2', 'value' => str_repeat('a', 10000)])->assertOk();
        $this->postJson('/chatbot/cell', ['page_name' => 'Page Uno', 'key' => 'prompt_2', 'value' => str_repeat('a', 10001)])->assertStatus(422);

        $this->postJson('/chatbot/column', ['name' => 'Token'])->assertOk()->assertJson(['key' => 'token']);
        $uno = collect($this->getJson('/chatbot/data')->json('rows'))->firstWhere('page', 'Page Uno');
        $this->assertArrayNotHasKey('token', (array) $uno['cells'], 'ang botcake.token ay hindi kailanman column');
        $this->assertStringNotContainsString(self::TOKEN, json_encode($uno));

        DB::table('app_settings')->where('key', 'chatbot.columns')->delete();
        $many = [];
        for ($i = 1; $i <= \App\Services\ChatbotFields::MAX_ADDED; $i++) $many['extra_' . $i] = 'Extra ' . $i;
        DB::table('app_settings')->insert(['key' => 'chatbot.columns', 'value' => json_encode($many), 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/chatbot/column', ['name' => 'One too many'])->assertStatus(422);
    }

    public function test_S_068_13_setup_all_leaves_pages_that_are_switched_off(): void
    {
        Http::fake(['botcake.io/*' => Http::response(['success' => true])]);
        $this->settings()->set('Page Dos', 'chatbot', 'active', '0', false, null, true);

        Artisan::call('botcake:chat-setup', ['--all' => true, '--dry-run' => true]);
        $this->assertStringContainsString('Page Dos (2002): bot is Off, skipped', Artisan::output());
        Artisan::call('botcake:chat-setup', ['--all' => true, '--confirm' => true]);

        $this->assertSame(['https://botcake.io/api/public_api/v1/pages/1001/default_reply/content', 'https://botcake.io/api/public_api/v1/pages/1001/default_reply'],
            array_map(fn ($p) => $p[0]->url(), Http::recorded()->all()));
        $this->assertSame('0', $this->settings()->get('Page Dos', 'chatbot', 'active'));
    }

    public function test_S_068_7_a_new_column_shows_for_every_page(): void
    {
        Http::fake();
        $this->actingAs($this->user());

        $this->postJson('/chatbot/column', ['name' => 'Promo Text!'])->assertOk()->assertJson(['key' => 'promo_text']);
        $this->postJson('/chatbot/column', ['name' => 'promo text'])->assertOk()->assertJson(['key' => 'promo_text']); // walang ulit
        $this->postJson('/chatbot/column', ['name' => 'Active'])->assertStatus(422);
        $this->postJson('/chatbot/column', ['name' => '!!!'])->assertStatus(422);

        $columns = $this->getJson('/chatbot/data')->json('columns');
        $this->assertSame(1, collect($columns)->where('key', 'promo_text')->count());
        $this->assertSame(['key' => 'promo_text', 'label' => 'Promo Text', 'kind' => 'text'], collect($columns)->last());

        $this->postJson('/chatbot/cell', ['page_name' => 'Page Dos', 'key' => 'promo_text', 'value' => 'Free shipping'])->assertOk();
        // Ang key na ginagamit sa page_settings (hal. idinagdag sa iba) ay column din.
        $this->settings()->set('Page Uno', 'chatbot', 'note_x', 'from elsewhere');
        $this->assertContains('note_x', array_column($this->getJson('/chatbot/data')->json('columns'), 'key'));
    }
}
