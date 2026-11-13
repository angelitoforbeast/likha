<?php

namespace Tests\Feature\Chatblast;

use App\Services\ChatblastSetupCheck;
use App\Services\PageSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Spec 271: ang "+ Page" ay nangangailangan ng kumpletong shipping status setup sa Botcake (ang flow, ang custom field at
 * ang APPROVED na template na may {{1}}, eksaktong isa bawat isa); ang tinanggihan ay walang naiiwan. Ang page na kulang
 * ang setup at may ad spend o order ay urgent sa Problems panel, at may pulang "No shipping setup" sa /chatbot/pages.
 * Pekeng Botcake; walang totoong tawag. Ngayon: 2026-11-13 12:00 (Manila).
 */
class ShippingSetupRequiredTest extends ChatblastTestCase
{
    private const NEW_TOKEN = 'tok-ship-setup-test-7h8j9k';

    private const NO_FLOW = 'No flow named UPDATE SHIPPING STATUS 2026 in Botcake';

    private const NO_FIELD = 'No custom field named message in Botcake';

    private const NO_TEMPLATE = 'No template named post_order in Botcake';

    private array $flows = [['id' => 502, 'name' => 'UPDATE SHIPPING STATUS 2026', 'is_removed' => false]];

    private array $fields = [['id' => 801, 'name' => 'message']];

