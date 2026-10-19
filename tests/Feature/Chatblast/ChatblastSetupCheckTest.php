<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\RunChatblastSetupCheck;
use App\Models\BotcakeMessage;
use App\Models\PageSetting;
use App\Services\Chatblast;
use App\Services\ChatblastReady;
use App\Services\ChatblastSetupCheck;
use App\Services\PageSettings;
use App\Services\PageSettingsGrid;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Ang setup check ng chatblast (spec 082): ang flow, ang custom field at ang template ayon sa eksaktong pangalan ng Setup
 * box ("_all"), ang Ready na walang Send test kapag ang check ang sumulat, ang delivered check, ang "_all" na hindi page,
 * at ang dry run. Fake na Botcake lang.
 */
class ChatblastSetupCheckTest extends ChatblastTestCase
{
    private array $flows = [];
    private array $fields = [];
    private array $templates = [];
    private array $stats = ['sent' => 0, 'delivery' => 0];
    private int $flowsHttp = 200;
    private int $fieldsHttp = 200;
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->user());
        $this->page('Page Uno', '1001'); // flow 5501 at field 7701 ng tao, may Send test: Ready
        $this->flows = [['id' => 9100, 'name' => 'UPDATE SHIPPING STATUS 2026', 'is_removed' => false], ['id' => 5501, 'name' => 'CHATBLAST - SHIPPING STATUS (post_order)', 'is_removed' => false]];
        $this->fields = [['id' => 9200, 'name' => 'message'], ['id' => 7701, 'name' => 'GPT INPUT']];
        $this->templates = [self::template('post_order', 'APPROVED', "May update po sa order nyo:\n{{1}}")];
        Http::fake(['botcake.io/*' => function (Request $r) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            $this->calls[] = basename($path);
            if (str_ends_with($path, '/statistics')) return Http::response(['success' => true, 'data' => $this->stats + ['seen' => 0, 'total_phone_number' => 0, 'is_clicked' => 0]]);
            if (str_ends_with($path, '/flows')) return Http::response(['success' => true, 'data' => ['flows' => $this->flows, 'folders' => []]], $this->flowsHttp);
            if (str_ends_with($path, '/custom_fields')) return Http::response(['success' => true, 'data' => $this->fields], $this->fieldsHttp);
            if (str_ends_with($path, '/message_templates')) return Http::response(['success' => true, 'data' => $this->templates]);
            return Http::response(['success' => false], 404);
        }]);
    }

    private static function template(string $name, string $status, string $body): array
    {
        return ['name' => $name, 'status' => $status, 'category' => 'UTILITY', 'components' => [['type' => 'BODY', 'text' => $body]]];
    }

    private function settings(): PageSettings
    {
        return app(PageSettings::class);
    }

    private function val(string $key): ?string
    {
        return $this->settings()->get('Page Uno', 'botcake', $key);
    }

    private function check(bool $dryRun = false): array
    {
        return app(ChatblastSetupCheck::class)->run('Page Uno', null, null, $dryRun);
    }

    private function ready(): ?string
    {
        ChatblastReady::forget($this->settings()->pageKey('Page Uno'));

        return app(ChatblastReady::class)->reason('Page Uno');
    }

    public function test_S_082_1_names_match_by_case_and_spaces_and_a_removed_flow_is_ignored(): void
    {
        $this->flows = [['id' => 9100, 'name' => '  update   SHIPPING status 2026 ', 'is_removed' => false], ['id' => 9101, 'name' => 'UPDATE SHIPPING STATUS 2026', 'is_removed' => true],
            ['id' => 9102, 'name' => 'UPDATE SHIPPING STATUS 2026 (copy)', 'is_removed' => false]];
        $this->fields = [['id' => 9200, 'name' => ' MESSAGE'], ['id' => 9201, 'name' => 'message 2']];

        $r = $this->check();

        $this->assertTrue($r['ok']);
        $this->assertSame(['9100', '9200', 'post_order'], [$this->val('flow.shipping'), $this->val('field.message'), $this->val('template.shipping')]);
        $this->assertSame([null, null], [PageSetting::where('key', 'flow.shipping')->where('active', true)->value('created_by'), PageSetting::where('key', 'field.message')->where('active', true)->value('created_by')], 'system writes');
        $this->assertSame(['5501'], array_column($this->getJson('/chatblast/page-settings/history?page_name=Page%20Uno&provider=botcake&key=flow.shipping')->json('history'), 'value'), 'the person\'s value is history');
    }

    public static function counts(): array
    {
        return [
            'no flow' => ['flows', [], 'No flow named UPDATE SHIPPING STATUS 2026 in Botcake'],
            'two flows' => ['flows', [['id' => 1, 'name' => 'UPDATE SHIPPING STATUS 2026', 'is_removed' => false], ['id' => 2, 'name' => 'Update Shipping Status 2026', 'is_removed' => false]], '2 flows named UPDATE SHIPPING STATUS 2026'],
            'no field' => ['fields', [['id' => 7701, 'name' => 'GPT INPUT']], 'No custom field named message in Botcake'],
            'two fields' => ['fields', [['id' => 1, 'name' => 'message'], ['id' => 2, 'name' => 'Message']], '2 custom fields named message'],
        ];
    }

    /** @dataProvider counts */
    public function test_S_082_2_none_or_two_matches_is_an_x_that_keeps_the_value_and_the_page_not_ready(string $what, array $list, string $reason): void
    {
        $this->$what = $list;
        $this->assertNull($this->ready(), 'Ready before: tested on the old flow');

        $r = $this->check();

        $this->assertSame([false, [$reason]], [$r['ok'], $r['x']]);
        $this->assertSame($what === 'flows' ? '5501' : '7701', $this->val($what === 'flows' ? 'flow.shipping' : 'field.message'), 'the stored value stays');
        $this->assertSame('Page not ready: ' . $reason, $this->ready(), 'a page on a non-fixed flow or field is not Ready, and sends skip with the reason');
        $this->assertSame([$reason], json_decode($this->val('setup.check'), true)['x']);
    }

    public static function templates(): array
    {
        return [
            'approved with {{1}}' => [[self::template('Post_Order ', 'APPROVED', 'Hi {{1}}')], null],
            'not approved' => [[self::template('post_order', 'PENDING', 'Hi {{1}}')], 'Template post_order is not approved in Botcake'],
            'no {{1}}' => [[self::template('post_order', 'APPROVED', 'Hi po')], 'Template post_order has no {{1}}'],
            'other name only' => [[self::template('post_order_2', 'APPROVED', 'Hi {{1}}')], 'No template named post_order in Botcake'],
        ];
    }

    /** @dataProvider templates */
    public function test_S_082_3_the_template_must_be_approved_by_that_name_and_have_the_slot(array $templates, ?string $reason): void
    {
        $this->templates = $templates;

        $r = $this->check();

        $this->assertSame($reason === null ? [] : [$reason], $r['x']);
        if ($reason === null) $this->assertSame('Post_Order', $this->val('template.shipping'));
        else $this->assertSame('Page not ready: ' . $reason, $this->ready());
    }

    public function test_S_082_4_a_system_switch_is_ready_without_a_test_and_a_persons_change_needs_one(): void
    {
        $this->travel(1)->minutes();
        $this->check();
        $this->assertSame(['9100', '9200'], [$this->val('flow.shipping'), $this->val('field.message')]);
        $this->assertNull($this->ready(), 'the check set the flow and field and passed: Ready without a Send test');

        $this->travel(1)->minutes();
        $this->settings()->set('Page Uno', 'botcake', 'flow.shipping', '9100', false, null);           // same value: no change
        $this->settings()->set('Page Uno', 'botcake', 'field.message', '7701', false, $this->user('Marketing', 'mkt@example.test')->id);
        $this->assertSame(ChatblastReady::NOT_TESTED, $this->ready(), 'a person changed the field: a Send test again');
        $this->assertSame(ChatblastReady::NOT_TESTED, app(ChatblastReady::class)->states()['page uno']['blocker']);

        // The next check sets it back (system): Ready again.
        $this->travel(1)->minutes();
        $this->check();
        $this->assertNull($this->ready());
    }

    public function test_S_082_5_a_check_that_cannot_reach_botcake_changes_nothing_and_keeps_ready(): void
    {
        $this->check();
        $before = PageSetting::where('active', true)->whereNotIn('key', ['setup.check'])->pluck('value', 'key')->all();
        $this->flowsHttp = 500;

        $r = $this->check();

        $this->assertSame(['error', 'Botcake: HTTP 500'], [$r['status'], $r['error']]);
        $this->assertSame($before, PageSetting::where('active', true)->whereNotIn('key', ['setup.check'])->pluck('value', 'key')->all());
        $check = json_decode($this->val('setup.check'), true);
        $this->assertSame([true, 'Botcake: HTTP 500'], [$check['ok'], $check['error']], 'the last result stays, with the failure beside it');
        $this->assertNull($this->ready());

        // The token test itself fails (Test token, Check all, nightly): the same grey line, nothing else changes.
        $this->fieldsHttp = 500;
        $this->assertSame('error', app(\App\Services\BotcakePageSetup::class)->check('Page Uno')['status']);
        $this->assertStringContainsString('500', json_decode($this->val('setup.check'), true)['error']);
        $this->assertSame($before, PageSetting::where('active', true)->whereNotIn('key', ['setup.check'])->pluck('value', 'key')->all());
        $this->assertNull($this->ready());
    }

    public function test_S_082_6_the_delivered_check_baseline_delivered_and_not_delivered(): void
    {
        $this->stats = ['sent' => 156, 'delivery' => 152];
        $this->check();
        $d = json_decode($this->val('setup.delivery'), true);
        $this->assertSame(['9100', 'pending', 156, 152], [$d['flow_id'], $d['status'], $d['base_sent'], $d['base_delivery']]);

        // Two hours later, a send via the new flow 30 minutes ago: too early, no statistics call.
        $this->travel(2)->hours();
        $send = fn (int $minutesAgo, string $flow = '9100') => BotcakeMessage::create(['page_name' => 'Page Uno', 'waybill' => 'JT' . random_int(1000, 9999), 'kind' => 'in_transit', 'psid' => '7001', 'status' => 'sent', 'is_test' => false,
            'steps' => [['step' => 'write'], ['step' => 'flow', 'request' => ['psid' => '7001', 'flow_id' => (int) $flow]]], 'queued_at' => now()->subMinutes($minutesAgo + 1), 'sent_at' => now()->subMinutes($minutesAgo)]);
        $send(30);
        $send(90, '5501'); // the old flow: does not count
        $this->calls = [];
        Artisan::call('botcake:setup-delivered');
        $this->assertNotContains('statistics', $this->calls);
        $this->assertSame('pending', json_decode($this->val('setup.delivery'), true)['status']);

        // 61 minutes later, Botcake delivered nothing new: X and not Ready, with the reason.
        $this->travel(31)->minutes();
        $this->stats = ['sent' => 158, 'delivery' => 152];
        Artisan::call('botcake:setup-delivered');
        $this->assertSame(['not_delivered', 2], [json_decode($this->val('setup.delivery'), true)['status'], json_decode($this->val('setup.delivery'), true)['sent']]);
        $this->assertSame('Page not ready: Sent 2, none delivered (Botcake)', $this->ready());

        // The next hour it was delivered: Delivered, and no more checks.
        $this->stats = ['sent' => 158, 'delivery' => 154];
        Artisan::call('botcake:setup-delivered');
        $this->assertSame('delivered', json_decode($this->val('setup.delivery'), true)['status']);
        $this->assertNull($this->ready());
        $this->calls = [];
        Artisan::call('botcake:setup-delivered');
        $this->assertSame([], $this->calls);
    }

    public function test_S_082_7_all_is_not_a_page_anywhere(): void
    {
        $this->postJson('/chatblast/page-settings/setup', ['flow' => ' New  Flow ', 'field' => 'msg', 'template' => 'post_order'])->assertOk()
            ->assertJsonPath('names.flow', 'New Flow');
        $this->assertSame(['flow' => 'New Flow', 'field' => 'msg', 'template' => 'post_order'], app(ChatblastSetupCheck::class)->names());
        $this->assertSame(3, PageSetting::where('page_name', '_all')->where('active', true)->count());

        $grid = app(PageSettingsGrid::class)->build();
        $this->assertNotContains('_all', array_column($grid['rows'], 'key'));
        $this->assertSame('New Flow', $grid['setup']['names']['flow']);
        $this->assertEmpty(array_filter(array_column($grid['columns'], 'key'), fn ($k) => str_starts_with($k, 'botcake.setup.')));
        $this->assertArrayNotHasKey('_all', app(ChatblastReady::class)->states());
        $this->assertNotContains('_all', $this->settings()->pagesWith('botcake', ['setup.flow_name']));
        $this->assertArrayNotHasKey('_all', app(Chatblast::class)->missingByPage());
        $this->getJson('/chatblast/page-settings/grid?since=' . urlencode(now()->subMinute()->format('Y-m-d H:i:s')))->assertOk()
            ->assertJsonMissing(['page' => '_all']);
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_10_20_100000_create_botcake_chats_table.php', 'database/migrations/2026_10_23_100000_create_chatbot_tables.php', 'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php']]);
        $this->get('/chatbot')->assertOk()->assertDontSee('>_all<', false);
        // The page can't write it: only the Setup box.
        $this->postJson('/chatblast/page-settings/cell', ['page_name' => '_all', 'provider' => 'botcake', 'key' => 'setup.flow_name', 'value' => 'x'])->assertStatus(422);
        $this->postJson('/chatblast/page-settings/setup', ['flow' => '', 'field' => 'msg', 'template' => 'x'])->assertStatus(422);
    }

    public function test_S_082_8_the_dry_run_writes_nothing_and_prints_each_page(): void
    {
        $before = PageSetting::count();

        $this->assertSame(0, Artisan::call('botcake:setup-check', ['--all' => true, '--dry-run' => true]));
        $out = Artisan::output();

        $this->assertSame($before, PageSetting::count());
        $this->assertStringContainsString('5501 → 9100', $out);
        $this->assertStringContainsString('7701 → 9200', $out);
        $this->assertStringContainsString('✔ check', $out);
        $this->assertStringContainsString('Ready', $out);

        $this->flows = [];
        Artisan::call('botcake:setup-check', ['page' => 'Page Uno', '--dry-run' => true]);
        $out = Artisan::output();
        $this->assertStringContainsString('X No flow named UPDATE SHIPPING STATUS 2026 in Botcake', $out);
        $this->assertStringContainsString('Ready → not Ready', $out);
        $this->assertSame($before, PageSetting::count());
    }

    public function test_S_082_9_check_all_is_a_queued_job_with_progress_and_the_page_shows_the_box_and_the_column(): void
    {
        Queue::fake();
        $this->postJson('/chatblast/page-settings/setup/check-all')->assertOk()->assertJsonPath('queued', true)->assertJsonPath('progress.status', 'queued');
        Queue::assertPushedOn('default', RunChatblastSetupCheck::class);
        $this->postJson('/chatblast/page-settings/setup/check-all')->assertOk()->assertJsonPath('queued', false);

        (new RunChatblastSetupCheck())->handle($this->settings(), app(\App\Services\BotcakePageSetup::class));
        $this->getJson('/chatblast/page-settings/setup/progress')->assertOk()
            ->assertJsonPath('progress.status', 'done')->assertJsonPath('progress.total', 1)->assertJsonPath('progress.done', 1)->assertJsonPath('progress.x', 0);
        $this->assertSame('9100', $this->val('flow.shipping'));

        $html = $this->get('/chatblast/page-settings')->assertOk()->getContent();
        foreach (['Setup for every page', 'name="flow"', 'value="UPDATE SHIPPING STATUS 2026"', 'Check all', "el('th', {class: th, text: 'Check'", 'function checkCell(r)', "icon('x', "] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringNotContainsString("text: '✓'", $html);
    }
}
