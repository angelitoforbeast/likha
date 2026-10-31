<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\RunChatbotContentCheck;
use App\Models\ChatbotFlow;
use App\Models\MacroOutput;
use App\Services\Chatbot\ChatbotPage;
use App\Services\Chatbot\CommentCheck;
use App\Services\Chatbot\FlowGraph;
use App\Services\Chatbot\ImageLinkCheck;
use App\Services\Chatbot\PageContentCheck;
use App\Services\Chatbot\PageExpectedItem;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ang content check ng /chatbot/pages: ang item at presyo ng Main Flow, ang larawan at ang ad video, laban sa item na
 * talagang ino-order. Fake ang OpenAI (bawat sagot ay ayon sa text o link na nasa tanong): walang tawag na lumalabas.
 */
class ChatbotContentCheckTest extends ChatblastTestCase
{
    private const IMG = 'https://img.example.test/';

    /** @var array<string, mixed> needle sa tanong => sagot (array = JSON, string = hilaw na content, int = HTTP status) */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['services.openai.key' => 'test-key']);
        Artisan::call('migrate', ['--path' => [
            'database/migrations/2026_10_20_100000_create_botcake_chats_table.php',
            'database/migrations/2026_10_23_100000_create_chatbot_tables.php',
            'database/migrations/2026_10_24_100000_add_draft_to_chatbot_flows.php',
            'database/migrations/2026_10_26_100000_create_chatbot_sequence_tables.php',
            'database/migrations/2026_11_02_106000_create_chatbot_content_answers_table.php',
        ]]);
        Schema::create('ads_manager_reports', function (Blueprint $t) {
            $t->id();
            $t->date('day')->nullable();
            $t->string('page_name');
            $t->string('campaign_name')->nullable();
            $t->string('item_name')->nullable();
        });
        Schema::create('page_item_settings', function (Blueprint $t) {
            $t->id();
            $t->string('page_name');
            $t->string('item_name');
            $t->integer('mode_cod_int')->nullable();
            $t->date('effective_date');
        });
        Schema::create('gpt_video_analyses', function (Blueprint $t) {
            $t->id();
            $t->string('file_name');
            $t->string('file_sha256', 64);
            $t->text('summary')->nullable();
            $t->string('item_name', 500)->nullable();
            $t->timestamps();
        });
        Schema::create('gpt_ad_generations', function (Blueprint $t) {
            $t->id();
            $t->string('page_filter')->nullable();
            $t->unsignedBigInteger('video_analysis_id')->nullable();
            $t->timestamps();
        });

        $this->page('Page Uno', '1001');
        $this->page('Page Dos', '2002');
        foreach (['Page Uno', 'Page Dos'] as $p) app(PageSettings::class)->set($p, 'botcake', 'chat.key', 'key-' . $p, false, null, true);
        $this->app->instance(ImageLinkCheck::class, new ImageLinkCheck(fn (string $host) => ['93.184.216.34']));
        Http::fake([
            self::IMG . '*' => Http::response('', 200, ['Content-Type' => 'image/jpeg']),
            'api.openai.com/*' => function (HttpRequest $request) {
                $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                foreach ($this->answers as $needle => $answer) {
                    if (!str_contains($body, (string) $needle)) continue;
                    if (is_int($answer)) return Http::response(['error' => ['message' => 'down']], $answer);

                    return Http::response(['choices' => [['message' => ['content' => is_array($answer) ? json_encode($answer) : $answer]]],
                        'usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 100]]);
                }

                return Http::response(['error' => ['message' => 'no fake answer']], 500);
            },
        ]);

        $flow = $this->flow('{{Main Flow}} {{Main Flow 2}} {{Fill-Up Form}}');
        app(ChatbotPage::class)->link('Page Uno', $flow);
        app(ChatbotPage::class)->link('Page Dos', $flow);
    }

    private function settings(): PageSettings
    {
        return app(PageSettings::class);
    }

    private function flow(string $text): ChatbotFlow
    {
        $flow = ChatbotFlow::create(['name' => 'Main', 'graph' => FlowGraph::clean(['nodes' => [
            ['id' => 'n0', 'type' => 'start'], ['id' => 'n1', 'type' => 'send', 'config' => ['items' => [['type' => 'text', 'value' => $text]]]],
        ], 'edges' => []])]);
        DB::table('chatbot_flows')->where('id', $flow->id)->update(['published_at' => now()]);

        return $flow->fresh();
    }

    private function orders(string $page, string $item, string $cod, int $count, int $daysAgo = 0): void
    {
        for ($i = 0; $i < $count; $i++) {
            MacroOutput::create(['PAGE' => $page, 'ITEM_NAME' => $item, 'COD' => $cod, 'ts_date' => now('Asia/Manila')->subDays($daysAgo)->toDateString()]);
        }
    }

    private function aiCalls(): int
    {
        return count(Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'api.openai.com')));
    }

    private function answer(string $item, bool $same, int $confidence, array $prices = []): array
    {
        return ['found_item' => $item, 'same_product' => $same, 'confidence' => $confidence, 'prices' => $prices];
    }

    private function content(string $page = 'Page Uno', bool $ask = true): array
    {
        return app(PageContentCheck::class)->run($page, $ask);
    }

    private function check(string $page = 'Page Uno'): array
    {
        if (!auth()->check()) $this->actingAs($this->user());

        return $this->getJson('/chatbot/check?page_name=' . urlencode($page))->assertOk()->json();
    }

    /** @return array<int, string> ang reason ng mga finding na may ganitong level */
    private function reasons(array $res, string $level): array
    {
        return array_values(array_map(fn ($f) => $f['reason'], array_filter($res['findings'], fn ($f) => $f['level'] === $level)));
    }

    public function test_S_106_1_the_expected_item_and_price_are_the_most_common_pair_of_the_last_3_days_of_orders(): void
    {
        $this->orders('Page Uno', 'SEAT COVER', '299', 1, 0);
        $this->orders('Page Uno', 'SEAT COVER', '₱299.00', 1, 1);
        $this->orders(' page  uno ', 'SEAT COVER', '299', 1, 2);
        $this->orders('Page Uno', 'SEAT COVER', '499', 2, 0);
        $this->orders('Page Uno', 'PHONE HOLDER', '199', 5, 3); // apat na araw na: hindi kasama
        $this->orders('Page Dos', 'NIGHT LAMP', '₱1,299.00', 4, 0);
        $this->orders('Page Tres', 'GIFT', '0', 2, 0); // walang COD (hal. bayad na): walang presyong ihahambing

        $this->assertSame(['item' => 'SEAT COVER', 'price' => 299, 'source' => 'orders', 'orders' => 5], app(PageExpectedItem::class)->for('Page Uno'));
        $this->assertSame(['item' => 'NIGHT LAMP', 'price' => 1299, 'source' => 'orders', 'orders' => 4], app(PageExpectedItem::class)->for('Page Dos'));
        $this->assertSame(['item' => 'GIFT', 'price' => null, 'source' => 'orders', 'orders' => 2], app(PageExpectedItem::class)->for('Page Tres'));
    }

    public function test_S_106_2_without_recent_orders_the_latest_ads_item_then_the_item_settings_are_used_and_with_none_nothing_is_asked(): void
    {
        $this->orders('Page Uno', 'OLD ITEM', '299', 3, 5);
        DB::table('ads_manager_reports')->insert([
            ['day' => now()->subDays(10)->toDateString(), 'page_name' => 'Page Uno', 'item_name' => 'OLD AD ITEM'],
            ['day' => now()->subDays(2)->toDateString(), 'page_name' => 'page uno', 'item_name' => 'NIGHT LAMP'],
            ['day' => now()->subDay()->toDateString(), 'page_name' => 'Page Uno', 'item_name' => ''],
            ['day' => now()->toDateString(), 'page_name' => 'Page Dos', 'item_name' => 'OTHER'],
        ]);
        $expected = app(PageExpectedItem::class);
        $this->assertSame(['item' => 'NIGHT LAMP', 'price' => null, 'source' => 'ads', 'orders' => 0], $expected->for('Page Uno'));

        DB::table('ads_manager_reports')->delete();
        DB::table('page_item_settings')->insert([
            ['page_name' => 'Page Uno', 'item_name' => 'SEAT COVER', 'mode_cod_int' => 350, 'effective_date' => now()->subDays(50)->toDateString()],
            ['page_name' => 'Page Uno', 'item_name' => 'SEAT COVER V2', 'mode_cod_int' => 399, 'effective_date' => now()->subDays(20)->toDateString()],
            ['page_name' => 'Page Uno', 'item_name' => 'FUTURE', 'mode_cod_int' => 1, 'effective_date' => now()->addDays(5)->toDateString()],
        ]);
        $this->assertSame(['item' => 'SEAT COVER V2', 'price' => 399, 'source' => 'item_settings', 'orders' => 0], $expected->for('Page Uno'));

        DB::table('page_item_settings')->delete();
        $this->assertNull($expected->for('Page Uno'));
        $this->settings()->set('Page Uno', 'chatbot', 'main_flow', 'Seat cover promo');
        $res = $this->content();
        $this->assertSame([null, [], 0], [$res['expected'], $res['findings'], $res['unchecked']]);
        $this->assertSame(0, $this->aiCalls());
    }

    public function test_S_106_3_another_item_is_an_x_a_synonym_passes_and_low_confidence_is_an_amber_warning(): void
    {
        $this->orders('Page Uno', 'SEAT COVER', '299', 3);
        $this->settings()->set('Page Uno', 'chatbot', 'main_flow', 'Text about a clip holder');
        $this->settings()->set('Page Uno', 'chatbot', 'main_flow_2', 'Text about a cushion cover');
        $this->settings()->set('Page Uno', 'chatbot', 'fill_up_form', "Name:
Phone:
Address:
Landmark:");
        $this->answers = [
            'Text about a clip holder' => $this->answer('FLEXIBLE CLIP PHONE HOLDER', false, 90),
            'Text about a cushion cover' => $this->answer('Car seat cushion cover', true, 85),
            'Text about a light' => $this->answer('LED STRIP', false, 55),
        ];
        $this->content();

        $res = $this->check();
        $this->assertSame('x', $res['status']);
        $this->assertSame(['Main Flow: FLEXIBLE CLIP PHONE HOLDER ≠ orders: SEAT COVER (90%)'], $res['reasons']);
        $this->assertSame([], $res['warnings']);

        // Mababang kumpiyansa lang: babala, hindi X.
        $this->orders('Page Dos', 'SEAT COVER', '299', 3);
        $this->settings()->set('Page Dos', 'chatbot', 'main_flow', 'Text about a light');
        $this->settings()->set('Page Dos', 'chatbot', 'main_flow_2', 'Text about a cushion cover');
        $this->settings()->set('Page Dos', 'chatbot', 'fill_up_form', "Name:
Phone:
Address:
Landmark:");
        $this->content('Page Dos');
        $dos = $this->check('Page Dos');
        $this->assertSame(['ok', [], ['Main Flow: maybe LED STRIP, orders: SEAT COVER (55%)']], [$dos['status'], $dos['reasons'], $dos['warnings']]);
    }

    public static function prices(): array
    {
        return [
            'total equals the COD' => [[['total' => 299]], 299, []],
            'item price plus shipping equals the COD' => [[['item_price' => 249, 'shipping' => 50]], 299, []],
            'item price plus shipping and insurance' => [[['item_price' => 199, 'shipping' => 50, 'insurance' => 50]], 299, []],
            'item price plus a list of fees (S-114.1)' => [[['item_price' => 199, 'fees' => [60, 40], 'total' => null]], 299, []],
            'stated total with fees (S-114.3)' => [[['item_price' => 199, 'fees' => [], 'total' => 299]], 299, []],
            'item price alone equals the COD' => [[['item_price' => 299]], 299, []],
            'one of several options matches' => [[['total' => 499], ['total' => 299]], 299, []],
            'item price alone differs (S-114.2)' => [[['item_price' => 199, 'fees' => []]], 299, ['Main Flow: price ₱199 ≠ orders ₱299 COD']],
            'item price plus shipping differs' => [[['item_price' => 199, 'shipping' => 50]], 299, ['Main Flow: price ₱199 + ₱50 = ₱249 ≠ orders ₱299 COD']],
            'total differs' => [[['total' => 350]], 299, ['Main Flow: price ₱350 ≠ orders ₱299 COD']],
            'no price stated' => [[], 299, []],
            'no expected price (ads source)' => [[['item_price' => 199]], null, []],
        ];
    }

    #[DataProvider('prices')]
    public function test_S_106_4_the_price_compares_like_with_like_with_the_order_cod(array $prices, ?int $cod, array $x): void
    {
        if ($cod !== null) $this->orders('Page Uno', 'SEAT COVER', (string) $cod, 3);
        else DB::table('ads_manager_reports')->insert(['day' => now()->toDateString(), 'page_name' => 'Page Uno', 'item_name' => 'SEAT COVER']);
        $this->settings()->set('Page Uno', 'chatbot', 'main_flow', 'Seat cover {{PRICE}}');
        $this->settings()->set('Page Uno', 'chatbot', 'price', 'only today');
        // Ang {{PRICE}} ay pinupunan mula sa botfield bago itanong.
        $this->answers = ['Seat cover only today' => $this->answer('SEAT COVER', true, 95, $prices)];

        // Ang SHOP DETAILS (kulang ang item at dami) ay ibang check: ang presyo lang dito.
        $this->assertSame($x, array_values(array_filter($this->reasons($this->content(), 'x'), fn ($r) => !str_starts_with($r, 'SHOP DETAILS:'))));
    }

    public function test_S_114_4_the_fill_up_form_is_checked_for_its_fields_without_ai_and_a_missing_one_warns(): void
    {
        $this->orders('Page Uno', 'SEAT COVER', '299', 3);
        $this->settings()->set('Page Uno', 'chatbot', 'fill_up_form', "Pangalan:\nCellphone Number:\nComplete Address:\nLandmark:\nQuantity:");

        $res = $this->content();
        $this->assertSame([], $res['findings']);
        $this->assertSame(0, $this->aiCalls());

        $this->settings()->set('Page Uno', 'chatbot', 'fill_up_form', "Full Name:\nPhone Number:\nAddress:");
        $this->assertSame(['Fill-Up Form: walang Landmark'], $this->reasons($this->content(), 'warn'));
        $this->assertSame(0, $this->aiCalls());
    }

    public function test_S_114_5_an_old_cached_text_answer_without_the_fees_shape_is_asked_again_once(): void
    {
        $this->orders('Page Uno', 'SEAT COVER', '299', 3);
        $this->settings()->set('Page Uno', 'chatbot', 'main_flow', 'Seat cover text');
        $row = $this->settings()->allWithIds('Page Uno', 'chatbot')['main_flow'];
        $old = sha1("text\0v" . $row['id'] . ':' . sha1('Seat cover text') . "\0SEAT COVER");
        DB::table('chatbot_content_answers')->insert(['cache_key' => $old, 'kind' => 'text', 'page_name' => 'Page Uno', 'field' => 'Main Flow',
            'expected_item' => 'SEAT COVER', 'answer' => json_encode($this->answer('SEAT COVER', true, 95, [['item_price' => 199, 'shipping' => null, 'insurance' => null, 'total' => null]])),
            'created_at' => now(), 'updated_at' => now()]);
        $this->answers = ['Seat cover text' => $this->answer('SEAT COVER', true, 95, [['item_price' => 199, 'fees' => [60, 40], 'total' => null]])];

        $this->assertSame(1, $this->content('Page Uno', false)['unchecked']);
        $this->assertSame([], $this->reasons($this->content(), 'x'));
        $this->content();
        $this->assertSame(1, $this->aiCalls());
    }
    public function test_S_106_5_at_most_5_images_are_asked_picture_first_and_another_item_is_a_reason(): void
    {
        $this->orders('Page Uno', 'SEAT COVER', '299', 3);
        $this->settings()->set('Page Uno', 'chatbot', 'picture', self::IMG . 'pic.jpg');
        for ($i = 1; $i <= 6; $i++) $this->settings()->set('Page Uno', 'chatbot', 'seq_img_' . $i, self::IMG . 'seq' . $i . '.jpg');
        $this->answers = [self::IMG . 'pic.jpg' => $this->answer('LED STRIP', false, 92)];
        for ($i = 1; $i <= 6; $i++) $this->answers[self::IMG . 'seq' . $i . '.jpg'] = $this->answer('SEAT COVER', true, 90);

        $res = $this->content();
        $asked = collect(Http::recorded(fn (HttpRequest $r) => str_contains($r->url(), 'api.openai.com')))->map(fn ($pair) => data_get($pair[0]->data(), 'messages.1.content.1.image_url.url'))->all();
        $this->assertSame([self::IMG . 'pic.jpg', self::IMG . 'seq1.jpg', self::IMG . 'seq2.jpg', self::IMG . 'seq3.jpg', self::IMG . 'seq4.jpg'], $asked);
        $this->assertSame(['PICTURE: looks like LED STRIP, orders: SEAT COVER'], $this->reasons($res, 'x'));
        $this->assertContains('PICTURE: looks like LED STRIP, orders: SEAT COVER', $this->check()['reasons']);
    }

    public function test_S_106_6_a_video_linked_to_the_page_through_its_ad_copy_is_compared_and_an_unlinked_page_asks_nothing(): void
    {
        $this->orders('Page Uno', 'SEAT COVER', '299', 3);
        $this->orders('Page Dos', 'SEAT COVER', '299', 3);
        $xyz = DB::table('gpt_video_analyses')->insertGetId(['file_name' => 'xyz.mp4', 'file_sha256' => str_repeat('a', 64), 'item_name' => 'NIGHT LAMP', 'summary' => 'A warm lamp by the bed', 'updated_at' => now()]);
        $abc = DB::table('gpt_video_analyses')->insertGetId(['file_name' => 'abc.mp4', 'file_sha256' => str_repeat('b', 64), 'item_name' => 'SEAT COVER', 'summary' => 'Covers', 'updated_at' => now()]);
        DB::table('gpt_ad_generations')->insert([
            ['page_filter' => 'Other Page, page uno', 'video_analysis_id' => $xyz, 'created_at' => now()],
            ['page_filter' => 'Page Uno Extra', 'video_analysis_id' => $abc, 'created_at' => now()], // ibang page, hindi kahawig
            ['page_filter' => 'Page Uno', 'video_analysis_id' => null, 'created_at' => now()],
        ]);
        $this->answers = ['A warm lamp by the bed' => $this->answer('NIGHT LAMP', false, 95)];

        $res = $this->content();
        $this->assertSame(["Ad video 'xyz.mp4': NIGHT LAMP ≠ SEAT COVER"], $this->reasons($res, 'x'));
        $this->assertSame(1, $this->aiCalls());

        $dos = $this->content('Page Dos');
        $this->assertSame([[], 1], [$dos['findings'], $this->aiCalls()]);
    }

    public function test_S_106_7_an_answer_is_reused_for_the_same_version_and_expected_item_and_a_page_view_never_asks(): void
    {
        $this->orders('Page Uno', 'SEAT COVER', '299', 3);
        $this->settings()->set('Page Uno', 'chatbot', 'main_flow', 'Seat cover text');
        $this->answers = ['Seat cover text' => $this->answer('SEAT COVER', true, 95), 'Seat cover text two' => $this->answer('SEAT COVER', true, 95)];

        // Ang page view: walang tanong, "not checked yet".
        $this->assertSame(1, $this->check()['content']['unchecked']);
        $this->getJson('/chatbot/data')->assertOk();
        $this->assertSame(0, $this->aiCalls());

        $this->content();
        $this->content();
        $this->assertSame(1, $this->aiCalls(), 'parehong version at item: mula sa naitala');
        $this->assertSame(0, $this->check()['content']['unchecked'], 'ang natapos na sagot ay lumalabas sa susunod na basa');

        $this->settings()->set('Page Uno', 'chatbot', 'main_flow', 'Seat cover text two');
        $this->assertSame(1, $this->content('Page Uno', false)['unchecked'], 'bagong version: wala pang sagot');
        $this->content();
        $this->assertSame(2, $this->aiCalls(), 'bagong version: tinanong ulit');

        $this->orders('Page Uno', 'PHONE HOLDER', '199', 5);
        $this->content();
        $this->assertSame(3, $this->aiCalls(), 'bagong inaasahang item: tinanong ulit');
    }

    public function test_S_106_8_the_command_queues_the_job_runs_it_with_sync_nightly_and_check_again_queues_one_page(): void
    {
        $this->orders('Page Uno', 'SEAT COVER', '299', 3);
        $this->orders('Page Dos', 'SEAT COVER', '299', 3);
        $this->settings()->set('Page Uno', 'chatbot', 'main_flow', 'Uno text');
        $this->settings()->set('Page Dos', 'chatbot', 'main_flow', 'Dos text');
        $this->answers = ['Uno text' => $this->answer('SEAT COVER', true, 95), 'Dos text' => $this->answer('SEAT COVER', true, 95)];

        $this->artisan('chatbot:content-check')->assertExitCode(0);
        Queue::assertPushed(RunChatbotContentCheck::class, fn ($job) => $job->page === null);
        $this->artisan('chatbot:content-check', ['--page' => 'Page Dos'])->assertExitCode(0);
        Queue::assertPushed(RunChatbotContentCheck::class, fn ($job) => $job->page === 'Page Dos');
        $this->assertSame(0, $this->aiCalls());

        $this->artisan('chatbot:content-check', ['--sync' => true])->assertExitCode(0);
        $this->assertSame(2, $this->aiCalls(), 'bawat page ng chatbot');
        $this->assertSame(2, DB::table('chatbot_content_answers')->count());

        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'chatbot:content-check'));
        $this->assertCount(1, $events);
        $this->assertSame(['40 2 * * *', 'Asia/Manila'], [$events->first()->expression, $events->first()->timezone]);

        $this->actingAs($this->user());
        $this->postJson('/chatbot/check', ['page_name' => 'Page Uno'])->assertOk();
        Queue::assertPushed(RunChatbotContentCheck::class, fn ($job) => $job->page === 'Page Uno');
    }

    public function test_S_106_9_the_check_json_and_the_command_json_carry_the_content_findings(): void
    {
        $this->orders('Page Uno', 'SEAT COVER', '299', 3);
        $this->settings()->set('Page Uno', 'chatbot', 'main_flow', 'Uno text');
        $this->answers = ['Uno text' => $this->answer('NIGHT LAMP', false, 80)];
        $this->content();

        $res = $this->check();
        $this->assertSame(['item' => 'SEAT COVER', 'price' => 299, 'source' => 'orders', 'orders' => 3], $res['content']['expected']);
        $this->assertSame([['kind' => 'text', 'field' => 'Main Flow', 'level' => 'x', 'reason' => 'Main Flow: NIGHT LAMP ≠ orders: SEAT COVER (80%)']], $res['content']['findings']);
        $this->assertSame([0, []], [$res['content']['unchecked'], $res['warnings']]);

        Artisan::call('chatbot:content-check', ['--json' => true]);
        $json = json_decode(Artisan::output(), true);
        $this->assertSame(['Page Dos', 'Page Uno'], array_column($json['pages'], 'page'));
        $uno = collect($json['pages'])->firstWhere('page', 'Page Uno');
        $this->assertSame(['page', 'expected', 'findings', 'unchecked'], array_keys($uno));
        $this->assertSame($res['content']['findings'], $uno['findings']);
        $this->assertSame(1, $this->aiCalls(), '--json lang: walang bagong tanong');
    }

    public function test_S_106_10_each_answer_logs_model_tokens_and_cost_and_a_failed_answer_stores_nothing(): void
    {
        $this->orders('Page Uno', 'SEAT COVER', '299', 3);
        $this->settings()->set('Page Uno', 'chatbot', 'main_flow', 'Good text');
        $this->settings()->set('Page Uno', 'chatbot', 'main_flow_2', 'Down text');
        $this->settings()->set('Page Uno', 'chatbot', 'seq_img_1', self::IMG . 'seq.jpg');
        $this->settings()->set('Page Uno', 'chatbot', 'picture', self::IMG . 'pic.jpg');
        // Ang "true" na string ay tinatanggap (hindi itinatanong ulit gabi-gabi).
        $this->answers = ['Good text' => $this->answer('SEAT COVER', true, 95), 'Down text' => 503, self::IMG . 'seq.jpg' => 'not json at all',
            self::IMG . 'pic.jpg' => ['found_item' => 'SEAT COVER', 'same_product' => 'true', 'confidence' => '90']];

        $res = $this->content();
        $this->assertSame(2, $res['unchecked']);
        $rows = DB::table('chatbot_content_answers')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('ok', collect($res['findings'])->firstWhere('kind', 'image')['level']);
        $this->assertSame(['gpt-4o-mini', 1000, 100, 0.00021], [$rows[0]->model, (int) $rows[0]->tokens_in, (int) $rows[0]->tokens_out, round((float) $rows[0]->cost_usd, 6)]);
    }

    /** Ang SHOP DETAILS column, at ang item, presyo at dami ng page. */
    private function shop(?string $details, string $item = 'UNISEX SOCKS', string $price = '299', string $quantity = '1'): void
    {
        app(ChatbotFields::class)->add('SHOP DETAILS');
        $s = $this->settings();
        foreach (['item_name_in_jnt_dashboard' => $item, 'price' => $price, 'quantity' => $quantity, 'shop_details' => $details] as $key => $value) {
            if ($value !== null) $s->set('Page Uno', 'chatbot', $key, $value);
        }
    }

    /** @return array<int, array{0: string, 1: string}> level at reason ng mga finding ng isang field */
    private function of(array $res, string $field): array
    {
        return array_values(array_map(fn ($f) => [$f['level'], $f['reason']], array_filter($res['findings'], fn ($f) => $f['field'] === $field)));
    }

    public function test_S_158_4_shop_details_is_built_from_the_fields_and_a_missing_part_is_an_x(): void
    {
        // Walang ITEM, PRICE, QUANTITY o lumang SHOP DETAILS: hindi pa nagbebenta, walang SHOP DETAILS finding.
        $this->assertSame([], $this->of($this->content(), 'SHOP DETAILS'));

        // Ang lumang naka-save na text (ibang page at item) ay hindi na binabasa.
        $this->shop('PAGE: Vanessa Reyes | ITEM: CHRISTMAS LIGHTS | PRICE: ₱299 | QUANTITY: 1');
        $this->assertSame([['ok', 'SHOP DETAILS: built from ITEM NAME IN JNT DASHBOARD, PRICE and QUANTITY']], $this->of($this->content(), 'SHOP DETAILS'));

        $this->settings()->deactivate($this->settings()->pageQuery('Page Uno')->where('provider', 'chatbot')->where('key', 'quantity')->where('active', true)->sole());
        $this->assertSame("PAGE: Page Uno\nITEM: UNISEX SOCKS\nPRICE: ₱299", app(\App\Services\Chatbot\ShopDetails::class)->for('Page Uno'));
        $this->assertSame([['x', 'SHOP DETAILS: missing QUANTITY']], $this->of($this->content(), 'SHOP DETAILS'));
        $res = $this->check();
        $this->assertSame('x', $res['status']);
        $this->assertContains('SHOP DETAILS: missing QUANTITY', $res['reasons']);
        $this->assertSame(0, $this->aiCalls(), 'walang AI');
    }

    public function test_S_145_4_a_seq_price_other_than_the_cod_and_price_is_an_x_and_unchanged_texts_are_not_asked_again(): void
    {
        $this->orders('Page Uno', 'SEAT COVER', '299', 3);
        $this->settings()->set('Page Uno', 'chatbot', 'price', '249');
        $this->settings()->set('Page Uno', 'chatbot', 'seq_1', 'Seq wrong price');
        $this->settings()->set('Page Uno', 'chatbot', 'seq_2', 'Seq right total');
        $this->settings()->set('Page Uno', 'chatbot', 'seq_3', 'Seq item price');
        $this->answers = [
            'Seq wrong price' => $this->answer('SEAT COVER', true, 95, [['item_price' => 399, 'fees' => [], 'total' => null]]),
            'Seq right total' => $this->answer('SEAT COVER', true, 95, [['item_price' => null, 'fees' => [], 'total' => 299]]),
            'Seq item price' => $this->answer('SEAT COVER', true, 95, [['item_price' => 249, 'fees' => [], 'total' => null]]),
        ];
        $res = $this->content();
        $this->assertSame([['ok', 'Seq 1: SEAT COVER (orders: SEAT COVER)'], ['x', 'Seq 1: price ₱399 ≠ orders ₱299 COD, PRICE ₱249']], $this->of($res, 'Seq 1'));
        $this->assertSame(['ok', 'Seq 2: price ₱299 = ₱299 COD'], $this->of($res, 'Seq 2')[1]);
        $this->assertSame(['ok', 'Seq 3: price ₱249 = PRICE ₱249'], $this->of($res, 'Seq 3')[1]);
        $this->assertSame(3, $this->aiCalls());

        $this->content();
        $this->assertSame(3, $this->aiCalls(), 'hindi nagbago: hindi itinanong ulit');
    }

    public function test_S_145_5_a_prompt_naming_another_item_is_an_x_and_a_seq_naming_no_item_gives_no_finding(): void
    {
        $this->orders('Page Uno', 'SEAT COVER', '299', 3);
        $this->settings()->set('Page Uno', 'chatbot', 'prompt_1', 'Prompt about lights');
        $this->settings()->set('Page Uno', 'chatbot', 'seq_1', 'Seq no item');
        $this->answers = ['Prompt about lights' => $this->answer('CHRISTMAS LIGHTS', false, 90), 'Seq no item' => $this->answer('', false, 40)];
        $res = $this->content();
        $this->assertSame([['x', 'Prompt 1: CHRISTMAS LIGHTS ≠ orders: SEAT COVER (90%)']], $this->of($res, 'Prompt 1'));
        $this->assertSame([], $this->of($res, 'Seq 1'));
    }

    public function test_S_145_7_the_nightly_run_and_its_json_include_shop_details_seq_and_setup_findings(): void
    {
        $this->orders('Page Uno', 'UNISEX SOCKS', '299', 3);
        $this->shop('PAGE: Vanessa Reyes | ITEM: UNISEX SOCKS | PRICE: 299 | QUANTITY: 1');
        $this->settings()->set('Page Uno', 'chatbot', 'seq_1', 'Seq socks');
        $this->answers = ['Seq socks' => $this->answer('UNISEX SOCKS', true, 95)];
        foreach ($this->settings()->pageQuery('Page Dos')->where('provider', 'botcake')->where('key', 'chat.key')->get() as $row) $this->settings()->deactivate($row);

        $this->artisan('chatbot:content-check', ['--sync' => true])->assertExitCode(0);
        $this->assertSame(1, $this->aiCalls(), 'ang Seq ay itinanong sa gabi');
        Artisan::call('chatbot:content-check', ['--json' => true]);
        $pages = collect(json_decode(Artisan::output(), true)['pages'])->keyBy('page');
        $fields = array_column($pages['Page Uno']['findings'], 'field');
        $this->assertContains('SHOP DETAILS', $fields);
        $this->assertContains('Seq 1', $fields);
        $this->assertContains('No chat block (chat.key): turn the chat block on with the bot toggle', array_column($pages['Page Dos']['findings'], 'reason'));
    }

    #[DataProvider('amounts')]
    public function test_S_145_8_peso_amounts_parse(string $text, ?float $amount): void
    {
        $this->assertSame($amount, PageContentCheck::peso($text));
    }

    public static function amounts(): array
    {
        return ['peso sign and comma' => ['₱1,198', 1198.0], 'pesos word' => ['198 pesos', 198.0], 'PHP and cents' => ['PHP 1,299.50', 1299.5], 'none' => ['free', null]];
    }

    /** Ang mga finding ng comment (kind comment), [level, reason]. */
    private function comments(array $res, bool $info = false): array
    {
        return array_values(array_map(fn ($f) => [$f['level'], $f['reason']], array_filter($res['findings'], fn ($f) => $f['kind'] === 'comment' && ($info || $f['level'] !== 'info'))));
    }

    private const FORM = "\n✅ Name:\n✅ Mobile Number:\n✅ Complete Address:\n✅ Landmark:";
    private const NO_FORM = 'Comment PM: no order form or call to reply: add Name, Mobile Number, Complete Address and Landmark, or ask the customer to reply for the form';

    public function test_S_151_1_a_comment_pm_naming_another_item_is_an_x_and_a_matching_item_and_price_passes(): void
    {
        $this->orders('Page Uno', 'SEAT COVER', '299', 3);
        $this->settings()->set('Page Uno', 'chatbot', 'comment_pm', 'PM lights ₱299' . self::FORM);
        $this->answers = ['PM lights' => $this->answer('CHRISTMAS LIGHTS', false, 90, [['item_price' => 299, 'fees' => [], 'total' => null]])];
        $this->assertSame([['x', 'Comment PM: CHRISTMAS LIGHTS ≠ orders: SEAT COVER (90%)']], $this->comments($this->content()));
        $res = $this->check();
        $this->assertSame('x', $res['status']);
        $this->assertSame(1, count(array_keys($res['reasons'], 'Comment PM: CHRISTMAS LIGHTS ≠ orders: SEAT COVER (90%)', true)), 'shown once on a page with a flow');

        $this->settings()->set('Page Uno', 'chatbot', 'comment_pm', 'PM seat cover ₱299' . self::FORM);
        $this->answers = ['PM seat cover' => $this->answer('SEAT COVER', true, 95, [['item_price' => 299, 'fees' => [], 'total' => null]])];
        $this->assertSame([['ok', 'Comment PM: SEAT COVER (orders: SEAT COVER)'], ['ok', 'Comment PM: price ₱299 = ₱299 COD']], $this->comments($this->content()));

        $this->settings()->set('Page Uno', 'chatbot', 'comment_pm', 'PM wrong price ₱399' . self::FORM);
        $this->answers = ['PM wrong price' => $this->answer('SEAT COVER', true, 95, [['item_price' => 399, 'fees' => [], 'total' => null]])];
        $this->assertSame(['x', 'Comment PM: price ₱399 ≠ orders ₱299 COD'], $this->comments($this->content())[1]);
    }

    public function test_S_151_2_a_comment_pm_without_a_price_or_without_a_form_or_reply_cue_is_a_warning(): void
    {
        $this->orders('Page Uno', 'SEAT COVER', '299', 3);
        $this->settings()->set('Page Uno', 'chatbot', 'comment_pm', 'PM no price' . self::FORM);
        $this->answers = ['PM no price' => $this->answer('SEAT COVER', true, 95)];
        $this->assertSame([['ok', 'Comment PM: SEAT COVER (orders: SEAT COVER)'], ['warn', 'Comment PM: no price']], $this->comments($this->content()));

        $this->settings()->set('Page Uno', 'chatbot', 'comment_pm', 'Text no form, seat cover ₱299 lang po');
        $this->answers = ['Text no form' => $this->answer('SEAT COVER', true, 95, [['item_price' => 299, 'fees' => [], 'total' => null]])];
        $this->assertContains(['warn', self::NO_FORM], $this->comments($this->content()));
        $this->assertContains(self::NO_FORM, $this->check()['warnings']);

        $this->answers = ['PM cue' => $this->answer('SEAT COVER', true, 95, [['item_price' => 299, 'fees' => [], 'total' => null]])];
        foreach (['Reply YES po para sa order form.', 'Mag-PM lang po kayo.', 'Pakisend po ang details.', 'Message lang po kayo.', 'Sagutan lang po ang form.'] as $cue) {
            $this->settings()->set('Page Uno', 'chatbot', 'comment_pm', 'PM cue, seat cover ₱299. ' . $cue);
            $this->assertSame([], array_values(array_filter($this->comments($this->content()), fn ($f) => $f[0] !== 'ok')), 'a call to reply is enough: ' . $cue);
        }
    }

    #[DataProvider('replies')]
    public function test_S_151_3_comment_replies_get_format_warnings_and_another_page_is_an_x(array $values, array $expected): void
    {
        foreach ($values as $i => $v) $this->settings()->set('Page Uno', 'chatbot', 'comment_reply_' . ($i + 1), $v);
        $this->assertSame($expected, $this->comments($this->content()));
        $this->assertSame(0, $this->aiCalls(), 'rules only, no AI');
    }

    public static function replies(): array
    {
        $spam = 'the same text on every comment can be flagged as spam';
        $short = 'keep the public reply short; the details go in the PM';

        return [
            'one variant' => [['Salamat po!'], [['warn', 'Comment Reply: only one variant: add 2–3 variants: ' . $spam]]],
            'identical ignoring case and spaces' => [['Salamat po!', 'salamat   PO!', 'Check inbox po'], [['warn', 'Comment Reply 1 and Comment Reply 2 are the same: ' . $spam]]],
            'link' => [['See https://shop.example.ph', 'Order sa www.shop.ph', 'Hi po'], [
                ['warn', 'Comment Reply 1: has a link: ' . $short], ['warn', 'Comment Reply 2: has a link: ' . $short]]],
            'phone' => [['Text 0917 123 4567 po', 'Hi po', 'Hello po'], [['warn', 'Comment Reply 1: has a phone number: ' . $short]]],
            'over 300' => [[str_repeat('a', 301), str_repeat('b', 300)], [['warn', 'Comment Reply 1: 301 characters: ' . $short]]],
            'another page named' => [['Salamat po!', 'Order na sa Page Dos!'], [['x', 'Comment Reply 2: names another page (Page Dos)']]],
            'three the same: one warning' => [['Salamat po!', 'salamat po!', 'SALAMAT PO!'], [['warn', 'Comment Reply 1, Comment Reply 2 and Comment Reply 3 are the same: ' . $spam]]],
            'good variants' => [['Salamat po!', 'Check inbox po', 'Thank you po!'], []],
            'no space after a period is not a link' => [['Salamat po.Online seller po kami', 'Hi po.Me ito', 'Order na po.CO'], []],
        ];
    }

    public function test_S_151_3_a_comment_pm_naming_another_page_is_a_warning_and_its_own_name_is_fine(): void
    {
        $this->settings()->set('Page Uno', 'chatbot', 'comment_pm', 'Galing sa page dos ito' . self::FORM);
        $this->assertSame([['warn', 'Comment PM: names another page (Page Dos)']], $this->comments($this->content('Page Uno', false)));
        $this->settings()->set('Page Dos', 'chatbot', 'comment_reply_1', 'Page Dos po ito');
        $this->settings()->set('Page Dos', 'chatbot', 'comment_reply_2', 'Salamat po sa Page Dos');
        $this->assertSame([], $this->comments($this->content('Page Dos', false)));
    }

    public function test_S_151_4_use_template_fills_the_pm_and_replies_with_the_pages_botfield_names_and_saves_nothing(): void
    {
        $this->assertSame([
            'comment_pm' => "Hi po! 😊 {{ITEM NAME IN JNT DASHBOARD}} — ₱{{PRICE}} lang po, FREE shipping at Cash on Delivery.\n\nPara po ma-reserve, i-fill up lang po:\n✅ Name:\n✅ Mobile Number:\n✅ Complete Address (House#/Purok/Street, Brgy, Municipality, Province):\n✅ Landmark:",
            'comment_reply_1' => 'Salamat po! Nag-message po kami sa inyo 😊',
            'comment_reply_2' => 'Hi po! Check your inbox po, may message kami 😊',
            'comment_reply_3' => 'Thank you po! Na-send na po namin ang details sa inyo 😊',
        ], CommentCheck::templates());
        app(ChatbotFields::class)->add('ITEM NAME');
        $this->assertStringStartsWith('Hi po! 😊 {{ITEM NAME}} — ₱{{PRICE}} lang po', CommentCheck::templates()['comment_pm']);

        $this->actingAs($this->user());
        $html = $this->get('/chatbot/pages')->assertOk()->getContent();
        $this->assertStringContainsString('Use template', $html);
        $this->assertStringContainsString('const commentTemplates = ' . json_encode(CommentCheck::templates()), $html);
        $this->assertNull($this->settings()->get('Page Uno', 'chatbot', 'comment_pm'), 'opening the page saves nothing');
    }

    /** Ang Forbeast page na may comment column: provider gateway at isang Comment Reply. */
    private function forbeastPage(): void
    {
        $this->settings()->set('Page Uno', 'chatbot', 'provider', 'gateway');
        $this->settings()->set('Page Uno', 'chatbot', 'comment_reply_1', 'Salamat po!');
        $this->settings()->set('Page Uno', 'chatbot', 'comment_reply_2', 'Check inbox po');
    }

    public function test_S_151_5_a_forbeast_token_without_the_comment_scopes_is_an_x_and_no_field_is_unknown(): void
    {
        $this->forbeastPage();
        config(['services.gateway.url' => 'https://gw.example.test', 'app.url' => 'https://likha.example.test']);
        $this->settings()->set('Page Uno', 'forbeast', 'token', 'fb-token', true);
        $perms = null;
        Http::fake(['gw.example.test/*' => function () use (&$perms) {
            return Http::response(['id' => '1001', 'name' => 'Page Uno', 'webhook' => ['url' => 'https://likha.example.test/api/gateway/events']]
                + ($perms === null ? [] : ['permissions' => $perms]));
        }]);
        $forbeast = app(\App\Services\Chatbot\ForbeastPageSetup::class);
        $no = 'Forbeast: walang comment permission — i-connect ulit ang page';

        $perms = ['pages_messaging', 'pages_manage_engagement'];
        $this->assertSame('ok', $forbeast->retest('Page Uno'));
        $this->assertContains(['x', $no], $this->comments($this->content('Page Uno', false)));
        $this->assertContains($no, $this->check()['reasons']);

        $perms = ['pages_messaging', 'pages_manage_engagement', 'pages_read_user_content'];
        $forbeast->retest('Page Uno');
        $this->assertSame([], $this->comments($this->content('Page Uno', false)));

        $perms = null;
        $forbeast->retest('Page Uno');
        $this->assertSame([['info', 'Forbeast: permissions unknown']], $this->comments($this->content('Page Uno', false), true), 'no field: no X');
        $this->assertContains('Forbeast: permissions unknown', $this->check()['notes']);
    }

    public function test_S_151_5_a_meta_permission_error_on_a_comment_send_in_the_last_24_hours_is_an_x(): void
    {
        Artisan::call('migrate', ['--path' => ['database/migrations/2026_11_08_100000_create_chatbot_comment_events_table.php']]);
        $this->forbeastPage();
        $this->settings()->set('Page Uno', 'forbeast', 'permissions', json_encode(CommentCheck::SCOPES), false, null, true);
        $event = fn (string $id, string $error, int $hoursAgo, string $page = '1001') => DB::table('chatbot_comment_events')->insert([
            'page_id' => $page, 'comment_id' => $id, 'status' => 'failed', 'pm_status' => 'failed', 'pm_error' => $error,
            'received_at' => now()->subHours($hoursAgo), 'handled_at' => now()->subHours($hoursAgo),
        ]);
        $event('c1', 'Forbeast said "meta_error: (#200) Permissions error" (Meta #200) (HTTP 502)', 30);
        $event('c2', 'Forbeast said "meta_error" (Meta #10) (HTTP 502)', 1, '2002');
        $event('c3', 'Forbeast said "meta_error" (Meta #100) (HTTP 502)', 1);
        $this->assertSame([], $this->comments($this->content('Page Uno', false)), 'old, another page, or not a permission code');

        $event('c4', 'Forbeast said "meta_error" (Meta #230) (HTTP 502)', 2);
        $this->assertSame([['x', 'Forbeast: walang comment permission — i-connect ulit ang page']], $this->comments($this->content('Page Uno', false)));
    }

    public function test_S_151_6_an_unchanged_comment_pm_is_not_asked_again(): void
    {
        $this->orders('Page Uno', 'SEAT COVER', '299', 3);
        $this->settings()->set('Page Uno', 'chatbot', 'comment_pm', 'PM seat cover ₱299' . self::FORM);
        $this->answers = ['PM seat cover' => $this->answer('SEAT COVER', true, 95, [['item_price' => 299, 'fees' => [], 'total' => null]])];
        $this->content();
        $this->assertSame(1, $this->aiCalls());
        $this->content();
        $this->check();
        $this->assertSame(1, $this->aiCalls(), 'unchanged: not asked again');
        $this->assertSame(1, DB::table('chatbot_content_answers')->where('field', 'Comment PM')->whereNotNull('cost_usd')->count(), 'cost logged like Prompt/Seq');

        $this->settings()->set('Page Uno', 'chatbot', 'comment_pm', 'PM seat cover ₱299 po' . self::FORM);
        $this->content();
        $this->assertSame(2, $this->aiCalls(), 'changed: asked once');
    }

    public function test_S_151_7_the_nightly_run_and_check_again_include_the_comment_checks(): void
    {
        $this->orders('Page Uno', 'SEAT COVER', '299', 3);
        $this->settings()->set('Page Uno', 'chatbot', 'comment_pm', 'PM lights ₱299' . self::FORM);
        $this->settings()->set('Page Uno', 'chatbot', 'comment_reply_1', 'Salamat po!');
        $this->answers = ['PM lights' => $this->answer('CHRISTMAS LIGHTS', false, 90)];

        $this->artisan('chatbot:content-check', ['--sync' => true])->assertExitCode(0);
        $this->assertSame(1, $this->aiCalls(), 'the PM is asked at night');
        Artisan::call('chatbot:content-check', ['--json' => true]);
        $reasons = array_column(collect(json_decode(Artisan::output(), true)['pages'])->keyBy('page')['Page Uno']['findings'], 'reason');
        $this->assertContains('Comment PM: CHRISTMAS LIGHTS ≠ orders: SEAT COVER (90%)', $reasons);
        $this->assertContains('Comment Reply: only one variant: add 2–3 variants: the same text on every comment can be flagged as spam', $reasons);

        $this->actingAs($this->user());
        $res = $this->postJson('/chatbot/check', ['page_name' => 'Page Uno'])->assertOk()->json();
        Queue::assertPushed(RunChatbotContentCheck::class, fn ($job) => $job->page === 'Page Uno');
        $this->assertContains('Comment PM: CHRISTMAS LIGHTS ≠ orders: SEAT COVER (90%)', $res['reasons']);
        $this->assertContains('Comment Reply: only one variant: add 2–3 variants: the same text on every comment can be flagged as spam', $res['warnings']);
    }

    public function test_S_151_8_a_botcake_page_with_comment_columns_gets_an_info_line(): void
    {
        $this->settings()->set('Page Uno', 'chatbot', 'comment_reply_1', 'Salamat po!');
        $this->settings()->set('Page Uno', 'chatbot', 'comment_reply_2', 'Check inbox po');
        $this->assertSame([['info', 'Comment columns: comments are answered only on Forbeast pages']], $this->comments($this->content('Page Uno', false), true));
        $res = $this->check();
        $this->assertContains('Comment columns: comments are answered only on Forbeast pages', $res['notes']);
        $this->assertSame([], $this->comments($this->content('Page Dos', false), true), 'no comment columns: nothing');
    }

    public function test_S_207_7_an_image_answer_saved_by_another_page_rechecks_a_page_waiting_for_it(): void
    {
        // Ang sagot ng AI sa larawan ay walang page sa key: ang dalawang page na may parehong link at item ay iisang sagot.
        $this->orders('Page Uno', 'SEAT COVER', '299', 3);
        $this->orders('Page Dos', 'SEAT COVER', '299', 3);
        foreach (['Page Uno', 'Page Dos'] as $p) $this->settings()->set($p, 'chatbot', 'picture', self::IMG . 'pic.jpg');
        $this->answers = [self::IMG . 'pic.jpg' => $this->answer('LED STRIP', false, 92)];
        $wrong = 'PICTURE: looks like LED STRIP, orders: SEAT COVER';

        $res = $this->check('Page Dos');
        $this->assertSame(1, $res['content']['unchecked'], 'no answer yet');
        $this->assertNotContains($wrong, $res['reasons']);
        $row = fn () => collect($this->getJson('/chatbot/data')->assertOk()->json('rows'))->firstWhere('page', 'Page Dos')['check'];
        $this->assertNotNull($row(), 'cached');

        // Ang content check ng Page Uno ang nagtanong at nagtala ng sagot (sa pangalan ng Page Uno).
        $this->content('Page Uno', true);
        $this->assertNull($row(), 'Page Dos waits for that answer: re-checked');
        $this->assertContains($wrong, $this->check('Page Dos')['reasons']);
        $this->assertNotNull($row(), 'cached again, now with the answer');
    }
}