    private array $templates = [['name' => 'post_order', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => "Update po\n{{1}}"]]]];

    /** null = sumasagot; kung hindi, ang path na pumapalya (HTTP 429 o walang koneksyon). */
    private ?array $down = null;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100100_create_chatbot_events_and_reply_user.php',
            'database/migrations/2026_10_28_100000_create_chatbot_ai_requests_table.php',
            'database/migrations/2026_10_30_098000_add_raw_to_chatbot_replies.php',
            'database/migrations/2026_10_30_100000_add_results_to_chatbot_replies.php',
            'database/migrations/2026_11_06_100000_add_chat_index_to_chatbot_replies.php',
            'database/migrations/2025_07_15_232022_create_ads_manager_reports_table.php',
        ]]);
        $this->travelTo('2026-11-13 12:00:00');
        Http::fake(function (Request $r) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            $last = basename($path);
            if ($this->down !== null && $last === $this->down[0]) {
                return $this->down[1] === 0 ? Http::failedConnection() : Http::response([], $this->down[1]);
            }

            return match (true) {
                $last === 'custom_fields' => Http::response(['success' => true, 'data' => $this->fields]),
                $last === 'statistics' => Http::response(['success' => true, 'data' => ['sent' => 0, 'delivery' => 0]]),
                $last === 'flows' => Http::response(['success' => true, 'data' => ['flows' => $this->flows, 'folders' => []]]),
                $last === 'message_templates' => Http::response(['success' => true, 'data' => $this->templates]),
                default => Http::response(['success' => true, 'data' => []]),
            };
        });
        $this->actingAs($this->user());
    }

    private function settings(): PageSettings
    {
        return app(PageSettings::class);
    }

    private function add(array $body = [])
    {
        return $this->postJson('/chatbot/pages', $body + ['page_name' => 'Page Cuatro', 'page_id' => '4004', 'token' => self::NEW_TOKEN]);
    }

    private function listed(): array
    {
        return array_column($this->getJson('/chatbot/data')->assertOk()->json('rows'), 'page');
    }

    private function blockWrites(): int
    {
        return collect(Http::recorded())->filter(fn ($s) => str_contains($s[0]->url(), '/default_reply') && $s[0]->method() === 'POST')->count();
    }

    public function test_s_271_1_plus_page_is_refused_with_every_missing_piece_and_leaves_nothing_behind(): void
    {
        $this->flows = [['id' => 503, 'name' => 'Welcome', 'is_removed' => false]];
        $this->fields = [['id' => 802, 'name' => 'GPT Input']];
        $this->templates = [];

        $res = $this->add()->assertStatus(422)->assertJson(['token_test' => 'ok', 'retry' => true]);
        $this->assertSame('The shipping status setup is not complete in Botcake, so the page was not added. Fix these in Botcake, then Check again:', $res->json('error'));
        $this->assertSame([self::NO_FLOW, self::NO_FIELD, self::NO_TEMPLATE], $res->json('missing'));
        $this->assertStringNotContainsString(self::NEW_TOKEN, $res->getContent());

        // Walang naiiwan: wala sa listahan, walang active na row ng page, walang block na isinulat sa Botcake.
        $this->assertNotContains('Page Cuatro', $this->listed());
        $this->assertSame(0, $this->settings()->pageQuery('Page Cuatro')->where('active', true)->count());
        $this->assertNull($this->settings()->get('Page Cuatro', 'botcake', 'token'));
        $this->assertSame(0, $this->blockWrites());

        // Inayos sa Botcake, saka Check again (ang parehong form): naidagdag at On.
        $this->flows = [['id' => 502, 'name' => 'UPDATE SHIPPING STATUS 2026', 'is_removed' => false]];
        $this->fields = [['id' => 801, 'name' => 'message']];
        $this->templates = [['name' => 'post_order', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}']]]];
        $this->add()->assertOk()->assertJson(['page' => 'Page Cuatro', 'token_test' => 'ok', 'active' => true, 'error' => null]);
        $this->assertContains('Page Cuatro', $this->listed());
        $this->assertSame('502', $this->settings()->get('Page Cuatro', 'botcake', 'flow.shipping'));
        $this->assertSame('1', $this->settings()->get('Page Cuatro', 'chatbot', 'active'));
    }

    public static function duplicates(): array
    {
        return [
            'two flows' => ['flows', [['id' => 1, 'name' => 'UPDATE SHIPPING STATUS 2026', 'is_removed' => false], ['id' => 2, 'name' => 'update shipping status 2026', 'is_removed' => false]],
                '2 flows named UPDATE SHIPPING STATUS 2026 in Botcake — keep only one'],
            'two fields' => ['fields', [['id' => 1, 'name' => 'message'], ['id' => 2, 'name' => 'Message']], '2 custom fields named message in Botcake — keep only one'],
            'template not approved' => ['templates', [['name' => 'post_order', 'status' => 'PENDING', 'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}']]]],
                'Template post_order is not approved in Botcake'],
            'template without {{1}}' => ['templates', [['name' => 'post_order', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => 'Hi po']]]],
                'Template post_order has no {{1}}'],
        ];
    }

    /** @dataProvider duplicates */
    public function test_s_271_1_a_duplicate_or_unusable_piece_is_refused_with_its_reason(string $what, array $list, string $reason): void
    {
        $this->$what = $list;

        $this->add()->assertStatus(422)->assertJson(['retry' => true, 'missing' => [$reason]]);
        $this->assertNotContains('Page Cuatro', $this->listed());
        $this->assertSame(0, $this->blockWrites());
    }

    public static function botcakeErrors(): array
    {
        return [
            'rate limit on the flows' => [['flows', 429], 'ok', 'Botcake did not answer (Botcake: HTTP 429'],
            'no answer on the token test' => [['custom_fields', 0], 'error', 'Botcake did not answer (Botcake: HTTP connection error'],
            'no answer on the templates' => [['message_templates', 0], 'ok', 'Botcake did not answer ('],
        ];
    }

    /** @dataProvider botcakeErrors */
    public function test_s_271_1_a_botcake_error_is_shown_as_such_and_does_not_add_the_page(array $down, string $tokenTest, string $error): void
    {
        \Illuminate\Support\Sleep::fake();
        $this->down = $down;

        $res = $this->add()->assertStatus(422)->assertJson(['token_test' => $tokenTest, 'retry' => true]);
        $this->assertStringStartsWith($error, $res->json('error'));
        $this->assertStringEndsWith('so the page was not added. Try Check again in a minute.', $res->json('error'));
        $this->assertNull($res->json('missing'));
        $this->assertNotContains('Page Cuatro', $this->listed());
        $this->assertSame(0, $this->settings()->pageQuery('Page Cuatro')->where('active', true)->count());
        $this->assertSame(0, $this->blockWrites());
    }

    public function test_s_271_1_an_invalid_token_is_refused(): void
    {
        $this->down = ['custom_fields', 401];

        $this->add()->assertStatus(422)->assertJson(['token_test' => 'invalid', 'retry' => true,
            'error' => 'The token test failed (token invalid), so the page was not added. Check the Botcake ID and token, then Check again.']);
        $this->assertNotContains('Page Cuatro', $this->listed());
    }

    public function test_s_271_1_a_page_from_page_settings_that_is_refused_goes_back_to_how_it_was(): void
    {
        $s = $this->settings();
        $s->set('Page Tres', 'botcake', 'page_id', '3003');
        $s->set('Page Tres', 'botcake', ChatblastSetupCheck::CHECK_KEY, json_encode(['at' => '2026-11-12 10:00:00', 'ok' => true, 'x' => []]), false, null, true);
        $before = $s->pageQuery('Page Tres')->where('active', true)->orderBy('id')->pluck('value', 'id')->all();
        $this->fields = [];

        $this->postJson('/chatbot/pages', ['page_name' => 'page tres', 'token' => self::NEW_TOKEN])->assertStatus(422)->assertJson(['missing' => [self::NO_FIELD]]);

        $this->assertSame($before, $s->pageQuery('Page Tres')->where('active', true)->orderBy('id')->pluck('value', 'id')->all(), 'the same active rows as before');
        PageSettings::forget('Page Tres');
        $this->assertSame('3003', $s->get('Page Tres', 'botcake', 'page_id'));
        $this->assertNull($s->get('Page Tres', 'botcake', 'token'));
        $this->assertTrue(json_decode((string) $s->get('Page Tres', 'botcake', ChatblastSetupCheck::CHECK_KEY), true)['ok']);
        $this->assertNotContains('Page Tres', $this->listed());
    }

    public function test_s_271_2_existing_pages_without_the_setup_stay_on_the_list_and_keep_their_switch(): void
    {
        $this->page('Page Uno', '1001');
        $this->settings()->set('Page Uno', 'chatbot', 'active', '1', false, null, true);
        $this->settings()->set('Page Uno', 'botcake', ChatblastSetupCheck::CHECK_KEY, json_encode(['at' => '2026-11-13 11:00:00', 'ok' => false, 'x' => [self::NO_FLOW]]), false, null, true);
        $this->fields = [];

        $this->add()->assertStatus(422); // ibang page na tinanggihan: hindi ginagalaw ang Page Uno
        $row = collect($this->getJson('/chatbot/data')->json('rows'))->firstWhere('page', 'Page Uno');
        $this->assertNotNull($row);
        $this->assertTrue($row['active']);
        $this->assertSame('1', $this->settings()->get('Page Uno', 'chatbot', 'active'));
    }

    // ---- S3: ang Problems panel ----

    private function check(string $page, array $c): void
    {
        $this->settings()->set($page, 'botcake', ChatblastSetupCheck::CHECK_KEY, json_encode(['at' => '2026-11-13 11:00:00'] + $c), false, null, true);
    }

    private function spend(string $page, float $php, int $daysAgo = 1): void
    {
        DB::table('ads_manager_reports')->insert(['day' => now('Asia/Manila')->subDays($daysAgo)->toDateString(), 'page_name' => $page,
            'campaign_id' => 'c1', 'ad_set_id' => 's1', 'amount_spent_php' => $php, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Ang mga linya ng bawat page sa panel: [kulay ng page, [text ng bawat linya]]. */
    private function panel(): array
    {
        $html = $this->get('/chatbot/inbox')->assertOk()->getContent();
        if (! preg_match('#<div[^>]*data-alert="problems"[^>]*>(.*?)</ol>#s', $html, $m)) {
            return [];
        }
        $m[1] = (string) preg_replace('#<span data-ack-(controls|back)[^>]*>.*?</span>#s', '', $m[1]);
        $clean = fn ($s) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($s))));
        $pages = [];
        preg_match_all('#<li data-page="([^"]*)" data-level="(\w+)"[^>]*>(.*?)</ul>\s*</li>#s', $m[1], $rows, PREG_SET_ORDER);
        foreach ($rows as [, $page, $level, $body]) {
            preg_match_all('#<li data-level="(\w+)"[^>]*>(.*?)</li>#s', $body, $lines, PREG_SET_ORDER);
            $pages[html_entity_decode($page)] = [$level, array_map(fn ($l) => $clean($l[2]), $lines)];
        }

        return $pages;
    }

    public function test_s_271_3_a_page_without_the_setup_and_with_ad_spend_or_orders_is_urgent_even_when_not_sending(): void
    {
        DB::table('chatblast_campaigns')->delete(); // walang pinapadalhan
        foreach (['Ads Page' => '1001', 'Order Page' => '1002', 'Quiet Page' => '1003', 'Old Ads Page' => '1004', 'Zero Ads Page' => '1005', 'Token Page' => '1006'] as $p => $id) {
            $this->page($p, $id);
        }
        foreach (['Ads Page', 'Order Page', 'Quiet Page', 'Old Ads Page', 'Zero Ads Page'] as $p) {
            $this->check($p, ['ok' => false, 'x' => [self::NO_FLOW, self::NO_TEMPLATE]]);
        }
        $this->check('Token Page', ['ok' => true, 'x' => [], 'error' => 'token invalid']);
        $this->spend('  ads   PAGE ', 9297.00, 1);  // kahapon, ibang baybay: tinutugma gaya ng pageKey
        $this->spend('Old Ads Page', 500.00, 2);     // lampas sa huling 2 araw
        $this->spend('Zero Ads Page', 0.00, 0);
        $this->spend('Token Page', 300.00, 0);       // token invalid: hindi "No shipping status setup"
        DB::table('macro_output')->insert(['PAGE' => 'Order Page', 'ts_date' => now('Asia/Manila')->subDays(5)->toDateString()]);

        $p = $this->panel();
        $reasons = self::NO_FLOW.', '.self::NO_TEMPLATE;
        $this->assertSame(['orange', ['🟠 No shipping status setup: '.$reasons.' Page settings']], $p['Ads Page']);
        $this->assertSame(['orange', ['🟠 No shipping status setup: '.$reasons.' Page settings']], $p['Order Page']);
        foreach (['Quiet Page', 'Old Ads Page', 'Zero Ads Page'] as $page) {
            $this->assertSame(['white', ['⚪ Not sending, setup incomplete: '.$reasons.' Page settings']], $p[$page], $page);
        }
        $this->assertSame(['white', ['⚪ Not sending, setup incomplete: token invalid Page settings']], $p['Token Page']);
        $this->assertStringContainsString('href="/chatblast/page-settings?q=Ads+Page"', $this->get('/chatbot/inbox')->getContent());
        $this->assertSame(2, \App\Services\Chatbot\ProblemsPanel::AD_DAYS);
    }

    public function test_s_271_3_ad_spend_is_one_grouped_query_cached_with_the_panel(): void
    {
        for ($i = 1; $i <= 40; $i++) {
            $this->page('Page '.$i, (string) (2000 + $i));
            $this->check('Page '.$i, ['ok' => false, 'x' => [self::NO_FLOW]]);
            if ($i % 2 === 0) {
                $this->spend('Page '.$i, 100.0 * $i, $i % 2);
            }
        }
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            if (preg_match('#\bfrom\s+["`]?ads_manager_reports\b#i', $q->sql)) {
                $queries[] = $q->sql;
            }
        });

        $p = $this->panel();
        $this->assertCount(1, $queries, 'one grouped query: '.implode(' | ', $queries));
        $this->assertStringContainsStringIgnoringCase('group by', $queries[0]);
        $this->assertSame('orange', $p['Page 2'][0]);
        $this->assertSame('white', $p['Page 3'][0]);

        $this->panel();
        $this->assertCount(1, $queries, 'the next build within the cache time reads the cache');
    }

    // ---- S4: ang badge sa /chatbot/pages ----

    public function test_s_271_4_the_list_carries_the_stored_reasons_without_calling_botcake(): void
    {
        $this->page('Page Uno', '1001');
        $this->page('Page Dos', '1002');
        $this->page('Page Tres', '1003');
        $this->check('Page Uno', ['ok' => false, 'x' => [self::NO_FLOW, '2 custom fields named message in Botcake — keep only one']]);
        $this->check('Page Dos', ['ok' => true, 'x' => []]);

        $this->get('/chatbot/pages')->assertOk();
        $rows = collect($this->getJson('/chatbot/data')->assertOk()->json('rows'))->keyBy('page');
        $this->assertSame([self::NO_FLOW, '2 custom fields named message in Botcake — keep only one'], $rows['Page Uno']['no_shipping_setup']);
        $this->assertNull($rows['Page Dos']['no_shipping_setup']);
        $this->assertNull($rows['Page Tres']['no_shipping_setup'], 'never checked: no badge');
        $this->assertSame(0, collect(Http::recorded())->filter(fn ($s) => str_contains($s[0]->url(), 'botcake'))->count());
    }

    /** Pinapatakbo ang mga helper ng badge at ng tinanggihang "+ Page" (shipping-badge:start/end) sa Node. */
    private function node(string $expr): mixed
    {
        $html = $this->get('/chatbot/pages')->assertOk()->getContent();
        $this->assertSame(1, preg_match('~// shipping-badge:start(.*?)// shipping-badge:end~s', $html, $m), 'the helpers are on the page');
        $node = (new ExecutableFinder)->find('node');
        if ($node === null) {
            $this->markTestSkipped('Node is not installed.');
        }
        $base = tempnam(sys_get_temp_dir(), 'shp');
        $file = $base.'.mjs';
        file_put_contents($file, $m[1]."\nconsole.log(JSON.stringify(".$expr.'));');
        $p = new Process([$node, $file]);
        $p->run();
        @unlink($file);
        @unlink($base);
        $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());

        return json_decode($p->getOutput(), true);
    }

    public function test_s_271_4_the_badge_shows_the_reasons_and_sits_beside_the_page_name(): void
    {
        $this->assertSame([
            ['text' => 'No shipping setup', 'title' => 'Shipping status setup not complete in Botcake: '.self::NO_FLOW.'; '.self::NO_FIELD, 'reasons' => [self::NO_FLOW, self::NO_FIELD]],
            null, null, null,
        ], $this->node('[['.json_encode(self::NO_FLOW).', '.json_encode(self::NO_FIELD).'], [], null, "x"].map(shippingBadge)'));

        $html = $this->get('/chatbot/pages')->assertOk()->getContent();
        $this->assertSame(1, preg_match("~\[pickBox\(r\), r\.page, fbAnchor\(r\), shippingBadgeEl\(r\),~", $html), 'the badge right after the name');
        // Bumubukas sa tap o click (details) para sa phone, at may title.
        $this->assertSame(1, preg_match("~el\('details', \{[^}]*'data-no-shipping'~", $html));
        $this->assertStringContainsString("el('summary', {class: 'inline-block cursor-pointer rounded bg-red-600", $html);
        $this->assertStringContainsString("b.reasons.map(t => el('li'", $html);
    }

    public function test_s_271_1_the_refused_dialog_lists_every_reason_in_a_red_box_with_check_again(): void
    {
        $this->flows = [];
        $this->fields = [['id' => 1, 'name' => 'message'], ['id' => 2, 'name' => 'message']];
        $this->templates = [];
        $data = $this->add()->assertStatus(422)->json();

        $this->assertSame(['error' => 'The shipping status setup is not complete in Botcake, so the page was not added. Fix these in Botcake, then Check again:',
            'missing' => [self::NO_FLOW, '2 custom fields named message in Botcake — keep only one', self::NO_TEMPLATE]],
            $this->node('pageRefusalLines('.json_encode($data).')'));
        $this->assertNull($this->node('pageRefusalLines({error: "The page name is required."})'), 'other errors keep the plain line');

        $html = $this->get('/chatbot/pages')->assertOk()->getContent();
        $this->assertSame(1, preg_match('~<div id="page-refused" class="[^"]*border-red-300 bg-red-50[^"]*" role="alert">\s*<p id="page-refused-text"[^>]*></p>\s*<ul id="page-missing"~', $html));
        $this->assertSame(1, preg_match('~<button id="page-again" type="button" class="cb-btn hidden">Check again</button>~', $html));
        $this->assertStringContainsString("\$('page-again').addEventListener('click', () => \$('page-form').requestSubmit());", $html);
        // Ang kahon ay naiiwan: walang setTimeout na nag-aalis nito, at ang form ay hindi nire-reset sa pagtanggi.
        $this->assertSame(1, preg_match("~if \(data\.retry\) pageRefusal\(data\);[^\n]*\n\s*return;~", $html));
    }
}
